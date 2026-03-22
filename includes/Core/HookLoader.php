<?php
/**
 * HookLoader — resolves and registers all WordPress hook classes.
 *
 * @package IdiomatticWP\Core
 */

declare( strict_types=1 );

namespace IdiomatticWP\Core;

use IdiomatticWP\Contracts\HookRegistrarInterface;

class HookLoader {

	public function __construct( private Container $container ) {}

	public function register(): void {

		// ── Compatibility notices (admin only) ────────────────────────────────
		if ( is_admin() && $this->container->has( \IdiomatticWP\Compatibility\CompatibilityChecker::class ) ) {
			$checker = $this->container->get( \IdiomatticWP\Compatibility\CompatibilityChecker::class );
			add_action( 'admin_notices', [ $checker, 'renderNotices' ] );
		}

		// ── Always load (every request) ───────────────────────────────────────
		$coreHooks = [
			\IdiomatticWP\Hooks\LanguageHooks::class,   // detect + set language
			\IdiomatticWP\Hooks\RoutingHooks::class,    // rewrite all permalink filters
		];

		// ── Admin only ────────────────────────────────────────────────────────
		$adminHooks = [];
		if ( is_admin() ) {
			$adminHooks = [
				\IdiomatticWP\Hooks\Admin\AdminMenuHooks::class,
				\IdiomatticWP\Hooks\Admin\OnboardingHooks::class,
				\IdiomatticWP\Hooks\Admin\TranslationEditorHooks::class,
				\IdiomatticWP\Hooks\Admin\AdminLanguageBar::class,
				\IdiomatticWP\Hooks\Admin\AdminLanguageFilter::class,
				\IdiomatticWP\Hooks\Admin\PostListHooks::class,
				\IdiomatticWP\Hooks\Admin\MetaboxHooks::class,
				\IdiomatticWP\Hooks\Admin\AjaxHooks::class,
				\IdiomatticWP\Hooks\Admin\AssetHooks::class,
				\IdiomatticWP\Hooks\Admin\SettingsHooks::class,
				\IdiomatticWP\Hooks\Admin\LanguageActivationHooks::class,
				\IdiomatticWP\Hooks\Admin\AdminLanguagePreferenceHooks::class,
				\IdiomatticWP\Admin\Pages\WpmlMigrationPage::class,
			];
		}

		// ── Frontend only ─────────────────────────────────────────────────────
		$frontendHooks = [];
		if ( ! is_admin() ) {
			$frontendHooks = [
				\IdiomatticWP\Hooks\Frontend\CanonicalHooks::class,         // canonical URL lang-aware
				\IdiomatticWP\Hooks\Frontend\HreflangHooks::class,          // <link rel="alternate">
				\IdiomatticWP\Hooks\Frontend\SwitcherHooks::class,          // widget + shortcode
				\IdiomatticWP\Hooks\Frontend\FrontendAssetHooks::class,     // CSS + RTL + lang attr
				\IdiomatticWP\Hooks\Frontend\NavMenuHooks::class,           // nav menus localized
				\IdiomatticWP\Hooks\Frontend\ThemeOptionsHooks::class,      // options + widgets translated
				\IdiomatticWP\Hooks\Frontend\ContentVisibilityHooks::class,    // hide translations of non-translatable types
				\IdiomatticWP\Hooks\Frontend\NavMenuSwitcherHooks::class,       // inject language items into nav menus
				\IdiomatticWP\Hooks\Frontend\PostTranslationsDisplayHooks::class, // "Also available in:" content notice
			];
		}

		// ── Translation hooks (all contexts) ──────────────────────────────────
		$translationHooks = [
			\IdiomatticWP\Hooks\Translation\StringTranslationHooks::class,
			\IdiomatticWP\Hooks\Translation\PostTranslationHooks::class,
			\IdiomatticWP\Hooks\Translation\FieldTranslationHooks::class,
			\IdiomatticWP\Hooks\Translation\NotificationHooks::class,
			\IdiomatticWP\Hooks\Translation\WebhookHooks::class,
			\IdiomatticWP\Hooks\Translation\TranslationMemoryHooks::class,
			\IdiomatticWP\Hooks\Translation\TranslateOnPublishHooks::class,
		];

		// ── Queue hooks ───────────────────────────────────────────────────────
		$queueHooks = [
			\IdiomatticWP\Hooks\Queue\QueueHooks::class,
		];

		$all = array_merge( $coreHooks, $adminHooks, $frontendHooks, $translationHooks, $queueHooks );

		foreach ( $all as $hookClass ) {
			if ( ! $this->container->has( $hookClass ) ) {
				continue;
			}

			$hook = $this->container->get( $hookClass );

			if ( $hook instanceof HookRegistrarInterface ) {
				$hook->register();
			}
		}

		do_action( 'idiomatticwp_hooks_loaded' );
	}
}
