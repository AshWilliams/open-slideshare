<?php

use Aws\S3\Exception\S3Exception;
use Monolog\Logger;

class SlideProcessingComponent extends Component
{
    /**
     * Slide.
     *
     * @var mixed
     */
    private $Slide;

    /**
     * log.
     *
     * @var mixed
     */
    private $log;

    /**
     * s3.
     *
     * @var mixed
     */
    private $S3;

    /**
     * __construct.
     */
    public function __construct(ComponentCollection $collection, $settings = array())
    {
        parent::__construct($collection, $settings);
        $this->Slide = ClassRegistry::init('Slide');

        // create S3 library instance
        App::uses('S3Component', 'Controller/Component');
        $this->S3 = new S3Component($collection, $settings);

        // create a log channel
        $this->log = new Logger('name');
        $this->log->pushHandler(new \Monolog\Handler\StreamHandler(LOGS . DS . 'batch.log', Logger::INFO));
        $this->log->pushHandler(new \Monolog\Handler\ErrorLogHandler());
    }

    /**
     * Delete slide from S3.
     *
     * @param string $key key to remove
     */
    public function delete_slide_from_s3($s3, $key)
    {
        $this->delete_master_slide($s3, $key);
        $this->delete_generated_files($s3, $key);
    }

    /**
     * Delete all generated files in Amazon S3.
     *
     * @param string $key
     */
    public function delete_generated_files($s3, $key)
    {
        // List files and delete them.
        $res = $s3->listObjects(array('Bucket' => Configure::read('image_bucket_name'), 'MaxKeys' => 1000, 'Prefix' => $key . '/'));
        $keys = $res->getPath('Contents');
        $delete_files = array();
        if (is_array($keys)) {
            foreach ($keys as $kk) {
                $delete_files[] = array('Key' => $kk['Key']);
            }
        }
        if (count($delete_files) > 0) {
            $res = $s3->deleteObjects(array(
                'Bucket' => Configure::read('image_bucket_name'),
                'Objects' => $delete_files,
            ));
        }
    }

