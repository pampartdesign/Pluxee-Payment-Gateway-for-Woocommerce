<?php
/**
 * Pluxee API Service Wrapper
 *
 * This class is the ONLY place that talks to the Pluxee SDK / HTTP API.
 * Every public method returns a typed result object so that the Gateway
 * class never needs to know about raw HTTP or SDK internals.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  HOW TO WIRE UP YOUR REAL SDK                                       │
 * │                                                                     │
 * │  1. Drop your SDK files into /includes/sdk/ (or use Composer).      │
 * │  2. Require / autoload them in pluxee-payment-gateway.php.          │
 * │  3. Replace every block marked  ── SDK PLACEHOLDER ──  below with  │
 * │     the real SDK method calls from the Pluxee documentation.        │
 * │  4. Map the SDK's response fields to the ApiResponse properties.    │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * @package PluxeeGateway
 */

declare( strict_types=1 );

namespace PluxeeGateway\Gateway;

defined( 'ABSPATH' ) || exit;

// ── Value objects returned by every public method ─────────────────────────────

/**
 * Generic result returned by every API call.
 *
 * @property-read bool   $success        Whether the call succeeded.
 * @property-read string $transaction_id Pluxee transaction / payment reference.
 * @property-read string $redirect_url   URL to redirect the customer to (if redirect flow).
 * @property-read string $status         Pluxee payment status string (e.g. "PAID", "FAILED").
 * @property-read string $error_message  Human-readable error (customer-safe text).
 * @property-read string $raw_response   Full JSON-encoded response for order meta storage.
 */
final class ApiResponse {
    public function __construct(
        public readonly bool   $success,
        public readonly string $transaction_id  = '',
        public readonly string $redirect_url    = '',
        public readonly string $status          = '',
        public readonly string $error_message   = '',
        public readonly string $raw_response    = '',
    ) {}
}

/**
 * Result of a refund API call.
 */
final class RefundResponse {
    public function __construct(
        public readonly bool   $success,
        public readonly string $refund_id     = '',
        public readonly string $error_message = '',
        public readonly string $raw_response  = '',
    ) {}
}

// ── API Service ───────────────────────────────────────────────────────────────

/**
 * Class PluxeeApi
 *
 * Wraps all Pluxee SDK / HTTP calls. Gateway and webhook classes depend on
 * this class exclusively — they never instantiate the SDK directly.
 */
final class PluxeeApi {

    // ── Status constants (map these to real Pluxee status strings) ────────────

    /** Payment confirmed and settled. */
    public const STATUS_PAID = 'PAID';

    /** Payment explicitly declined. */
    public const STATUS_FAILED = 'FAILED';

    /** Payment initiated but not yet completed. */
    public const STATUS_PENDING = 'PENDING';

    /** Payment cancelled by the customer. */
    public const STATUS_CANCELLED = 'CANCELLED';

    /** Payment authorised but awaiting capture / settlement. */
    public const STATUS_AUTHORISED = 'AUTHORISED';

    // ─────────────────────────────────────────────────────────────────────────

    /** @var string Merchant ID from settings. */
    private string $merchant_id;

    /** @var string API key from settings. */
    private string $api_key;

    /** @var string API secret from settings. */
    private string $api_secret;

    /** @var string Base URL (live or sandbox). */
    private string $base_url;

    /** @var bool Whether this is a test-mode instance. */
    private bool $test_mode;

