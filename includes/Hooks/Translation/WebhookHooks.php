<?php
/**
 * WebhookHooks — wires WordPress translation actions to WebhookDispatcher.
 *
 * @package IdiomatticWP\Hooks\Translation
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Translation;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Webhooks\WebhookDispatcher;

class WebhookHooks implements HookRegistrarInterface {

	public function __construct( private WebhookDispatcher $dispatcher ) {}

	public function register(): void {
		add_action( 'idiomatticwp_translation_completed',      [ $this->dispatcher, 'onTranslationCompleted' ], 10, 2 );
		add_action( 'idiomatticwp_translation_marked_outdated', [ $this->dispatcher, 'onTranslationOutdated' ] );
		add_action( 'idiomatticwp_translation_job_queued',     [ $this->dispatcher, 'onTranslationQueued' ],    10, 2 );
	}
}
