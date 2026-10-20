<?php
/**
 * The SHARED «AddSynapse» top-level wp-admin menu of all our plugins (Search & Filter, AI Shop
 * Assistant, Connector, …). Contract: docs/ADMIN-MENU.md (reference implementation:
 * addsynapse-search-filter/admin/class-addsynapse-search-admin-menu.php). No shared PHP class and no
 * class_exists() guard: plugins coordinate through WordPress' runtime menu registry
 * ($admin_page_hooks) and two filters — `addsynapse_products` (cards) and
 * `addsynapse_overview_renderers` (highest version renders the Overview).
 *
 * @package Addsynapse\StoreAssistant
 */

namespace Addsynapse\StoreAssistant\Admin;

use Addsynapse\StoreAssistant\Capabilities;
use Addsynapse\StoreAssistant\Byok\KeyStore;

defined( 'ABSPATH' ) || exit;

/**
 * Menu: admin_menu:9 parent + Overview (guarded) · :10 product submenus · :999 stable sort by ORDER.
 */
final class AddsynapseMenu {

	/** The slug of the top-level AND of the Overview page (first submenu). Shared contract. */
	public const PARENT_SLUG = 'addsynapse';

	/** Priority on admin_menu: top-level + Overview (same in every plugin). */
	public const PRIORITY_PARENT = 9;

	/** Priority on admin_menu: product submenus (same in every plugin). */
	public const PRIORITY_SUBMENU = 10;

	/** Priority on admin_menu: final sort of the submenus. */
	public const PRIORITY_SORT = 999;

	/** Display order per slug (shared contract); unknown slugs → 100, in registration order. */
	public const ORDER = array(
		'addsynapse'                     => 0,
		'addsynapse-store-assistant'     => 10,
		// «Upgrade to Pro» of the Store Assistant (a link to the add-on's page), right below it.
		'addsynapse-store-assistant-pro' => 11,
		'addsynapse-search'              => 20,
		'addsynapse-connector'           => 30,
	);

	/** Menu position (string: avoids the WooCommerce Marketing slot at 58). */
	public const POSITION = '58.7';

	/** Version of OUR Overview renderer (bump when it changes, always compatible with the card schema). */
	public const RENDERER_VERSION = '1.0.0';

	/** The customer portal. */
	public const PORTAL_URL = 'https://addsynapse.com';

	/**
	 * Monochrome 20×20 SVG (fill="black" only, so WordPress recolours it with the admin colour scheme)
	 * as a base64 data URI — byte-for-byte the same in every plugin (docs/ADMIN-MENU.md §5).
	 */
	public const ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCI+PHBhdGggZmlsbD0iYmxhY2siIGQ9Ik0xMC43OCA0Ljk1TDkuMjIgNC4wNUwzLjIyIDE0LjU1TDQuNzggMTUuNDVaTTkuMjIgNC45NUwxMC43OCA0LjA1TDE2Ljc4IDE0LjU1TDE1LjIyIDE1LjQ1Wk00IDE0LjFIMTZWMTUuOUg0Wk03LjQgNC41YTIuNiAyLjYgMCAxIDAgNS4yIDBhMi42IDIuNiAwIDEgMCAtNS4yIDBaTTEuNCAxNWEyLjYgMi42IDAgMSAwIDUuMiAwYTIuNiAyLjYgMCAxIDAgLTUuMiAwWk0xMy40IDE1YTIuNiAyLjYgMCAxIDAgNS4yIDBhMi42IDIuNiAwIDEgMCAtNS4yIDBaIi8+PC9zdmc+';

