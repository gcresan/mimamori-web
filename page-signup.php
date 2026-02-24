<?php
/**
 * Template Name: お申込みページ（旧）
 *
 * 旧申込ページ。/apply/ へ 301 リダイレクト。
 *
 * @package GCREV_INSIGHT
 */
wp_safe_redirect( home_url('/apply/'), 301 );
exit;
