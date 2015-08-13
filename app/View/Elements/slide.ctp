<div class="slider">
<?php echo $this->element("slide_div", array("slide" => $slide)); ?>
</div>

<div class="slide_control" style="display:none">
    <?php echo $this->Html->link('<i id="slide_control_home_link" class="fa fa-television"></i>', array("controller" => "slides", "action" => "view", $slide["id"], "full_base" => true), array('escape' => false)); ?>&nbsp;
    <a href="javascript:void(0);return false;"><i id="slide_control_fast_backward" class="fa fa-fast-backward"></i></a>&nbsp;
    <span id="prev" class="slide_control_link" /></span>&nbsp;&nbsp;
    <span id="pager" class="small"></span>&nbsp;&nbsp;
    <span id="next" class="slide_control_link"></span>&nbsp;
    <a href="javascript:void(0);return false;"><i id="slide_control_fast_forward" class="fa fa-fast-forward"></i></a>
</div>

<script type="text/javascript">
$1102(document).ready(function(){
    $1102(".openslideshare_body img.lazy").lazyload({
        threshold : 200,
        effect: "fadeIn"
    });
});
</script>

<script type="text/javascript">
$1102(document).ready(function(){
    $1102(".openslideshare_body .bxslider_<?php echo $slide["key"]; ?>").show();
    $1102(".openslideshare_body .slide_control").show();

    function bxslider_init() {
        var slider_config = {
            mode: 'horizontal',
            controls: true,
            responsive:true,
            pager:true,
            pagerType:'short',
            prevText: '◀',
            nextText: '▶',
            prevSelector: "#prev",
            nextSelector: "#next",
            pagerSelector: "#pager",
            adaptiveHeight: false,
            infiniteLoop: false,
            onSlideBefore: function($slideElement, oldIndex, newIndex){
                var $lazy_next2 = $1102(".openslideshare_body ul.bxslider_<?php echo $slide["key"]; ?> img.image-" +  (newIndex + 1));
                var $load_next2 = $lazy_next2.attr("data-src");
                $lazy_next2.attr("src",$load_next2).removeClass("lazy");

                var $lazy_next = $1102(".openslideshare_body ul.bxslider_<?php echo $slide["key"]; ?> img.image-" +  (newIndex));
                var $load_next = $lazy_next.attr("data-src");
                $lazy_next.attr("src",$load_next).removeClass("lazy");
                $lazy_next.each(function(){
                    // @TODO: 画像のローディングをここに入れる
                    // while (!this.complete) {
                    // ;
                    // }
                });
            }
        }
        myslider = $1102('.openslideshare_body .bxslider_<?php echo $slide["key"]; ?>').bxSlider(slider_config);
    }
    bxslider_init();
    var timer = setInterval( updateDiv, 10 * 100);

    // スライドのページ数が0の場合は定期的に確認する
    function updateDiv() {
        var messageDiv = $1102('.openslideshare_body .slider');
        if ($1102('.openslideshare_body div.slider ul').attr("data-count") > 0) {
            return;
        }
        $1102.ajax({
            type: 'GET',
            async: false,
            url: "<?php echo Router::url($this->Html->url(array("controller" => "slides", "action" => "update_view", $slide["id"])), true); ?>",
            cache: false,
            success: function(result) {
                $1102('.openslideshare_body div.slide_control span#prev').empty();
                $1102('.openslideshare_body div.slide_control span#next').empty();
                $1102('.openslideshare_body div.slide_control span#pager').empty();
                messageDiv.empty();
                messageDiv.append(result);
                bxslider_init();
            },
            error: function(xhr, ajaxOptions, thrownError) {
                // messageDiv.empty();
            }
        });
    }
    $1102("#slide_control_fast_backward").click(function () {
        myslider.goToSlide(0);
    });
    $1102("#slide_control_fast_forward").click(function () {
        myslider.goToSlide(<?php echo count($file_list) -1; ?>);
    });
});
</script>
