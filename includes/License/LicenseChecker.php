<?php
/**
 * LicenseChecker — reads plan status.
 *
 * For testing: define IDIOMATTICWP_FORCE_PRO=true in wp-config.php to
 * simulate a Pro license without Freemius SDK.
 *
 * Phase 1: always returns free unless IDIOMATTICWP_FORCE_PRO is defined.
 * Phase 2: replace with Freemius SDK call: idiomatticwp_fs()->can_use_premium_code()
 *
 * @package IdiomatticWP\License
 */

declare( strict_types=1 );

namespace IdiomatticWP\License;

class LicenseChecker {

	public function isPro(): bool {
		// Allow overriding via wp-config.php constant for testing purposes
		if ( defined( 'IDIOMATTICWP_FORCE_PRO' ) && IDIOMATTICWP_FORCE_PRO ) {
			return true;
		}

		// Phase 2 (when Freemius SDK is integrated):
		// if ( function_exists( 'idiomatticwp_fs' ) ) {
		//     return idiomatticwp_fs()->can_use_premium_code();
		// }

		return false;
	}

	public function isAgency(): bool {
		if ( defined( 'IDIOMATTICWP_FORCE_PRO' ) && IDIOMATTICWP_FORCE_PRO ) {
			return true;
		}
		return false;
	}

	public function getPlan(): string {
		if ( defined( 'IDIOMATTICWP_FORCE_PRO' ) && IDIOMATTICWP_FORCE_PRO ) {
			return 'pro';
		}
		return 'free';
	}
}
