<?php
/**
 * Plugin Name:       Pluxee WooCommerce Gateway
 * Plugin URI:        https://pampart.com/pluxee-gateway
 * Description:       Integrates Pluxee as a payment gateway for WooCommerce. Supports redirect-based payment flow, webhook verification, order status management, and refunds.
 * Version:           1.0.0
 * Author:            Pampart Dessign Software and IT
 * Author URI:        https://pampart.com
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pluxee-gateway
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * WC requires at least: 7.0
 * WC tested up to:   9.0
 *
 * @package PluxeeGateway
 */

declare( strict_types=1 );

namespace PluxeeGateway;

defined( 'ABSPATH' ) || exit;

// ── Plugin constants ──────────────────────────────────────────────────────────
define( 'PLUXEE_GW_VERSION',    '1.0.0' );
define( 'PLUXEE_GW_FILE',       __FILE__ );
define( 'PLUXEE_GW_DIR',        plugin_dir_path( __FILE__ ) );
define( 'PLUXEE_GW_URL',        plugin_dir_url( __FILE__ ) );
define( 'PLUXEE_GW_BASENAME',   plugin_basename( __FILE__ ) );
define( 'PLUXEE_GW_ID',         'pluxee' );          // WooCommerce gateway ID

// ── Declare HPOS compatibility ────────────────────────────────────────────────
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
} );

// ── Activation / deactivation hooks ──────────────────────────────────────────
register_activation_hook( __FILE__, [ __NAMESPACE__ . '\\Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ __NAMESPACE__ . '\\Plugin', 'deactivate' ] );

// ── Bootstrap after plugins are loaded (ensures WooCommerce is present) ──────
add_action( 'plugins_loaded', [ __NAMESPACE__ . '\\Plugin', 'init' ], 0 );

/**
 * Central plugin orchestrator.
 *
 * Responsible only for bootstrapping; business logic lives in dedicated classes.
 */
final class Plugin {

    /** @var self|null Singleton instance. */
    private static ?self $instance = null;

    /** Prevent external instantiation. */
    private function __construct() {}

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    /**
     * Fired on `plugins_loaded`. Checks for WooCommerce, then loads the plugin.
     */
    public static function init(): void {
        if ( ! self::is_woocommerce_active() ) {
            add_action( 'admin_notices', [ self::class, 'notice_woocommerce_missing' ] );
            return;
        }

        self::get_instance()->boot();
    }

    /**
     * Activation hook: run version checks / option seeding.
     */
    public static function activate(): void {
        if ( ! self::is_woocommerce_active() ) {
            deactivate_plugins( PLUXEE_GW_BASENAME );
            wp_die(
                esc_html__( 'Pluxee Gateway requires WooCommerce to be installed and active.', 'pluxee-gateway' ),
                esc_html__( 'Plugin Activation Error', 'pluxee-gateway' ),
                [ 'back_link' => true ]
            );
        }

        // Seed default option so the gateway record exists on first activation.
        add_option( 'woocommerce_' . PLUXEE_GW_ID . '_settings', [] );
        update_option( 'pluxee_gw_version', PLUXEE_GW_VERSION );
    }

    /**
     * Deactivation hook: flush rewrite rules so our custom endpoint is removed.
     */
    public static function deactivate(): void {
        flush_rewrite_rules();
    }

    // ── Singleton ─────────────────────────────────────────────────────────────

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ── Booting ───────────────────────────────────────────────────────────────

    /**
     * Load all plugin components and register hooks.
     */
    private function boot(): void {
        $this->load_textdomain();
        $this->autoload();

        // Register gateway with WooCommerce.
        add_filter( 'woocommerce_payment_gateways', [ $this, 'register_gateway' ] );

        // Webhook endpoint: register a custom WC API route.
        add_action( 'woocommerce_api_' . PLUXEE_GW_ID . '_webhook', [ Webhook\Handler::class, 'handle' ] );

        // Admin-only assets and links.
        if ( is_admin() ) {
            add_filter( 'plugin_action_links_' . PLUXEE_GW_BASENAME, [ $this, 'add_settings_link' ] );
        }

        /**
         * Action: fired after all Pluxee plugin components are loaded.
         * Third-party code can hook here to extend the plugin safely.
         *
         * @since 1.0.0
         */
        do_action( 'pluxee_gateway_loaded' );
    }

    /**
     * Require all plugin class files.
     */
    private function autoload(): void {
        $includes = PLUXEE_GW_DIR . 'includes/';

        require_once $includes . 'class-pluxee-logger.php';
        require_once $includes . 'class-pluxee-api.php';
        require_once $includes . 'class-pluxee-webhook.php';
        require_once $includes . 'class-pluxee-gateway.php';
    }

    /**
     * Load plugin text domain for translations.
     */
    private function load_textdomain(): void {
        load_plugin_textdomain(
            'pluxee-gateway',
            false,
            dirname( PLUXEE_GW_BASENAME ) . '/languages/'
        );
    }

    // ── Hooks ─────────────────────────────────────────────────────────────────

    /**
     * Add Pluxee gateway class to WooCommerce gateway list.
     *
     * @param  array<string> $gateways Existing gateway class names.
     * @return array<string>
     */
    public function register_gateway( array $gateways ): array {
        $gateways[] = Gateway\PluxeeGateway::class;
        return $gateways;
    }

    /**
     * Add a direct "Settings" link on the Plugins screen.
     *
     * @param  array<string, string> $links Existing plugin action links.
     * @return array<string, string>
     */
    public function add_settings_link( array $links ): array {
        $url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . PLUXEE_GW_ID );
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url( $url ),
            esc_html__( 'Settings', 'pluxee-gateway' )
        );
        array_unshift( $links, $settings_link );
        return $links;
    }

    // ── Admin notices ─────────────────────────────────────────────────────────

    /**
     * Display an admin notice if WooCommerce is not active.
     */
    public static function notice_woocommerce_missing(): void {
        echo '<div class="notice notice-error"><p>';
        printf(
            /* translators: %s WooCommerce plugin name */
            esc_html__( 'Pluxee WooCommerce Gateway requires %s to be installed and active.', 'pluxee-gateway' ),
            '<strong>WooCommerce</strong>'
        );
        echo '</p></div>';
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Check whether WooCommerce is available.
     */
    private static function is_woocommerce_active(): bool {
        return class_exists( 'WooCommerce' );
    }
}
