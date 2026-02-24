<div class="col_side">
   <div class="col_sideIn">
      <?php if ( !function_exists('dynamic_sidebar') || !dynamic_sidebar('widget1') ) : ?>
      <?php endif; ?>
      <div class="widget" id="search-3">
         <h3>ブログ内検索</h3>
         <form method="get" action="<?php echo home_url(); ?>/">
            <div class="searchBox">
               <input type="text" value="" name="s" id="s" class="searchform" />
               <div class="btn_search"><input type="image" src="<?php echo get_template_directory_uri(); ?>/images/blog/btn_search.png" /></div>
            </div>
         </form>
      </div>
      <?php if ( !function_exists('dynamic_sidebar') || !dynamic_sidebar('widget2') ) : ?>
      <?php endif; ?>
   </div>
</div>