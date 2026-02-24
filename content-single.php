<?php
  $category = get_the_category();
  $cat_id   = $category[0]->cat_ID;
  $cat_name = $category[0]->cat_name;
  $cat_slug = $category[0]->category_nicename;
  $link = get_category_link($cat_id);
?>
<div class="post clearfix">
   <div class="postIn">
      <div class="hd"><span class="date"><?php the_time('Y.m.d'); ?></span><span class="cat"><?php echo $cat_name; ?></span></div>
      <h1><?php the_title(); ?></h1>
      <div class="expl">
         <?php remove_filter ('the_content', 'wpautop'); ?>
         <?php the_content(); ?>
      </div>

      </div>
</div>