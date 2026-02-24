<?php get_header(); ?>
<article id="main">
   <div class="mainIn">
      <div class="pankuzu">
         <ul>
            <li><a href="<?php echo home_url(); ?>">ホーム</a> &gt; </li>
            <li><a href="<?php echo home_url(); ?>/blog/">活動レポート</a> &gt; </li>
            <li>検索結果</li>
         </ul>
      </div>
      <h2><span>活動レポート</span></h2>
      <section class="sec n1">
         <h3><span>検索結果</span></h3>
         <div class="colWrap">
      <div class="col_main">
         <?php $allsearch = new WP_Query("s=$s&posts_per_page=-1");
            $key = wp_specialchars($s, 1);
            $count = $allsearch->post_count;
            if($count!=0){
            // 検索結果を表示:該当記事あり
                echo '<div class="mb20">“<strong>'.$key.'</strong>”で検索した結果、<strong>'.$count.'</strong>件の記事が見つかりました。</div>';
            } 
            else {
            // 検索結果を表示:該当記事なし
                echo '<div class="mb20">“<strong>'.$key.'</strong>”で検索した結果、関連する記事は見つかりませんでした。</div>';
            }
            ?>
         <?php if(have_posts()): while(have_posts()):
            the_post(); ?>
         <?php get_template_part('content-single'); ?>
         <?php endwhile; ?>
         <?php else: ?>
         <div class="msg">
            <p>リクエストされたページが存在しませんでした。他の検索ワードで見つかるかもしれません。</p>
         </div>
         <?php endif; ?>
      </div>
      <?php get_sidebar(); ?>
   </div>
         <?php if(function_exists('wp_pagenavi')) { wp_pagenavi(); } ?>
      </section>
   </div>
</article>
<?php get_footer(); ?>