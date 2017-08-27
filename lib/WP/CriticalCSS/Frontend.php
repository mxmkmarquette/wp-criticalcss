<?php


namespace WP\CriticalCSS;

use pcfreak30\WordPress\Plugin\Framework\ComponentAbstract;

class Frontend extends ComponentAbstract {
	public function init() {
		if ( ! is_admin() ) {
			add_action(
				'wp_print_styles', [
				$this,
				'print_styles',
			], 7 );
			add_action(
				'wp', [
					$this,
					'wp_action',
				]
			);
			add_action(
				'wp_head', [
					$this,
					'wp_head',
				]
			);
		}
	}

	/**
	 *
	 */
	public function wp_head() {
		if ( get_query_var( 'nocache' ) ) :
			?>
			<meta name="robots" content="noindex, nofollow"/>
			<?php
		endif;
	}

	/**
	 *
	 */
	public function wp_action() {
		set_query_var( 'nocache', $this->plugin->request->is_no_cache() );
		$this->plugin->integration_manager->enable_integrations();
	}

	/**
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	public function print_styles() {
		if ( get_query_var( 'nocache' ) ) {
			do_action( 'wp_criticalcss_nocache' );
		}
		if ( ! get_query_var( 'nocache' ) && ! is_404() ) {
			$request  = $this->plugin->request->get_current_page_type();
			$manual   = true;
			$fallback = false;
			if ( 'post' === $request['type'] ) {
				$manual = apply_filters( 'wp_criticalcss_manual_post_css', true );
			}
			if ( 'term' === $request['type'] ) {
				$manual = apply_filters( 'wp_criticalcss_manual_term_css', true );
			}
			$manual_cache = null;
			$fallback_css = trim( $this->plugin->settings_manager->get_setting( 'fallback_css' ) );

			if ( $manual ) {
				$manual_cache = trim( $this->plugin->data_manager->get_manual_css() );
			}

			$cache = trim( $this->plugin->data_manager->get_cache() );
			if ( 'on' === $this->plugin->settings_manager->get_setting( 'prioritize_manual_css' ) ) {
				$cache = $manual_cache;
				if ( empty( $cache ) ) {
					$manual   = false;
					$fallback = true;
					$cache    = $fallback_css;
				}
			}

			if ( empty( $cache ) ) {
				$manual   = true;
				$fallback = false;
				$cache    = $manual_cache;
			}
			if ( empty( $cache ) ) {
				$manual   = false;
				$fallback = true;
				$cache    = $fallback_css;
			}

			$cache = apply_filters( 'wp_criticalcss_print_styles_cache', $cache );

			do_action( 'wp_criticalcss_before_print_styles', $cache );

			if ( ! empty( $cache ) ) {
				?>
				<style type="text/css" id="criticalcss" data-no-minify="1"><?php echo $cache ?></style>
				<?php
			}
			$type  = $this->plugin->request->get_current_page_type();
			$hash  = $this->plugin->data_manager->get_item_hash( $type );
			$check = $this->plugin->cache_manager->get_cache_fragment( [ 'webcheck', $hash ] );
			if ( 'on' === $this->plugin->settings_manager->get_setting( 'template_cache' ) && ! empty( $type['template'] ) ) {
				if ( empty( $cache ) && ! $this->plugin->api_queue->get_item_exists( $type ) ) {
					$this->plugin->api_queue->push_to_queue( $type )->save();
				}
			} else {
				if ( ! ( $manual || $fallback ) && empty( $check ) && ! $this->plugin->web_check_queue->get_item_exists( $type ) ) {
					$this->plugin->web_check_queue->push_to_queue( $type )->save();
					$this->plugin->cache_manager->update_cache_fragment( [ 'webcheck', $hash ], true );
				}
			}

			do_action( 'wp_criticalcss_after_print_styles' );
		}
	}
}
