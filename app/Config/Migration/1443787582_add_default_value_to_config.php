<?php
class AddDefaultValueToConfig extends CakeMigration
{
    /**
     * Migration description
     *
     * @var string
     */
    public $description = 'add_default_value_to_config';

    /**
     * Actions to be performed
     *
     * @var array $migration
     */
    public $migration = array(
        'up' => array(
        ),
        'down' => array(
        ),
    );

    /**
     * Before migration callback
     *
     * @param string $direction Direction of migration process (up or down)
     * @return bool Should process continue
     */
    public function before($direction)
    {
        return true;
    }

    /**
     * After migration callback
     *
     * @param string $direction Direction of migration process (up or down)
     * @return bool Should process continue
     */
    public function after($direction)
    {
        // Config data
        $Config = ClassRegistry::init('Config');
        if ($direction === 'up') {
            $config_data = array(
                "display_login_link" => "1",
                "signup_enabled" => "1",
            );
            foreach ($config_data as $k => $v) {
                $data = array();
                $data['Config']['name'] = $k;
                $data['Config']['value'] = $v;

                $Config->create();
                if ($Config->save($data)) {
                    $this->callback->out('Config data was created...');
                } else {
                    echo 'Config data creation failed......';
                }
            }
        }
        return true;
    }
}
