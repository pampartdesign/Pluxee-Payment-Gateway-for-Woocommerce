<?php
/**
 * Admin Settings Info Panel
 *
 * Rendered at the top of the Pluxee gateway settings page.
 * Provides quick reference links and environment status indicators.
 *
 * @package PluxeeGateway
 * @var \PluxeeGateway\Gateway\PluxeeGateway $this Gateway instance (passed by WooCommerce).
 */

defined( 'ABSPATH' ) || exit;

$is_test_mode   = $this->is_test_mode();
$webhook_url    = $this->get_webhook_url();
$log_url        = admin_url( 'admin.php?page=wc-status&tab=logs&source=pluxee-gateway' );
$settings_saved = isset( $_GET['saved'] ) && '1' === $_GET['saved']; // phpcs:ignore
?>

<div class="pluxee-admin-info" style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:16px 20px;margin-bottom:20px;">

    <h3 style="margin-top:0;">
        <?php esc_html_e( 'Pluxee Payment Gateway', 'pluxee-gateway' ); ?>
        &nbsp;
        <?php if ( $is_test_mode ) : ?>
            <span style="background:#f0ad4e;color:#fff;font-size:11px;padding:2px 8px;border-radius:3px;font-weight:600;">
                <?php esc_html_e( 'TEST MODE', 'pluxee-gateway' ); ?>
            </span>
        <?php else : ?>
            <span style="background:#5cb85c;color:#fff;font-size:11px;padding:2px 8px;border-radius:3px;font-weight:600;">
                <?php esc_html_e( 'LIVE MODE', 'pluxee-gateway' ); ?>
            </span>
        <?php endif; ?>
    </h3>

    <table style="width:100%;border-collapse:collapse;">
        <tr>
            <td style="width:160px;font-weight:600;padding:4px 0;">
                <?php esc_html_e( 'Webhook URL', 'pluxee-gateway' ); ?>
            </td>
            <td>
                <code style="background:#f5f5f5;padding:2px 6px;border-radius:3px;user-select:all;">
                    <?php echo esc_url( $webhook_url ); ?>
                </code>
                <span style="color:#888;font-size:12px;">
                    — <?php esc_html_e( 'Register this URL in your Pluxee merchant dashboard.', 'pluxee-gateway' ); ?>
                </span>
            </td>
        </tr>
        <tr>
            <td style="font-weight:600;padding:4px 0;">
                <?php esc_html_e( 'Debug Logs', 'pluxee-gateway' ); ?>
            </td>
            <td>
                <a href="<?php echo esc_url( $log_url ); ?>" target="_blank">
                    <?php esc_html_e( 'WooCommerce → Status → Logs → pluxee-gateway', 'pluxee-gateway' ); ?>
                </a>
            </td>
        </tr>
        <tr>
            <td style="font-weight:600;padding:4px 0;">
                <?php esc_html_e( 'Version', 'pluxee-gateway' ); ?>
            </td>
            <td><?php echo esc_html( PLUXEE_GW_VERSION ); ?></td>
        </tr>
    </table>

    <?php if ( $is_test_mode ) : ?>
        <p style="margin-bottom:0;margin-top:12px;color:#856404;background:#fff3cd;border:1px solid #ffc107;border-radius:3px;padding:8px 12px;">
            <strong><?php esc_html_e( 'Test mode is active.', 'pluxee-gateway' ); ?></strong>
            <?php esc_html_e( 'No real payments will be processed. Disable test mode before going live.', 'pluxee-gateway' ); ?>
        </p>
    <?php endif; ?>

</div>
