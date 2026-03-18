<?php
/**
 * Pluxee WooCommerce Gateway — Uninstall
 *
 * Runs when the user clicks "Delete" on the Plugins page.
 * Removes plugin options only — order meta is intentionally preserved for
 * compliance and audit purposes. Adjust the list below if your site policy
 * requires full removal.
 *
 * @package PluxeeGateway
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Remove gateway settings stored by WooCommerce.
delete_option( 'woocommerce_pluxee_settings' );
delete_option( 'pluxee_gw_version' );

// NOTE: Order meta (_pluxee_transaction_id, _pluxee_payment_status, etc.) is
// intentionally NOT deleted. Financial records must be retained for compliance.
// If you need full removal, uncomment the block below and acknowledge the risk.
//
// global $wpdb;
// $meta_keys = [
//     '_pluxee_transaction_id',
//     '_pluxee_payment_status',
//     '_pluxee_raw_response',
//     '_pluxee_payment_verified',
// ];
// foreach ( $meta_keys as $key ) {
//     $wpdb->delete( $wpdb->postmeta, [ 'meta_key' => $key ] );
//     // HPOS:
//     if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->prefix . 'wc_orders_meta' ) ) ) {
//         $wpdb->delete( $wpdb->prefix . 'wc_orders_meta', [ 'meta_key' => $key ] );
//     }
// }
