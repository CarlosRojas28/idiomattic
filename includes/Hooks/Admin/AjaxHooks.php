<?php
/**
 * AjaxHooks — registers WordPress AJAX handlers for all translation operations.
 *
 * @package IdiomatticWP\Hooks\Admin
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Admin;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Admin\Ajax\AutoTranslateAjax;
use IdiomatticWP\Admin\Ajax\CreateTranslationAjax;
use IdiomatticWP\Admin\Ajax\GetTranslationStatusAjax;
use IdiomatticWP\Admin\Ajax\SaveFieldTranslationAjax;

class AjaxHooks implements HookRegistrarInterface {

	public function __construct(
		private CreateTranslationAjax    $createTranslationAjax,
		private GetTranslationStatusAjax $getTranslationStatusAjax,
		private AutoTranslateAjax        $autoTranslateAjax,
		private SaveFieldTranslationAjax $saveFieldTranslationAjax,
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
}
