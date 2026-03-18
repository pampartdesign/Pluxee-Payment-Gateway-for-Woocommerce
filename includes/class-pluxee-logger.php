<?php
/**
 * Logger helper — thin wrapper around WooCommerce's built-in logger.
 *
 * Centralises log-channel creation and guards every write behind the
 * "debug_logging" setting so that production sites are never noisy.
 *
 * @package PluxeeGateway
 */

declare( strict_types=1 );

namespace PluxeeGateway\Gateway;

defined( 'ABSPATH' ) || exit;

use WC_Logger_Interface;

/**
 * Class PluxeeLogger
 *
 * Usage:
 *   PluxeeLogger::debug( 'Payment initiated', [ 'order_id' => 42 ] );
 *   PluxeeLogger::error( 'API call failed', [ 'code' => 500 ] );
 */
final class PluxeeLogger {

    /** WooCommerce log channel / source identifier. */
    public const CHANNEL = 'pluxee-gateway';

    /** @var WC_Logger_Interface|null Cached logger instance. */
    private static ?WC_Logger_Interface $logger = null;

    /** @var bool|null Cached "is debug enabled" flag. */
    private static ?bool $debug_enabled = null;

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Write a DEBUG-level entry (only when debug logging is enabled in settings).
     *
     * @param string               $message Human-readable message.
     * @param array<string, mixed> $context Optional key→value context data.
     */
    public static function debug( string $message, array $context = [] ): void {
        if ( ! self::is_debug_enabled() ) {
            return;
        }
        self::write( 'debug', $message, $context );
    }

    /**
     * Write an INFO-level entry (always written regardless of debug flag).
     *
     * @param string               $message Human-readable message.
     * @param array<string, mixed> $context Optional key→value context data.
     */
    public static function info( string $message, array $context = [] ): void {
        self::write( 'info', $message, $context );
    }

    /**
     * Write a WARNING-level entry.
     *
     * @param string               $message Human-readable message.
     * @param array<string, mixed> $context Optional key→value context data.
     */
    public static function warning( string $message, array $context = [] ): void {
        self::write( 'warning', $message, $context );
    }

    /**
     * Write an ERROR-level entry (always written regardless of debug flag).
     *
     * @param string               $message Human-readable message.
     * @param array<string, mixed> $context Optional key→value context data.
     */
    public static function error( string $message, array $context = [] ): void {
        self::write( 'error', $message, $context );
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    /**
     * Write an entry at the given level.
     *
     * Context is JSON-encoded and appended to the message so that log viewers
     * that do not support structured context still show the data.
     *
     * SECURITY: Never pass sensitive data (secrets, card numbers, raw tokens)
     * in $context — callers are responsible for sanitising before calling.
     *
     * @param string               $level   WooCommerce log level string.
     * @param string               $message Human-readable message.
     * @param array<string, mixed> $context Additional structured data.
     */
    private static function write( string $level, string $message, array $context ): void {
        $logger = self::get_logger();

        $full_message = $message;
        if ( ! empty( $context ) ) {
            $full_message .= ' | ' . wp_json_encode( $context, JSON_UNESCAPED_SLASHES );
        }

        $logger->log(
            $level,
            $full_message,
            [ 'source' => self::CHANNEL ]
        );
    }

    /**
     * Lazy-load and cache the WooCommerce logger.
     */
    private static function get_logger(): WC_Logger_Interface {
        if ( null === self::$logger ) {
            self::$logger = wc_get_logger();
        }
        return self::$logger;
    }

    /**
     * Check whether debug logging is turned on in the gateway settings.
     * Result is cached for the request lifetime.
     */
    private static function is_debug_enabled(): bool {
        if ( null === self::$debug_enabled ) {
            $options             = get_option( 'woocommerce_' . PLUXEE_GW_ID . '_settings', [] );
            self::$debug_enabled = isset( $options['debug_logging'] ) && 'yes' === $options['debug_logging'];
        }
        return self::$debug_enabled;
    }
}
