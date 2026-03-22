<?php
/**
 * TranslationEditor — full-page side-by-side translation interface.
 *
 * Loaded when accessing post.php?post={id}&action=idiomatticwp_translate.
 * Replaces the standard WordPress editor with a two-column layout:
 * source (read-only) on the left, editable translation on the right.
 *
 * Supports:
 *  - Core fields: title, content, excerpt
 *  - Custom fields registered via CustomElementRegistry (ACF, meta, etc.)
 *  - Extensible via idiomatticwp_translation_editor_fields filter
 *
 * @package IdiomatticWP\Admin\Pages
 */

declare( strict_types=1 );

namespace IdiomatticWP\Admin\Pages;

use IdiomatticWP\Contracts\TranslationRepositoryInterface;
use IdiomatticWP\Core\CustomElementRegistry;
use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\License\LicenseChecker;
use IdiomatticWP\Memory\MemoryMatch;
use IdiomatticWP\Memory\TranslationMemory;
use IdiomatticWP\Translation\FieldTranslator;
use IdiomatticWP\ValueObjects\LanguageCode;

class TranslationEditor {

	public function __construct(
		private TranslationRepositoryInterface $repository,
		private LanguageManager                $languageManager,
		private LicenseChecker                 $licenseChecker,
		private FieldTranslator                $fieldTranslator,
		private CustomElementRegistry          $registry,
		private TranslationMemory              $translationMemory,
	) {}

	// ── Public API ────────────────────────────────────────────────────────

	public function intercept(): void {
		if ( ! $this->isTranslationEditRequest() ) {
			return;
		}

		$postId = absint( $_GET['post'] ?? 0 );
		$post   = get_post( $postId );

		if ( ! $post || ! current_user_can( 'edit_post', $postId ) ) {
			wp_die( esc_html__( 'You do not have permission to edit this post.', 'idiomattic-wp' ) );
		}

		$record = $this->repository->findByTranslatedPost( $postId );
		if ( ! $record ) {
			wp_safe_redirect( get_edit_post_link( $postId, 'raw' ) );
			exit;
		}

		$sourcePost = get_post( (int) $record['source_post_id'] );
		if ( ! $sourcePost ) {
			wp_die( esc_html__( 'Original post not found.', 'idiomattic-wp' ) );
		}

		$this->maybeSave( $post, $sourcePost, $record );
		$this->render( $post, $sourcePost, $record );
		exit;
	}

	// ── Save handler ──────────────────────────────────────────────────────

	private function maybeSave( \WP_Post $translated, \WP_Post $source, array $record ): void {
		if ( empty( $_POST['idiomatticwp_te_save'] ) ) {
			return;
		}

		check_admin_referer( 'idiomatticwp_translation_editor_' . $translated->ID );

		$title   = sanitize_text_field( wp_unslash( $_POST['te_post_title']   ?? '' ) );
		// Users with unfiltered_html (site admins on single-site) may store arbitrary HTML.
		// wp_kses_post() preserves HTML comments including Gutenberg block markers
		// (<!-- wp:paragraph -->) so it is safe to apply for unprivileged users.
		$rawContent = wp_unslash( $_POST['te_post_content'] ?? '' );
		$content    = current_user_can( 'unfiltered_html' )
			? $rawContent
			: wp_kses_post( $rawContent );
		$excerpt = sanitize_textarea_field( wp_unslash( $_POST['te_post_excerpt'] ?? '' ) );
		$status  = sanitize_key( $_POST['te_post_status'] ?? $translated->post_status );

		if ( ! in_array( $status, [ 'draft', 'publish', 'pending' ], true ) ) {
			$status = $translated->post_status;
		}

		// Suppress mark-as-outdated for this programmatic update
		add_filter( 'idiomatticwp_skip_outdated_on_update', '__return_true' );

		wp_update_post( [
			'ID'           => $translated->ID,
			'post_title'   => $title,
			'post_content' => $content,
			'post_excerpt' => $excerpt,
			'post_status'  => $status,
		] );

		remove_filter( 'idiomatticwp_skip_outdated_on_update', '__return_true' );

		// Save custom fields
		$customFields = $this->getCustomFields( $source->post_type );
		foreach ( $customFields as $field ) {
			$key       = $field['key'];
			$inputName = 'te_meta_' . sanitize_key( $key );
			if ( ! isset( $_POST[ $inputName ] ) ) {
				continue;
			}
			$rawValue = wp_unslash( $_POST[ $inputName ] );
			$value    = ( $field['field_type'] ?? 'text' ) === 'html'
				? wp_kses_post( $rawValue )
				: sanitize_textarea_field( $rawValue );

			$this->fieldTranslator->saveFieldTranslation(
				(int) $record['id'],
				$key,
				$value
			);
		}

		/**
		 * Fires after the Translation Editor saves all fields.
		 *
		 * @param int      $translatedPostId
		 * @param int      $sourcePostId
		 * @param array    $record
		 * @param \WP_Post $translated
		 */
		do_action(
			'idiomatticwp_translation_editor_saved',
			$translated->ID,
			$source->ID,
			$record,
			$translated
		);

		wp_safe_redirect( add_query_arg( [
			'post'   => $translated->ID,
			'action' => 'idiomatticwp_translate',
			'saved'  => '1',
		], admin_url( 'post.php' ) ) );
		exit;
	}

	// ── Renderer ──────────────────────────────────────────────────────────

