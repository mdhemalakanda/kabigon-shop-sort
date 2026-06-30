<?php
/**
 * Plugin Name: Kabigon Shop Sort
 * Description: Auto sort dropdown for WooCommerce shop, category, and product archives (Astra + Elementor Loop Grid). Optional shortcode: [kabigon_shop_sort]
 * Version: 1.3.6
 * Author: Kabigon Shop
 * Requires Plugins: woocommerce
 * Text Domain: kabigon-shop-sort
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Kabigon_Shop_Sort {

	const VERSION = '1.3.6';

	const POSTS_CLAUSES_PRIORITY = PHP_INT_MAX;

	private static ?self $instance = null;

	/** @var bool */
	private static $toolbar_rendered = false;

	/** @var bool */
	private static $posts_clauses_registered = false;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_shortcode' ) );
		add_action( 'init', array( $this, 'maybe_register_posts_clauses_early' ), 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );

		add_action( 'woocommerce_before_shop_loop', array( $this, 'maybe_render_toolbar' ), 15 );
		add_action( 'elementor/frontend/widget/before_render', array( $this, 'maybe_render_before_elementor_loop' ), 10, 1 );
		add_filter( 'elementor/query/query_args', array( $this, 'apply_url_orderby_to_elementor_query' ), PHP_INT_MAX, 2 );
		add_filter( 'query_loop_block_query_vars', array( $this, 'apply_url_orderby_to_block_query' ), PHP_INT_MAX, 3 );
		add_action( 'pre_get_posts', array( $this, 'apply_orderby_to_product_queries' ), PHP_INT_MAX );
		add_action( 'woocommerce_product_query', array( $this, 'apply_to_woocommerce_product_query' ), PHP_INT_MAX, 2 );
		add_action( 'astra_primary_content_top', array( $this, 'maybe_render_toolbar' ), 12 );
		add_action( 'wp', array( $this, 'maybe_register_posts_clauses_on_request' ), 1 );
		add_action( 'elementor/loaded', array( $this, 'register_elementor_query_hooks' ) );
	}

	/**
	 * @param array<int,string> $vars
	 * @return array<int,string>
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = 'kss_catalog_orderby';
		$vars[] = 'kss_sql_sorted';

		return $vars;
	}

	public function maybe_register_posts_clauses_early(): void {
		if ( is_admin() || ! $this->url_has_orderby() ) {
			return;
		}

		if ( $this->uses_posts_clauses( $this->get_current_orderby() ) ) {
			$this->ensure_posts_clauses_handler();
		}
	}

	public function maybe_register_posts_clauses_on_request(): void {
		$this->maybe_register_posts_clauses_early();
	}

	public function register_elementor_query_hooks(): void {
		foreach ( $this->get_elementor_query_ids() as $query_id ) {
			add_action( "elementor/query/{$query_id}", array( $this, 'apply_orderby_to_elementor_wp_query' ), PHP_INT_MAX );
		}
	}

	/**
	 * @return list<string>
	 */
	private function get_elementor_query_ids(): array {
		return array(
			'current_query',
			'current',
			'archive',
			'archive_posts',
			'products',
			'main_query',
			'loop',
			'woocommerce',
		);
	}

	/**
	 * Elementor Loop Grid "Current Query" uses elementor/query/{id} with a WP_Query instance.
	 *
	 * @param WP_Query $query
	 */
	public function apply_orderby_to_elementor_wp_query( $query ): void {
		if ( is_admin() || ! $this->url_has_orderby() || ! $query instanceof WP_Query ) {
			return;
		}

		if ( ! $this->is_active_context() || ! $this->is_product_query( $query ) || ! $this->should_apply_sort_to_query( $query ) ) {
			return;
		}

		$query->set( 'suppress_filters', false );
		$this->apply_orderby_to_query( $query, $this->get_current_orderby() );
	}

	public function is_active_context(): bool {
		if ( $this->is_sortable_view() ) {
			return true;
		}

		$shop_page_id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'shop' ) : 0;
		if ( $shop_page_id > 0 && is_page( $shop_page_id ) ) {
			return true;
		}

		/** @param bool $show */
		return (bool) apply_filters( 'kabigon_shop_sort_show_on_page', is_page( array( 'shop-2', 'shop', 'catalog' ) ) );
	}

	public function is_sortable_view(): bool {
		if ( is_admin() ) {
			return false;
		}

		return is_shop()
			|| is_product_taxonomy()
			|| is_post_type_archive( 'product' )
			|| ( is_search() && 'product' === get_query_var( 'post_type' ) );
	}

	private function url_has_orderby(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['orderby'] ) && '' !== wc_clean( wp_unslash( $_GET['orderby'] ) );
	}

	/**
	 * @return array<string, string>
	 */
	public function get_sort_options(): array {
		$options = array(
			'menu_order' => __( 'Default', 'kabigon-shop-sort' ),
			'popularity' => __( 'Best selling', 'kabigon-shop-sort' ),
			'date'       => __( 'Newest', 'kabigon-shop-sort' ),
			'price'      => __( 'Price: low to high', 'kabigon-shop-sort' ),
			'price-desc' => __( 'Price: high to low', 'kabigon-shop-sort' ),
		);

		if ( wc_review_ratings_enabled() ) {
			$options['rating'] = __( 'Top rated', 'kabigon-shop-sort' );
		}

		if ( is_search() ) {
			$options = array( 'relevance' => __( 'Relevance', 'kabigon-shop-sort' ) ) + $options;
			unset( $options['menu_order'] );
		}

		return apply_filters( 'kabigon_shop_sort_options', $options );
	}

	public function get_current_orderby(): string {
		$options = $this->get_sort_options();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested = isset( $_GET['orderby'] ) ? wc_clean( wp_unslash( $_GET['orderby'] ) ) : '';

		if ( $requested && array_key_exists( $requested, $options ) ) {
			return $requested;
		}

		$default = apply_filters( 'woocommerce_default_catalog_orderby', get_option( 'woocommerce_default_catalog_orderby', 'menu_order' ) );

		if ( array_key_exists( $default, $options ) ) {
			return $default;
		}

		return (string) array_key_first( $options );
	}

	private function uses_posts_clauses( string $orderby ): bool {
		return in_array( $orderby, array( 'price', 'price-desc', 'popularity', 'rating' ), true );
	}

	/**
	 * @return array{orderby:string,order:string,meta_key:string}
	 */
	private function get_ordering_args( string $orderby ): array {
		if ( ! function_exists( 'WC' ) || ! WC()->query ) {
			return array(
				'orderby'  => 'menu_order',
				'order'    => 'ASC',
				'meta_key' => '',
			);
		}

		WC()->query->remove_ordering_args();

		$ordering = WC()->query->get_catalog_ordering_args( $orderby, '' );

		WC()->query->remove_ordering_args();

		return $ordering;
	}

	private function ensure_posts_clauses_handler(): void {
		if ( self::$posts_clauses_registered ) {
			return;
		}

		self::$posts_clauses_registered = true;

		if ( WC()->query ) {
			WC()->query->remove_ordering_args();
		}

		remove_filter( 'posts_clauses', array( $this, 'filter_posts_clauses' ), self::POSTS_CLAUSES_PRIORITY );
		add_filter( 'posts_clauses', array( $this, 'filter_posts_clauses' ), self::POSTS_CLAUSES_PRIORITY, 2 );
	}

	/**
	 * WooCommerce price / sales / rating sorts need wc_product_meta_lookup joins.
	 * Runs late so Elementor Loop Grid cannot overwrite with widget default order.
	 *
	 * @param array<string,mixed> $clauses
	 * @param \WP_Query           $query
	 * @return array<string,mixed>
	 */
	private function attach_catalog_ordering( string $orderby ): void {
		$this->ensure_posts_clauses_handler();
	}

	public function filter_posts_clauses( array $clauses, $query ): array {
		if ( ! $query instanceof WP_Query || ! $this->should_filter_posts_clauses( $query ) ) {
			return $clauses;
		}

		$orderby = (string) $query->get( 'kss_catalog_orderby' );
		if ( '' === $orderby ) {
			$orderby = $this->get_current_orderby();
		}

		if ( ! $this->uses_posts_clauses( $orderby ) || ! WC()->query ) {
			return $clauses;
		}

		if ( WC()->query ) {
			WC()->query->remove_ordering_args();
		}

		switch ( $orderby ) {
			case 'price':
				$clauses = WC()->query->order_by_price_asc_post_clauses( $clauses );
				break;
			case 'price-desc':
				$clauses = WC()->query->order_by_price_desc_post_clauses( $clauses );
				break;
			case 'popularity':
				$clauses = WC()->query->order_by_popularity_post_clauses( $clauses );
				break;
			case 'rating':
				$clauses = WC()->query->order_by_rating_post_clauses( $clauses );
				break;
		}

		$query->set( 'kss_sql_sorted', 1 );

		return $clauses;
	}

	private function should_filter_posts_clauses( WP_Query $query ): bool {
		if ( is_admin() || ! $this->is_product_query( $query ) || ! $this->should_apply_sort_to_query( $query ) ) {
			return false;
		}

		$catalog_orderby = (string) $query->get( 'kss_catalog_orderby' );
		if ( '' !== $catalog_orderby && $this->uses_posts_clauses( $catalog_orderby ) ) {
			return true;
		}

		if ( ! $this->url_has_orderby() ) {
			return false;
		}

		return $this->uses_posts_clauses( $this->get_current_orderby() );
	}

	/**
	 * Elementor Loop Grid can inject ASC / menu_order after WooCommerce hooks.
	 *
	 * @param array<string,mixed> $query
	 * @return array<string,mixed>
	 */
	private function clear_elementor_order_conflicts( array $query ): array {
		unset(
			$query['product_query_order'],
			$query['product_query_orderby'],
			$query['meta_value'],
			$query['meta_compare']
		);

		return $query;
	}

	private function is_product_query( WP_Query $query ): bool {
		if ( $query->get( 'wc_query' ) ) {
			return true;
		}

		$post_type = $query->get( 'post_type' );

		if ( 'product' === $post_type ) {
			return true;
		}

		return is_array( $post_type ) && in_array( 'product', $post_type, true );
	}

	/**
	 * Elementor Loop Grid uses a secondary WP_Query (not main_query).
	 */
	private function should_apply_sort_to_query( WP_Query $query ): bool {
		if ( ! $this->is_product_query( $query ) ) {
			return false;
		}

		if ( $query->get( 'p' ) || $query->get( 'page_id' ) || $query->get( 'name' ) || $query->get( 'pagename' ) ) {
			return false;
		}

		$post__in = $query->get( 'post__in' );
		if ( is_array( $post__in ) && 1 === count( $post__in ) ) {
			return false;
		}

		if ( is_array( $post__in ) && $post__in && 'post__in' === $query->get( 'orderby' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * @param array<string,mixed> $query
	 * @return array<string,mixed>
	 */
	private function merge_ordering_into_query( array $query ): array {
		$orderby = $this->get_current_orderby();

		$query = $this->clear_elementor_order_conflicts( $query );

		unset( $query['meta_key'] );
		$query['suppress_filters'] = false;

		if ( $this->uses_posts_clauses( $orderby ) ) {
			$this->attach_catalog_ordering( $orderby );
			$query['kss_catalog_orderby'] = $orderby;
			$query['kss_sql_sorted']      = 1;
			$query['orderby']             = $orderby;
			$query['order']               = in_array( $orderby, array( 'price-desc', 'popularity', 'rating' ), true ) ? 'DESC' : 'ASC';
			return $query;
		}

		unset( $query['kss_catalog_orderby'] );

		$ordering = $this->get_ordering_args( $orderby );

		$query['orderby'] = $ordering['orderby'];
		$query['order']   = $ordering['order'];

		if ( ! empty( $ordering['meta_key'] ) ) {
			$query['meta_key'] = $ordering['meta_key']; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		}

		return $query;
	}

	public function apply_orderby_to_product_queries( WP_Query $query ): void {
		if ( is_admin() || ! $this->url_has_orderby() ) {
			return;
		}

		if ( ! $this->should_apply_sort_to_query( $query ) ) {
			return;
		}

		if ( $this->is_active_context() && $this->is_product_query( $query ) ) {
			$query->set( 'suppress_filters', false );
		}

		$orderby = $this->get_current_orderby();
		$this->apply_orderby_to_query( $query, $orderby );
	}

	/**
	 * @param WP_Query $query
	 */
	private function apply_orderby_to_query( WP_Query $query, string $orderby ): void {
		$query->set( 'suppress_filters', false );

		if ( $this->uses_posts_clauses( $orderby ) ) {
			$this->attach_catalog_ordering( $orderby );
			$query->set( 'kss_catalog_orderby', $orderby );
			$query->set( 'kss_sql_sorted', 1 );
			$query->set( 'orderby', $orderby );
			$query->set( 'order', in_array( $orderby, array( 'price-desc', 'popularity', 'rating' ), true ) ? 'DESC' : 'ASC' );
			$query->set( 'meta_key', '' );
			return;
		}

		$query->set( 'kss_catalog_orderby', '' );

		$ordering = $this->get_ordering_args( $orderby );
		$query->set( 'orderby', $ordering['orderby'] );
		$query->set( 'order', $ordering['order'] );

		if ( ! empty( $ordering['meta_key'] ) ) {
			$query->set( 'meta_key', $ordering['meta_key'] );
		}
	}

	/**
	 * @param WP_Query        $query
	 * @param WC_Query|null   $wc_query
	 */
	public function apply_to_woocommerce_product_query( $query, $wc_query = null ): void {
		if ( is_admin() || ! $this->url_has_orderby() || ! $query instanceof WP_Query ) {
			return;
		}

		if ( ! $this->should_apply_sort_to_query( $query ) ) {
			return;
		}

		$this->apply_orderby_to_query( $query, $this->get_current_orderby() );
	}

	/**
	 * @param array<string,mixed> $query_args
	 * @param object              $widget
	 * @return array<string,mixed>
	 */
	public function apply_url_orderby_to_elementor_query( array $query_args, $widget ): array {
		if ( ! $this->url_has_orderby() ) {
			return $query_args;
		}

		if ( ! $this->is_product_loop_widget( $widget ) ) {
			return $query_args;
		}

		if ( $this->uses_posts_clauses( $this->get_current_orderby() ) ) {
			$this->ensure_posts_clauses_handler();
		}

		$query_args['suppress_filters'] = false;

		return $this->merge_ordering_into_query( $query_args );
	}

	public function apply_url_orderby_to_block_query( array $query, $block, int $page ): array {
		if ( ! $this->url_has_orderby() || ! $this->is_active_context() ) {
			return $query;
		}

		$is_product_collection = ! empty( $block->context['query']['isProductCollectionBlock'] );
		$is_product_query      = isset( $block->context['query']['post_type'] ) && 'product' === $block->context['query']['post_type'];

		if ( ! $is_product_collection && ! $is_product_query ) {
			return $query;
		}

		return $this->merge_ordering_into_query( $query );
	}

	private function is_product_loop_widget( $widget ): bool {
		if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) ) {
			return false;
		}

		if ( 'loop-grid' !== $widget->get_name() ) {
			return false;
		}

		if ( ! method_exists( $widget, 'get_settings_for_display' ) ) {
			return $this->is_active_context();
		}

		$settings = $widget->get_settings_for_display();
		$skin     = $settings['_skin'] ?? '';

		return 'product' === $skin || $this->is_active_context();
	}

	public function maybe_render_before_elementor_loop( $widget ): void {
		if ( self::$toolbar_rendered || ! $this->is_active_context() ) {
			return;
		}

		if ( ! $this->is_product_loop_widget( $widget ) ) {
			return;
		}

		$this->maybe_render_toolbar();
	}

	public function register_shortcode(): void {
		add_shortcode( 'kabigon_shop_sort', array( $this, 'render_shortcode' ) );
	}

	public function maybe_render_toolbar(): void {
		if ( self::$toolbar_rendered || ! $this->is_active_context() ) {
			return;
		}

		self::$toolbar_rendered = true;
		$this->enqueue_assets();
		echo $this->get_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function render_shortcode(): string {
		if ( ! $this->is_active_context() ) {
			return '';
		}

		$this->enqueue_assets();
		return $this->get_markup();
	}

	private function get_markup(): string {
		$options  = $this->get_sort_options();
		$current  = $this->get_current_orderby();
		$uid      = 'kss-' . wp_unique_id();
		$action   = $this->get_form_action_url();
		$preserve = $this->get_preserved_query_args();

		ob_start();
		?>
		<div class="kss-toolbar" data-kss-root>
			<form class="kss-form" method="get" action="<?php echo esc_url( $action ); ?>">
				<label class="kss-form__label" for="<?php echo esc_attr( $uid ); ?>">
					<?php esc_html_e( 'Sort by', 'kabigon-shop-sort' ); ?>
				</label>
				<div class="kss-select">
					<select
						class="kss-select__input"
						id="<?php echo esc_attr( $uid ); ?>"
						name="orderby"
						aria-label="<?php esc_attr_e( 'Sort products', 'kabigon-shop-sort' ); ?>"
						data-kss-select
					>
						<?php foreach ( $options as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<span class="kss-select__icon" aria-hidden="true">
						<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
					</span>
				</div>
				<input type="hidden" name="paged" value="1" />
				<?php foreach ( $preserve as $key => $value ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>" />
				<?php endforeach; ?>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private function get_form_action_url(): string {
		global $wp;

		if ( is_search() ) {
			return home_url( '/' );
		}

		if ( ! empty( $wp->request ) ) {
			return trailingslashit( home_url( user_trailingslashit( $wp->request ) ) );
		}

		return get_permalink() ?: home_url( '/' );
	}

	/**
	 * @return array<string, string>
	 */
	private function get_preserved_query_args(): array {
		$preserve = array();
		$allowed  = array( 's', 'post_type', 'product_cat', 'product_tag', 'min_price', 'max_price', 'rating_filter', 'filter_', 'query_type_' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		foreach ( $_GET as $key => $value ) {
			if ( in_array( $key, array( 'orderby', 'submit', 'paged', 'product-page' ), true ) ) {
				continue;
			}

			$keep = in_array( $key, $allowed, true );
			if ( ! $keep ) {
				foreach ( $allowed as $prefix ) {
					if ( str_ends_with( $prefix, '_' ) && str_starts_with( $key, $prefix ) ) {
						$keep = true;
						break;
					}
				}
			}

			if ( ! $keep ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$value = implode( ',', array_map( 'wc_clean', $value ) );
			} else {
				$value = wc_clean( wp_unslash( $value ) );
			}

			if ( '' !== $value ) {
				$preserve[ $key ] = $value;
			}
		}

		return $preserve;
	}

	public function maybe_enqueue_assets(): void {
		if ( ! $this->is_active_context() ) {
			return;
		}

		$this->enqueue_assets();
	}

	public function enqueue_assets(): void {
		if ( is_admin() || wp_style_is( 'kabigon-shop-sort', 'enqueued' ) ) {
			return;
		}

		wp_register_style( 'kabigon-shop-sort', false, array(), self::VERSION );
		wp_enqueue_style( 'kabigon-shop-sort' );
		wp_add_inline_style( 'kabigon-shop-sort', $this->get_css() );

		wp_register_script( 'kabigon-shop-sort', false, array(), self::VERSION, true );
		wp_enqueue_script( 'kabigon-shop-sort' );
		wp_add_inline_script( 'kabigon-shop-sort', $this->get_js() );
	}

	private function get_css(): string {
		return '
		.kss-toolbar,
		.kss-toolbar * { box-sizing:border-box; }

		.kss-toolbar {
			width:100%;
			max-width:100%;
			margin:0 0 20px;
			padding:0;
			font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
		}

		.kss-form {
			display:flex;
			align-items:center;
			justify-content:flex-end;
			gap:12px;
			flex-wrap:wrap;
			margin:0;
			padding:0;
		}

		.kss-form__label {
			margin:0;
			font-size:12px;
			font-weight:600;
			letter-spacing:.08em;
			text-transform:uppercase;
			color:#707070;
			white-space:nowrap;
		}

		.kss-select {
			position:relative;
			display:inline-flex;
			align-items:center;
			min-width:min(280px,100%);
		}

		.kss-select__input {
			appearance:none !important;
			-webkit-appearance:none !important;
			width:100%;
			min-height:44px;
			margin:0 !important;
			padding:10px 42px 10px 16px !important;
			border:1px solid #1a1a1a !important;
			border-radius:6px !important;
			background:#fff !important;
			color:#1a1a1a !important;
			font-size:14px !important;
			font-weight:500 !important;
			line-height:1.3 !important;
			cursor:pointer;
			box-shadow:none !important;
			outline:none !important;
		}

		.kss-select__input:hover,
		.kss-select__input:focus {
			border-color:#1a1a1a !important;
			box-shadow:0 0 0 1px #1a1a1a !important;
		}

		.kss-select__icon {
			position:absolute;
			right:14px;
			top:50%;
			transform:translateY(-50%);
			pointer-events:none;
			color:#1a1a1a;
			display:flex;
			align-items:center;
			justify-content:center;
		}

		.elementor-widget-loop-grid .kss-toolbar {
			margin-bottom:16px;
		}

		@media (max-width:768px) {
			.kss-form {
				justify-content:stretch;
				flex-direction:column;
				align-items:stretch;
			}

			.kss-select {
				width:100%;
			}
		}
		';
	}

	private function get_js(): string {
		return <<<'JS'
(function () {
	'use strict';
	document.querySelectorAll('[data-kss-select]').forEach(function (select) {
		select.addEventListener('change', function () {
			const form = select.closest('form');
			if (form) form.submit();
		});
	});
})();
JS;
	}
}

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Kabigon Shop Sort requires WooCommerce.', 'kabigon-shop-sort' ) . '</p></div>';
				}
			);
			return;
		}
		Kabigon_Shop_Sort::instance();
	}
);
