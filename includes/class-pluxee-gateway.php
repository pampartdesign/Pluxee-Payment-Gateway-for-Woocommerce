<?php
/**
 * Pluxee WooCommerce Payment Gateway
 *
 * Extends WC_Payment_Gateway to add Pluxee as a checkout payment method.
 * Handles:
 *   - Admin settings definition
 *   - Checkout rendering
 *   - Payment initiation (redirect flow)
 *   - Return URL processing (verify before marking paid)
 *   - WooCommerce refund support
 *
 * @package PluxeeGateway
 */

declare( strict_types=1 );

namespace PluxeeGateway\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Class PluxeeGateway
 */
class PluxeeGateway extends \WC_Payment_Gateway {

    // ── Order meta keys ───────────────────────────────────────────────────────

    public const META_TRANSACTION_ID   = '_pluxee_transaction_id';
    public const META_PAYMENT_STATUS   = '_pluxee_payment_status';
    public const META_RAW_RESPONSE     = '_pluxee_raw_response';
    public const META_PAYMENT_VERIFIED = '_pluxee_payment_verified'; // 'yes'|'no'

    // ── Constructor ───────────────────────────────────────────────────────────

    public function __construct() {
        $this->id                 = PLUXEE_GW_ID;
        $this->method_title       = __( 'Pluxee', 'pluxee-gateway' );
        $this->method_description = __( 'Accept payments via the Pluxee payment platform.', 'pluxee-gateway' );
        $this->has_fields         = false; // No inline payment fields; redirect flow.
        $this->supports           = [
            'products',
            'refunds',
        ];

        // Load settings definition + stored values.
        $this->init_form_fields();
        $this->init_settings();

        // Expose settings as class properties (WooCommerce convention).
        $this->title              = $this->get_option( 'title' );
        $this->description        = $this->get_option( 'description' );

        // Save settings when updated via admin.
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );

