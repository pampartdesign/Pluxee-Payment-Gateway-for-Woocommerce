<?php
/**
 * Pluxee Webhook / Callback Handler
 *
 * Registered as a WooCommerce API endpoint:
 *   GET/POST  ?wc-api=pluxee_webhook
 *
 * Security model
 * ──────────────
 *  1. Read raw body before any framework parsing.
 *  2. Verify HMAC signature against the webhook secret stored in settings.
 *  3. Decode and validate the payload structure.
 *  4. Re-query the Pluxee API for the current transaction status (do NOT trust
 *     the webhook payload status alone).
 *  5. Guard against duplicate processing using an order-meta flag.
 *  6. Apply the status update and respond 200 OK to acknowledge receipt.
 *
 * @package PluxeeGateway
 */

declare( strict_types=1 );

namespace PluxeeGateway\Webhook;

defined( 'ABSPATH' ) || exit;

use PluxeeGateway\Gateway\PluxeeApi;
use PluxeeGateway\Gateway\PluxeeGateway;
use PluxeeGateway\Gateway\PluxeeLogger;

/**
 * Class Handler
 *
 * All methods are static because WooCommerce routes callbacks via class name.
 */
final class Handler {

    /** Header name Pluxee uses to deliver the request signature. */
    private const SIGNATURE_HEADER = 'HTTP_X_PLUXEE_SIGNATURE';

    // ── SDK PLACEHOLDER ───────────────────────────────────────────────────────
    // If Pluxee uses a different header name for the signature, update the
    // constant above. Common patterns:
    //   'HTTP_X_PLUXEE_SIGNATURE'    → X-Pluxee-Signature
    //   'HTTP_X_SIGNATURE'           → X-Signature
    //   'HTTP_X_HUB_SIGNATURE_256'   → X-Hub-Signature-256 (GitHub-style)
    // ── END SDK PLACEHOLDER ───────────────────────────────────────────────────

