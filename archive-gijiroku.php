<?php get_header(); ?>
<article id="main">
   <div class="mainIn">
      <div class="pankuzu">
         <ul>
            <li><a href="<?php echo home_url(); ?>">ホーム</a> &gt; </li>
            <li>メンバー専用ページ</li>
         </ul>
      </div>
      <h2><span>メンバー専用ページ</span></h2>
      <section class="sec n2">
         <h3><span>議事録等</span></h3>
         <div class="selectBox">
            <select name="archive-dropdown" onChange='document.location.href=this.options[this.selectedIndex].value;'>
               <option value=""><?php echo attribute_escape(__('Select Month')); ?></option>
               <?php wp_get_archives('type=monthly&post_type=gijiroku&format=option&show_post_count=1'); ?>
            </select>
         </div>
         <h4><span>
            <?php
               $date = single_month_title('',false);
               $pos  = strpos($date, '月');
               echo mb_substr($date,$pos+1).'年'.mb_substr($date,0,$pos+1);
               ?>
            </span>
         </h4>
         <div class="list">
            <ul>
               <?php if(have_posts()): while(have_posts()):
                  the_post(); ?>
               <li><a href="<?php the_field('file_pdf'); ?>" target="_blank"><span class="fwB"><?php the_title();?></span>（<?php the_time('Y.m.d');?>）</a></li>
               <?php endwhile; endif; ?>
            </ul>
            <div class="pagenavi">
               <?php if(function_exists('wp_pagenavi')) { wp_pagenavi(); } ?>
            </div>
         </div>
      </section>
      <section class="sec n1">
         <h3><span>出欠確認の回答状況</span></h3>
         <?php query_posts( array(
            'post_type' => 'contact_matters', //カスタム投稿名
            'posts_per_page' => 1 //表示件数（ -1 = 全件 ）
            )); ?>
         <?php if(have_posts()): while(have_posts()):
            the_post(); ?>
         <div class="expl pt00 pl00">
            <?php the_content(); ?><br />
            <span class="fzS">※回答状況は5分ごとに更新されます</span>
         </div>
         <?php endwhile; endif; ?>
         <iframe src="https://docs.google.com/spreadsheets/d/e/2PACX-1vQJ986vBwxQbLpVChbr_dXp77hr8Z3FYo_IrWK1Rv3eX_E8gy_LvOo6wxOqTKILLTK9TByXd5sHYVEh/pubhtml?gid=1254295862&amp;single=true&amp;widget=true&amp;headers=false"></iframe>
      </section>
   </div>
</article>
<?php get_footer(); ?>