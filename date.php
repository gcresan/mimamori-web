<?php get_header(); ?>
<article id="main">
   <div class="mainIn">
      <div class="pankuzu">
         <ul>
            <li><a href="<?php echo home_url(); ?>">ホーム</a> &gt; </li>
            <li><a href="<?php echo home_url(); ?>/blog/">活動レポート</a> &gt; </li>
            <li>
            <?php
               $date = single_month_title('',false);
               $pos  = strpos($date, '月');
               echo mb_substr($date,$pos+1).'年'.mb_substr($date,0,$pos+1);
               ?>
         </li>
         </ul>
      </div>
      <h2><span>活動レポート</span></h2>
      <section class="sec n1">
         <h3><span><?php
               $date = single_month_title('',false);
               $pos  = strpos($date, '月');
               echo mb_substr($date,$pos+1).'年'.mb_substr($date,0,$pos+1);
               ?></span></h3>
         <div class="colWrap">
            <div class="col_main">
               <?php if(have_posts()): while(have_posts()): the_post(); ?>
               <?php get_template_part( 'content-single'); ?>
               <?php endwhile; endif; ?>
            </div>
            <?php get_sidebar(); ?>
         </div>
         <?php if(function_exists('wp_pagenavi')) { wp_pagenavi(); } ?>
      </section>
   </div>
</article>
<?php get_footer(); ?>