    /**
     * Entry point called by WordPress via:
     *   add_action( 'woocommerce_api_pluxee_webhook', [ Handler::class, 'handle' ] )
     */
    public static function handle(): void {
        PluxeeLogger::debug( 'Webhook: request received' );

        // ── 1. Read raw body ──────────────────────────────────────────────────
        $raw_body = self::get_raw_body();

        if ( '' === $raw_body ) {
            PluxeeLogger::warning( 'Webhook: empty request body' );
            self::respond( 400, 'Empty body.' );
        }

        // ── 2. Verify signature ───────────────────────────────────────────────
        $settings       = get_option( 'woocommerce_' . PLUXEE_GW_ID . '_settings', [] );
        $webhook_secret = $settings['webhook_secret'] ?? '';

        if ( empty( $webhook_secret ) ) {
            PluxeeLogger::error( 'Webhook: webhook_secret not configured in plugin settings.' );
            self::respond( 500, 'Gateway misconfigured.' );
        }

        $signature = $_SERVER[ self::SIGNATURE_HEADER ] ?? '';

        $api = self::get_api( $settings );

        if ( ! $api->verify_webhook_signature( $raw_body, $signature, $webhook_secret ) ) {
            PluxeeLogger::warning( 'Webhook: invalid signature — request rejected', [
                'signature_header' => self::SIGNATURE_HEADER,
                'signature_value'  => substr( $signature, 0, 8 ) . '…', // log prefix only
            ] );
            self::respond( 401, 'Invalid signature.' );
        }

        PluxeeLogger::debug( 'Webhook: signature verified' );

        // ── 3. Decode payload ─────────────────────────────────────────────────
        $payload = json_decode( $raw_body, true );

        if ( ! is_array( $payload ) ) {
            PluxeeLogger::error( 'Webhook: payload is not valid JSON', [ 'body_preview' => substr( $raw_body, 0, 200 ) ] );
            self::respond( 400, 'Invalid JSON payload.' );
        }

        PluxeeLogger::debug( 'Webhook: payload decoded', [
            // Log only non-sensitive fields.
            'event'          => $payload['event']         ?? 'unknown',
            'transaction_id' => $payload['transactionId'] ?? $payload['transaction_id'] ?? 'unknown',
            'order_ref'      => $payload['reference']     ?? $payload['orderId']        ?? 'unknown',
        ] );

        // ── 4. Extract identifiers ────────────────────────────────────────────
        // ── SDK PLACEHOLDER ───────────────────────────────────────────────────
        // Adjust the field names below to match the actual Pluxee webhook schema.
        // Common patterns:
        //   transactionId / transaction_id / paymentId / id
        //   reference / orderId / order_id / merchantReference
        // ── END SDK PLACEHOLDER ───────────────────────────────────────────────
        $transaction_id = sanitize_text_field(
            $payload['transactionId'] ?? $payload['transaction_id'] ?? $payload['paymentId'] ?? ''
        );
        $order_reference = sanitize_text_field(
            $payload['reference'] ?? $payload['orderId'] ?? $payload['order_id'] ?? ''
        );

        if ( empty( $transaction_id ) ) {
            PluxeeLogger::error( 'Webhook: missing transaction ID in payload' );
            self::respond( 400, 'Missing transaction ID.' );
        }

        // Resolve the WooCommerce order.
        $order = self::resolve_order( $transaction_id, $order_reference );

        if ( ! $order ) {
            PluxeeLogger::error( 'Webhook: could not resolve order', [
                'transaction_id' => $transaction_id,
                'order_reference'=> $order_reference,
            ] );
            // Respond 200 to stop Pluxee from retrying for unresolvable orders.
            self::respond( 200, 'Order not found — acknowledged.' );
        }

        // ── 5. Duplicate-processing guard ─────────────────────────────────────
        if ( $order->is_paid() && 'yes' === $order->get_meta( PluxeeGateway::META_PAYMENT_VERIFIED ) ) {
            PluxeeLogger::info( 'Webhook: order already paid — duplicate callback ignored', [
                'order_id' => $order->get_id(),
            ] );
            self::respond( 200, 'Already processed.' );
        }

        // ── 6. Re-query the API (do NOT trust webhook payload status alone) ───
        PluxeeLogger::debug( 'Webhook: re-querying API for transaction status', [
            'transaction_id' => $transaction_id,
        ] );

        $status_response = $api->get_payment_status( $transaction_id );

        if ( ! $status_response->success ) {
            PluxeeLogger::error( 'Webhook: API status re-query failed', [
                'order_id' => $order->get_id(),
                'error'    => $status_response->error_message,
            ] );
            $order->add_order_note( sprintf(
                /* translators: %s error message */
                __( 'Pluxee webhook received but API re-query failed: %s', 'pluxee-gateway' ),
                esc_html( $status_response->error_message )
            ) );
            // Respond with a 500 so Pluxee retries later.
            self::respond( 500, 'Status verification failed.' );
        }

        $verified_status = $status_response->status;

        // Save updated meta from re-query.
        $order->update_meta_data( PluxeeGateway::META_PAYMENT_STATUS, $verified_status );
        $order->update_meta_data( PluxeeGateway::META_RAW_RESPONSE,   $status_response->raw_response );
        $order->save();

        $order->add_order_note( sprintf(
            /* translators: 1: transaction ID, 2: status */
            __( 'Pluxee webhook received. Transaction ID: %1$s. Verified status: %2$s.', 'pluxee-gateway' ),
            esc_html( $transaction_id ),
            esc_html( $verified_status )
        ) );

        PluxeeLogger::info( 'Webhook: applying verified status', [
            'order_id' => $order->get_id(),
            'status'   => $verified_status,
        ] );

        // ── 7. Apply status (this redirects internally on return flow only) ───
        // For webhook context we do NOT redirect; we just update order status.
        self::apply_order_status( $order, $verified_status, $transaction_id, $settings );

        // ── 8. Acknowledge ────────────────────────────────────────────────────
        self::respond( 200, 'OK' );
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Apply the correct WooCommerce status without redirecting (webhook context).
     *
     * This mirrors PluxeeGateway::apply_payment_status() but omits the redirect
     * because webhook requests have no browser to redirect.
     *
     * @param \WC_Order            $order
     * @param string               $pluxee_status
     * @param string               $transaction_id
     * @param array<string, mixed> $settings Plugin settings array.
     */
    private static function apply_order_status(
        \WC_Order $order,
        string    $pluxee_status,
        string    $transaction_id,
        array     $settings
    ): void {
        $order_id = $order->get_id();

        if ( PluxeeApi::is_paid_status( $pluxee_status ) ) {
            // Guard duplicate.
            if ( $order->is_paid() ) {
                return;
            }

            $order->update_meta_data( PluxeeGateway::META_PAYMENT_VERIFIED, 'yes' );
            $order->save();

            $order->payment_complete( $transaction_id );

            $success_status = $settings['order_status_success'] ?? 'processing';
            if ( 'completed' === $success_status ) {
                $order->update_status( 'completed' );
            }

            PluxeeLogger::info( 'Webhook: order marked as paid', [
                'order_id'       => $order_id,
                'transaction_id' => $transaction_id,
            ] );

        } elseif ( PluxeeApi::is_failed_status( $pluxee_status ) ) {
            $failed_status = $settings['order_status_failed'] ?? 'failed';
            $order->update_status(
                $failed_status,
                sprintf(
                    /* translators: %s Pluxee status string */
                    __( 'Pluxee webhook: payment %s.', 'pluxee-gateway' ),
                    esc_html( strtolower( $pluxee_status ) )
                )
            );

            PluxeeLogger::info( 'Webhook: order marked as failed/cancelled', [
                'order_id' => $order_id,
                'status'   => $pluxee_status,
            ] );

        } else {
            // Pending / unrecognised — leave order as-is.
            PluxeeLogger::info( 'Webhook: unknown/pending status — order unchanged', [
                'order_id' => $order_id,
                'status'   => $pluxee_status,
            ] );
        }
    }

    /**
     * Resolve a WooCommerce order from a transaction ID or order reference.
     *
     * Tries three strategies in order:
     *  1. Look up by META_TRANSACTION_ID.
     *  2. Treat $order_reference as a WC order ID.
     *  3. Look up by order key if $order_reference looks like one.
     *
     * @param  string $transaction_id
     * @param  string $order_reference Pluxee's reference (often = WC order ID).
     * @return \WC_Order|null
     */
    private static function resolve_order( string $transaction_id, string $order_reference ): ?\WC_Order {
        // Strategy 1: search by stored Pluxee transaction ID.
        $orders = wc_get_orders( [
            'meta_key'   => PluxeeGateway::META_TRANSACTION_ID,
            'meta_value' => $transaction_id,
            'limit'      => 1,
            'return'     => 'objects',
        ] );

        if ( ! empty( $orders ) ) {
            return reset( $orders );
        }

        // Strategy 2: treat reference as WC order ID.
        if ( ! empty( $order_reference ) && is_numeric( $order_reference ) ) {
            $order = wc_get_order( (int) $order_reference );
            if ( $order && $order->get_payment_method() === PLUXEE_GW_ID ) {
                return $order;
            }
        }

        // Strategy 3: treat reference as WC order key.
        if ( ! empty( $order_reference ) && str_starts_with( $order_reference, 'wc_order_' ) ) {
            $order_id = wc_get_order_id_by_order_key( $order_reference );
            if ( $order_id ) {
                $order = wc_get_order( $order_id );
                if ( $order ) {
                    return $order;
                }
            }
        }

        return null;
    }

    /**
     * Build a PluxeeApi instance from stored settings.
     *
     * @param  array<string, mixed> $settings Plugin settings.
     * @return PluxeeApi
     */
    private static function get_api( array $settings ): PluxeeApi {
        return new PluxeeApi(
            merchant_id: $settings['merchant_id']  ?? '',
            api_key    : $settings['api_key']       ?? '',
            api_secret : $settings['api_secret']    ?? '',
            base_url   : $settings['api_base_url']  ?? '',
            test_mode  : isset( $settings['test_mode'] ) && 'yes' === $settings['test_mode'],
        );
    }

    /**
     * Read the raw request body before any PHP parsing.
     *
     * Must be called early — once WP processes the request body, php://input
     * may be empty on some server configurations.
     *
     * @return string
     */
    private static function get_raw_body(): string {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        return (string) file_get_contents( 'php://input' );
    }

    /**
     * Send a JSON response and terminate execution.
     *
     * @param int    $status_code HTTP status code.
     * @param string $message     Human-readable message.
     *
     * @return never
     */
    private static function respond( int $status_code, string $message ): never {
        status_header( $status_code );
        header( 'Content-Type: application/json; charset=utf-8' );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo wp_json_encode( [ 'status' => $status_code, 'message' => $message ] );
        exit;
    }
}