    /**
     * @param string $merchant_id  Pluxee merchant identifier.
     * @param string $api_key      API public key.
     * @param string $api_secret   API secret key (never logged).
     * @param string $base_url     e.g. "https://api.pluxee.com/v1".
     * @param bool   $test_mode    true = sandbox / test environment.
     */
    public function __construct(
        string $merchant_id,
        string $api_key,
        string $api_secret,
        string $base_url,
        bool   $test_mode = false
    ) {
        $this->merchant_id = $merchant_id;
        $this->api_key     = $api_key;
        $this->api_secret  = $api_secret;
        $this->base_url    = rtrim( $base_url, '/' );
        $this->test_mode   = $test_mode;
    }

    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    /**
     * Initiate a payment and return a redirect URL (or an inline payment token).
     *
     * Called when the customer submits checkout. The order should already be
     * in "pending-payment" state before this method is invoked.
     *
     * @param  array{
     *     order_id:      int,
     *     amount:        float,
     *     currency:      string,
     *     description:   string,
     *     customer_name: string,
     *     customer_email:string,
     *     return_url:    string,
     *     cancel_url:    string,
     *     webhook_url:   string,
     * } $params Payment parameters.
     *
     * @return ApiResponse
     */
    public function create_payment( array $params ): ApiResponse {
        PluxeeLogger::debug( 'API: create_payment called', [
            'order_id' => $params['order_id'],
            'amount'   => $params['amount'],
            'currency' => $params['currency'],
        ] );

        try {
            // ── SDK PLACEHOLDER ───────────────────────────────────────────────
            // Replace the block below with the real Pluxee SDK call.
            //
            // Example (adjust method names to match actual SDK):
            //
            //   $client   = $this->get_sdk_client();
            //   $response = $client->payments()->create([
            //       'merchantId'   => $this->merchant_id,
            //       'amount'       => (int) round( $params['amount'] * 100 ), // cents
            //       'currency'     => $params['currency'],
            //       'reference'    => (string) $params['order_id'],
            //       'description'  => $params['description'],
            //       'customer'     => [
            //           'name'  => $params['customer_name'],
            //           'email' => $params['customer_email'],
            //       ],
            //       'returnUrl'    => $params['return_url'],
            //       'cancelUrl'    => $params['cancel_url'],
            //       'webhookUrl'   => $params['webhook_url'],
            //   ]);
            //
            //   return new ApiResponse(
            //       success        : true,
            //       transaction_id : $response->getId(),
            //       redirect_url   : $response->getRedirectUrl(),
            //       status         : self::STATUS_PENDING,
            //       raw_response   : wp_json_encode( $response->toArray() ),
            //   );
            // ── END SDK PLACEHOLDER ───────────────────────────────────────────

            // TEMPORARY stub — remove when SDK is wired up.
            return new ApiResponse(
                success       : false,
                error_message : __( 'Pluxee API not yet configured. Please wire up the SDK.', 'pluxee-gateway' ),
            );

        } catch ( \Throwable $e ) {
            PluxeeLogger::error( 'API: create_payment exception', [
                'message' => $e->getMessage(),
                'code'    => $e->getCode(),
            ] );

            return new ApiResponse(
                success       : false,
                error_message : $this->safe_error_message( $e ),
            );
        }
    }

    /**
     * Verify the status of a transaction by querying the Pluxee API directly.
     *
     * Always call this before marking an order as paid — never trust the
     * redirect return URL parameters alone.
     *
     * @param  string $transaction_id The Pluxee transaction / payment ID.
     * @return ApiResponse
     */
    public function verify_payment( string $transaction_id ): ApiResponse {
        PluxeeLogger::debug( 'API: verify_payment called', [
            'transaction_id' => $transaction_id,
        ] );

        try {
            // ── SDK PLACEHOLDER ───────────────────────────────────────────────
            // Example:
            //
            //   $client   = $this->get_sdk_client();
            //   $response = $client->payments()->get( $transaction_id );
            //
            //   return new ApiResponse(
            //       success        : true,
            //       transaction_id : $response->getId(),
            //       status         : $response->getStatus(),   // e.g. "PAID"
            //       raw_response   : wp_json_encode( $response->toArray() ),
            //   );
            // ── END SDK PLACEHOLDER ───────────────────────────────────────────

            return new ApiResponse(
                success       : false,
                error_message : __( 'Pluxee API not yet configured. Please wire up the SDK.', 'pluxee-gateway' ),
            );

        } catch ( \Throwable $e ) {
            PluxeeLogger::error( 'API: verify_payment exception', [
                'transaction_id' => $transaction_id,
                'message'        => $e->getMessage(),
            ] );

            return new ApiResponse(
                success       : false,
                error_message : $this->safe_error_message( $e ),
            );
        }
    }

