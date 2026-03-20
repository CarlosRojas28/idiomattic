<?php
/**
 * StagingDetector — heuristic detection of staging / development environments.
 *
 * Used to distinguish production licenses from staging sites so that
 * a license activation on staging does not consume a production seat.
 *
 * Detection order (first match wins):
 *   1. WP_ENVIRONMENT_TYPE constant ('local' or 'staging')
 *   2. WP_DEBUG + non-public site (wp_is_site_public())
 *   3. Known staging hostname patterns (*.staging.*, *.dev, *.local, etc.)
 *   4. Local / private IP ranges
 *
 * @package IdiomatticWP\License
 */

declare( strict_types=1 );

namespace IdiomatticWP\License;

class StagingDetector {

	/** Hostname patterns that indicate a non-production environment. */
	private const STAGING_PATTERNS = [
		'/\.staging\./i',
		'/\.stage\./i',
		'/\.(local|test|dev|development|sandbox|preview)$/i',
		'/\.(wpengine|pantheonsite|kinsta|cloudwaysapps|siteground)\.com$/i',
		'/localhost(:\d+)?$/i',
	];

	/** Staging-specific path suffixes (used by some hosts). */
	private const STAGING_PATH_PATTERNS = [
		'/\/staging\//i',
		'/\/stage\//i',
		'/\/dev\//i',
	];

	/**
	 * Returns true when the current request is detected as a staging environment.
	 *
	 * Result is memoised for the life of the request (no DB calls).
	 */
	public function isStaging(): bool {
		static $result = null;

		if ( null !== $result ) {
			return $result;
		}

		$result = $this->detect();

		return $result;
	}

	// ── Private detection logic ───────────────────────────────────────────

	/**
	 * Run all heuristics in priority order.
	 */
	private function detect(): bool {
		// 1. WordPress native environment type (WP 5.5+).
		if ( $this->checkWpEnvironmentType() ) {
			return true;
		}

		// 2. ABSPATH-level constant set by deployment scripts.
		if ( defined( 'IDIOMATTICWP_STAGING' ) && IDIOMATTICWP_STAGING ) {
			return true;
		}

		// 3. Non-public site running in debug mode (likely dev).
		if ( $this->checkDebugAndVisibility() ) {
			return true;
		}

		// 4. Hostname / URL pattern matching.
		if ( $this->checkHostname() ) {
			return true;
		}

		// 5. Private or loopback IP address.
		if ( $this->checkPrivateIp() ) {
			return true;
		}

		return false;
	}

	/**
	 * Check wp_get_environment_type() (WP 5.5+).
	 */
	private function checkWpEnvironmentType(): bool {
		if ( ! function_exists( 'wp_get_environment_type' ) ) {
			return false;
		}

		return in_array( wp_get_environment_type(), [ 'local', 'staging' ], true );
	}

	/**
	 * WP_DEBUG + non-public site strongly implies development.
	 */
	private function checkDebugAndVisibility(): bool {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return false;
		}

		// blog_public = 0 means "Discourage search engines" — another staging signal.
		return ! (bool) get_option( 'blog_public', 1 );
	}

	/**
	 * Match site URL against known staging hostname / path patterns.
	 */
	private function checkHostname(): bool {
		$siteUrl = (string) get_option( 'siteurl', '' );

		if ( ! $siteUrl ) {
			return false;
		}

		$host = (string) ( parse_url( $siteUrl, PHP_URL_HOST ) ?? '' );
		$path = (string) ( parse_url( $siteUrl, PHP_URL_PATH ) ?? '' );

		foreach ( self::STAGING_PATTERNS as $pattern ) {
			if ( preg_match( $pattern, $host ) ) {
				return true;
			}
		}

		foreach ( self::STAGING_PATH_PATTERNS as $pattern ) {
			if ( preg_match( $pattern, $path ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Detect loopback or RFC-1918 / link-local IP addresses.
	 */
	private function checkPrivateIp(): bool {
		$siteUrl = (string) get_option( 'siteurl', '' );
		$host    = (string) ( parse_url( $siteUrl, PHP_URL_HOST ) ?? '' );

		if ( ! $host ) {
			return false;
		}

		// Resolve hostname → IP (skips if already an IP).
		$ip = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : gethostbyname( $host );

		if ( ! $ip || $ip === $host ) {
			return false; // Could not resolve or no DNS.
		}

		// FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE → returns false for private/reserved IPs.
		return false === filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}
}