	/**
	 * Registers hooks (wp-admin only).
	 */
	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'ensure_parent' ), self::PRIORITY_PARENT );
		add_action( 'admin_menu', array( self::class, 'sort_submenu' ), self::PRIORITY_SORT );
		add_filter( 'addsynapse_overview_renderers', array( self::class, 'offer_renderer' ) );
		add_filter( 'addsynapse_products', array( self::class, 'describe_product' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * Sorts the `addsynapse` submenus by ORDER (stable). Idempotent: running it from two plugins
	 * changes nothing the second time.
	 */
	public static function sort_submenu(): void {
		global $submenu;
		if ( empty( $submenu[ self::PARENT_SLUG ] ) || ! is_array( $submenu[ self::PARENT_SLUG ] ) ) {
			return;
		}
		$rows = array();
		$i    = 0;
		foreach ( $submenu[ self::PARENT_SLUG ] as $item ) {
			$slug   = isset( $item[2] ) ? (string) $item[2] : '';
			$rows[] = array( self::ORDER[ $slug ] ?? 100, $i++, $item );
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return ( $a[0] === $b[0] ) ? $a[1] - $b[1] : $a[0] - $b[0];
			}
		);
		$submenu[ self::PARENT_SLUG ] = array_column( $rows, 2 ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reorders ONLY our shared menu.
	}

	/**
	 * Capability of the top-level and the Overview (contract value, see Capabilities::overview()).
	 */
	public static function capability(): string {
		return Capabilities::overview();
	}

	/**
	 * On admin_menu:9 — creates the top-level + «Overview» ONLY if another AddSynapse plugin has not
	 * already (a query on WordPress' runtime state, like menu_page_url(), not a code guard).
	 */
	public static function ensure_parent(): void {
		if ( ! empty( $GLOBALS['admin_page_hooks'][ self::PARENT_SLUG ] ) ) {
			return;
		}
		$cap = self::capability();
		// «AddSynapse» is the brand — never translated.
		add_menu_page( 'AddSynapse', 'AddSynapse', $cap, self::PARENT_SLUG, array( self::class, 'render_overview' ), self::ICON, self::POSITION );
		add_submenu_page( self::PARENT_SLUG, 'AddSynapse', __( 'Overview', 'addsynapse-store-assistant' ), $cap, self::PARENT_SLUG, array( self::class, 'render_overview' ), self::ORDER[ self::PARENT_SLUG ] );
	}

	/**
	 * Adds one of OUR product pages under the shared parent (call from admin_menu:10).
	 *
	 * @param string   $page_title Page title.
	 * @param string   $menu_title Menu label.
	 * @param string   $capability Capability.
	 * @param string   $slug       Page slug (a key of ORDER).
	 * @param callable $callback   Renderer.
	 * @return string Hook suffix ('' when not added).
	 */
	public static function add_page( string $page_title, string $menu_title, string $capability, string $slug, callable $callback ): string {
		self::ensure_parent();
		return (string) add_submenu_page( self::PARENT_SLUG, $page_title, $menu_title, $capability, $slug, $callback, self::ORDER[ $slug ] ?? null );
	}

	/**
	 * Offers our renderer to `addsynapse_overview_renderers`.
	 *
	 * @param mixed $renderers List of [ 'version' => string, 'callback' => callable ].
	 * @return array<int,array<string,mixed>>
	 */
	public static function offer_renderer( $renderers ): array {
		$renderers   = is_array( $renderers ) ? $renderers : array();
		$renderers[] = array(
			'version'  => self::RENDERER_VERSION,
			'callback' => array( self::class, 'render_overview_body' ),
		);
		return $renderers;
	}

	/**
	 * Our card on the Overview (`addsynapse_products`) — LOCAL state only, never a network call.
	 *
	 * @param mixed $products Cards of the other plugins.
	 * @return array<string,array<string,mixed>>
	 */
	public static function describe_product( $products ): array {
		$products                      = is_array( $products ) ? $products : array();
		$connected                     = KeyStore::active();
		$products['ai-shop-assistant'] = array(
			'id'           => 'ai-shop-assistant',
			'name'         => 'Store Assistant',
			'version'      => defined( 'ADDSYNAPSE_ASSISTANT_VERSION' ) ? ADDSYNAPSE_ASSISTANT_VERSION : '',
			'status'       => $connected ? 'connected' : 'not_connected',
			'status_label' => $connected ? __( 'Ready', 'addsynapse-store-assistant' ) : __( 'Not set up — add your AI key in Settings', 'addsynapse-store-assistant' ),
			'settings_url' => SettingsPage::url(),
			'description'  => __( 'Ask in plain language for shop, page, menu and SEO changes; every change is a draft you approve.', 'addsynapse-store-assistant' ),
			'icon'         => 'dashicons-format-chat',
			'capability'   => Capabilities::resolve(),
		);
		return $products;
	}

	/**
	 * Overview callback: the renderer with the highest version draws the page.
	 */
	public static function render_overview(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'addsynapse-store-assistant' ) );
		}
		$best = null;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- shared cross-plugin contract hook (docs/ADMIN-MENU.md).
		foreach ( (array) apply_filters( 'addsynapse_overview_renderers', array() ) as $renderer ) {
			if ( ! is_array( $renderer ) || empty( $renderer['version'] ) || empty( $renderer['callback'] ) || ! is_callable( $renderer['callback'] ) ) {
				continue;
			}
			if ( null === $best || version_compare( (string) $renderer['version'], (string) $best['version'], '>' ) ) {
				$best = $renderer;
			}
		}
		call_user_func( $best ? $best['callback'] : array( self::class, 'render_overview_body' ) );
	}

	/**
	 * Normalises the `addsynapse_products` cards. Other plugins' data is untrusted: anything missing
	 * or of the wrong type is dropped.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function products(): array {
		$out = array();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- shared cross-plugin contract hook (docs/ADMIN-MENU.md).
		foreach ( (array) apply_filters( 'addsynapse_products', array() ) as $key => $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$id = isset( $p['id'] ) ? sanitize_key( (string) $p['id'] ) : sanitize_key( (string) $key );
			if ( '' === $id || empty( $p['name'] ) ) {
				continue;
			}
			if ( ! empty( $p['capability'] ) && ! current_user_can( (string) $p['capability'] ) ) {
				continue;
			}
			$status     = isset( $p['status'] ) && in_array( $p['status'], array( 'connected', 'not_connected', 'error' ), true ) ? $p['status'] : 'not_connected';
			$out[ $id ] = array(
				'id'           => $id,
				'name'         => (string) $p['name'],
				'version'      => isset( $p['version'] ) ? (string) $p['version'] : '',
				'status'       => $status,
				'status_label' => isset( $p['status_label'] ) ? (string) $p['status_label'] : '',
				'settings_url' => isset( $p['settings_url'] ) ? (string) $p['settings_url'] : '',
				'description'  => isset( $p['description'] ) ? (string) $p['description'] : '',
				'icon'         => isset( $p['icon'] ) ? (string) $p['icon'] : '',
			);
		}//end foreach
		return $out;
	}

	/**
	 * Overview styles (assets/admin.css), on the Overview screen only.
	 *
	 * @param string $hook_suffix Current screen hook.
	 */
	public static function enqueue( $hook_suffix ): void {
		if ( 'toplevel_page_' . self::PARENT_SLUG === $hook_suffix ) {
			AdminPage::enqueue_admin_css();
		}
	}

	/**
	 * Our Overview renderer (version RENDERER_VERSION) — same markup/classes as the reference one.
	 */
	public static function render_overview_body(): void {
		$products       = self::products();
		$default_labels = array(
			'connected'     => __( 'Connected', 'addsynapse-store-assistant' ),
			'not_connected' => __( 'Not connected', 'addsynapse-store-assistant' ),
			'error'         => __( 'Needs attention', 'addsynapse-store-assistant' ),
		);
		?>
		<div class="wrap addsynapse-overview">
			<div class="addsynapse-overview-head">
				<h1>AddSynapse</h1>
				<a class="button" href="<?php echo esc_url( self::PORTAL_URL ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Visit addsynapse.com', 'addsynapse-store-assistant' ); ?>
					<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'addsynapse-store-assistant' ); ?></span>
				</a>
			</div>
			<hr class="wp-header-end">
			<p><?php esc_html_e( 'The AddSynapse plugins installed on this site.', 'addsynapse-store-assistant' ); ?></p>
			<?php if ( empty( $products ) ) : ?>
				<p><em><?php esc_html_e( 'No AddSynapse plugin has registered itself here yet.', 'addsynapse-store-assistant' ); ?></em></p>
			<?php else : ?>
				<div class="addsynapse-overview-grid">
					<?php foreach ( $products as $p ) : ?>
						<div class="addsynapse-card" data-product="<?php echo esc_attr( $p['id'] ); ?>">
							<div class="addsynapse-card-top">
								<?php self::render_icon( $p['icon'] ); ?>
								<div>
									<h2><?php echo esc_html( $p['name'] ); ?></h2>
									<?php if ( '' !== $p['version'] ) : ?>
										<span class="addsynapse-card-version">
											<?php
											/* translators: %s: plugin version number */
											echo esc_html( sprintf( __( 'Version %s', 'addsynapse-store-assistant' ), $p['version'] ) );
											?>
										</span>
									<?php endif; ?>
								</div>
							</div>
							<span class="addsynapse-status addsynapse-status-<?php echo esc_attr( $p['status'] ); ?>">
								<?php echo esc_html( '' !== $p['status_label'] ? $p['status_label'] : $default_labels[ $p['status'] ] ); ?>
							</span>
							<?php if ( '' !== $p['description'] ) : ?>
								<p><?php echo esc_html( $p['description'] ); ?></p>
							<?php endif; ?>
							<?php if ( '' !== $p['settings_url'] ) : ?>
								<div class="addsynapse-card-actions">
									<a class="button button-primary" href="<?php echo esc_url( $p['settings_url'] ); ?>"><?php esc_html_e( 'Settings', 'addsynapse-store-assistant' ); ?></a>
								</div>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Card icon: `dashicons-*` or an image (data:image/… or https URL); anything else → our logo.
	 *
	 * @param string $icon Value of the `icon` field.
	 */
	private static function render_icon( string $icon ): void {
		if ( 0 === strpos( $icon, 'dashicons-' ) ) {
			printf( '<span class="addsynapse-card-icon dashicons %s" aria-hidden="true"></span>', esc_attr( sanitize_html_class( $icon ) ) );
			return;
		}
		$src = ( 0 === strpos( $icon, 'data:image/' ) || 0 === strpos( $icon, 'https://' ) ) ? $icon : self::ICON;
		printf( '<img class="addsynapse-card-icon" src="%s" alt="" width="32" height="32" />', esc_url( $src, array( 'https', 'data' ) ) );
	}
}
