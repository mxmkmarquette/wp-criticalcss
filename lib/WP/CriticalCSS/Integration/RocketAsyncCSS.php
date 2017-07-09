<?php

namespace WP\CriticalCSS\Integration;

/**
 * Class RocketAsyncCSS
 */
class RocketAsyncCSS extends IntegrationAbstract {

	/**
	 * WP_CriticalCSS_Integration_Rocket_Async_CSS constructor.
	 */
	public function __construct() {
		if ( class_exists( 'Rocket_Async_Css' ) ) {
			parent::__construct();
		}
	}

	/**
	 * @return void
	 */
	public function enable() {
		add_action( 'wp', [
			$this,
			'wp_action',
		] );
		add_action( 'wp_criticalcss_before_print_styles', [
			$this,
			'purge_cache',
		] );
	}

	/**
	 * @return void
	 */
	public function disable() {
		remove_action( 'wp', [
			$this,
			'wp_action',
		] );
		remove_action( 'wp_criticalcss_before_print_styles', [
			$this,
			'purge_cache',
		] );
	}

	/**
	 * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
	 * @param $cache
	 */
	public function purge_cache( $cache ) {
		if ( ! empty( $cache ) ) {
			remove_action( 'wp_enqueue_scripts', [
				'Rocket_Async_Css_The_Preloader',
				'add_window_resize_js',
			] );
			remove_action( 'rocket_buffer', [
				'Rocket_Async_Css_The_Preloader',
				'inject_div',
			] );
		}
	}

	public function wp_action() {
		if ( get_query_var( 'nocache' ) ) {
			remove_action( 'wp_enqueue_scripts', [
				'Rocket_Async_Css_The_Preloader',
				'add_window_resize_js',
			] );
			remove_action( 'rocket_buffer', [
				'Rocket_Async_Css_The_Preloader',
				'inject_div',
			] );
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
		}
	}
}
