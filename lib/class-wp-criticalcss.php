<?php

/**
 * Class CriticalCSS
 */
class WP_CriticalCSS {
	/**
	 *
	 */
	const VERSION = '0.6.3';

	/**
	 *
	 */
	const LANG_DOMAIN = 'wp_criticalcss';

	/**
	 *
	 */
	const OPTIONNAME = 'wp_criticalcss';

	const TRANSIENT_PREFIX = 'wp_criticalcss_web_check_';
	protected static $instance;
	/**
	 * @var bool
	 */
	protected $nocache = false;
	/**
	 * @var \WP_CriticalCSS_Settings_API
	 */
	private $_settings_ui;
	/**
	 * @var WP_CriticalCSS_Web_Check_Background_Process
	 */
	private $_web_check_queue;
	/**
	 * @var WP_CriticalCSS_API_Background_Process
	 */
	private $_api_queue;
	/**
	 * @var \WP_CriticalCSS_Queue_List_Table
	 */
	private $_queue_table;
	/**
	 * @var array
	 */
	private $_settings = array();
	private $_template;

	public static function get_instance() {
		if ( empty( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * @return bool
	 */
	public function get_no_cache() {
		return $this->nocache;
	}

	public function wp_head() {
		if ( get_query_var( 'nocache' ) ):
			?>
            <meta name="robots" content="noindex, nofollow"/>
			<?php
		endif;
	}

	/**
	 * @param $redirect_url
	 *
	 * @return bool
	 */
	public function redirect_canonical( $redirect_url ) {
		global $wp_query;
		if ( ! array_diff( array_keys( $wp_query->query ), array( 'nocache' ) ) || get_query_var( 'nocache' ) ) {
			$redirect_url = false;
		}

		return $redirect_url;
	}

	/**
	 * @param \WP $wp
	 */
	public function parse_request( WP &$wp ) {
		if ( isset( $wp->query_vars['nocache'] ) ) {
			$this->nocache = $wp->query_vars['nocache'];
			unset( $wp->query_vars['nocache'] );
		}
	}

	/**
	 * @param $vars
	 *
	 * @return array
	 */
	public function query_vars( $vars ) {
		$vars[] = 'nocache';

		return $vars;
	}

	/**
	 * @param $vars
	 *
	 * @return mixed
	 */
	public function update_request( $vars ) {
		if ( isset( $vars['nocache'] ) ) {
			$vars['nocache'] = true;
		}

		return $vars;
	}

	/**
	 *
	 */
	public function wp_action() {
		set_query_var( 'nocache', $this->nocache );
		if ( $this->has_external_integration() ) {
			$this->external_integration();
		}
	}

	/**
	 * @return bool
	 */
	public function has_external_integration() {
		// // Compatibility with WP Rocket ASYNC CSS preloader integration
		if ( class_exists( 'Rocket_Async_Css_The_Preloader' ) ) {
			return true;
		}
		// WP-Rocket integration
		if ( function_exists( 'get_rocket_option' ) ) {
			return true;
		}

		return false;
	}

	/**
	 *
	 */
	public function external_integration() {
		if ( get_query_var( 'nocache' ) ) {
			// Compatibility with WP Rocket ASYNC CSS preloader integration
			if ( class_exists( 'Rocket_Async_Css_The_Preloader' ) ) {
				remove_action( 'wp_enqueue_scripts', array(
					'Rocket_Async_Css_The_Preloader',
					'add_window_resize_js',
				) );
				remove_action( 'rocket_buffer', array( 'Rocket_Async_Css_The_Preloader', 'inject_div' ) );
			}
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
		}
		// Compatibility with WP Rocket
		if ( function_exists( 'get_rocket_option' ) ) {
			add_action( 'after_rocket_clean_domain', array( $this, 'reset_web_check_transients' ) );
			add_action( 'after_rocket_clean_post', array( $this, 'reset_web_check_post_transient' ) );
			add_action( 'after_rocket_clean_term', array( $this, 'reset_web_check_term_transient' ) );
			add_action( 'after_rocket_clean_home', array( $this, 'reset_web_check_home_transient' ) );
			if ( ! has_action( 'after_rocket_clean_domain', 'rocket_clean_wpengine' ) ) {
				add_action( 'after_rocket_clean_domain', 'rocket_clean_wpengine' );
			}
			if ( ! has_action( 'after_rocket_clean_domain', 'rocket_clean_supercacher' ) ) {
				add_action( 'after_rocket_clean_domain', 'rocket_clean_supercacher' );
			}

		}
	}

	public function disable_external_integration() {
		// Compatibility with WP Rocket
		if ( function_exists( 'get_rocket_option' ) ) {
			remove_action( 'after_rocket_clean_domain', array( $this, 'reset_web_check_transients' ) );
			remove_action( 'after_rocket_clean_post', array( $this, 'reset_web_check_post_transient' ) );
			remove_action( 'after_rocket_clean_term', array( $this, 'reset_web_check_term_transient' ) );
			remove_action( 'after_rocket_clean_home', array( $this, 'reset_web_check_home_transient' ) );
			remove_action( 'after_rocket_clean_domain', 'rocket_clean_wpengine' );
			remove_action( 'after_rocket_clean_domain', 'rocket_clean_supercacher' );
		}
	}

	/**
	 *
	 */
	public function activate() {
		global $wpdb;
		$settings    = $this->get_settings();
		$no_version  = ! empty( $settings ) && empty( $settings['version'] );
		$version_0_3 = false;
		$version_0_4 = false;
		$version_0_5 = false;
		if ( ! $no_version ) {
			$version     = $settings['version'];
			$version_0_3 = version_compare( '0.3.0', $version ) === 1;
			$version_0_4 = version_compare( '0.4.0', $version ) === 1;
			$version_0_5 = version_compare( '0.5.0', $version ) === 1;
		}
		if ( $no_version || $version_0_3 || $version_0_4 ) {
			remove_action( 'update_option_criticalcss', array( $this, 'after_options_updated' ) );
			if ( isset( $settings['disable_autopurge'] ) ) {
				unset( $settings['disable_autopurge'] );
				$this->update_settings( $settings );
			}
			if ( isset( $settings['expire'] ) ) {
				unset( $settings['expire'] );
				$this->update_settings( $settings );
			}
		}
		if ( $no_version || $version_0_3 || $version_0_4 || $version_0_5 ) {
			$wpdb->get_results( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_criticalcss_%', '_transient_timeout_criticalcss_%' ) );
		}

		if ( is_multisite() ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'site__not_in' => array( 1 ) ) ) as $blog_id ) {
				switch_to_blog( $blog_id );
				$wpdb->query( "DROP TABLE {$wpdb->prefix}_wp_criticalcss_web_check_queue IF EXISTS" );
				$wpdb->query( "DROP TABLE {$wpdb->prefix}_wp_criticalcss_api_queue IF EXISTS" );
				restore_current_blog();
			}
		}

		$this->update_settings( array_merge( array(
			'web_check_interval' => DAY_IN_SECONDS,
			'template_cache'     => 'off',
		), $this->get_settings(), array( 'version' => self::VERSION ) ) );

		$this->init();
		$this->init_action();

		$this->_web_check_queue->create_table();
		$this->_api_queue->create_table();

		flush_rewrite_rules();
	}

	/**
	 * @return array
	 */
	public function get_settings() {
		$settings = array();
		if ( is_multisite() ) {
			$settings = get_site_option( self::OPTIONNAME, array() );
			if ( empty( $settings ) ) {
				$settings = get_option( self::OPTIONNAME, array() );
			}
		} else {
			$settings = get_option( self::OPTIONNAME, array() );
		}

		return $settings;
	}

	/**
	 * @param array $settings
	 *
	 * @return bool
	 */
	public function update_settings( array $settings ) {
		if ( is_multisite() ) {
			return update_site_option( self::OPTIONNAME, $settings );
		} else {
			return update_option( self::OPTIONNAME, $settings );
		}
	}

	/**
	 *
	 */
	public function init() {
		$this->_settings = $this->get_settings();
		if ( empty( $this->_settings_ui ) ) {
			$this->_settings_ui = new WP_CriticalCSS_Settings_API();
		}
		if ( empty( $this->_web_check_queue ) ) {
			$this->_web_check_queue = new WP_CriticalCSS_Web_Check_Background_Process();
		}
		if ( empty( $this->_api_queue ) ) {
			$this->_api_queue = new WP_CriticalCSS_API_Background_Process();
		}
		if ( ! is_admin() ) {
			add_action( 'wp_print_styles', array( $this, 'print_styles' ), 7 );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		add_action( 'network_admin_menu', array( $this, 'settings_init' ) );
		add_action( 'admin_menu', array( $this, 'settings_init' ) );
		add_action( 'pre_update_option_wp_criticalcss', array( $this, 'sync_options' ), 10, 2 );

		add_action( 'after_switch_theme', array( $this, 'reset_web_check_transients' ) );
		add_action( 'upgrader_process_complete', array( $this, 'reset_web_check_transients' ) );
		add_action( 'request', array( $this, 'update_request' ) );
		if ( ! empty( $this->_settings['template_cache'] ) && 'on' == $this->_settings['template_cache'] ) {
			add_action( 'template_include', array( $this, 'template_include' ), PHP_INT_MAX );
		} else {
			add_action( 'post_updated', array( $this, 'reset_web_check_post_transient' ) );
			add_action( 'edited_term', array( $this, 'reset_web_check_term_transient' ) );
		}
		if ( is_admin() ) {
			add_action( 'wp_loaded', array( $this, 'wp_action' ) );
		} else {
			add_action( 'wp', array( $this, 'wp_action' ) );
			add_action( 'wp_head', array( $this, 'wp_head' ) );
		}
		add_action( 'init', array( $this, 'init_action' ) );
		add_filter( 'rewrite_rules_array', array( $this, 'fix_rewrites' ), 11 );
		/*
		 * Prevent a 404 on homepage if a static page is set.
		 * Will store query_var outside \WP_Query temporarily so we don't need to do any extra routing logic and will appear as if it was not set.
		 */
		add_action( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'parse_request', array( $this, 'parse_request' ) );
		// Don't fix url or try to guess url if we are using nocache on the homepage
		add_filter( 'redirect_canonical', array( $this, 'redirect_canonical' ) );
	}

	/**
	 * @param array $settings
	 */
	public function set_settings( $settings ) {
		$this->_settings = $settings;
	}

	/**
	 *
	 */
	public function init_action() {
		add_rewrite_endpoint( 'nocache', E_ALL );
		add_rewrite_rule( 'nocache/?$', 'index.php?nocache=1', 'top' );
		$taxonomies = get_taxonomies( array(
			'public'   => true,
			'_builtin' => false,
		), 'objects' );

		foreach ( $taxonomies as $tax_id => $tax ) {
			if ( ! empty( $tax->rewrite ) ) {
				add_rewrite_rule( $tax->rewrite['slug'] . '/(.+?)/nocache/?$', 'index.php?' . $tax_id . '=$matches[1]&nocache', 'top' );
			}
		}
	}

	/**
	 *
	 */
	public function fix_rewrites( $rules ) {
		$nocache_rules = array(
			// Fix page archives
			'(.?.+?)/page(?:/([0-9]+))?/nocache/?' => 'index.php?pagename=$matches[1]&paged=$matches[2]&nocache',
		);
		// Fix all custom taxonomies
		$tokens = get_taxonomies( array(
			'public'   => true,
			'_builtin' => false,
		) );
		foreach ( $rules as $match => $query ) {
			if ( false !== strpos( $match, 'nocache' ) && preg_match( '/' . implode( '|', $tokens ) . '/', $query ) ) {
				$nocache_rules[ $match ] = $query;
				unset( $rules[ $match ] );
			}
		}
		$rules = array_merge( $nocache_rules, $rules );

		return $rules;
	}

	/**
	 *
	 */
	public function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * @param array $object
	 *
	 * @return false|mixed|string|\WP_Error
	 */
	public function get_permalink( array $object ) {
		$this->disable_relative_plugin_filters();
		if ( ! empty( $object['object_id'] ) ) {
			$object['object_id'] = absint( $object['object_id'] );
		}
		switch ( $object['type'] ) {
			case 'post':
				$url = get_permalink( $object['object_id'] );
				break;
			case 'term':
				$url = get_term_link( $object['object_id'] );
				break;
			case 'author':
				$url = get_author_posts_url( $object['object_id'] );
				break;
			case 'url':
				$url = $object['url'];
				break;
			default:
				$url = $object['url'];
		}
		$this->enable_relative_plugin_filters();

		if ( $url instanceof WP_Error ) {
			return false;
		}

		$url_parts         = parse_url( $url );
		$url_parts['path'] = trailingslashit( $url_parts['path'] ) . 'nocache/';
		if ( class_exists( 'http\Url' ) ) {
			/** @noinspection PhpUndefinedClassInspection */
			$url = new \http\Url( $url_parts );
			$url = $url->toString();
		} else {
			if ( ! function_exists( 'http_build_url' ) ) {
				require_once plugin_dir_path( __FILE__ ) . 'http_build_url.php';
			}
			$url = http_build_url( $url_parts );
		}

		return $url;
	}

	/**
	 *
	 */
	protected function disable_relative_plugin_filters() {
		if ( class_exists( 'MP_WP_Root_Relative_URLS' ) ) {
			remove_filter( 'post_link', array( 'MP_WP_Root_Relative_URLS', 'proper_root_relative_url' ), 1 );
			remove_filter( 'page_link', array( 'MP_WP_Root_Relative_URLS', 'proper_root_relative_url' ), 1 );
			remove_filter( 'attachment_link', array( 'MP_WP_Root_Relative_URLS', 'proper_root_relative_url' ), 1 );
			remove_filter( 'post_type_link', array( 'MP_WP_Root_Relative_URLS', 'proper_root_relative_url' ), 1 );
			remove_filter( 'get_the_author_url', array( 'MP_WP_Root_Relative_URLS', 'dynamic_rss_absolute_url' ), 1 );
		}
	}

	/**
	 *
	 */
	protected function enable_relative_plugin_filters() {
		if ( class_exists( 'MP_WP_Root_Relative_URLS' ) ) {
			add_filter( 'post_link', array( 'MP_WP_Root_Relative_URLS', 'proper_root_relative_url' ), 1 );
			add_filter( 'page_link', array( 'MP_WP_Root_Relative_URLS', 'proper_root_relative_url' ), 1 );
			add_filter( 'attachment_link', array( 'MP_WP_Root_Relative_URLS', 'proper_root_relative_url' ), 1 );
			add_filter( 'post_type_link', array( 'MP_WP_Root_Relative_URLS', 'proper_root_relative_url' ), 1 );
			add_filter( 'get_the_author_url', array( 'MP_WP_Root_Relative_URLS', 'dynamic_rss_absolute_url' ), 1, 2 );
		}
	}

	/**
	 * @param $type
	 * @param $object_id
	 * @param $url
	 */
	public function purge_page_cache( $type = null, $object_id = null, $url = null ) {
		global $wpe_varnish_servers;
		$url = preg_replace( '#nocache/$#', '', $url );
// WP Engine Support
		if ( class_exists( 'WPECommon' ) ) {
			if ( empty( $type ) ) {
				/** @noinspection PhpUndefinedClassInspection */
				WpeCommon::purge_varnish_cache();
			} else if ( 'post' == $type ) {
				/** @noinspection PhpUndefinedClassInspection */
				WpeCommon::purge_varnish_cache( $object_id );
			} else {
				$blog_url       = home_url();
				$blog_url_parts = @parse_url( $blog_url );
				$blog_domain    = $blog_url_parts['host'];
				$purge_domains  = array( $blog_domain );
				$object_parts   = parse_url( $url );
				$object_uri     = rtrim( $object_parts   ['path'], '/' ) . "(.*)";
				if ( ! empty( $object_parts['query'] ) ) {
					$object_uri .= "?" . $object_parts['query'];
				}
				$paths = array( $object_uri );
				/** @noinspection PhpUndefinedClassInspection */
				$purge_domains = array_unique( array_merge( $purge_domains, WpeCommon::get_blog_domains() ) );
				if ( defined( 'WPE_CLUSTER_TYPE' ) && WPE_CLUSTER_TYPE == "pod" ) {
					$wpe_varnish_servers = array( "localhost" );
				} // Ordinarily, the $wpe_varnish_servers are set during apply. Just in case, let's figure out a fallback plan.
				else if ( ! isset( $wpe_varnish_servers ) ) {
					if ( ! defined( 'WPE_CLUSTER_ID' ) || ! WPE_CLUSTER_ID ) {
						$lbmaster = "lbmaster";
					} else if ( WPE_CLUSTER_ID >= 4 ) {
						$lbmaster = "localhost"; // so the current user sees the purge
					} else {
						$lbmaster = "lbmaster-" . WPE_CLUSTER_ID;
					}
					$wpe_varnish_servers = array( $lbmaster );
				}
				$path_regex          = '(' . join( '|', $paths ) . ')';
				$hostname            = $purge_domains[0];
				$purge_domains       = array_map( 'preg_quote', $purge_domains );
				$purge_domain_chunks = array_chunk( $purge_domains, 100 );
				foreach ( $purge_domain_chunks as $chunk ) {
					$purge_domain_regex = '^(' . join( '|', $chunk ) . ')$';
					// Tell Varnish.
					foreach ( $wpe_varnish_servers as $varnish ) {
						$headers = array( 'X-Purge-Path' => $path_regex, 'X-Purge-Host' => $purge_domain_regex );
						/** @noinspection PhpUndefinedClassInspection */
						WpeCommon::http_request_async( 'PURGE', $varnish, 9002, $hostname, '/', $headers, 0 );
					}
				}
			}
			sleep( 1 );
		}
		// WP-Rocket Support
		if ( function_exists( 'rocket_clean_files' ) ) {

			if ( 'post' == $type ) {
				rocket_clean_post( $object_id );
			}
			if ( 'term' == $type ) {
				rocket_clean_term( $object_id, get_term( $object_id )->taxonomy );
			}
			if ( 'url' == $type ) {
				rocket_clean_files( $url );
			}
			if ( empty( $type ) ) {
				rocket_clean_domain();
			}

		}
	}

	/**
	 *
	 */
	public function print_styles() {
		if ( ! get_query_var( 'nocache' ) && ! is_404() ) {
			$cache        = $this->get_cache();
			$style_handle = null;
			if ( ! empty( $cache ) ) {
				// Enable CDN in CSS for WP-Rocket
				if ( function_exists( 'rocket_cdn_css_properties' ) ) {
					$cache = rocket_cdn_css_properties( $cache );
				}
				// Compatibility with WP Rocket ASYNC CSS preloader integration
				if ( class_exists( 'Rocket_Async_Css_The_Preloader' ) ) {
					remove_action( 'wp_enqueue_scripts', array(
						'Rocket_Async_Css_The_Preloader',
						'add_window_resize_js',
					) );
					remove_action( 'rocket_buffer', array( 'Rocket_Async_Css_The_Preloader', 'inject_div' ) );
				}
				?>
                <style type="text/css" id="criticalcss" data-no-minify="1"><?= $cache ?></style>
				<?php
			}
			$type  = $this->get_current_page_type();
			$hash  = $this->get_item_hash( $type );
			$check = $this->get_cache_fragment( array( $hash ) );
			if ( 'on' == $this->_settings['template_cache'] && ! empty( $type['template'] ) ) {
				if ( empty( $cache ) && ! $this->_api_queue->get_item_exists( $type ) ) {
					$this->_api_queue->push_to_queue( $type )->save();
				}
			} else {
				if ( empty( $check ) && ! $this->_web_check_queue->get_item_exists( $type ) ) {
					$this->_web_check_queue->push_to_queue( $type )->save();
					$this->update_cache_fragment( array( $hash ), true );
				}
			}
		}
	}

	/**
	 * @param array $item
	 *
	 * @return string
	 */
	public function get_cache( $item = array() ) {
		return $this->get_item_data( $item, 'cache' );
	}

	/**
	 * @param array $item
	 * @param       $name
	 *
	 * @return mixed|null
	 */
	public function get_item_data( $item = array(), $name ) {
		$value = null;
		if ( empty( $item ) ) {
			$item = $this->get_current_page_type();
		}
		if ( 'on' == $this->_settings['template_cache'] && ! empty( $item['template'] ) ) {
			$name  = "criticalcss_{$name}_" . md5( $item['template'] );
			$value = get_transient( $name );
		} else {
			if ( 'url' == $item['type'] ) {
				$name  = "criticalcss_url_{$name}_" . md5( $item['url'] );
				$value = get_transient( $name );
			} else {
				$name = "criticalcss_{$name}";
				switch ( $item['type'] ) {
					case 'post':
						$value = get_post_meta( $item['object_id'], $name, true );
						break;
					case 'term':
						$value = get_term_meta( $item['object_id'], $name, true );
						break;
					case 'author':
						$value = get_user_meta( $item['object_id'], $name, true );
						break;

				}
			}
		}

		return $value;
	}

	/**
	 * @return array
	 */
	protected function get_current_page_type() {
		global $wp;
		$object_id = 0;
		if ( is_home() ) {
			$page_for_posts = get_option( 'page_for_posts' );
			if ( ! empty( $page_for_posts ) ) {
				$object_id = $page_for_posts;
				$type      = 'post';
			}
		} else if ( is_front_page() ) {
			$page_on_front = get_option( 'page_on_front' );
			if ( ! empty( $page_on_front ) ) {
				$object_id = $page_on_front;
				$type      = 'post';
			}
		} else if ( is_singular() ) {
			$object_id = get_the_ID();
			$type      = 'post';
		} else if ( is_tax() || is_category() || is_tag() ) {
			$object_id = get_queried_object()->term_id;
			$type      = 'term';
		} else if ( is_author() ) {
			$object_id = get_the_author_meta( 'ID' );
			$type      = 'author';

		}

		if ( ! isset( $type ) ) {
			$this->disable_relative_plugin_filters();
			$url = site_url( $wp->request );
			$this->enable_relative_plugin_filters();

			$type = 'url';
		}
		$object_id = absint( $object_id );

		if ( 'on' == $this->_settings['template_cache'] ) {
			$template = $this->_template;
		}

		if ( is_multisite() ) {
			$blog_id = get_current_blog_id();
		}

		return compact( 'object_id', 'type', 'url', 'template', 'blog_id' );
	}

	public function get_item_hash( $item ) {
		extract( $item );
		$parts = array( 'object_id', 'type', 'url' );
		if ( 'on' == $this->_settings['template_cache'] ) {
			$template = $this->_template;
			$parts    = array( 'template' );
		}
		$type = compact( $parts );

		return md5( serialize( $type ) );
	}

	protected function get_cache_fragment( $path ) {
		if ( ! in_array( 'cache', $path ) ) {
			array_unshift( $path, 'cache' );
		}

		return $this->get_transient( self::TRANSIENT_PREFIX . implode( '_', $path ) );
	}

	protected function get_transient() {
		if ( is_multisite() ) {
			return call_user_func_array( 'get_site_transient', func_get_args() );
		} else {
			return call_user_func_array( 'get_transient', func_get_args() );
		}
	}

	protected function update_cache_fragment( $path, $value ) {
		if ( ! in_array( 'cache', $path ) ) {
			array_unshift( $path, 'cache' );
		}
		$this->build_cache_tree( array_slice( $path, 0, count( $path ) - 1 ) );
		$this->update_tree_branch( $path, $value );
	}

	protected function build_cache_tree( $path ) {
		$levels = count( $path );
		$expire = $this->get_expire_period();
		for ( $i = 0; $i < $levels; $i ++ ) {
			$transient_id       = self::TRANSIENT_PREFIX . implode( '_', array_slice( $path, 0, $i + 1 ) );
			$transient_cache_id = $transient_id;
			if ( 'cache' != $path[ $i ] ) {
				$transient_cache_id .= '_cache';
			}
			$transient_cache_id .= '_1';
			$cache              = $this->get_transient( $transient_cache_id );
			$transient_value    = array();
			if ( $i + 1 < $levels ) {
				$transient_value[] = self::TRANSIENT_PREFIX . implode( '_', array_slice( $path, 0, $i + 2 ) );
			}
			if ( ! is_null( $cache ) && false !== $cache ) {
				$transient_value = array_unique( array_merge( $cache, $transient_value ) );
			}
			$this->set_transient( $transient_cache_id, $transient_value, $expire );
			$transient_counter_id = $transient_id;
			if ( 'cache' != $path[ $i ] ) {
				$transient_counter_id .= '_cache';
			}
			$transient_counter_id .= '_count';
			$transient_counter    = $this->get_transient( $transient_counter_id );
			if ( is_null( $transient_counter ) || false === $transient_counter ) {
				$this->set_transient( $transient_counter_id, 1, $expire );
			}
		}
	}

	/**
	 * @return int
	 */
	public function get_expire_period() {
// WP-Rocket integration
		if ( function_exists( 'get_rocket_purge_cron_interval' ) ) {
			return get_rocket_purge_cron_interval();
		}
		$settings = $this->get_settings();

		return absint( $this->_settings['web_check_interval'] );
	}

	protected function set_transient() {
		if ( is_multisite() ) {
			call_user_func_array( 'set_site_transient', func_get_args() );
		} else {
			call_user_func_array( 'set_transient', func_get_args() );
		}
	}

	protected function update_tree_branch( $path, $value ) {
		$branch            = self::TRANSIENT_PREFIX . implode( '_', $path );
		$parent_path       = array_slice( $path, 0, count( $path ) - 1 );
		$parent            = self::TRANSIENT_PREFIX . implode( '_', $parent_path );
		$counter_transient = $parent;
		$cache_transient   = $parent;
		if ( 'cache' != end( $parent_path ) ) {
			$counter_transient .= '_cache';
			$cache_transient   .= '_cache';
		}
		$counter_transient .= '_count';
		$counter           = (int) $this->get_transient( $counter_transient );
		$cache_transient   .= "_{$counter}";
		$cache             = $this->get_transient( $cache_transient );
		$count             = count( $cache );
		$cache_keys        = array_flip( $cache );
		$expire            = $this->get_expire_period();
		if ( ! isset( $cache_keys[ $branch ] ) ) {
			if ( $count >= apply_filters( 'rocket_async_css_max_branch_length', 50 ) ) {
				$counter ++;
				$this->set_transient( $counter_transient, $counter, $expire );
				$cache_transient = $parent;
				if ( 'cache' != end( $parent_path ) ) {
					$cache_transient .= '_cache';
				}
				$cache_transient .= "_{$counter}";
				$cache           = array();
			}
			$cache[] = $branch;
			$this->set_transient( $cache_transient, $cache, $expire );
		}
		$this->set_transient( $branch, $value, $expire );
	}

	/**
	 * @param array $item
	 *
	 * @return string
	 */
	public function get_html_hash( $item = array() ) {
		return $this->get_item_data( $item, 'html_hash' );
	}

	/**
	 * @param        $item
	 * @param string $css
	 *
	 * @return void
	 * @internal param array $type
	 */
	public function set_cache( $item, $css ) {
		$this->set_item_data( $item, 'cache', $css );
	}

	/**
	 * @param     $item
	 * @param     $name
	 * @param     $value
	 * @param int $expires
	 */
	public function set_item_data( $item, $name, $value, $expires = 0 ) {
		if ( 'on' == $this->_settings['template_cache'] && ! empty( $item['template'] ) ) {
			$name = "criticalcss_{$name}_" . md5( $item['template'] );
			set_transient( $name, $value, $expires );
		} else {
			if ( 'url' == $item['type'] ) {
				$name = "criticalcss_url_{$name}_" . md5( $item['url'] );
				set_transient( $name, $value, $expires );
			} else {
				$name  = "criticalcss_{$name}";
				$value = wp_slash( $value );
				switch ( $item['type'] ) {
					case 'post':
						update_post_meta( $item['object_id'], $name, $value );
						break;
					case 'term':
						update_term_meta( $item['object_id'], $name, $value );
						break;
					case 'author':
						update_user_meta( $item['object_id'], $name, $value );
						break;
				}
			}
		}

	}

	/**
	 * @param array $item
	 *
	 * @return string
	 */
	public function get_css_hash( $item = array() ) {
		return $this->get_item_data( $item, 'css_hash' );
	}

	/**
	 * @param        $item
	 * @param string $hash
	 *
	 * @return void
	 * @internal param array $type
	 */
	public function set_css_hash( $item, $hash ) {
		$this->set_item_data( $item, 'css_hash', $hash );
	}

	/**
	 * @param        $item
	 * @param string $hash
	 *
	 * @return void
	 * @internal param array $type
	 */
	public function set_html_hash( $item, $hash ) {
		$this->set_item_data( $item, 'html_hash', $hash );
	}

	/**
	 *
	 */
	public function settings_init() {
		if ( is_multisite() ) {
			$hook = add_submenu_page( 'settings.php', 'WP Critical CSS', 'WP Critical CSS', 'manage_network_options', 'wp_criticalcss', array(
				$this,
				'settings_ui',
			) );
		} else {
			$hook = add_options_page( 'WP Critical CSS', 'WP Critical CSS', 'manage_options', 'wp_criticalcss', array(
				$this,
				'settings_ui',
			) );
		}
		add_action( "load-$hook", array( $this, 'screen_option' ) );
		$this->_settings_ui->add_section( array( 'id' => self::OPTIONNAME, 'title' => 'WP Critical CSS Options' ) );
		$this->_settings_ui->add_field( self::OPTIONNAME, array(
			'name'              => 'apikey',
			'label'             => 'API Key',
			'type'              => 'text',
			'sanitize_callback' => array( $this, 'validate_criticalcss_apikey' ),
			'desc'              => __( 'API Key for CriticalCSS.com. Please view yours at <a href="https://www.criticalcss.com/account/api-keys?aff=3">CriticalCSS.com</a>', self::LANG_DOMAIN ),
		) );
		$this->_settings_ui->add_field( self::OPTIONNAME, array(
			'name'  => 'force_web_check',
			'label' => 'Force Web Check',
			'type'  => 'checkbox',
			'desc'  => __( 'Force a web check on all pages for css changes. This will run for new web requests.', self::LANG_DOMAIN ),
		) );
		$this->_settings_ui->add_field( self::OPTIONNAME, array(
			'name'  => 'template_cache',
			'label' => 'Template Cache',
			'type'  => 'checkbox',
			'desc'  => __( 'Cache Critical CSS based on WordPress templates and not the post, page, term, author page, or arbitrary url.', self::LANG_DOMAIN ),
		) );
		if ( ! $this->has_external_integration() ) {
			$this->_settings_ui->add_field( self::OPTIONNAME, array(
				'name'  => 'web_check_interval',
				'label' => 'Web Check Interval',
				'type'  => 'number',
				'desc'  => __( 'How often in seconds web pages should be checked for changes to re-generate CSS', self::LANG_DOMAIN ),
			) );
		}
		$this->_settings_ui->admin_init();
	}

	/**
	 *
	 */
	public function settings_ui() {
		require ABSPATH . 'wp-admin/options-head.php';
		$this->_settings_ui->add_section( array(
			'id'    => 'wp_criticalcss_queue',
			'title' => 'WP Critical CSS Queue',
			'form'  => false,
		) );

		ob_start();

		?>
        <style type="text/css">
            .queue > th {
                display: none;
            }
        </style>
        <form method="post">
			<?php
			$this->_queue_table->prepare_items();
			$this->_queue_table->display();
			?>
        </form>
		<?php
		$this->_settings_ui->add_field( 'wp_criticalcss_queue', array(
			'name'  => 'queue',
			'label' => null,
			'type'  => 'html',
			'desc'  => ob_get_clean(),
		) );

		$this->_settings_ui->admin_init();
		$this->_settings_ui->show_navigation();
		$this->_settings_ui->show_forms();
		?>

		<?php
	}

	/**
	 * @param $options
	 *
	 * @return bool
	 */
	public function validate_criticalcss_apikey( $options ) {
		$valid = true;
		if ( empty( $options['apikey'] ) ) {
			$valid = false;
			add_settings_error( 'apikey', 'invalid_apikey', __( 'API Key is empty', self::LANG_DOMAIN ) );
		}
		if ( ! $valid ) {
			return $valid;
		}
		$api = new WP_CriticalCSS_API( $options['apikey'] );
		if ( ! $api->ping() ) {
			add_settings_error( 'apikey', 'invalid_apikey', 'CriticalCSS.com API Key is invalid' );
			$valid = false;
		}

		return ! $valid ? $valid : $options['apikey'];
	}

	/**
	 * @param $value
	 * @param $old_value
	 *
	 * @return array
	 */
	public function sync_options( $value, $old_value ) {
		$original_old_value = $old_value;
		if ( ! is_array( $old_value ) ) {
			$old_value = array();
		}

		$value = array_merge( $old_value, $value );

		if ( isset( $value['force_web_check'] ) && 'on' == $value['force_web_check'] ) {
			$value['force_web_check'] = 'off';
			$this->reset_web_check_transients();
		}

		if ( is_multisite() ) {
			update_site_option( self::OPTIONNAME, $value );
			$value = $original_old_value;
		}

		return $value;
	}

	/**
	 *
	 */
	public function reset_web_check_transients() {
		$this->delete_cache_branch();
	}

	protected function delete_cache_branch( $path = array() ) {
		if ( is_array( $path ) ) {
			if ( ! empty( $path ) ) {
				$path = self::TRANSIENT_PREFIX . implode( '_', $path ) . '_';
			} else {
				$path = self::TRANSIENT_PREFIX;
			}
		}
		$counter_transient = "{$path}cache_count";
		$counter           = get_transient( $counter_transient );

		if ( is_null( $counter ) || false === $counter ) {
			$this->delete_transient( rtrim( $path, '_' ) );

			return;
		}
		for ( $i = 1; $i <= $counter; $i ++ ) {
			$transient_name = "{$path}cache_{$i}";
			$cache          = get_transient( "{$path}cache_{$i}" );
			if ( ! empty( $cache ) ) {
				foreach ( $cache as $sub_branch ) {
					$this->delete_cache_branch( "{$sub_branch}_" );
				}
				$this->delete_transient( $transient_name );
			}
		}
		$this->delete_transient( $counter_transient );
	}

	protected function delete_transient() {
		if ( is_multisite() ) {
			return call_user_func_array( 'delete_site_transient', func_get_args() );
		} else {
			return call_user_func_array( 'delete_transient', func_get_args() );
		}
	}

	public function reset_web_check_post_transient( $post ) {
		$post = get_post( $post );
		$hash = $this->get_item_hash( array( 'object_id' => $post->ID, 'type' => 'post' ) );
		$this->delete_cache_branch( array( $hash ) );
	}

	/**
	 * @param $term
	 *
	 * @internal param \WP_Term $post
	 */
	public function reset_web_check_term_transient( $term ) {
		$term = get_term( $term );
		$hash = $this->get_item_hash( array( 'object_id' => $term->term_id, 'type' => 'term' ) );
		$this->delete_cache_branch( array( $hash ) );
	}

	/**
	 * @internal param \WP_Term $post
	 */
	public function reset_web_check_home_transient() {
		$page_for_posts = get_option( 'page_for_posts' );
		if ( ! empty( $page_for_posts ) ) {
			$post_id = $page_for_posts;
		}
		if ( empty( $post_id ) || ( ! empty( $post_id ) && get_permalink( $post_id ) != site_url() ) ) {
			$page_on_front = get_option( 'page_on_front' );
			if ( ! empty( $page_on_front ) ) {
				$post_id = $page_on_front;
			} else {
				$post_id = false;
			}
		}
		if ( ! empty( $post_id ) && get_permalink( $post_id ) == site_url() ) {
			$hash = $this->get_item_hash( array( 'object_id' => $post_id, 'type' => 'post' ) );
		} else {
			$hash = $this->get_item_hash( array( 'type' => 'url', 'url' => site_url() ) );
		}
		$this->delete_cache_branch( array( $hash ) );
	}

	/**
	 *
	 */
	public function screen_option() {
		add_screen_option( 'per_page', array(
			'label'   => 'Queue Items',
			'default' => 20,
			'option'  => 'queue_items_per_page',
		) );
		$this->_queue_table = new WP_CriticalCSS_Queue_List_Table( $this->_api_queue );
	}

	public function template_include( $template ) {
		$this->_template = str_replace( trailingslashit( WP_CONTENT_DIR ), '', $template );

		return $template;
	}

	/**
	 * @return \WP_CriticalCSS_API_Background_Process
	 */
	public function get_api_queue() {
		return $this->_api_queue;
	}
}