        // Handle return URL after redirect from Pluxee.
        add_action( 'woocommerce_api_' . $this->id . '_return', [ $this, 'handle_return' ] );
    }

    // =========================================================================
    // ADMIN SETTINGS
    // =========================================================================

    /**
     * Define all admin form fields.
     *
     * These are rendered by WooCommerce on WooCommerce → Settings → Payments → Pluxee.
     */
    public function init_form_fields(): void {
        $this->form_fields = [

            // ── Gateway toggle ─────────────────────────────────────────────
            'enabled' => [
                'title'   => __( 'Enable / Disable', 'pluxee-gateway' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable Pluxee Payment Gateway', 'pluxee-gateway' ),
                'default' => 'no',
            ],

            // ── Checkout presentation ──────────────────────────────────────
            'title' => [
                'title'       => __( 'Title', 'pluxee-gateway' ),
                'type'        => 'text',
                'description' => __( 'Payment method title customers see at checkout.', 'pluxee-gateway' ),
                'default'     => __( 'Pay with Pluxee', 'pluxee-gateway' ),
                'desc_tip'    => true,
            ],
            'description' => [
                'title'       => __( 'Description', 'pluxee-gateway' ),
                'type'        => 'textarea',
                'description' => __( 'Short description shown below the payment method title at checkout.', 'pluxee-gateway' ),
                'default'     => __( 'Secure payment via Pluxee.', 'pluxee-gateway' ),
                'desc_tip'    => true,
            ],

            // ── Environment ────────────────────────────────────────────────
            'test_mode' => [
                'title'       => __( 'Test Mode', 'pluxee-gateway' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable test / sandbox mode', 'pluxee-gateway' ),
                'default'     => 'yes',
                'description' => __( 'Use the Pluxee sandbox environment. Disable in production.', 'pluxee-gateway' ),
            ],

            // ── API credentials ────────────────────────────────────────────
            'merchant_id' => [
                'title'       => __( 'Merchant ID', 'pluxee-gateway' ),
                'type'        => 'text',
                'description' => __( 'Your Pluxee Merchant ID.', 'pluxee-gateway' ),
                'default'     => '',
                'desc_tip'    => true,
            ],
            'api_key' => [
                'title'       => __( 'API Key', 'pluxee-gateway' ),
                'type'        => 'text',
                'description' => __( 'Pluxee public API key.', 'pluxee-gateway' ),
                'default'     => '',
                'desc_tip'    => true,
            ],
            'api_secret' => [
                'title'       => __( 'API Secret', 'pluxee-gateway' ),
                'type'        => 'password',
                'description' => __( 'Pluxee API secret. Never share this.', 'pluxee-gateway' ),
                'default'     => '',
                'desc_tip'    => true,
            ],
            'api_base_url' => [
                'title'       => __( 'API Base URL', 'pluxee-gateway' ),
                'type'        => 'text',
                'description' => __( 'Pluxee API base URL (e.g. https://api.pluxee.com/v1). Leave empty to use the SDK default.', 'pluxee-gateway' ),
                'default'     => '',
                'desc_tip'    => true,
            ],
            'webhook_secret' => [
                'title'       => __( 'Webhook / Callback Secret', 'pluxee-gateway' ),
                'type'        => 'password',
                'description' => __( 'Secret used to verify incoming webhook signatures from Pluxee.', 'pluxee-gateway' ),
                'default'     => '',
                'desc_tip'    => true,
            ],

            // ── Order status mapping ───────────────────────────────────────
            'order_status_success' => [
                'title'       => __( 'Order Status on Success', 'pluxee-gateway' ),
                'type'        => 'select',
                'description' => __( 'WooCommerce order status after a verified successful payment.', 'pluxee-gateway' ),
                'options'     => [
                    'processing' => __( 'Processing', 'pluxee-gateway' ),
                    'completed'  => __( 'Completed', 'pluxee-gateway' ),
                ],
                'default'     => 'processing',
                'desc_tip'    => true,
            ],
            'order_status_failed' => [
                'title'       => __( 'Order Status on Failure', 'pluxee-gateway' ),
                'type'        => 'select',
                'description' => __( 'WooCommerce order status after a verified failed payment.', 'pluxee-gateway' ),
                'options'     => [
                    'failed'  => __( 'Failed', 'pluxee-gateway' ),
                    'pending' => __( 'Pending payment', 'pluxee-gateway' ),
                ],
                'default'     => 'failed',
                'desc_tip'    => true,
            ],

            // ── Debug ──────────────────────────────────────────────────────
            'debug_logging' => [
                'title'       => __( 'Debug Logging', 'pluxee-gateway' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable debug logging (WooCommerce → Status → Logs → pluxee-gateway)', 'pluxee-gateway' ),
                'default'     => 'no',
                'description' => __( 'Only enable during development. Logs API interactions (never logs secrets).', 'pluxee-gateway' ),
            ],

        ];
    }

    // =========================================================================
    // CHECKOUT
    // =========================================================================

    /**
     * Display the payment method at checkout.
     *
     * WooCommerce calls this to render the method's description / additional HTML.
     */
    public function payment_fields(): void {
        if ( $this->description ) {
            echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
        }

        if ( $this->is_test_mode() ) {
            echo '<p class="pluxee-test-notice" style="color:#888;">'
                . esc_html__( 'TEST MODE ACTIVE — No real payments will be taken.', 'pluxee-gateway' )
                . '</p>';
        }
    }

    /**
     * Validate any required fields before processing the payment.
     *
     * Returning false prevents WooCommerce from proceeding. Errors should be
     * added via wc_add_notice().
     *
     * @return bool
     */
    public function validate_fields(): bool {
        // This gateway uses a redirect flow and has no inline fields.
        // Add per-field validation here if the flow ever includes custom fields.
        return true;
    }

    // =========================================================================
    // PAYMENT PROCESSING
    // =========================================================================

    /**
     * Process payment — called by WooCommerce on checkout form submission.
     *
     * IMPORTANT: The order is already in "pending-payment" state when this runs.
     * We initiate the payment, store the transaction reference, and redirect the
     * customer to the Pluxee hosted page.
     *
     * @param  int $order_id WooCommerce order ID.
     * @return array{result: string, redirect: string}
     */
    public function process_payment( $order_id ): array {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            wc_add_notice( __( 'Order not found. Please try again.', 'pluxee-gateway' ), 'error' );
            return [ 'result' => 'fail', 'redirect' => '' ];
        }

        PluxeeLogger::info( 'Payment processing started', [ 'order_id' => $order_id ] );

        // Reduce stock now (reversible) so inventory is held while customer pays.
        wc_reduce_stock_levels( $order_id );

        // Keep the order in pending-payment; do NOT set it to processing here.
        $order->update_status( 'pending', __( 'Awaiting Pluxee payment.', 'pluxee-gateway' ) );

        $api      = $this->get_api();
        $response = $api->create_payment( [
            'order_id'       => $order_id,
            'amount'         => (float) $order->get_total(),
            'currency'       => get_woocommerce_currency(),
            'description'    => sprintf(
                /* translators: %s Order number */
                __( 'Order #%s', 'pluxee-gateway' ),
                $order->get_order_number()
            ),
            'customer_name'  => $order->get_formatted_billing_full_name(),
            'customer_email' => $order->get_billing_email(),
            'return_url'     => $this->get_return_url_for_order( $order ),
            'cancel_url'     => wc_get_cart_url(),
            'webhook_url'    => $this->get_webhook_url(),
        ] );

        if ( ! $response->success ) {
            PluxeeLogger::error( 'Payment initiation failed', [
                'order_id' => $order_id,
                'error'    => $response->error_message,
            ] );

            $order->add_order_note(
                sprintf(
                    /* translators: %s error message */
                    __( 'Pluxee payment initiation failed: %s', 'pluxee-gateway' ),
                    esc_html( $response->error_message )
                )
            );

            wc_add_notice( $response->error_message, 'error' );
            return [ 'result' => 'fail', 'redirect' => '' ];
        }

        // Store the transaction reference before redirecting.
        $this->save_transaction_meta( $order, $response->transaction_id, $response->raw_response, PluxeeApi::STATUS_PENDING );

        $order->add_order_note(
            sprintf(
                /* translators: %s Pluxee transaction ID */
                __( 'Pluxee payment initiated. Transaction ID: %s. Customer redirected to payment page.', 'pluxee-gateway' ),
                esc_html( $response->transaction_id )
            )
        );

        PluxeeLogger::info( 'Customer redirected to Pluxee', [
            'order_id'       => $order_id,
            'transaction_id' => $response->transaction_id,
            'redirect_url'   => $response->redirect_url,
        ] );

        return [
            'result'   => 'success',
            'redirect' => $response->redirect_url,
        ];
    }

    // =========================================================================
    // RETURN URL HANDLER  (customer lands back after Pluxee redirect)
    // =========================================================================

    /**
     * Handle the customer's return from the Pluxee hosted payment page.
     *
     * Pluxee will redirect to:
     *   ?wc-api=pluxee_return&order_id=123&transaction_id=TXN_ABC
     *
     * IMPORTANT: Never trust query parameters alone.
     * Always verify the status by calling the API before updating the order.
     */
    public function handle_return(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $order_id       = isset( $_GET['order_id'] )       ? absint( $_GET['order_id'] )                  : 0;
        $transaction_id = isset( $_GET['transaction_id'] ) ? sanitize_text_field( wp_unslash( $_GET['transaction_id'] ) ) : '';
        // phpcs:enable

        if ( ! $order_id ) {
            PluxeeLogger::warning( 'Return handler called without order_id' );
            wp_safe_redirect( wc_get_cart_url() );
            exit;
        }

        $order = wc_get_order( $order_id );

        if ( ! $order || $order->get_payment_method() !== $this->id ) {
            PluxeeLogger::warning( 'Return handler: invalid or mismatched order', [ 'order_id' => $order_id ] );
            wp_safe_redirect( wc_get_cart_url() );
            exit;
        }

        // Prevent double-processing if the order is already paid.
        if ( $order->is_paid() ) {
            PluxeeLogger::info( 'Return handler: order already paid, skipping', [ 'order_id' => $order_id ] );
            wp_safe_redirect( $this->get_return_url( $order ) );
            exit;
        }

        PluxeeLogger::info( 'Return handler: verifying payment', [
            'order_id'       => $order_id,
            'transaction_id' => $transaction_id,
        ] );

        // Use the stored transaction ID if the URL param is missing.
        if ( empty( $transaction_id ) ) {
            $transaction_id = (string) $order->get_meta( self::META_TRANSACTION_ID );
        }

        if ( empty( $transaction_id ) ) {
            PluxeeLogger::error( 'Return handler: no transaction_id to verify', [ 'order_id' => $order_id ] );
            $order->add_order_note( __( 'Pluxee return: no transaction ID found, cannot verify payment.', 'pluxee-gateway' ) );
            wc_add_notice( __( 'Payment could not be verified. Please contact support.', 'pluxee-gateway' ), 'error' );
            wp_safe_redirect( wc_get_cart_url() );
            exit;
        }

        $api      = $this->get_api();
        $response = $api->verify_payment( $transaction_id );

        if ( ! $response->success ) {
            PluxeeLogger::error( 'Return handler: verification API call failed', [
                'order_id' => $order_id,
                'error'    => $response->error_message,
            ] );
            $order->add_order_note( sprintf(
                /* translators: %s error text */
                __( 'Pluxee payment verification failed: %s', 'pluxee-gateway' ),
                esc_html( $response->error_message )
            ) );
            wc_add_notice( __( 'We could not verify your payment status. Please contact support.', 'pluxee-gateway' ), 'error' );
            wp_safe_redirect( wc_get_cart_url() );
            exit;
        }

        $this->save_transaction_meta( $order, $response->transaction_id, $response->raw_response, $response->status );
        $this->apply_payment_status( $order, $response->status, $response->transaction_id, 'return' );
    }

    // =========================================================================
    // REFUNDS
    // =========================================================================

    /**
     * Process a refund issued from the WooCommerce admin.
     *
     * WooCommerce calls this method when an admin clicks "Refund".
     *
     * @param  int        $order_id Order ID.
     * @param  float|null $amount   Amount to refund (null = full refund).
     * @param  string     $reason   Refund reason text.
     * @return bool|\WP_Error
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return new \WP_Error( 'pluxee_refund_error', __( 'Order not found.', 'pluxee-gateway' ) );
        }

        $transaction_id = (string) $order->get_meta( self::META_TRANSACTION_ID );

        if ( empty( $transaction_id ) ) {
            return new \WP_Error(
                'pluxee_refund_error',
                __( 'No Pluxee transaction ID found on this order. Manual refund may be required.', 'pluxee-gateway' )
            );
        }

        $refund_amount = $amount ?? (float) $order->get_total();

        PluxeeLogger::info( 'Refund requested', [
            'order_id'       => $order_id,
            'transaction_id' => $transaction_id,
            'amount'         => $refund_amount,
        ] );

        $api      = $this->get_api();
        $response = $api->refund_payment( $transaction_id, $refund_amount, $reason );

        if ( ! $response->success ) {
            PluxeeLogger::error( 'Refund failed', [
                'order_id' => $order_id,
                'error'    => $response->error_message,
            ] );
            return new \WP_Error( 'pluxee_refund_error', $response->error_message );
        }

        $order->add_order_note( sprintf(
            /* translators: 1: amount, 2: currency, 3: refund ID */
            __( 'Pluxee refund issued: %1$s %2$s. Refund ID: %3$s', 'pluxee-gateway' ),
            number_format_i18n( $refund_amount, 2 ),
            get_woocommerce_currency(),
            esc_html( $response->refund_id )
        ) );

        PluxeeLogger::info( 'Refund successful', [
            'order_id'  => $order_id,
            'refund_id' => $response->refund_id,
        ] );

        return true;
    }

    // =========================================================================
    // INTERNAL HELPERS
    // =========================================================================

    /**
     * Build and return a configured PluxeeApi instance.
     */
    public function get_api(): PluxeeApi {
        return new PluxeeApi(
            merchant_id : $this->get_option( 'merchant_id' ),
            api_key     : $this->get_option( 'api_key' ),
            api_secret  : $this->get_option( 'api_secret' ),
            base_url    : $this->get_option( 'api_base_url', '' ),
            test_mode   : $this->is_test_mode(),
        );
    }

    /**
     * Apply the correct WooCommerce order status based on a Pluxee status string.
     *
     * This is the single place where orders transition from pending → paid / failed.
     *
     * @param  \WC_Order $order          The order to update.
     * @param  string    $pluxee_status  Raw status string from the Pluxee API.
     * @param  string    $transaction_id Pluxee transaction reference.
     * @param  string    $source         Context string used in order notes ("return"|"webhook").
     */
    public function apply_payment_status(
        \WC_Order $order,
        string    $pluxee_status,
        string    $transaction_id,
        string    $source = 'webhook'
    ): void {
        $order_id = $order->get_id();

        if ( PluxeeApi::is_paid_status( $pluxee_status ) ) {
            // Guard: do not mark as paid twice.
            if ( $order->is_paid() || 'yes' === $order->get_meta( self::META_PAYMENT_VERIFIED ) ) {
                PluxeeLogger::info( 'apply_payment_status: order already paid, skipping', [
                    'order_id' => $order_id,
                ] );
                wp_safe_redirect( $this->get_return_url( $order ) );
                exit;
            }

            // Mark as verified and complete the payment.
            $order->update_meta_data( self::META_PAYMENT_VERIFIED, 'yes' );
            $order->save();

            $order->payment_complete( $transaction_id );

            // Apply configured success status (default: processing).
            $success_status = $this->get_option( 'order_status_success', 'processing' );
            if ( 'completed' === $success_status ) {
                $order->update_status( 'completed' );
            }

            // Empty the customer's cart.
            if ( isset( WC()->cart ) ) {
                WC()->cart->empty_cart();
            }

            $order->add_order_note( sprintf(
                /* translators: 1: source, 2: transaction ID, 3: Pluxee status */
                __( 'Pluxee payment confirmed via %1$s. Transaction ID: %2$s. Status: %3$s', 'pluxee-gateway' ),
                esc_html( $source ),
                esc_html( $transaction_id ),
                esc_html( $pluxee_status )
            ) );

            PluxeeLogger::info( 'Order marked as paid', [
                'order_id'       => $order_id,
                'transaction_id' => $transaction_id,
                'source'         => $source,
            ] );

            wp_safe_redirect( $this->get_return_url( $order ) );
            exit;

        } elseif ( PluxeeApi::is_failed_status( $pluxee_status ) ) {

            $failed_status = $this->get_option( 'order_status_failed', 'failed' );
            $order->update_status(
                $failed_status,
                sprintf(
                    /* translators: 1: Pluxee status, 2: source */
                    __( 'Pluxee payment %1$s (received via %2$s).', 'pluxee-gateway' ),
                    esc_html( strtolower( $pluxee_status ) ),
                    esc_html( $source )
                )
            );

            PluxeeLogger::info( 'Order marked as failed', [
                'order_id' => $order_id,
                'status'   => $pluxee_status,
                'source'   => $source,
            ] );

            if ( 'return' === $source ) {
                wc_add_notice(
                    __( 'Your payment was not successful. Please try again or choose a different payment method.', 'pluxee-gateway' ),
                    'error'
                );
                wp_safe_redirect( wc_get_cart_url() );
                exit;
            }

        } else {
            // Pending / unknown — leave the order in its current state.
            $order->add_order_note( sprintf(
                /* translators: 1: status, 2: source */
                __( 'Pluxee returned status "%1$s" via %2$s. Order left in current state pending further update.', 'pluxee-gateway' ),
                esc_html( $pluxee_status ),
                esc_html( $source )
            ) );

            PluxeeLogger::info( 'Payment status pending/unknown', [
                'order_id' => $order_id,
                'status'   => $pluxee_status,
                'source'   => $source,
            ] );

            if ( 'return' === $source ) {
                wc_add_notice(
                    __( 'Your payment is still being processed. You will be notified once confirmed.', 'pluxee-gateway' ),
                    'notice'
                );
                wp_safe_redirect( $this->get_return_url( $order ) );
                exit;
            }
        }
    }

    /**
     * Store transaction data in order meta.
     *
     * SECURITY: $raw_response must never contain full card data or secrets.
     * The API wrapper is responsible for sanitising before returning.
     *
     * @param \WC_Order $order
     * @param string    $transaction_id
     * @param string    $raw_response   JSON-encoded, sanitised API response.
     * @param string    $status
     */
    private function save_transaction_meta(
        \WC_Order $order,
        string    $transaction_id,
        string    $raw_response,
        string    $status
    ): void {
        if ( $transaction_id ) {
            $order->set_transaction_id( $transaction_id );
            $order->update_meta_data( self::META_TRANSACTION_ID, $transaction_id );
        }
        $order->update_meta_data( self::META_PAYMENT_STATUS, $status );
        $order->update_meta_data( self::META_RAW_RESPONSE, $raw_response );
        $order->save();
    }

    /**
     * Return URL that Pluxee should redirect the customer back to.
     *
     * Appends order_id and a hash so we can safely retrieve the order.
     * (The hash is informational only — we verify via the API, not via this hash.)
     *
     * @param  \WC_Order $order
     * @return string
     */
    private function get_return_url_for_order( \WC_Order $order ): string {
        return add_query_arg(
            [
                'wc-api'   => $this->id . '_return',
                'order_id' => $order->get_id(),
                'key'      => $order->get_order_key(),
            ],
            home_url( '/' )
        );
    }

    /**
     * Return the WooCommerce API URL used as the webhook endpoint.
     *
     * Pluxee will POST payment events to this URL.
     *
     * @return string
     */
    public function get_webhook_url(): string {
        return add_query_arg(
            [ 'wc-api' => PLUXEE_GW_ID . '_webhook' ],
            home_url( '/' )
        );
    }

    /**
     * Whether this gateway instance is in test mode.
     */
    public function is_test_mode(): bool {
        return 'yes' === $this->get_option( 'test_mode', 'yes' );
    }

    /**
     * Whether the gateway is available at checkout.
     *
     * Hides the gateway if required credentials are missing.
     *
     * @return bool
     */
    public function is_available(): bool {
        if ( ! parent::is_available() ) {
            return false;
        }

        if ( empty( $this->get_option( 'merchant_id' ) ) || empty( $this->get_option( 'api_key' ) ) ) {
            return false;
        }

        /**
         * Filter: pluxee_gateway_is_available
         *
         * Allow third-party code to conditionally hide the gateway.
         *
         * @param bool            $available Current availability.
         * @param PluxeeGateway   $gateway   Gateway instance.
         */
        return (bool) apply_filters( 'pluxee_gateway_is_available', true, $this );
    }
}
