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

	/**
	 *
	 */
	const TRANSIENT_PREFIX = 'wp_criticalcss_web_check_';
	/**
	 * @var
	 */
	protected static $instance;
	/**
	 * @var array
	 */
	protected static $integrations = array(
		'WP_CriticalCSS_Integration_Rocket_Async_CSS',
		'WP_CriticalCSS_Integration_Root_Relative_URLS',
		'WP_CriticalCSS_Integration_WP_Rocket',
		'WP_CriticalCSS_Integration_WPEngine',
	);
	/**
	 * @var bool
	 */
	protected $nocache = false;
	/**
	 * @var WP_CriticalCSS_Web_Check_Background_Process
	 */
	protected $web_check_queue;
	/**
	 * @var WP_CriticalCSS_API_Background_Process
	 */
	protected $api_queue;
	/**
	 * @var array
	 */
	protected $settings = array();
	/**
	 * @var string
	 */
	protected $template;

	/**
	 * @var \WP_CriticalCSS_Admin_UI
	 */
	protected $admin_ui;

	/**
	 * @var \WP_CriticalCSS_Data_Manager
	 */
	protected $data_manager;

	/**
	 * @var \WP_CriticalCSS_Cache_Manager
	 */
	protected $cache_manager;

	/**
	 * @return \WP_CriticalCSS
	 */
	public static function get_instance() {
		if ( empty( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * @return mixed
	 */
	public function get_data_manager() {
		return $this->data_manager;
	}

	/**
	 * @return mixed
	 */
	public function get_cache_manager() {
		return $this->cache_manager;
	}

	/**
	 * @return array
	 */
	public function get_integrations() {
		return self::$integrations;
	}

	/**
	 * @return bool
	 */
	public function is_no_cache() {
		return $this->nocache;
	}

	/**
	 *
	 */
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
		$this->enable_integrations();
	}

	/**
	 *
	 */
	public function enable_integrations() {
		do_action( 'wp_criticalcss_enable_integrations' );

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
		$this->add_rewrite_rules();

		$this->web_check_queue->create_table();
		$this->api_queue->create_table();

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
	 */
	public function set_settings( $settings ) {
		$this->settings = $settings;
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
		$this->settings = $this->get_settings();
		if ( empty( $this->admin_ui ) ) {
			$this->admin_ui = new WP_CriticalCSS_Admin_UI();
		}
		if ( empty( $this->web_check_queue ) ) {
			$this->web_check_queue = new WP_CriticalCSS_Web_Check_Background_Process();
		}
		if ( empty( $this->api_queue ) ) {
			$this->api_queue = new WP_CriticalCSS_API_Background_Process();
		}
		if ( empty( $this->data_manager ) ) {
			$this->data_manager = new WP_CriticalCSS_Data_Manager();
		}
		if ( empty( $this->cache_manager ) ) {
			$this->cache_manager = new WP_CriticalCSS_Cache_Manager();
		}
		$integrations = array();
		foreach ( self::$integrations as $integration ) {
			$integrations[ $integration ] = new $integration();
		}
		self::$integrations = $integrations;

		if ( ! is_admin() ) {
			add_action( 'wp_print_styles', array( $this, 'print_styles' ), 7 );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		add_action( 'after_switch_theme', array( $this, 'reset_web_check_transients' ) );
		add_action( 'upgrader_process_complete', array( $this, 'reset_web_check_transients' ) );
		add_action( 'request', array( $this, 'update_request' ) );
		if ( ! empty( $this->settings['template_cache'] ) && 'on' == $this->settings['template_cache'] ) {
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
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
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
	 *
	 */
	public function add_rewrite_rules() {
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
		$this->disable_integrations();
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
		$this->enable_integrations();

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
	public function disable_integrations() {
		do_action( 'wp_criticalcss_disable_integrations' );
	}

	/**
	 * @param $type
	 * @param $object_id
	 * @param $url
	 */
	public function purge_page_cache( $type = null, $object_id = null, $url = null ) {
		$url = preg_replace( '#nocache/$#', '', $url );

		do_action( 'wp_criticalcss_purge_cache', $type, $object_id, $url );
	}

	/**
	 *
	 */
	public function print_styles() {
		if ( ! get_query_var( 'nocache' ) && ! is_404() ) {
			$cache        = $this->data_manager->get_cache();
			$style_handle = null;

			$cache = apply_filters( 'wp_criticalcss_print_styles_cache', $cache );

			do_action( 'wp_criticalcss_before_print_styles', $cache );

			if ( ! empty( $cache ) ) {
				?>
                <style type="text/css" id="criticalcss" data-no-minify="1"><?= $cache ?></style>
				<?php
			}
			$type  = $this->get_current_page_type();
			$hash  = $this->get_item_hash( $type );
			$check = $this->cache_manager->get_cache_fragment( array( $hash ) );
			if ( 'on' == $this->settings['template_cache'] && ! empty( $type['template'] ) ) {
				if ( empty( $cache ) && ! $this->api_queue->get_item_exists( $type ) ) {
					$this->api_queue->push_to_queue( $type )->save();
				}
			} else {
				if ( empty( $check ) && ! $this->web_check_queue->get_item_exists( $type ) ) {
					$this->web_check_queue->push_to_queue( $type )->save();
					$this->cache_manager->update_cache_fragment( array( $hash ), true );
				}
			}

			do_action( 'wp_criticalcss_after_print_styles' );
		}
	}


	/**
	 * @return array
	 */
	public function get_current_page_type() {
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
			$this->disable_integrations();
			$url = site_url( $wp->request );
			$this->enable_integrations();

			$type = 'url';
		}
		$object_id = absint( $object_id );

		if ( 'on' == $this->settings['template_cache'] ) {
			$template = $this->template;
		}

		if ( is_multisite() ) {
			$blog_id = get_current_blog_id();
		}

		return compact( 'object_id', 'type', 'url', 'template', 'blog_id' );
	}

	/**
	 * @param $item
	 *
	 * @return string
	 */
	public function get_item_hash( $item ) {
		extract( $item );
		$parts = array( 'object_id', 'type', 'url' );
		if ( 'on' == $this->settings['template_cache'] ) {
			$template = $this->template;
			$parts    = array( 'template' );
		}
		$type = compact( $parts );

		return md5( serialize( $type ) );
	}




	/**
	 *
	 */
	public function reset_web_check_transients() {
		$this->cache_manager->delete_cache_branch();
	}

	/**
	 * @param array $path
	 */


	/**
	 * @param $post
	 */
	public function reset_web_check_post_transient( $post ) {
		$post = get_post( $post );
		$hash = $this->get_item_hash( array( 'object_id' => $post->ID, 'type' => 'post' ) );
		$this->cache_manager->delete_cache_branch( array( $hash ) );
	}

	/**
	 * @param $term
	 *
	 * @internal param \WP_Term $post
	 */
	public function reset_web_check_term_transient( $term ) {
		$term = get_term( $term );
		$hash = $this->get_item_hash( array( 'object_id' => $term->term_id, 'type' => 'term' ) );
		$this->cache_manager->delete_cache_branch( array( $hash ) );
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
		$this->cache_manager->delete_cache_branch( array( $hash ) );
	}

	/**
	 *
	 */


	/**
	 * @param $template
	 *
	 * @return mixed
	 */
	public function template_include( $template ) {
		$this->template = str_replace( trailingslashit( WP_CONTENT_DIR ), '', $template );

		return $template;
	}

	/**
	 * @return \WP_CriticalCSS_API_Background_Process
	 */
	public function get_api_queue() {
		return $this->api_queue;
	}
}