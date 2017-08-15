<?php


namespace WP\CriticalCSS\Queue\Web\Check;


use WP\CriticalCSS\Queue\ListTableAbstract;

class Table extends ListTableAbstract {
	const TABLE_NAME = 'web_check';
	const STATUS_PROCESSING = 'processing';
	const STATUS_PENDING = 'pending';

	public function __construct( array $args = [] ) {
		parent::__construct( [
			'singular' => __( 'Web Check Queue Item', 'criticalcss' ),
			'plural'   => __( 'Web Check Queue Queue Items', 'criticalcss' ),
			'ajax'     => false,
		] );
	}

	/**
	 * @return array
	 */
	public function get_columns() {
		$columns = [
			'url'    => __( 'URL', wp_criticalcss()->get_lang_domain() ),
			'status' => __( 'Status', wp_criticalcss()->get_lang_domain() ),
		];
		if ( is_multisite() ) {
			$columns = array_merge( [
				'blog_id' => __( 'Blog', wp_criticalcss()->get_lang_domain() ),
			], $columns );
		}

		return $columns;
	}

	protected function do_prepare_items() {
		$wpdb        = wp_criticalcss()->wpdb;
		$table       = $this->get_table_name();
		$this->items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY LOCATE('status', {$table}.data) DESC LIMIT %d,%d", $this->start, $this->per_page ), ARRAY_A );
	}

	protected function process_purge_action() {
		parent::process_purge_action();
		wp_criticalcss()->get_cache_manager()->reset_web_check_transients();
	}

	/**
	 * @param array $item
	 *
	 * @return string
	 */
	protected function column_blog_id( array $item ) {
		if ( empty( $item['blog_id'] ) ) {
			return __( 'N/A', wp_criticalcss()->get_lang_domain() );
		}

		$details = get_blog_details( [
			'blog_id' => $item['blog_id'],
		] );

		if ( empty( $details ) ) {
			return __( 'Blog Deleted', wp_criticalcss()->get_lang_domain() );
		}

		return $details->blogname;
	}

	/**
	 * @param array $item
	 *
	 * @return string
	 */
	protected function column_url( array $item ) {
		return wp_criticalcss()->get_permalink( $item );
	}

	/**
	 * @param array $item
	 *
	 * @return string
	 */
	protected function column_status( array $item ) {
		$data = maybe_unserialize( $item['data'] );
		if ( ! empty( $data ) && ! empty( $data['status'] ) ) {
			if ( empty( $data['status'] ) ) {
				return __( 'Pending', wp_criticalcss()->get_lang_domain() );
			}
			switch ( $data['status'] ) {
				case self::STATUS_PROCESSING:
					return __( 'Processing', wp_criticalcss()->get_lang_domain() );
					break;
				default:
					return __( 'Pending', wp_criticalcss()->get_lang_domain() );
			}
		} else {
			return __( 'Pending', wp_criticalcss()->get_lang_domain() );
		}
	}
}