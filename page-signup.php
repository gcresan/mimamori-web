<?php
/**
 * Template Name: お申込みページ（旧）
 *
 * 旧申込ページ。/apply/ へ 301 リダイレクト。
 *
 * @package Mimamori_Web
 */
wp_safe_redirect( home_url('/apply/'), 301 );
exit;