    /**
     * Extract images from uploaded file.
     *
     * @param array $data that is retrieved from SQS
     */
    public function extract_images($s3, $data)
    {
        // S3 object key
        $key = $data['key'];

        // filename to use for original one from S3
        $save_dir = TMP . basename($key);

        set_error_handler(function($severity, $message, $file, $line) {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            mkdir($save_dir);
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
        $file_path = tempnam($save_dir, 'original');

        // retrieve original file from S3
        $this->log->addInfo('Start retrieving file from S3');
        $object = $s3->getObject(array(
            'Bucket' => Configure::read('bucket_name'),
            'Key' => $key,
            'SaveAs' => $file_path,
        ));

        $mime_type = $this->get_mime_type($file_path);
        $this->log->addInfo('File Type is ' . $mime_type);

        // Convertable mime type
        $all_convertable = array(
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.ms-powerpoint',
        );
        $need_to_convert_pdf = array(
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.ms-powerpoint',
        );

        try {
            // Convert PowerPoint to PDF
            if (in_array($mime_type, $need_to_convert_pdf)) {
                if ($mime_type == 'application/vnd.openxmlformats-officedocument.presentationml.presentation') {
                    $extension = '.pptx';
                } elseif ($mime_type == 'application/vnd.ms-powerpoint') {
                    $extension = '.ppt';
                } else {
                    $extension = '';
                }

                $status = $this->convert_ppt_to_pdf($file_path);
                if (!$status) {
                    $this->Slide->update_status($key, ERROR_CONVERT_PPT_TO_PDF);

                    return false;
                }
            } elseif (in_array($mime_type, $all_convertable)) {
                $extension = '.pdf';
                $this->log->addInfo('Renaming file...');
                rename($file_path, $file_path . '.pdf');
            }
            $this->Slide->update_extension($key, $extension);

            if (in_array($mime_type, $all_convertable)) {
                // Convert PDF to ppm
                $status = $this->convert_pdf_to_ppm($save_dir, $file_path);
                if (!$status) {
                    $this->Slide->update_status($key, ERROR_CONVERT_PDF_TO_PPM);

                    return false;
                }

                // Convert ppm to jpg
                $status = $this->convert_ppm_to_jpg($save_dir);
                if (!$status) {
                    $this->Slide->update_status($key, ERROR_CONVERT_PPM_TO_JPG);

                    return false;
                }

                $files = $this->list_local_images($save_dir);
                $first_page = false;
                $this->upload_extract_images($s3, $key, $save_dir, $files, $first_page);

                // create thumbnail images
                if ($first_page) {
                    $this->create_thumbnail($s3, $key, $first_page);
                }
                $this->log->addInfo('Converting file successfully completed!!');
                // update the db record
                $this->Slide->update_status($key, SUCCESS_CONVERT_COMPLETED);
            } else {
                $this->Slide->update_status($key, ERROR_NO_CONVERT_SOURCE);
                $this->log->addWarning('No Convertable File');
            }
        } catch (Exception $e) {
            $this->Slide->update_status($key, -99);
        }
        $this->log->addInfo('Cleaning up working directory ' . $save_dir . '...');
        $this->cleanup_working_dir($save_dir);
        $this->log->addInfo('Completed to run the process...');

        return true;
    }

    /**
     * get_slide_pages_list.
     *
     * @param mixed $slide_key
     */
    public function get_slide_pages_list($slide_key)
    {
        App::uses('CommonHelper', 'View/Helper');
        $helper = new CommonHelper(new View());
        $url = $helper->json_url($slide_key);

        $context = stream_context_create(array(
            'http' => array('ignore_errors' => true),
        ));

        set_error_handler(function($severity, $message, $file, $line) {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        $file_list = array();
        try {
            $contents = file_get_contents($url, false, $context);
            if (strpos($http_response_header[0], '200')) {
                $file_list = json_decode($contents);
            }

            return $file_list;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * Download original file from bucket.
     *
     * @param string $key filename in S3
     */
    public function get_original_file_download_path($s3, $key, $extension = null)
    {
        $filename = $key . $extension;
        $opt = array('ResponseContentDisposition' => 'attachment; filename="' . $filename . '"');
        $url = $s3->getObjectUrl(Configure::read('bucket_name'), $key, '+15 minutes', $opt);

        return $url;
    }

    ################## Private ################

    /**
     * Convert PPT file to PDF.
     *
     * @param string $file_path source file to convert
     */
    private function convert_ppt_to_pdf($file_path)
    {
        $status = '';
        $command_logs = array();

        $this->log->addInfo('Start converting PowerPoint to PDF');
        exec('unoconv -f pdf -o ' . $file_path . '.pdf ' . $file_path, $command_logs, $status);
        $this->log->addInfo(var_export($command_logs, true));
        if ($status === 0) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Convert PDF file to PPM.
     *
     * @param string $save_dir path to store file
     *                         string $file_path source file to convert
     */
    private function convert_pdf_to_ppm($save_dir, $file_path)
    {
        $status = '';
        $command_logs = array();

        $this->log->addInfo('Start converting PDF to ppm');
        exec('cd ' . $save_dir . '&& pdftoppm ' . $file_path . '.pdf slide', $command_logs, $status);
        $this->log->addInfo(var_export($command_logs, true));
        if ($status === 0) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Convert PPM file to Jpeg.
     *
     * @param string $save_dir path to store file
     */
    private function convert_ppm_to_jpg($save_dir)
    {
        $status = '';
        $command_logs = array();

        $this->log->addInfo('Start converting ppm to jpg');
        exec('cd ' . $save_dir . '&& mogrify -format jpg slide*.ppm', $command_logs, $status);
        $this->log->addInfo(var_export($command_logs, true));
        if ($status != 0) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * Get mime type from file.
     *
     * @param string $file_path path to file
     *
     * @return string mime_type
     */
    private function get_mime_type($file_path)
    {
        $mime = shell_exec('file -bi ' . escapeshellcmd($file_path));
        $mime = trim($mime);
        $parts = explode(';', $mime);
        $mime = preg_replace('/ [^ ]*/', '', trim($parts[0]));

        return $mime;
    }

    /**
     * Upload all generated files to Amazon S3.
     *
     * @param string $key
     *                    string $save_dir
     *                    array  $files
     */
    private function upload_extract_images($s3, $key, $save_dir, $files, &$first_page)
    {
        $file_array = array();
        $this->log->addInfo('Total number of files is ' . count($files));

        $bucket = Configure::read('image_bucket_name');
        foreach ($files as $file_path => $file_info) {
            $file_key = str_replace(TMP, '', $file_path);
            $file_array[] = $file_key;
            // store image to S3
            $this->log->addInfo("Start uploading image to S3($bucket). " . $file_key);
            try {
                $s3->putObject(array(
                    'Bucket' => $bucket,
                    'Key' => $file_key,
                    'SourceFile' => $file_path,
                    'ContentType' => 'image/jpg',
                    'ACL' => 'public-read',
                    'StorageClass' => 'REDUCED_REDUNDANCY',
                ));
            } catch (S3Exception $e) {
                $this->log->addError("The file was not uploaded.\n" . $e->getMessage());
            }
        }

        sort($file_array);
        $json_contents = json_encode($file_array, JSON_UNESCAPED_SLASHES);
        file_put_contents($save_dir . '/list.json', $json_contents);

        // store list.json to S3
        $this->log->addInfo('Start uploading list.json to S3');
        $s3->putObject(array(
            'Bucket' => Configure::read('image_bucket_name'),
            'Key' => $key . '/list.json',
            'SourceFile' => $save_dir . '/list.json',
            'ContentType' => 'text/plain',
            'ACL' => 'public-read',
            'StorageClass' => 'REDUCED_REDUNDANCY',
        ));

        if (count($file_array) > 0) {
            $first_page = $file_array[0];
        } else {
            $first_page = false;
        }
    }

    /**
     * Delete master slide from Amazon S3.
     *
     * @param string $key
     */
    private function delete_master_slide($s3, $key)
    {
        $res = $s3->deleteObject(array(
            'Bucket' => Configure::read('bucket_name'),
            'Key' => $key,
        ));
    }

    /**
     * Clean up working directory.
     *
     * @param string $dir
     */
    private function cleanup_working_dir($dir)
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            $command = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $command($fileinfo->getRealPath());
        }
        rmdir($dir);
    }

    /**
     * Create thumbnail from specified original image file.
     *
     * @param string $key
     *                    string $filename
     */
    private function create_thumbnail($s3, $key, $filename)
    {
        // Create Same Size Thumbnail
        $f = TMP . $filename;
        $src_image = imagecreatefromjpeg($f);

        // get size
        $width = ImageSx($src_image);
        $height = ImageSy($src_image);

        // Tatenaga...
        if ($height > $width * 0.75) {
            $src_y = (int) ($height - ($width * 0.75));
            $src_h = $height - $src_y;
            $src_x = 0;
            $src_w = $width;
        } else {
            // Yokonaga
            $src_y = 0;
            $src_h = $height;
            $src_x = 0;
            $src_w = $height / 0.75;
        }

        // get resized size
        $dst_w = 320;
        $dst_h = 240;

        // generate file
        $dst_image = ImageCreateTrueColor($dst_w, $dst_h);
        ImageCopyResampled($dst_image, $src_image, 0, 0, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h);

        imagejpeg($dst_image, TMP . $key . '/thumbnail.jpg');

        // store thumbnail to S3
        $s3->putObject(array(
            'Bucket' => Configure::read('image_bucket_name'),
            'Key' => $key . '/thumbnail.jpg',
            'SourceFile' => TMP . $key . '/thumbnail.jpg',
            'ContentType' => 'image/jpeg',
            'ACL' => 'public-read',
            'StorageClass' => 'REDUCED_REDUNDANCY',
        ));
    }

    /**
     * List all files in specified directory.
     *
     * @param string $dir
     *
     * @return array
     */
    private function list_local_images($dir)
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir,
                FilesystemIterator::CURRENT_AS_FILEINFO |
                FilesystemIterator::KEY_AS_PATHNAME |
                FilesystemIterator::SKIP_DOTS
            )
        );

        $files = new RegexIterator($files, '/^.+\.jpg$/i', RecursiveRegexIterator::MATCH);

        return $files;
    }
}