	private function render( \WP_Post $translated, \WP_Post $source, array $record ): void {
		$sourceLang    = $record['source_lang'];
		$targetLang    = $record['target_lang'];
		$status        = $record['status'];
		$translationId = (int) $record['id'];

		try {
			$sourceLangObj  = LanguageCode::from( $sourceLang );
			$targetLangObj  = LanguageCode::from( $targetLang );
			$sourceLangName = $this->languageManager->getLanguageName( $sourceLangObj );
			$targetLangName = $this->languageManager->getLanguageName( $targetLangObj );
		} catch ( \Throwable $e ) {
			$sourceLangName = strtoupper( $sourceLang );
			$targetLangName = strtoupper( $targetLang );
		}

		$sourceFlagUrl = IDIOMATTICWP_ASSETS_URL . 'flags/' . $sourceLang . '.svg';
		$targetFlagUrl = IDIOMATTICWP_ASSETS_URL . 'flags/' . $targetLang . '.svg';
		$savedNotice   = ! empty( $_GET['saved'] );
		$sourceEditUrl = get_edit_post_link( $source->ID, 'raw' );
		$listUrl       = admin_url( 'edit.php?post_type=' . $source->post_type );
		$currentStatus = $translated->post_status;
		$isPro         = $this->licenseChecker->isPro();
		$sourceHasBlocks = function_exists( 'has_blocks' ) && has_blocks( $source->post_content );
		$targetIsRtl = ! empty( $this->languageManager->getLanguageData( $targetLangObj )['rtl'] );
		$upgradeUrl    = idiomatticwp_upgrade_url( 'translation-editor' );

		// Resolve custom fields for this post type
		$customFields         = $this->getCustomFields( $source->post_type );
		$existingTranslations = $this->fieldTranslator->getFieldTranslations( $translationId );
		$existingByKey        = array_column( $existingTranslations, null, 'field_key' );

		// Pre-compute TM matches for core fields
		$tmTitle   = $source->post_title   ? $this->translationMemory->lookup( $source->post_title,   $sourceLangObj, $targetLangObj ) : null;
		$tmExcerpt = $source->post_excerpt ? $this->translationMemory->lookup( $source->post_excerpt, $sourceLangObj, $targetLangObj ) : null;

		// Segment-level TM matches for the content field
		$tmContentSegments = $this->getContentSegmentMatches( $source->post_content, $sourceLangObj, $targetLangObj );

		// Source data for JS copy operations (core fields only — custom uses data-source attributes)
		$sourceData = wp_json_encode( [
			'title'   => $source->post_title,
			'content' => $source->post_content,
			'excerpt' => $source->post_excerpt,
		] );

		// Bootstrap WP admin context
		global $post, $typenow, $pagenow, $title;
		$post    = $translated;
		$typenow = $translated->post_type;
		$pagenow = 'post.php';
		$title   = sprintf( __( 'Translate: %s', 'idiomattic-wp' ), $targetLangName );

		set_current_screen( 'post' );
		$current_screen = get_current_screen();
		if ( $current_screen ) {
			$current_screen->post_type = $translated->post_type;
		}
		setup_postdata( $translated );

		require_once ABSPATH . 'wp-admin/admin-header.php';
		?>
		<div class="wrap idiomatticwp-te-wrap<?php echo $targetIsRtl ? ' idiomatticwp-te-rtl' : ''; ?>">

			<?php if ( $savedNotice ) : ?>
			<div class="notice notice-success is-dismissible idiomatticwp-te-notice">
				<p><?php esc_html_e( 'Translation saved successfully.', 'idiomattic-wp' ); ?></p>
			</div>
			<?php endif; ?>

			<!-- ── Header ──────────────────────────────────────────────── -->
			<div class="idiomatticwp-te-header">
				<div class="idiomatticwp-te-header-left">
					<a href="<?php echo esc_url( $listUrl ); ?>" class="idiomatticwp-te-back">
						← <?php esc_html_e( 'Back to list', 'idiomattic-wp' ); ?>
					</a>
					<div class="idiomatticwp-te-lang-path">
						<span class="idiomatticwp-te-lang">
							<img src="<?php echo esc_url( $sourceFlagUrl ); ?>" alt="<?php echo esc_attr( $sourceLangName ); ?>" width="20" height="15">
							<?php echo esc_html( $sourceLangName ); ?>
						</span>
						<span class="idiomatticwp-te-arrow">→</span>
						<span class="idiomatticwp-te-lang idiomatticwp-te-lang-target">
							<img src="<?php echo esc_url( $targetFlagUrl ); ?>" alt="<?php echo esc_attr( $targetLangName ); ?>" width="20" height="15">
							<?php echo esc_html( $targetLangName ); ?>
						</span>
					</div>
					<span class="idiomatticwp-te-status-badge status-<?php echo esc_attr( $status ); ?>">
						<?php
					$statusLabels = [
						'draft'       => __( 'Draft',       'idiomattic-wp' ),
						'in_progress' => __( 'In Progress', 'idiomattic-wp' ),
						'complete'    => __( 'Complete',    'idiomattic-wp' ),
						'outdated'    => __( 'Outdated',    'idiomattic-wp' ),
						'failed'      => __( 'Failed',      'idiomattic-wp' ),
						'pending'     => __( 'Pending',     'idiomattic-wp' ),
					];
					echo esc_html( $statusLabels[ $status ] ?? ucfirst( str_replace( '_', ' ', $status ) ) );
					?>
					</span>
				</div>
				<div class="idiomatticwp-te-header-right">
					<a href="<?php echo esc_url( $sourceEditUrl ); ?>" class="button" target="_blank">
						<?php esc_html_e( 'View Original', 'idiomattic-wp' ); ?>
					</a>
					<button type="submit" form="idiomatticwp-te-form" name="te_post_status"
						value="<?php echo esc_attr( $currentStatus === 'publish' ? 'publish' : 'draft' ); ?>"
						class="button button-secondary">
						<?php esc_html_e( 'Save Draft', 'idiomattic-wp' ); ?>
					</button>
					<button type="submit" form="idiomatticwp-te-form" name="te_post_status" value="publish" class="button button-primary">
						<?php echo $currentStatus === 'publish'
							? esc_html__( 'Update', 'idiomattic-wp' )
							: esc_html__( 'Publish', 'idiomattic-wp' ); ?>
					</button>
				</div>
			</div>

			<!-- ── Column headers ──────────────────────────────────────── -->
			<div class="idiomatticwp-te-col-headers">
				<div class="idiomatticwp-te-col-header idiomatticwp-te-col-source">
					<img src="<?php echo esc_url( $sourceFlagUrl ); ?>" alt="" width="20" height="15">
					<strong><?php echo esc_html( $sourceLangName ); ?></strong>
					<span class="idiomatticwp-te-readonly-badge"><?php esc_html_e( 'Original', 'idiomattic-wp' ); ?></span>
				</div>
				<div class="idiomatticwp-te-col-header idiomatticwp-te-col-target">
					<img src="<?php echo esc_url( $targetFlagUrl ); ?>" alt="" width="20" height="15">
					<strong><?php echo esc_html( $targetLangName ); ?></strong>
					<span class="idiomatticwp-te-editing-badge"><?php esc_html_e( 'Translating', 'idiomattic-wp' ); ?></span>
				</div>
			</div>

			<!-- ── Toolbar ─────────────────────────────────────────────── -->
			<div class="idiomatticwp-te-toolbar">
				<div class="idiomatticwp-te-toolbar-group">
					<span class="idiomatticwp-te-toolbar-label"><?php esc_html_e( 'Copy from original:', 'idiomattic-wp' ); ?></span>
					<button type="button" class="button" id="idiomatticwp-copy-all">
						<span class="dashicons dashicons-admin-page"></span>
						<?php esc_html_e( 'Duplicate all', 'idiomattic-wp' ); ?>
					</button>
					<button type="button" class="button" id="idiomatticwp-copy-empty">
						<span class="dashicons dashicons-migrate"></span>
						<?php esc_html_e( 'Duplicate untranslated', 'idiomattic-wp' ); ?>
					</button>
				</div>
				<div class="idiomatticwp-te-toolbar-group">
					<span class="idiomatticwp-te-toolbar-label"><?php esc_html_e( 'AI translation:', 'idiomattic-wp' ); ?></span>
					<?php if ( $isPro ) : ?>
						<button type="button" class="button button-primary" id="idiomatticwp-ai-all">
							<span class="dashicons dashicons-translation"></span>
							<?php esc_html_e( 'Translate all', 'idiomattic-wp' ); ?>
						</button>
						<button type="button" class="button button-primary" id="idiomatticwp-ai-segment">
							<span class="dashicons dashicons-editor-spellcheck"></span>
							<?php esc_html_e( 'Translate field', 'idiomattic-wp' ); ?>
						</button>
					<?php else : ?>
						<span class="idiomatticwp-te-pro-btn">
							<span class="dashicons dashicons-translation"></span>
							<?php esc_html_e( 'Translate all', 'idiomattic-wp' ); ?>
							<span class="idiomatticwp-te-pro-badge">PRO</span>
						</span>
						<span class="idiomatticwp-te-pro-btn">
							<span class="dashicons dashicons-editor-spellcheck"></span>
							<?php esc_html_e( 'Translate field', 'idiomattic-wp' ); ?>
							<span class="idiomatticwp-te-pro-badge">PRO</span>
						</span>
						<a href="<?php echo esc_url( $upgradeUrl ); ?>" target="_blank" class="idiomatticwp-te-upgrade-link">
							<?php esc_html_e( 'Upgrade to Pro →', 'idiomattic-wp' ); ?>
						</a>
					<?php endif; ?>
				</div>
			</div><!-- .idiomatticwp-te-toolbar -->

			<?php if ( $status === 'outdated' ) $this->renderOutdatedDiff( $source, $record ); ?>

			<!-- ── Form ────────────────────────────────────────────────── -->
			<form id="idiomatticwp-te-form" method="post" action="">
				<?php wp_nonce_field( 'idiomatticwp_translation_editor_' . $translated->ID ); ?>
				<input type="hidden" name="idiomatticwp_te_save" value="1">

				<!-- ── Core: Title ─────────────────────────────────────── -->
				<?php
				$translatedTitle = $translated->post_title;
				if ( preg_match( '/^\[.*(translation pending|Needs translation).*\]/', $translatedTitle )
					|| $translatedTitle === $source->post_title ) {
					$translatedTitle = '';
				}
				$this->renderCoreField(
					__( 'Title', 'idiomattic-wp' ),
					'title',
					$source->post_title,
					$translatedTitle,
					$source->post_title,
					'input',
					$tmTitle
				); ?>

				<!-- ── Core: Content ────────────────────────────────────── -->
				<div class="idiomatticwp-te-field-group">
					<div class="idiomatticwp-te-field-label">
						<span><?php esc_html_e( 'Content', 'idiomattic-wp' ); ?></span>
					</div>
					<div class="idiomatticwp-te-field-row idiomatticwp-te-field-row-content">
						<div class="idiomatticwp-te-field idiomatticwp-te-field-source">
							<div class="idiomatticwp-te-source-value idiomatticwp-te-source-content">
								<?php echo wp_kses_post( wpautop( $source->post_content ) ); ?>
							</div>
							<?php if ( ! empty( $tmContentSegments ) ) : ?>
							<details class="idiomatticwp-te-seg-panel">
								<summary class="idiomatticwp-te-seg-summary">
									<span class="dashicons dashicons-book"></span>
									<?php printf(
										esc_html( _n( '%d segment match in TM', '%d segment matches in TM', count( $tmContentSegments ), 'idiomattic-wp' ) ),
										count( $tmContentSegments )
									); ?>
								</summary>
								<ul class="idiomatticwp-te-seg-list">
								<?php foreach ( $tmContentSegments as $seg ) : ?>
									<li class="idiomatticwp-te-seg-item">
										<span class="idiomatticwp-te-seg-score <?php echo $seg['match']->isExact() ? 'is-exact' : 'is-fuzzy'; ?>"><?php echo esc_html( $seg['match']->label() ); ?></span>
										<span class="idiomatticwp-te-seg-text"><?php echo esc_html( mb_strimwidth( $seg['segment'], 0, 80, '…' ) ); ?></span>
										<button type="button" class="button button-small idiomatticwp-te-seg-apply" data-value="<?php echo esc_attr( $seg['match']->translatedText ); ?>"><?php esc_html_e( 'Insert', 'idiomattic-wp' ); ?></button>
									</li>
								<?php endforeach; ?>
								</ul>
							</details>
							<?php endif; ?>
						</div>
						<div class="idiomatticwp-te-field idiomatticwp-te-field-target">
							<?php if ( $sourceHasBlocks ) : ?>
								<div class="notice notice-info inline" style="margin:0 0 8px;padding:6px 12px;">
									<p style="margin:0;font-size:12px;"><?php esc_html_e( 'This post uses Gutenberg blocks. Translate the text inside the block markup, but do not modify the block comment markers (<!-- wp:... -->).', 'idiomattic-wp' ); ?></p>
								</div>
								<textarea
									id="te_post_content"
									name="te_post_content"
									class="large-text code idiomatticwp-te-input"
									rows="25"
									data-field="content"
									spellcheck="false"
									style="font-family:monospace;font-size:12px;"
								><?php echo esc_textarea( $translated->post_content ); ?></textarea>
							<?php else : ?>
								<?php wp_editor( $translated->post_content, 'te_post_content', [
								'textarea_name' => 'te_post_content',
								'textarea_rows' => 20,
								'media_buttons' => false,
								'teeny'         => false,
								'quicktags'     => true,
							] ); ?>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<!-- ── Core: Excerpt ────────────────────────────────────── -->
				<?php $this->renderCoreField(
					__( 'Excerpt', 'idiomattic-wp' ),
					'excerpt',
					$source->post_excerpt ?: '',
					$translated->post_excerpt ?: '',
					$source->post_excerpt ?: __( '(optional)', 'idiomattic-wp' ),
					'textarea',
					$tmExcerpt
				); ?>

				<?php
				// ── Custom fields ────────────────────────────────────────
				foreach ( $customFields as $field ) :
					$key             = $field['key'];
					$label           = $field['label'] ?? $key;
					$fieldType       = $field['field_type'] ?? 'text';
					$sourceValue     = (string) get_post_meta( $source->ID, $key, true );
					$translatedValue = isset( $existingByKey[ $key ] )
						? (string) $existingByKey[ $key ]['translated_value']
						: $sourceValue;
					?>
					<div class="idiomatticwp-te-field-group" data-field-key="<?php echo esc_attr( $key ); ?>">
						<div class="idiomatticwp-te-field-label">
							<span><?php echo esc_html( $label ); ?></span>
							<code class="idiomatticwp-te-field-key"><?php echo esc_html( $key ); ?></code>
						</div>
						<div class="idiomatticwp-te-field-row">
							<div class="idiomatticwp-te-field idiomatticwp-te-field-source">
								<div class="idiomatticwp-te-source-value">
									<?php echo $fieldType === 'html'
										? wp_kses_post( wpautop( $sourceValue ) )
										: esc_html( $sourceValue ); ?>
								</div>
							</div>
							<div class="idiomatticwp-te-field idiomatticwp-te-field-target">
								<?php
								$inputName = 'te_meta_' . sanitize_key( $key );
								if ( $fieldType === 'html' ) :
									wp_editor( $translatedValue, sanitize_key( 'te_meta_' . $key ), [
										'textarea_name' => $inputName,
										'textarea_rows' => 8,
										'media_buttons' => false,
										'teeny'         => true,
										'quicktags'     => true,
									] );
								elseif ( $fieldType === 'textarea' ) :
									?>
									<textarea
										name="<?php echo esc_attr( $inputName ); ?>"
										rows="4"
										class="large-text idiomatticwp-te-input"
										data-field="<?php echo esc_attr( $key ); ?>"
										data-source="<?php echo esc_attr( $sourceValue ); ?>"
									><?php echo esc_textarea( $translatedValue ); ?></textarea>
								<?php else : ?>
									<input
										type="text"
										name="<?php echo esc_attr( $inputName ); ?>"
										value="<?php echo esc_attr( $translatedValue ); ?>"
										class="large-text idiomatticwp-te-input"
										data-field="<?php echo esc_attr( $key ); ?>"
										data-source="<?php echo esc_attr( $sourceValue ); ?>"
										placeholder="<?php echo esc_attr( $sourceValue ); ?>"
									>
								<?php endif; ?>
							</div>
						</div>
					</div>
				<?php endforeach; ?>

				<!-- ── Footer save bar ─────────────────────────────────── -->
				<div class="idiomatticwp-te-footer">
					<button type="submit" name="te_post_status"
						value="<?php echo esc_attr( $currentStatus === 'publish' ? 'publish' : 'draft' ); ?>"
						class="button button-secondary">
						<?php esc_html_e( 'Save Draft', 'idiomattic-wp' ); ?>
					</button>
					<button type="submit" name="te_post_status" value="publish" class="button button-primary">
						<?php echo $currentStatus === 'publish'
							? esc_html__( 'Update', 'idiomattic-wp' )
							: esc_html__( 'Publish Translation', 'idiomattic-wp' ); ?>
					</button>
					<a href="<?php echo esc_url( get_edit_post_link( $source->ID, 'raw' ) ); ?>" class="button">
						<?php esc_html_e( 'View Original', 'idiomattic-wp' ); ?>
					</a>
				</div>

			</form><!-- #idiomatticwp-te-form -->

		</div><!-- .idiomatticwp-te-wrap -->

		<style>
		/* RTL support for target language column */
		.idiomatticwp-te-rtl .idiomatticwp-te-field-target input,
		.idiomatticwp-te-rtl .idiomatticwp-te-field-target textarea,
		.idiomatticwp-te-rtl .idiomatticwp-te-field-target .wp-editor-area {
			direction: rtl;
			text-align: right;
		}

		.idiomatticwp-te-tm-match {
			display: flex;
			align-items: center;
			gap: 8px;
			margin-bottom: 6px;
			padding: 5px 8px;
			background: #f0f6fc;
			border-left: 3px solid #2271b1;
			border-radius: 0 3px 3px 0;
			font-size: 12px;
		}
		.idiomatticwp-te-tm-score {
			font-weight: 600;
			color: #2271b1;
			white-space: nowrap;
		}
		.idiomatticwp-te-tm-text {
			flex: 1;
			color: #3c434a;
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
		}
		
		.idiomatticwp-te-diff-panel {
			margin: 0 0 16px;
			border: 1px solid #f0b849;
			border-radius: 4px;
			background: #fffdf0;
		}
		.idiomatticwp-te-diff-summary {
			display: flex;
			align-items: center;
			gap: 6px;
			padding: 10px 14px;
			font-weight: 600;
			cursor: pointer;
			list-style: none;
			color: #7a5900;
		}
		.idiomatticwp-te-diff-summary::-webkit-details-marker { display: none; }
		.idiomatticwp-te-diff-summary .dashicons { color: #b07800; }
		.idiomatticwp-te-diff-body {
			padding: 0 14px 14px;
		}
		.idiomatticwp-te-diff-legend {
			margin: 0 0 10px;
			font-size: 12px;
			color: #7a5900;
		}
		.idiomatticwp-te-diff-field {
			margin-bottom: 12px;
		}
		.idiomatticwp-te-diff-field-label {
			display: block;
			margin-bottom: 4px;
			font-size: 11px;
			text-transform: uppercase;
			letter-spacing: .04em;
			color: #7a5900;
		}
		.idiomatticwp-te-diff-field .diff {
			width: 100%;
			border-collapse: collapse;
			font-size: 13px;
			font-family: monospace;
		}
		.idiomatticwp-te-diff-field .diff td {
			padding: 2px 6px;
			vertical-align: top;
			white-space: pre-wrap;
			word-break: break-word;
		}
		.idiomatticwp-te-diff-field .diff del,
		.idiomatticwp-te-diff-field .diff ins {
			text-decoration: none;
			border-radius: 2px;
			padding: 0 1px;
		}
		.idiomatticwp-te-diff-field .diff .diff-deletedline td { background: #ffeef0; }
		.idiomatticwp-te-diff-field .diff .diff-addedline td   { background: #e6ffed; }
		.idiomatticwp-te-diff-field .diff del { background: #fdb8c0; }
		.idiomatticwp-te-diff-field .diff ins { background: #acf2bd; }

		/* ── Segment TM panel ─────────────────────────────── */
		.idiomatticwp-te-seg-panel {
			margin-top: 8px;
			border: 1px solid #d1d9e3;
			border-radius: 4px;
			background: #f9fbfe;
			font-size: 12px;
		}
		.idiomatticwp-te-seg-summary {
			display: flex;
			align-items: center;
			gap: 5px;
			padding: 6px 10px;
			cursor: pointer;
			list-style: none;
			font-weight: 600;
			color: #3c434a;
		}
		.idiomatticwp-te-seg-summary::-webkit-details-marker { display: none; }
		.idiomatticwp-te-seg-summary .dashicons { font-size: 14px; width: 14px; height: 14px; color: #2271b1; }
		.idiomatticwp-te-seg-list {
			margin: 0;
			padding: 0 0 6px;
			list-style: none;
		}
		.idiomatticwp-te-seg-item {
			display: flex;
			align-items: center;
			gap: 6px;
			padding: 4px 10px;
			border-top: 1px solid #edf0f3;
		}
		.idiomatticwp-te-seg-score {
			flex-shrink: 0;
			padding: 1px 5px;
			border-radius: 3px;
			font-weight: 600;
			font-size: 11px;
			white-space: nowrap;
		}
		.idiomatticwp-te-seg-score.is-exact { background: #d1fae5; color: #065f46; }
		.idiomatticwp-te-seg-score.is-fuzzy { background: #e0f2fe; color: #0c4a6e; }
		.idiomatticwp-te-seg-text {
			flex: 1;
			color: #3c434a;
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
		}
</style>
		<script>
		(function() {
			'use strict';

			// Source values for core fields (passed from PHP)
			var source = <?php echo $sourceData; ?>;

			// ── Helpers ──────────────────────────────────────────────────

			function getFieldValue( field ) {
				if ( field === 'content' ) {
					if ( typeof tinyMCE !== 'undefined' ) {
						var ed = tinyMCE.get( 'te_post_content' );
						if ( ed && !ed.isHidden() ) return ed.getContent();
					}
					var ta = document.getElementById( 'te_post_content' );
					return ta ? ta.value : '';
				}
				var el = document.querySelector( '[data-field="' + field + '"]' );
				return el ? el.value : '';
			}

			function setFieldValue( field, value ) {
				if ( field === 'content' ) {
					if ( typeof tinyMCE !== 'undefined' ) {
						var ed = tinyMCE.get( 'te_post_content' );
						if ( ed && !ed.isHidden() ) {
							ed.setContent( value );
							ed.fire( 'change' );
							return;
						}
					}
					var ta = document.getElementById( 'te_post_content' );
					if ( ta ) ta.value = value;
					return;
				}
				var el = document.querySelector( '[data-field="' + field + '"]' );
				if ( el ) {
					el.value = value;
					el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				}
			}

			function isUntranslated( field ) {
				var val = ( getFieldValue( field ) || '' ).trim();
				return val === '' || val.indexOf( '[Needs translation]' ) === 0;
			}

			function flash( field ) {
				var selector = ( field === 'content' ) ? '.wp-editor-wrap' : '[data-field="' + field + '"]';
				var el = document.querySelector( selector );
				if ( !el ) return;
				el.style.transition = 'box-shadow .15s';
				el.style.boxShadow  = '0 0 0 3px #46b450';
				setTimeout( function() { el.style.boxShadow = ''; }, 800 );
			}

			function getAllFields() {
				var fields = [ 'title', 'content', 'excerpt' ];
				document.querySelectorAll( '[data-field]' ).forEach( function( el ) {
					var f = el.getAttribute( 'data-field' );
					if ( fields.indexOf( f ) === -1 ) fields.push( f );
				} );
				return fields;
			}

			function getSourceForField( field ) {
				if ( source.hasOwnProperty( field ) ) return source[ field ];
				var el = document.querySelector( '[data-field="' + field + '"]' );
				return el ? ( el.getAttribute( 'data-source' ) || '' ) : '';
			}

			// ── Unsaved changes guard ─────────────────────────────────────

		var formDirty = false;
		var teForm = document.getElementById( 'idiomatticwp-te-form' );
		if ( teForm ) {
			teForm.addEventListener( 'input', function() { formDirty = true; } );
			teForm.addEventListener( 'change', function() { formDirty = true; } );
			teForm.addEventListener( 'submit', function() { formDirty = false; } );
		}
		window.addEventListener( 'beforeunload', function( e ) {
			if ( ! formDirty ) { return; }
			e.preventDefault();
			e.returnValue = '';
		} );

		// Mark dirty when TinyMCE content changes
		if ( typeof tinyMCE !== 'undefined' ) {
			document.addEventListener( 'DOMContentLoaded', function() {
				var ed = tinyMCE.get( 'te_post_content' );
				if ( ed ) {
					ed.on( 'input change keyup', function() { formDirty = true; } );
				}
			} );
		}

		// Disable dirty flag when any submit button is clicked (for header buttons)
		document.querySelectorAll( '[form="idiomatticwp-te-form"][type="submit"]' ).forEach( function( btn ) {
			btn.addEventListener( 'click', function() { formDirty = false; } );
		} );

		// ── TM Apply ─────────────────────────────────────────────────

			document.addEventListener('click', function(e) {
				var btn = e.target.closest('.idiomatticwp-te-tm-apply');
				if (!btn) return;
				var match = btn.closest('.idiomatticwp-te-tm-match');
				var field = match.getAttribute('data-field');
				var value = match.getAttribute('data-value');
				if (field && value !== null) {
					setFieldValue(field, value);
					flash(field);
				}
			});

			// ── Segment TM Insert ─────────────────────────────────────────

			document.addEventListener('click', function(e) {
				var btn = e.target.closest('.idiomatticwp-te-seg-apply');
				if (!btn) return;
				var value = btn.getAttribute('data-value') || '';
				// Insert at TinyMCE cursor if editor is active, otherwise append to textarea
				if (typeof tinyMCE !== 'undefined') {
					var ed = tinyMCE.get('te_post_content');
					if (ed && !ed.isHidden()) {
						ed.execCommand('mceInsertContent', false, value + ' ');
						return;
					}
				}
				var ta = document.getElementById('te_post_content');
				if (ta) { ta.value += (ta.value ? ' ' : '') + value; }
			});

			// ── Duplicate All ────────────────────────────────────────────

			var btnAll = document.getElementById( 'idiomatticwp-copy-all' );
			if ( btnAll ) {
				btnAll.addEventListener( 'click', function() {
					if ( !confirm( '<?php echo esc_js( __( 'This will overwrite all fields with the original content. Continue?', 'idiomattic-wp' ) ); ?>' ) ) return;
					getAllFields().forEach( function( f ) {
						setFieldValue( f, getSourceForField( f ) );
						flash( f );
					} );
				} );
			}

			// ── Duplicate Untranslated ────────────────────────────────────

			var btnEmpty = document.getElementById( 'idiomatticwp-copy-empty' );
			if ( btnEmpty ) {
				btnEmpty.addEventListener( 'click', function() {
					var copied = 0;
					getAllFields().forEach( function( f ) {
						if ( isUntranslated( f ) ) {
							setFieldValue( f, getSourceForField( f ) );
							flash( f );
							copied++;
						}
					} );
					if ( copied === 0 ) {
						alert( '<?php echo esc_js( __( 'All fields already have a translation.', 'idiomattic-wp' ) ); ?>' );
					}
				} );
			}

			<?php if ( $isPro ) : ?>
			// ── AI handlers (Pro only) ────────────────────────────────────

			var ajaxUrl   = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var ajaxNonce = <?php echo wp_json_encode( wp_create_nonce( 'idiomatticwp_nonce' ) ); ?>;
			var postId    = <?php echo (int) $translated->ID; ?>;

			function setLoading( btn, loading ) {
				btn.disabled = loading;
				btn.classList.toggle( 'idiomatticwp-te-loading', loading );
			}

			function showError( msg ) {
				alert( '<?php echo esc_js( __( 'AI error: ', 'idiomattic-wp' ) ); ?>' + msg );
			}

			// AI: translate ALL fields
			var btnAiAll = document.getElementById( 'idiomatticwp-ai-all' );
			if ( btnAiAll ) {
				btnAiAll.addEventListener( 'click', function() {
					setLoading( btnAiAll, true );
					fetch( ajaxUrl, {
						method: 'POST',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body: new URLSearchParams( {
							action: 'idiomatticwp_ai_translate_all',
							nonce:   ajaxNonce,
							post_id: postId
						} )
					} )
					.then( function( r ) { return r.json(); } )
					.then( function( data ) {
						if ( ! data.success ) {
							showError( data.data.message );
							return;
						}
						var f = data.data;
						// Core fields
						if ( f.title )   { setFieldValue( 'title',   f.title );   flash( 'title' ); }
						if ( f.content ) { setFieldValue( 'content', f.content ); flash( 'content' ); }
						if ( f.excerpt ) { setFieldValue( 'excerpt', f.excerpt ); flash( 'excerpt' ); }
						// Custom fields
						if ( f.custom_fields && typeof f.custom_fields === 'object' ) {
							Object.keys( f.custom_fields ).forEach( function( key ) {
								setFieldValue( key, f.custom_fields[ key ] );
								flash( key );
							} );
						}
					} )
					.catch( function( e ) { showError( e.message ); } )
					.finally( function() { setLoading( btnAiAll, false ); } );
				} );
			}

			// AI: translate FOCUSED field
			var activeField = 'title';
			document.querySelectorAll( '[data-field]' ).forEach( function( el ) {
				el.addEventListener( 'focus', function() {
					activeField = el.getAttribute( 'data-field' );
				} );
			} );
			if ( typeof tinyMCE !== 'undefined' ) {
				document.addEventListener( 'DOMContentLoaded', function() {
					var ed = tinyMCE.get( 'te_post_content' );
					if ( ed ) {
						ed.on( 'focus', function() { activeField = 'content'; } );
					}
				} );
			}

			var btnAiSeg = document.getElementById( 'idiomatticwp-ai-segment' );
			if ( btnAiSeg ) {
				btnAiSeg.addEventListener( 'click', function() {
					setLoading( btnAiSeg, true );
					fetch( ajaxUrl, {
						method: 'POST',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body: new URLSearchParams( {
							action: 'idiomatticwp_ai_translate_field',
							nonce:   ajaxNonce,
							post_id: postId,
							field:   activeField
						} )
					} )
					.then( function( r ) { return r.json(); } )
					.then( function( data ) {
						if ( data.success ) {
							setFieldValue( activeField, data.data.value );
							flash( activeField );
						} else {
							showError( data.data.message );
						}
					} )
					.catch( function( e ) { showError( e.message ); } )
					.finally( function() { setLoading( btnAiSeg, false ); } );
				} );
			}
			<?php endif; ?>

		})();
		</script>

		<?php
		require_once ABSPATH . 'wp-admin/admin-footer.php';
	}

	// ── Private helpers ───────────────────────────────────────────────────

	// ── Outdated diff ────────────────────────────────────────────────────

	/**
	 * Render a collapsible diff panel showing what changed in the source post
	 * since the translation was last completed.
	 *
	 * Uses WordPress post revisions to find the snapshot at translated_at (UTC).
	 * Falls back silently when revisions are unavailable.
	 */
	private function renderOutdatedDiff( \WP_Post $source, array $record ): void {
		$translatedAt = $record['translated_at'] ?? null;
		if ( ! $translatedAt ) {
			return;
		}

		// Revisions are stored newest-first; post_modified_gmt is UTC.
		$revisions = wp_get_post_revisions( $source->ID, [ 'order' => 'DESC', 'orderby' => 'date' ] );
		if ( empty( $revisions ) ) {
			return;
		}

		// Find the newest revision that is at or before translated_at.
		$snapshot = null;
		foreach ( $revisions as $rev ) {
			if ( $rev->post_modified_gmt <= $translatedAt ) {
				$snapshot = $rev;
				break;
			}
		}
		// All revisions are newer — use the earliest available as the baseline.
		if ( ! $snapshot ) {
			$snapshot = end( $revisions );
		}

		// Build diffs for translatable core fields.
		$diffs = [];
		foreach ( [
			'title'   => [ $snapshot->post_title,                        $source->post_title   ],
			'content' => [ wp_strip_all_tags( $snapshot->post_content ), wp_strip_all_tags( $source->post_content ) ],
			'excerpt' => [ $snapshot->post_excerpt,                      $source->post_excerpt ],
		] as $field => [ $old, $new ] ) {
			if ( $old === $new ) {
				continue;
			}
			$diff = wp_text_diff( $old, $new, [ 'show_split_view' => false ] );
			if ( ! $diff ) {
				continue;
			}
			$diffs[] = [
				'label' => match ( $field ) {
					'title'   => __( 'Title',   'idiomattic-wp' ),
					'content' => __( 'Content', 'idiomattic-wp' ),
					'excerpt' => __( 'Excerpt', 'idiomattic-wp' ),
				},
				'diff' => $diff,
			];
		}

		if ( empty( $diffs ) ) {
			return;
		}

		$timeAgo = human_time_diff( strtotime( $translatedAt . ' UTC' ) );
		?>
		<details class="idiomatticwp-te-diff-panel" open>
			<summary class="idiomatticwp-te-diff-summary">
				<span class="dashicons dashicons-editor-code"></span>
				<?php esc_html_e( 'Changes in the source post since last translation', 'idiomattic-wp' ); ?>
			</summary>
			<div class="idiomatticwp-te-diff-body">
				<p class="idiomatticwp-te-diff-legend">
					<?php
					printf(
						/* translators: %s: human-readable time e.g. "3 hours" */
						esc_html__( 'Showing what changed in the last %s. Red = removed, green = added.', 'idiomattic-wp' ),
						esc_html( $timeAgo )
					);
					?>
				</p>
				<?php foreach ( $diffs as $item ) : ?>
				<div class="idiomatticwp-te-diff-field">
					<strong class="idiomatticwp-te-diff-field-label"><?php echo esc_html( $item['label'] ); ?></strong>
					<?php echo $item['diff']; // wp_text_diff output is safe HTML ?>
				</div>
				<?php endforeach; ?>
			</div>
		</details>
		<?php
	}

	// ── Core field renderer ───────────────────────────────────────────────

	/**
	 * Render a simple core field (title or excerpt) in the two-column layout.
	 */
	private function renderCoreField(
		string $label,
		string $field,
		string $sourceValue,
		string $targetValue,
		string $placeholder,
		string $inputType,
		?\IdiomatticWP\Memory\MemoryMatch $tmMatch = null
	): void {
		$inputId   = 'te_post_' . $field;
		$inputName = 'te_post_' . $field;
		?>
		<div class="idiomatticwp-te-field-group">
			<div class="idiomatticwp-te-field-label">
				<span><?php echo esc_html( $label ); ?></span>
			</div>
			<div class="idiomatticwp-te-field-row">
				<div class="idiomatticwp-te-field idiomatticwp-te-field-source">
					<div class="idiomatticwp-te-source-value">
						<?php echo $sourceValue
							? esc_html( $sourceValue )
							: '<em class="idiomatticwp-te-empty">' . esc_html__( '(empty)', 'idiomattic-wp' ) . '</em>'; ?>
					</div>
				</div>
				<div class="idiomatticwp-te-field idiomatticwp-te-field-target">
					<?php if ( $tmMatch && $tmMatch->score >= 70 ) : ?>
					<div class="idiomatticwp-te-tm-match"
					     data-field="<?php echo esc_attr( $field ); ?>"
					     data-value="<?php echo esc_attr( $tmMatch->translatedText ); ?>">
						<span class="idiomatticwp-te-tm-score"><?php echo esc_html( $tmMatch->label() ); ?></span>
						<span class="idiomatticwp-te-tm-text"><?php echo esc_html( $tmMatch->translatedText ); ?></span>
						<button type="button" class="button button-small idiomatticwp-te-tm-apply">
							<?php esc_html_e( 'Apply', 'idiomattic-wp' ); ?>
						</button>
					</div>
					<?php endif; ?>
					<?php if ( $inputType === 'textarea' ) : ?>
						<textarea
							id="<?php echo esc_attr( $inputId ); ?>"
							name="<?php echo esc_attr( $inputName ); ?>"
							rows="4"
							class="large-text idiomatticwp-te-input"
							data-field="<?php echo esc_attr( $field ); ?>"
							placeholder="<?php echo esc_attr( $placeholder ); ?>"
						><?php echo esc_textarea( $targetValue ); ?></textarea>
					<?php else : ?>
						<input
							type="text"
							id="<?php echo esc_attr( $inputId ); ?>"
							name="<?php echo esc_attr( $inputName ); ?>"
							value="<?php echo esc_attr( $targetValue ); ?>"
							class="large-text idiomatticwp-te-input"
							data-field="<?php echo esc_attr( $field ); ?>"
							placeholder="<?php echo esc_attr( $placeholder ); ?>"
						>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Split post content into plain-text segments (paragraphs) and look each up
	 * in the Translation Memory. Returns an array of ['segment' => string, 'match' => MemoryMatch].
	 *
	 * @param string       $content    Raw post content (may contain HTML).
	 * @param LanguageCode $sourceLang
	 * @param LanguageCode $targetLang
	 * @return array<int, array{segment: string, match: \IdiomatticWP\Memory\MemoryMatch}>
	 */
	private function getContentSegmentMatches( string $content, LanguageCode $sourceLang, LanguageCode $targetLang ): array {
		if ( ! $content ) {
			return [];
		}

		// Convert HTML to plain text, then split on paragraph/line boundaries.
		$plain    = wp_strip_all_tags( $content );
		$segments = array_values( array_filter(
			array_map( 'trim', preg_split( '/\n{2,}/', $plain ) ?? [] )
		) );

		$results = [];
		foreach ( $segments as $segment ) {
			if ( mb_strlen( $segment ) < 10 ) {
				continue; // Skip very short segments (headings, single words, etc.)
			}
			$match = $this->translationMemory->lookup( $segment, $sourceLang, $targetLang );
			if ( $match ) {
				$results[] = [ 'segment' => $segment, 'match' => $match ];
			}
		}

		return $results;
	}

	/**
	 * Get the list of custom fields to display for a post type.
	 *
	 * Excludes core WP fields. Filterable so plugins/themes can add or remove fields.
	 *
	 * @param string $postType
	 * @return array[] Each item: ['key', 'label', 'field_type', 'mode']
	 */
	private function getCustomFields( string $postType ): array {
		$coreKeys   = [ 'post_title', 'post_content', 'post_excerpt', '*' ];
		$registered = $this->registry->getFieldsForPostType( $postType );
		$fields     = [];

		foreach ( $registered as $field ) {
			$key  = $field['key'] ?? '';
			$mode = $field['mode'] ?? 'translate';

			if ( in_array( $key, $coreKeys, true ) || $mode !== 'translate' ) {
				continue;
			}

			$fields[] = [
				'key'        => $key,
				'label'      => $field['label'] ?? $key,
				'field_type' => $field['field_type'] ?? 'text',
				'mode'       => $mode,
			];
		}

		/**
		 * Filter the custom fields shown in the Translation Editor.
		 *
		 * Use this to add, remove, or reorder fields without modifying plugin code.
		 *
		 * @param array[] $fields   Array of field definitions.
		 * @param string  $postType The current post type.
		 */
		return (array) apply_filters( 'idiomatticwp_translation_editor_fields', $fields, $postType );
	}

	private function isTranslationEditRequest(): bool {
		return is_admin()
			&& ( $_GET['action'] ?? '' ) === 'idiomatticwp_translate'
			&& ! empty( $_GET['post'] );
	}
}
