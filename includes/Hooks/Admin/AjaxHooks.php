<?php
/**
 * AjaxHooks — registers WordPress AJAX handlers for all translation operations.
 *
 * @package IdiomatticWP\Hooks\Admin
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Admin;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Admin\Ajax\AssignTranslatorAjax;
use IdiomatticWP\Admin\Ajax\AutoTranslateAjax;
use IdiomatticWP\Admin\Ajax\AutoTranslateStringsAjax;
use IdiomatticWP\Admin\Ajax\CreateTranslationAjax;
use IdiomatticWP\Admin\Ajax\GetTranslationStatusAjax;
use IdiomatticWP\Admin\Ajax\LinkTranslationAjax;
use IdiomatticWP\Admin\Ajax\SaveFieldTranslationAjax;
use IdiomatticWP\Admin\Ajax\RegisterStringLangAjax;
use IdiomatticWP\Admin\Ajax\ScanStringsAjax;
use IdiomatticWP\Admin\Ajax\TranslateSingleStringAjax;

class AjaxHooks implements HookRegistrarInterface {

	public function __construct(
		private CreateTranslationAjax    $createTranslationAjax,
		private GetTranslationStatusAjax $getTranslationStatusAjax,
		private AutoTranslateAjax        $autoTranslateAjax,
		private AutoTranslateStringsAjax $autoTranslateStringsAjax,
		private SaveFieldTranslationAjax $saveFieldTranslationAjax,
		private ScanStringsAjax          $scanStringsAjax,
		private RegisterStringLangAjax   $registerStringLangAjax,
		private LinkTranslationAjax      $linkTranslationAjax,
		private TranslateSingleStringAjax $translateSingleStringAjax,
		private AssignTranslatorAjax     $assignTranslatorAjax,
	) {}

	// ── HookRegistrarInterface ────────────────────────────────────────────

	public function register(): void {
		// Translation management
		add_action( 'wp_ajax_idiomatticwp_create_translation',     [ $this, 'handleCreateTranslation'    ] );
		add_action( 'wp_ajax_idiomatticwp_get_translation_status', [ $this, 'handleGetTranslationStatus' ] );

		// AI translation (Translation Editor)
		add_action( 'wp_ajax_idiomatticwp_ai_translate_all',   [ $this, 'handleAiTranslateAll'   ] );
		add_action( 'wp_ajax_idiomatticwp_ai_translate_field', [ $this, 'handleAiTranslateField' ] );

		// Save individual translated field
		add_action( 'wp_ajax_idiomatticwp_save_field_translation', [ $this, 'handleSaveFieldTranslation' ] );

		// Scan plugin/theme for translatable strings
		add_action( 'wp_ajax_idiomatticwp_scan_strings', [ $this, 'handleScanStrings' ] );

		// Register a string for an additional target language
		add_action( 'wp_ajax_idiomatticwp_register_string_lang', [ $this, 'handleRegisterStringLang' ] );

		// AI bulk-translate pending UI strings
		add_action( 'wp_ajax_idiomatticwp_auto_translate_strings', [ $this, 'handleAutoTranslateStrings' ] );

		// Link an existing post as a translation
		add_action( 'wp_ajax_idiomatticwp_search_posts',       [ $this, 'handleSearchPosts'    ] );
		add_action( 'wp_ajax_idiomatticwp_link_translation',   [ $this, 'handleLinkTranslation'] );

		// AI translate a single UI string on demand
		add_action( 'wp_ajax_idiomatticwp_translate_single_string', [ $this, 'handleTranslateSingleString' ] );

		// Assign a translator to a translation post
		add_action( 'wp_ajax_idiomatticwp_assign_translator', [ $this, 'handleAssignTranslator' ] );
	}

	// ── Callbacks ─────────────────────────────────────────────────────────

	public function handleCreateTranslation(): void {
		$this->createTranslationAjax->handle();
	}

	public function handleGetTranslationStatus(): void {
		$this->getTranslationStatusAjax->handle();
	}

	public function handleAiTranslateAll(): void {
		$this->autoTranslateAjax->handleAll();
	}

	public function handleAiTranslateField(): void {
		$this->autoTranslateAjax->handleField();
	}

	public function handleSaveFieldTranslation(): void {
		$this->saveFieldTranslationAjax->handle();
	}

	public function handleScanStrings(): void {
		$this->scanStringsAjax->handle();
	}

	public function handleRegisterStringLang(): void {
		$this->registerStringLangAjax->handle();
	}

	public function handleAutoTranslateStrings(): void {
		$this->autoTranslateStringsAjax->handle();
	}

	public function handleSearchPosts(): void {
		$this->linkTranslationAjax->handleSearch();
	}

	public function handleLinkTranslation(): void {
		$this->linkTranslationAjax->handleLink();
	}

	public function handleTranslateSingleString(): void {
		$this->translateSingleStringAjax->handle();
	}

	public function handleAssignTranslator(): void {
		$this->assignTranslatorAjax->handle();
	}
}
