<?php
/**
 * ContainerConfig — single source of truth for all dependency wiring.
 *
 * Read this file top to bottom to understand the full dependency graph.
 * Add new bindings in the relevant phase section as modules are implemented.
 *
 * @package IdiomatticWP\Core
 */

declare( strict_types=1 );

namespace IdiomatticWP\Core;

use IdiomatticWP\Contracts\UrlStrategyInterface;
use IdiomatticWP\License\LicenseChecker;

class ContainerConfig {

	public static function configure( Container $c ): void {

		// ── Phase 1: Core services ────────────────────────────────────────────

		$c->singleton( LicenseChecker::class, fn( $c ) => new LicenseChecker() );

		$c->singleton(
			\IdiomatticWP\Compatibility\CompatibilityChecker::class,
			fn( $c ) => new \IdiomatticWP\Compatibility\CompatibilityChecker()
		);

		$c->singleton( LanguageManager::class, fn( $c ) => new LanguageManager() );

		$c->singleton(
			\IdiomatticWP\Routing\UrlStrategyResolver::class,
			fn( $c ) => new \IdiomatticWP\Routing\UrlStrategyResolver(
				$c->get( LanguageManager::class ),
				$c->get( LicenseChecker::class )
			)
		);

		$c->singleton(
			UrlStrategyInterface::class,
			fn( $c ) => $c->get( \IdiomatticWP\Routing\UrlStrategyResolver::class )->resolve()
		);

		// ── Phase 2: Routing hooks ────────────────────────────────────────────

		$c->singleton(
			\IdiomatticWP\Hooks\LanguageHooks::class,
			fn( $c ) => new \IdiomatticWP\Hooks\LanguageHooks(
				$c->get( LanguageManager::class ),
				$c->get( UrlStrategyInterface::class ),
				$c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Hooks\RoutingHooks::class,
			fn( $c ) => new \IdiomatticWP\Hooks\RoutingHooks(
				$c->get( UrlStrategyInterface::class ),
				$c->get( LanguageManager::class ),
				$c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class )
			)
		);

		// ── Phase 3: Translation core ─────────────────────────────────────────

		$c->singleton(
			\IdiomatticWP\Infrastructure\WpdbTranslationRepository::class,
			fn( $c ) => new \IdiomatticWP\Infrastructure\WpdbTranslationRepository( $GLOBALS['wpdb'] )
		);
		$c->alias(
			\IdiomatticWP\Contracts\TranslationRepositoryInterface::class,
			\IdiomatticWP\Infrastructure\WpdbTranslationRepository::class
		);

		$c->singleton(
			\IdiomatticWP\Translation\PostDuplicator::class,
			fn( $c ) => new \IdiomatticWP\Translation\PostDuplicator()
		);

		$c->singleton(
			\IdiomatticWP\Translation\CreateTranslation::class,
			fn( $c ) => new \IdiomatticWP\Translation\CreateTranslation(
				$c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class ),
				$c->get( \IdiomatticWP\Translation\PostDuplicator::class ),
				$c->get( LanguageManager::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Translation\MarkAsOutdated::class,
			fn( $c ) => new \IdiomatticWP\Translation\MarkAsOutdated(
				$c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Hooks\Translation\PostTranslationHooks::class,
			fn( $c ) => new \IdiomatticWP\Hooks\Translation\PostTranslationHooks(
				$c->get( \IdiomatticWP\Translation\CreateTranslation::class ),
				$c->get( \IdiomatticWP\Translation\MarkAsOutdated::class ),
				$c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Hooks\Admin\PostListHooks::class,
			fn( $c ) => new \IdiomatticWP\Hooks\Admin\PostListHooks(
				$c->get( \IdiomatticWP\Core\LanguageManager::class ),
				$c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class ),
				$c->get( \IdiomatticWP\Core\CustomElementRegistry::class ),
				$c->get( \IdiomatticWP\Translation\CreateTranslation::class ),
				$c->get( \IdiomatticWP\Queue\TranslationQueue::class )
			)
		);

		// ── Phase 3b: Field system & Integration registry ─────────────────────
		// Registered early because TranslationEditor, FieldTranslator, and most
		// integrations depend on CustomElementRegistry.

		$c->singleton(
			\IdiomatticWP\Core\CustomElementRegistry::class,
			fn( $c ) => new \IdiomatticWP\Core\CustomElementRegistry()
		);

		$c->singleton(
			\IdiomatticWP\Fields\FieldClassifier::class,
			fn( $c ) => new \IdiomatticWP\Fields\FieldClassifier(
				$c->get( \IdiomatticWP\Core\CustomElementRegistry::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Translation\FieldTranslator::class,
			fn( $c ) => new \IdiomatticWP\Translation\FieldTranslator(
				$c->get( \IdiomatticWP\Core\CustomElementRegistry::class ),
				$GLOBALS['wpdb']
			)
		);

		// IntegrationRegistry: lets external plugins/themes hook in without
		// modifying this file. Booted by IntegrationLoader after all built-ins.
		$c->singleton(
			\IdiomatticWP\Core\IntegrationRegistry::class,
			fn( $c ) => new \IdiomatticWP\Core\IntegrationRegistry()
		);

		// ── Phase 4: Admin ───────────────────────────────────────────────────

		$c->singleton(
			\IdiomatticWP\Admin\Metaboxes\TranslationsMetabox::class,
			fn( $c ) => new \IdiomatticWP\Admin\Metaboxes\TranslationsMetabox(
				$c->get( \IdiomatticWP\Core\LanguageManager::class ),
				$c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Admin\Metaboxes\TranslationOriginMetabox::class,
			fn( $c ) => new \IdiomatticWP\Admin\Metaboxes\TranslationOriginMetabox(
				$c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class ),
				$c->get( \IdiomatticWP\Core\LanguageManager::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Hooks\Admin\MetaboxHooks::class,
			fn( $c ) => new \IdiomatticWP\Hooks\Admin\MetaboxHooks(
				$c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class ),
				$c->get( \IdiomatticWP\Core\LanguageManager::class ),
				$c->get( \IdiomatticWP\Admin\Metaboxes\TranslationsMetabox::class ),
				$c->get( \IdiomatticWP\Admin\Metaboxes\TranslationOriginMetabox::class ),
				$c->get( \IdiomatticWP\Core\CustomElementRegistry::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Admin\Ajax\CreateTranslationAjax::class,
			fn( $c ) => new \IdiomatticWP\Admin\Ajax\CreateTranslationAjax(
				$c->get( \IdiomatticWP\Translation\CreateTranslation::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Admin\Ajax\GetTranslationStatusAjax::class,
			fn( $c ) => new \IdiomatticWP\Admin\Ajax\GetTranslationStatusAjax(
				$c->get( \IdiomatticWP\Core\LanguageManager::class ),
				$c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Admin\Ajax\AutoTranslateAjax::class,
			fn( $c ) => new \IdiomatticWP\Admin\Ajax\AutoTranslateAjax(
				$c->get( \IdiomatticWP\Translation\AIOrchestrator::class ),
				$c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class ),
				$c->get( \IdiomatticWP\License\LicenseChecker::class ),
				$c->get( \IdiomatticWP\Translation\FieldTranslator::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Admin\Ajax\SaveFieldTranslationAjax::class,
			fn( $c ) => new \IdiomatticWP\Admin\Ajax\SaveFieldTranslationAjax(
				$c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class ),
				$c->get( \IdiomatticWP\Translation\FieldTranslator::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Admin\Ajax\ScanStringsAjax::class,
			fn( $c ) => new \IdiomatticWP\Admin\Ajax\ScanStringsAjax(
				$c->get( \IdiomatticWP\Strings\StringScanner::class ),
				$c->get( \IdiomatticWP\Repositories\StringRepository::class ),
				$c->get( \IdiomatticWP\Core\LanguageManager::class ),
				$c->get( \IdiomatticWP\Strings\LanguagePackImporter::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Admin\Ajax\RegisterStringLangAjax::class,
			fn( $c ) => new \IdiomatticWP\Admin\Ajax\RegisterStringLangAjax(
				$c->get( \IdiomatticWP\Repositories\StringRepository::class ),
				$c->get( \IdiomatticWP\Core\LanguageManager::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Admin\Ajax\AutoTranslateStringsAjax::class,
			fn( $c ) => new \IdiomatticWP\Admin\Ajax\AutoTranslateStringsAjax(
				$c->get( \IdiomatticWP\Repositories\StringRepository::class ),
				$c->get( \IdiomatticWP\Providers\ProviderFactory::class )->make(),
				$c->get( \IdiomatticWP\Core\LanguageManager::class ),
				$c->get( \IdiomatticWP\License\LicenseChecker::class ),
				$c->get( \IdiomatticWP\Strings\MoCompiler::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Admin\Ajax\LinkTranslationAjax::class,
			fn( $c ) => new \IdiomatticWP\Admin\Ajax\LinkTranslationAjax(
				$c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class ),
				$c->get( \IdiomatticWP\Core\LanguageManager::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Admin\Ajax\TranslateSingleStringAjax::class,
			fn( $c ) => new \IdiomatticWP\Admin\Ajax\TranslateSingleStringAjax(
				$c->get( \IdiomatticWP\Repositories\StringRepository::class ),
				$c->get( \IdiomatticWP\Providers\ProviderFactory::class )->make(),
				$c->get( \IdiomatticWP\Core\LanguageManager::class ),
				$c->get( \IdiomatticWP\License\LicenseChecker::class ),
				$c->get( \IdiomatticWP\Strings\MoCompiler::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Hooks\Admin\AjaxHooks::class,
			fn( $c ) => new \IdiomatticWP\Hooks\Admin\AjaxHooks(
				$c->get( \IdiomatticWP\Admin\Ajax\CreateTranslationAjax::class ),
				$c->get( \IdiomatticWP\Admin\Ajax\GetTranslationStatusAjax::class ),
				$c->get( \IdiomatticWP\Admin\Ajax\AutoTranslateAjax::class ),
				$c->get( \IdiomatticWP\Admin\Ajax\AutoTranslateStringsAjax::class ),
				$c->get( \IdiomatticWP\Admin\Ajax\SaveFieldTranslationAjax::class ),
				$c->get( \IdiomatticWP\Admin\Ajax\ScanStringsAjax::class ),
				$c->get( \IdiomatticWP\Admin\Ajax\RegisterStringLangAjax::class ),
				$c->get( \IdiomatticWP\Admin\Ajax\LinkTranslationAjax::class ),
				$c->get( \IdiomatticWP\Admin\Ajax\TranslateSingleStringAjax::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Hooks\Admin\AssetHooks::class,
			fn( $c ) => new \IdiomatticWP\Hooks\Admin\AssetHooks()
		);

		$c->singleton(
			\IdiomatticWP\Admin\Pages\TranslationEditor::class,
			fn( $c ) => new \IdiomatticWP\Admin\Pages\TranslationEditor(
				$c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class ),
				$c->get( \IdiomatticWP\Core\LanguageManager::class ),
				$c->get( \IdiomatticWP\License\LicenseChecker::class ),
				$c->get( \IdiomatticWP\Translation\FieldTranslator::class ),
				$c->get( \IdiomatticWP\Core\CustomElementRegistry::class ),
				$c->get( \IdiomatticWP\Memory\TranslationMemory::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Hooks\Translation\TranslationMemoryHooks::class,
			fn( $c ) => new \IdiomatticWP\Hooks\Translation\TranslationMemoryHooks(
				$c->get( \IdiomatticWP\Memory\TranslationMemory::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Hooks\Translation\TranslateOnPublishHooks::class,
			fn( $c ) => new \IdiomatticWP\Hooks\Translation\TranslateOnPublishHooks(
				$c->get( \IdiomatticWP\Core\LanguageManager::class ),
				$c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class ),
				$c->get( \IdiomatticWP\Translation\CreateTranslation::class ),
				$c->get( \IdiomatticWP\Queue\TranslationQueue::class ),
				$c->get( \IdiomatticWP\License\LicenseChecker::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Hooks\Admin\TranslationEditorHooks::class,
			fn( $c ) => new \IdiomatticWP\Hooks\Admin\TranslationEditorHooks(
				$c->get( \IdiomatticWP\Admin\Pages\TranslationEditor::class ),
				$c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Hooks\Admin\AdminLanguageBar::class,
			fn( $c ) => new \IdiomatticWP\Hooks\Admin\AdminLanguageBar(
				$c->get( \IdiomatticWP\Core\LanguageManager::class ),
				$c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Hooks\Admin\AdminLanguageFilter::class,
			fn( $c ) => new \IdiomatticWP\Hooks\Admin\AdminLanguageFilter(
				$c->get( \IdiomatticWP\Core\LanguageManager::class ),
				$c->get( \IdiomatticWP\Hooks\Admin\AdminLanguageBar::class ),
				$GLOBALS['wpdb'],
				$c->get( \IdiomatticWP\Core\CustomElementRegistry::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Admin\Pages\DashboardPage::class,
			fn( $c ) => new \IdiomatticWP\Admin\Pages\DashboardPage(
				$c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class ),
				$c->get( \IdiomatticWP\Core\LanguageManager::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Admin\Pages\SettingsPage::class,
			fn( $c ) => new \IdiomatticWP\Admin\Pages\SettingsPage(
				$c->get( \IdiomatticWP\Core\LanguageManager::class ),
				$c->get( \IdiomatticWP\License\LicenseChecker::class ),
				$c->get( \IdiomatticWP\Providers\ProviderRegistry::class ),
				$c->get( \IdiomatticWP\Support\EncryptionService::class ),
				$c->get( \IdiomatticWP\Core\CustomElementRegistry::class ),
				$c->get( \IdiomatticWP\Glossary\WpdbGlossaryRepository::class )
			)
		);

		// ── Phase 4b: Compatibility system ────────────────────────────────────

		$c->singleton(
			\IdiomatticWP\Compatibility\CompatibilityScanner::class,
			fn( $c ) => new \IdiomatticWP\Compatibility\CompatibilityScanner()
		);

		$c->singleton(
			\IdiomatticWP\Compatibility\WpmlConfigParser::class,
			fn( $c ) => new \IdiomatticWP\Compatibility\WpmlConfigParser(
				$c->get( \IdiomatticWP\Core\CustomElementRegistry::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Compatibility\CompatibilityXmlGenerator::class,
			fn( $c ) => new \IdiomatticWP\Compatibility\CompatibilityXmlGenerator()
		);

		$c->singleton(
			\IdiomatticWP\Repositories\StringRepository::class,
			fn( $c ) => new \IdiomatticWP\Repositories\StringRepository( $GLOBALS['wpdb'] )
		);

		$c->singleton(
			\IdiomatticWP\Admin\Pages\StringTranslationPage::class,
			fn( $c ) => new \IdiomatticWP\Admin\Pages\StringTranslationPage(
				$c->get( \IdiomatticWP\Core\LanguageManager::class ),
				$c->get( \IdiomatticWP\Repositories\StringRepository::class ),
				$c->get( \IdiomatticWP\Strings\MoCompiler::class ),
				$c->get( \IdiomatticWP\License\LicenseChecker::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Admin\Pages\CompatibilityPage::class,
			fn( $c ) => new \IdiomatticWP\Admin\Pages\CompatibilityPage(
				$c->get( \IdiomatticWP\Compatibility\CompatibilityScanner::class ),
				$c->get( \IdiomatticWP\Compatibility\CompatibilityXmlGenerator::class ),
				$c->get( \IdiomatticWP\Core\CustomElementRegistry::class ),
				$c->get( \IdiomatticWP\Compatibility\WpmlConfigParser::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Admin\Pages\ImportExportPage::class,
			fn( $c ) => new \IdiomatticWP\Admin\Pages\ImportExportPage(
				$c->get( \IdiomatticWP\Core\LanguageManager::class ),
				$c->get( \IdiomatticWP\ImportExport\Exporter::class ),
				$c->get( \IdiomatticWP\ImportExport\Importer::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Admin\Pages\ContentTranslationPage::class,
			fn( $c ) => new \IdiomatticWP\Admin\Pages\ContentTranslationPage(
				$c->get( \IdiomatticWP\Core\LanguageManager::class ),
				$c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class ),
				$c->get( \IdiomatticWP\Core\CustomElementRegistry::class ),
				$c->get( \IdiomatticWP\Translation\CreateTranslation::class ),
				$c->get( \IdiomatticWP\Queue\TranslationQueue::class ),
				$c->get( \IdiomatticWP\License\LicenseChecker::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Admin\Pages\OnboardingPage::class,
			fn( $c ) => new \IdiomatticWP\Admin\Pages\OnboardingPage(
				$c->get( \IdiomatticWP\Core\LanguageManager::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Hooks\Admin\OnboardingHooks::class,
			fn( $c ) => new \IdiomatticWP\Hooks\Admin\OnboardingHooks(
				$c->get( \IdiomatticWP\Core\LanguageManager::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Hooks\Admin\AdminMenuHooks::class,
			fn( $c ) => new \IdiomatticWP\Hooks\Admin\AdminMenuHooks(
				$c->get( \IdiomatticWP\Admin\Pages\DashboardPage::class ),
				$c->get( \IdiomatticWP\Admin\Pages\SettingsPage::class ),
				$c->get( \IdiomatticWP\Admin\Pages\CompatibilityPage::class ),
				$c->get( \IdiomatticWP\Admin\Pages\StringTranslationPage::class ),
				$c->get( \IdiomatticWP\Admin\Pages\ImportExportPage::class ),
				$c->get( \IdiomatticWP\Admin\Pages\ContentTranslationPage::class ),
				$c->get( \IdiomatticWP\Admin\Pages\OnboardingPage::class ),
				$c->get( \IdiomatticWP\Core\LanguageManager::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Hooks\Admin\SettingsHooks::class,
			fn( $c ) => new \IdiomatticWP\Hooks\Admin\SettingsHooks()
		);

		$c->singleton(
			\IdiomatticWP\Admin\Pages\SetupWizard::class,
			fn( $c ) => new \IdiomatticWP\Admin\Pages\SetupWizard(
				$c->get( \IdiomatticWP\Core\LanguageManager::class ),
				$c->get( \IdiomatticWP\License\LicenseChecker::class )
			)
		);

		// ── Phase 5: Frontend ─────────────────────────────────────────────────

		$c->singleton(
			\IdiomatticWP\Frontend\LanguageSwitcher::class,
			fn( $c ) => new \IdiomatticWP\Frontend\LanguageSwitcher(
				$c->get( \IdiomatticWP\Core\LanguageManager::class ),
				$c->get( \IdiomatticWP\Contracts\UrlStrategyInterface::class ),
				$c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Frontend\PostTranslationsDisplay::class,
			fn( $c ) => new \IdiomatticWP\Frontend\PostTranslationsDisplay(
				$c->get( \IdiomatticWP\Core\LanguageManager::class ),
				$c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Frontend\HreflangOutput::class,
			fn( $c ) => new \IdiomatticWP\Frontend\HreflangOutput(
				$c->get( \IdiomatticWP\Core\LanguageManager::class ),
				$c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class ),
				$c->get( \IdiomatticWP\Contracts\UrlStrategyInterface::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Hooks\Frontend\HreflangHooks::class,
			fn( $c ) => new \IdiomatticWP\Hooks\Frontend\HreflangHooks(
				$c->get( \IdiomatticWP\Frontend\HreflangOutput::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Hooks\Frontend\SwitcherHooks::class,
			fn( $c ) => new \IdiomatticWP\Hooks\Frontend\SwitcherHooks(
				$c->get( \IdiomatticWP\Frontend\LanguageSwitcher::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Hooks\Frontend\CanonicalHooks::class,
			fn( $c ) => new \IdiomatticWP\Hooks\Frontend\CanonicalHooks(
				$c->get( \IdiomatticWP\Contracts\UrlStrategyInterface::class ),
				$c->get( \IdiomatticWP\Core\LanguageManager::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Hooks\Frontend\FrontendAssetHooks::class,
			fn( $c ) => new \IdiomatticWP\Hooks\Frontend\FrontendAssetHooks(
				$c->get( \IdiomatticWP\Core\LanguageManager::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Hooks\Frontend\NavMenuHooks::class,
			fn( $c ) => new \IdiomatticWP\Hooks\Frontend\NavMenuHooks(
				$c->get( \IdiomatticWP\Core\LanguageManager::class ),
				$c->get( \IdiomatticWP\Contracts\UrlStrategyInterface::class ),
				$c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Hooks\Frontend\NavMenuSwitcherHooks::class,
			fn( $c ) => new \IdiomatticWP\Hooks\Frontend\NavMenuSwitcherHooks(
				$c->get( \IdiomatticWP\Frontend\LanguageSwitcher::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Hooks\Frontend\PostTranslationsDisplayHooks::class,
			fn( $c ) => new \IdiomatticWP\Hooks\Frontend\PostTranslationsDisplayHooks(
				$c->get( \IdiomatticWP\Frontend\PostTranslationsDisplay::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Hooks\Frontend\ThemeOptionsHooks::class,
			fn( $c ) => new \IdiomatticWP\Hooks\Frontend\ThemeOptionsHooks(
				$c->get( \IdiomatticWP\Core\LanguageManager::class ),
				$c->get( \IdiomatticWP\Core\CustomElementRegistry::class ),
				$c->get( \IdiomatticWP\Strings\StringTranslator::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Hooks\Frontend\ContentVisibilityHooks::class,
			fn( $c ) => new \IdiomatticWP\Hooks\Frontend\ContentVisibilityHooks(
				$GLOBALS['wpdb'],
				$c->get( \IdiomatticWP\Core\CustomElementRegistry::class )
			)
		);

		// ── Phase 6: String services ──────────────────────────────────────────

		$c->singleton(
			\IdiomatticWP\Strings\StringScanner::class,
			fn( $c ) => new \IdiomatticWP\Strings\StringScanner()
		);

		$c->singleton(
			\IdiomatticWP\Strings\StringTranslator::class,
			fn( $c ) => new \IdiomatticWP\Strings\StringTranslator( $GLOBALS['wpdb'] )
		);

		$c->singleton(
			\IdiomatticWP\Strings\PoParser::class,
			fn( $c ) => new \IdiomatticWP\Strings\PoParser()
		);

		$c->singleton(
			\IdiomatticWP\Strings\LanguagePackImporter::class,
			fn( $c ) => new \IdiomatticWP\Strings\LanguagePackImporter(
				$c->get( \IdiomatticWP\Repositories\StringRepository::class ),
				$c->get( \IdiomatticWP\Strings\PoParser::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Hooks\Admin\LanguageActivationHooks::class,
			fn( $c ) => new \IdiomatticWP\Hooks\Admin\LanguageActivationHooks(
				$c->get( \IdiomatticWP\Strings\LanguagePackImporter::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Hooks\Admin\AdminLanguagePreferenceHooks::class,
			fn( $c ) => new \IdiomatticWP\Hooks\Admin\AdminLanguagePreferenceHooks(
				$c->get( LanguageManager::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Strings\MoCompiler::class,
			fn( $c ) => new \IdiomatticWP\Strings\MoCompiler(
				$GLOBALS['wpdb']
			)
		);

		$c->singleton(
			\IdiomatticWP\Hooks\Translation\FieldTranslationHooks::class,
			fn( $c ) => new \IdiomatticWP\Hooks\Translation\FieldTranslationHooks(
				$c->get( \IdiomatticWP\Translation\FieldTranslator::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Hooks\Translation\StringTranslationHooks::class,
			fn( $c ) => new \IdiomatticWP\Hooks\Translation\StringTranslationHooks(
				$c->get( \IdiomatticWP\Core\LanguageManager::class ),
				$c->get( \IdiomatticWP\Strings\StringTranslator::class )
			)
		);

		// ── Phase 6b: Notifications & Webhooks ───────────────────────────────

		$c->singleton(
			\IdiomatticWP\Notifications\OutdatedTranslationNotifier::class,
			fn( $c ) => new \IdiomatticWP\Notifications\OutdatedTranslationNotifier()
		);

		$c->singleton(
			\IdiomatticWP\Hooks\Translation\NotificationHooks::class,
			fn( $c ) => new \IdiomatticWP\Hooks\Translation\NotificationHooks(
				$c->get( \IdiomatticWP\Notifications\OutdatedTranslationNotifier::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Webhooks\WebhookDispatcher::class,
			fn( $c ) => new \IdiomatticWP\Webhooks\WebhookDispatcher()
		);

		$c->singleton(
			\IdiomatticWP\Hooks\Translation\WebhookHooks::class,
			fn( $c ) => new \IdiomatticWP\Hooks\Translation\WebhookHooks(
				$c->get( \IdiomatticWP\Webhooks\WebhookDispatcher::class )
			)
		);

		// ── Phase 7: BYOK Providers ───────────────────────────────────────────

		$c->singleton(
			\IdiomatticWP\Support\HttpClient::class,
			fn( $c ) => new \IdiomatticWP\Support\HttpClient()
		);

		$c->singleton(
			\IdiomatticWP\Support\EncryptionService::class,
			fn( $c ) => new \IdiomatticWP\Support\EncryptionService()
		);

		$c->singleton(
			\IdiomatticWP\Providers\ProviderRegistry::class,
			fn( $c ) => new \IdiomatticWP\Providers\ProviderRegistry()
		);

		$c->singleton(
			\IdiomatticWP\Providers\ProviderFactory::class,
			fn( $c ) => new \IdiomatticWP\Providers\ProviderFactory(
				$c->get( \IdiomatticWP\Providers\ProviderRegistry::class ),
				$c->get( \IdiomatticWP\Support\HttpClient::class ),
				$c->get( \IdiomatticWP\Support\EncryptionService::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Providers\OpenAIProvider::class,
			fn( $c ) => new \IdiomatticWP\Providers\OpenAIProvider(
				$c->get( \IdiomatticWP\Support\HttpClient::class ),
				$c->get( \IdiomatticWP\Support\EncryptionService::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Providers\ClaudeProvider::class,
			fn( $c ) => new \IdiomatticWP\Providers\ClaudeProvider(
				$c->get( \IdiomatticWP\Support\HttpClient::class ),
				$c->get( \IdiomatticWP\Support\EncryptionService::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Providers\DeepLProvider::class,
			fn( $c ) => new \IdiomatticWP\Providers\DeepLProvider(
				$c->get( \IdiomatticWP\Support\HttpClient::class ),
				$c->get( \IdiomatticWP\Support\EncryptionService::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Providers\GoogleProvider::class,
			fn( $c ) => new \IdiomatticWP\Providers\GoogleProvider(
				$c->get( \IdiomatticWP\Support\HttpClient::class ),
				$c->get( \IdiomatticWP\Support\EncryptionService::class )
			)
		);

		// ── Phase 8: Translation Memory & AI ─────────────────────────────────

		$c->singleton(
			\IdiomatticWP\Memory\WpdbTranslationMemoryRepository::class,
			fn( $c ) => new \IdiomatticWP\Memory\WpdbTranslationMemoryRepository( $GLOBALS['wpdb'] )
		);

		$c->singleton(
			\IdiomatticWP\Memory\TranslationMemory::class,
			fn( $c ) => new \IdiomatticWP\Memory\TranslationMemory(
				$c->get( \IdiomatticWP\Memory\WpdbTranslationMemoryRepository::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Glossary\GlossaryManager::class,
			fn( $c ) => new \IdiomatticWP\Glossary\GlossaryManager()
		);

		$c->singleton(
			\IdiomatticWP\Translation\Segmenter::class,
			fn( $c ) => new \IdiomatticWP\Translation\Segmenter(
				$c->get( \IdiomatticWP\Core\CustomElementRegistry::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Translation\AIOrchestrator::class,
			fn( $c ) => new \IdiomatticWP\Translation\AIOrchestrator(
				$c->get( \IdiomatticWP\Translation\Segmenter::class ),
				$c->get( \IdiomatticWP\Memory\TranslationMemory::class ),
				$c->get( \IdiomatticWP\Providers\ProviderFactory::class )->make(),
				$c->get( \IdiomatticWP\Translation\FieldTranslator::class ),
				$c->get( \IdiomatticWP\Glossary\GlossaryManager::class ),
				$c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class )
			)
		);

		// ── Phase 9: Third-party integrations ─────────────────────────────────

		// REST API
		$c->singleton(
			\IdiomatticWP\Integrations\REST\RestApiIntegration::class,
			fn( $c ) => new \IdiomatticWP\Integrations\REST\RestApiIntegration(
				$c->get( \IdiomatticWP\Core\LanguageManager::class ),
				$c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class ),
				$c->get( \IdiomatticWP\License\LicenseChecker::class ),
				$c->get( \IdiomatticWP\Repositories\StringRepository::class )
			)
		);

		// Page builders
		$c->singleton(
			\IdiomatticWP\Integrations\Builders\GutenbergIntegration::class,
			fn( $c ) => new \IdiomatticWP\Integrations\Builders\GutenbergIntegration(
				$c->get( \IdiomatticWP\Frontend\LanguageSwitcher::class ),
				$c->get( \IdiomatticWP\Core\LanguageManager::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Integrations\Builders\ElementorIntegration::class,
			fn( $c ) => new \IdiomatticWP\Integrations\Builders\ElementorIntegration(
				$c->get( \IdiomatticWP\Core\CustomElementRegistry::class ),
				$c->get( \IdiomatticWP\Frontend\LanguageSwitcher::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Integrations\Builders\BeaverBuilderIntegration::class,
			fn( $c ) => new \IdiomatticWP\Integrations\Builders\BeaverBuilderIntegration(
				$c->get( \IdiomatticWP\Core\CustomElementRegistry::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Integrations\Builders\DiviIntegration::class,
			fn( $c ) => new \IdiomatticWP\Integrations\Builders\DiviIntegration(
				$c->get( \IdiomatticWP\Core\CustomElementRegistry::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Integrations\Builders\BricksIntegration::class,
			fn( $c ) => new \IdiomatticWP\Integrations\Builders\BricksIntegration(
				$c->get( \IdiomatticWP\Core\CustomElementRegistry::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Integrations\Builders\OxygenIntegration::class,
			fn( $c ) => new \IdiomatticWP\Integrations\Builders\OxygenIntegration(
				$c->get( \IdiomatticWP\Core\CustomElementRegistry::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Integrations\Builders\WPBakeryIntegration::class,
			fn( $c ) => new \IdiomatticWP\Integrations\Builders\WPBakeryIntegration(
				$c->get( \IdiomatticWP\Core\CustomElementRegistry::class )
			)
		);

		// WooCommerce
		$c->singleton(
			\IdiomatticWP\Integrations\WooCommerce\WooCommerceIntegration::class,
			fn( $c ) => new \IdiomatticWP\Integrations\WooCommerce\WooCommerceIntegration(
				$c->get( \IdiomatticWP\Core\LanguageManager::class ),
				$c->get( \IdiomatticWP\Core\CustomElementRegistry::class )
			)
		);

		// SEO plugins
		$c->singleton(
			\IdiomatticWP\Integrations\SEO\YoastIntegration::class,
			fn( $c ) => new \IdiomatticWP\Integrations\SEO\YoastIntegration(
				$c->get( \IdiomatticWP\Core\CustomElementRegistry::class ),
				$c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Integrations\SEO\RankMathIntegration::class,
			fn( $c ) => new \IdiomatticWP\Integrations\SEO\RankMathIntegration(
				$c->get( \IdiomatticWP\Core\CustomElementRegistry::class ),
				$c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class ),
				$c->get( \IdiomatticWP\Contracts\UrlStrategyInterface::class ),
				$c->get( \IdiomatticWP\Core\LanguageManager::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Integrations\SEO\AIOSEOIntegration::class,
			fn( $c ) => new \IdiomatticWP\Integrations\SEO\AIOSEOIntegration(
				$c->get( \IdiomatticWP\Core\CustomElementRegistry::class ),
				$c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class ),
				$c->get( \IdiomatticWP\Contracts\UrlStrategyInterface::class ),
				$c->get( \IdiomatticWP\Core\LanguageManager::class )
			)
		);

		// Themes
		$c->singleton(
			\IdiomatticWP\Integrations\Themes\AstraIntegration::class,
			fn( $c ) => new \IdiomatticWP\Integrations\Themes\AstraIntegration(
				$c->get( \IdiomatticWP\Core\CustomElementRegistry::class ),
				$c->get( \IdiomatticWP\Frontend\LanguageSwitcher::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Integrations\Themes\GeneratePressIntegration::class,
			fn( $c ) => new \IdiomatticWP\Integrations\Themes\GeneratePressIntegration(
				$c->get( \IdiomatticWP\Core\CustomElementRegistry::class ),
				$c->get( \IdiomatticWP\Frontend\LanguageSwitcher::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Integrations\Themes\KadenceIntegration::class,
			fn( $c ) => new \IdiomatticWP\Integrations\Themes\KadenceIntegration(
				$c->get( \IdiomatticWP\Core\CustomElementRegistry::class ),
				$c->get( \IdiomatticWP\Frontend\LanguageSwitcher::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Integrations\Themes\NeveIntegration::class,
			fn( $c ) => new \IdiomatticWP\Integrations\Themes\NeveIntegration(
				$c->get( \IdiomatticWP\Core\CustomElementRegistry::class ),
				$c->get( \IdiomatticWP\Frontend\LanguageSwitcher::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Integrations\Themes\OceanWPIntegration::class,
			fn( $c ) => new \IdiomatticWP\Integrations\Themes\OceanWPIntegration(
				$c->get( \IdiomatticWP\Core\CustomElementRegistry::class ),
				$c->get( \IdiomatticWP\Frontend\LanguageSwitcher::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Integrations\Themes\BlocksyIntegration::class,
			fn( $c ) => new \IdiomatticWP\Integrations\Themes\BlocksyIntegration(
				$c->get( \IdiomatticWP\Core\CustomElementRegistry::class ),
				$c->get( \IdiomatticWP\Frontend\LanguageSwitcher::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Integrations\Themes\AvadaIntegration::class,
			fn( $c ) => new \IdiomatticWP\Integrations\Themes\AvadaIntegration(
				$c->get( \IdiomatticWP\Core\CustomElementRegistry::class ),
				$c->get( \IdiomatticWP\Frontend\LanguageSwitcher::class )
			)
		);

		// ── Phase 9b: Migration ───────────────────────────────────────────────

		$c->singleton(
			\IdiomatticWP\Migration\WpmlDetector::class,
			fn( $c ) => new \IdiomatticWP\Migration\WpmlDetector( $GLOBALS['wpdb'] )
		);

		$c->singleton(
			\IdiomatticWP\Migration\WpmlMigrator::class,
			fn( $c ) => new \IdiomatticWP\Migration\WpmlMigrator(
				$GLOBALS['wpdb'],
				$c->get( \IdiomatticWP\Migration\WpmlDetector::class ),
				$c->get( \IdiomatticWP\Core\LanguageManager::class ),
				$c->get( \IdiomatticWP\Infrastructure\WpdbTranslationRepository::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Migration\PolylangDetector::class,
			fn( $c ) => new \IdiomatticWP\Migration\PolylangDetector( $GLOBALS['wpdb'] )
		);

		$c->singleton(
			\IdiomatticWP\Migration\PolylangMigrator::class,
			fn( $c ) => new \IdiomatticWP\Migration\PolylangMigrator(
				$GLOBALS['wpdb'],
				$c->get( \IdiomatticWP\Migration\PolylangDetector::class ),
				$c->get( \IdiomatticWP\Core\LanguageManager::class ),
				$c->get( \IdiomatticWP\Infrastructure\WpdbTranslationRepository::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Admin\Pages\WpmlMigrationPage::class,
			fn( $c ) => new \IdiomatticWP\Admin\Pages\WpmlMigrationPage(
				$c->get( \IdiomatticWP\Migration\WpmlDetector::class ),
				$c->get( \IdiomatticWP\Migration\WpmlMigrator::class ),
				$c->get( \IdiomatticWP\Migration\PolylangDetector::class ),
				$c->get( \IdiomatticWP\Migration\PolylangMigrator::class )
			)
		);

		// ── Phase 10: Auto-translate & Queue ──────────────────────────────────

		$c->singleton(
			\IdiomatticWP\Translation\AutoTranslate::class,
			fn( $c ) => new \IdiomatticWP\Translation\AutoTranslate(
				$c->get( \IdiomatticWP\Translation\AIOrchestrator::class ),
				$c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Queue\TranslationQueue::class,
			fn( $c ) => new \IdiomatticWP\Queue\TranslationQueue(
				$c->get( \IdiomatticWP\Translation\AutoTranslate::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\Hooks\Queue\QueueHooks::class,
			fn( $c ) => new \IdiomatticWP\Hooks\Queue\QueueHooks(
				$c->get( \IdiomatticWP\Queue\TranslationQueue::class ),
				$c->get( \IdiomatticWP\Translation\AutoTranslate::class )
			)
		);

		// ── Phase 11: Import/Export & Glossary ────────────────────────────────

		$c->singleton(
			\IdiomatticWP\Glossary\WpdbGlossaryRepository::class,
			fn( $c ) => new \IdiomatticWP\Glossary\WpdbGlossaryRepository( $GLOBALS['wpdb'] )
		);

		$c->singleton(
			\IdiomatticWP\ImportExport\Exporter::class,
			fn( $c ) => new \IdiomatticWP\ImportExport\Exporter(
				$c->get( \IdiomatticWP\Infrastructure\WpdbTranslationRepository::class )
			)
		);

		$c->singleton(
			\IdiomatticWP\ImportExport\Importer::class,
			fn( $c ) => new \IdiomatticWP\ImportExport\Importer(
				$c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class )
			)
		);

		// Wire glossary repository into the GlossaryManager singleton
		$glossary = $c->get( \IdiomatticWP\Glossary\GlossaryManager::class );
		$glossary->setRepository( $c->get( \IdiomatticWP\Glossary\WpdbGlossaryRepository::class ) );


		// ── CLI (WP-CLI) ──────────────────────────────────────────────

		$c->singleton(
			\IdiomatticWP\CLI\IdiomatticCommand::class,
			fn( $c ) => new \IdiomatticWP\CLI\IdiomatticCommand(
				$c->get( \IdiomatticWP\Core\LanguageManager::class ),
				$c->get( \IdiomatticWP\Contracts\TranslationRepositoryInterface::class ),
				$c->get( \IdiomatticWP\Translation\CreateTranslation::class )
			)
		);
	}
}