    /**
     * Get the current status of a payment (lightweight poll, no heavy hydration).
     *
     * Used by the webhook handler to re-confirm the status before updating orders.
     *
     * @param  string $transaction_id The Pluxee transaction / payment ID.
     * @return ApiResponse
     */
    public function get_payment_status( string $transaction_id ): ApiResponse {
        PluxeeLogger::debug( 'API: get_payment_status called', [
            'transaction_id' => $transaction_id,
        ] );

        try {
            // ── SDK PLACEHOLDER ───────────────────────────────────────────────
            // Example:
            //
            //   $client   = $this->get_sdk_client();
            //   $response = $client->payments()->status( $transaction_id );
            //
            //   return new ApiResponse(
            //       success        : true,
            //       transaction_id : $transaction_id,
            //       status         : $response->getStatus(),
            //       raw_response   : wp_json_encode( $response->toArray() ),
            //   );
            // ── END SDK PLACEHOLDER ───────────────────────────────────────────

            return new ApiResponse(
                success       : false,
                error_message : __( 'Pluxee API not yet configured. Please wire up the SDK.', 'pluxee-gateway' ),
            );

        } catch ( \Throwable $e ) {
            PluxeeLogger::error( 'API: get_payment_status exception', [
                'transaction_id' => $transaction_id,
                'message'        => $e->getMessage(),
            ] );

            return new ApiResponse(
                success       : false,
                error_message : $this->safe_error_message( $e ),
            );
        }
    }

    /**
     * Issue a full or partial refund for a completed transaction.
     *
     * @param  string $transaction_id   Original Pluxee transaction ID.
     * @param  float  $amount           Amount to refund (in major currency units, e.g. 12.50 EUR).
     * @param  string $reason           Optional refund reason text.
     * @return RefundResponse
     */
    public function refund_payment(
        string $transaction_id,
        float  $amount,
        string $reason = ''
    ): RefundResponse {
        PluxeeLogger::debug( 'API: refund_payment called', [
            'transaction_id' => $transaction_id,
            'amount'         => $amount,
        ] );

        try {
            // ── SDK PLACEHOLDER ───────────────────────────────────────────────
            // Example:
            //
            //   $client   = $this->get_sdk_client();
            //   $response = $client->refunds()->create([
            //       'paymentId' => $transaction_id,
            //       'amount'    => (int) round( $amount * 100 ),
            //       'reason'    => $reason,
            //   ]);
            //
            //   return new RefundResponse(
            //       success      : true,
            //       refund_id    : $response->getRefundId(),
            //       raw_response : wp_json_encode( $response->toArray() ),
            //   );
            // ── END SDK PLACEHOLDER ───────────────────────────────────────────

            return new RefundResponse(
                success       : false,
                error_message : __( 'Pluxee API not yet configured. Please wire up the SDK.', 'pluxee-gateway' ),
            );

        } catch ( \Throwable $e ) {
            PluxeeLogger::error( 'API: refund_payment exception', [
                'transaction_id' => $transaction_id,
                'message'        => $e->getMessage(),
            ] );

            return new RefundResponse(
                success       : false,
                error_message : $this->safe_error_message( $e ),
            );
        }
    }

    /**
     * Verify a webhook callback signature.
     *
     * Returns true only if the signature is cryptographically valid.
     * Always returns false on any error.
     *
     * @param  string $raw_payload   Raw (un-decoded) request body.
     * @param  string $signature     Signature header value from the webhook request.
     * @param  string $webhook_secret The shared webhook secret from plugin settings.
     * @return bool
     */
    public function verify_webhook_signature(
        string $raw_payload,
        string $signature,
        string $webhook_secret
    ): bool {
        if ( empty( $raw_payload ) || empty( $signature ) || empty( $webhook_secret ) ) {
            return false;
        }

        try {
            // ── SDK PLACEHOLDER ───────────────────────────────────────────────
            // Option A — HMAC-SHA256 (common pattern, adjust if Pluxee differs):
            //
            //   $expected = hash_hmac( 'sha256', $raw_payload, $webhook_secret );
            //   return hash_equals( $expected, $signature );
            //
            // Option B — SDK verification helper:
            //
            //   $client = $this->get_sdk_client();
            //   return $client->webhooks()->verify( $raw_payload, $signature );
            // ── END SDK PLACEHOLDER ───────────────────────────────────────────

            // Fallback: HMAC-SHA256 — replace if Pluxee uses a different scheme.
            $expected = hash_hmac( 'sha256', $raw_payload, $webhook_secret );
            return hash_equals( $expected, $signature );

        } catch ( \Throwable $e ) {
            PluxeeLogger::error( 'API: webhook signature verification exception', [
                'message' => $e->getMessage(),
            ] );
            return false;
        }
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Build and cache the SDK client instance.
     *
     * ── SDK PLACEHOLDER ──────────────────────────────────────────────────────
     * Replace the body of this method with SDK initialisation.
     *
     * Example:
     *   use Pluxee\Client;
     *
     *   return new Client([
     *       'merchantId' => $this->merchant_id,
     *       'apiKey'     => $this->api_key,
     *       'apiSecret'  => $this->api_secret,
     *       'baseUrl'    => $this->base_url,
     *       'sandbox'    => $this->test_mode,
     *   ]);
     * ── END SDK PLACEHOLDER ──────────────────────────────────────────────────
     *
     * @return object SDK client instance.
     */
    private function get_sdk_client(): object {
        // @phpstan-ignore-next-line (stub — replace with real SDK instantiation)
        throw new \RuntimeException( 'SDK client not yet configured. Implement get_sdk_client().' );
    }

    /**
     * Convert a Throwable to a short, customer-safe error string.
     *
     * Full stack traces and internal messages are intentionally omitted here;
     * they are already captured by PluxeeLogger::error().
     */
    private function safe_error_message( \Throwable $e ): string {
        // Surface SDK-level user messages if they exist.
        $msg = $e->getMessage();

        // Strip any internal path information or sensitive tokens from the string.
        $msg = preg_replace( '/\b(?:apiKey|secret|password|token)\s*[=:]\s*\S+/i', '[REDACTED]', $msg ) ?? $msg;

        return $msg ?: __( 'An unexpected payment error occurred. Please try again.', 'pluxee-gateway' );
    }

    // ── Utility: check whether a status string represents success ─────────────

    /**
     * Return true if the given Pluxee status string means the payment is settled.
     *
     * @param  string $status Status string from the API.
     * @return bool
     */
    public static function is_paid_status( string $status ): bool {
        /**
         * Filter: pluxee_gateway_paid_statuses
         *
         * Allows integration code to add extra "paid" status strings if the
         * Pluxee API returns custom values in specific regions or verticals.
         *
         * @param string[] $statuses Default paid status strings.
         */
        $paid_statuses = apply_filters(
            'pluxee_gateway_paid_statuses',
            [ self::STATUS_PAID, self::STATUS_AUTHORISED ]
        );

        return in_array( strtoupper( $status ), array_map( 'strtoupper', $paid_statuses ), true );
    }

    /**
     * Return true if the given status string means the payment definitively failed.
     *
     * @param  string $status Status string from the API.
     * @return bool
     */
    public static function is_failed_status( string $status ): bool {
        $failed_statuses = apply_filters(
            'pluxee_gateway_failed_statuses',
            [ self::STATUS_FAILED, self::STATUS_CANCELLED ]
        );

        return in_array( strtoupper( $status ), array_map( 'strtoupper', $failed_statuses ), true );
    }
}
