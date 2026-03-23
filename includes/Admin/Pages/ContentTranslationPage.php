<?php
/**
 * ContentTranslationPage — centralised bulk content translation hub.
 *
 * Displays one card per translatable post type. Each card lists every
 * target language with the count of published posts that still have no
 * translation, plus a "Queue AI translation" button to create and dispatch
 * translation jobs for that post type × language combination.
 *
 * A "Translate all missing content" button at the top queues every
 * untranslated combination in a single action.
 *
 * Processing is capped at 500 posts per post-type × language pair per
 * request. Click again to pick up the next batch (already-created
 * translation records are skipped automatically by CreateTranslation).
 *
 * @package IdiomatticWP\Admin\Pages
 */

declare( strict_types=1 );

namespace IdiomatticWP\Admin\Pages;

use IdiomatticWP\Contracts\TranslationRepositoryInterface;
use IdiomatticWP\Core\CustomElementRegistry;
use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\License\LicenseChecker;
use IdiomatticWP\Queue\BulkTranslationBatch;

class ContentTranslationPage {

	/** Maximum untranslated post IDs fetched per post-type × language per click. */
	private const BATCH_LIMIT = 500;

	public function __construct(
		private LanguageManager                $languageManager,
		private TranslationRepositoryInterface $repository,
		private CustomElementRegistry          $registry,
		private BulkTranslationBatch           $bulkBatch,
		private LicenseChecker                 $licenseChecker,
	) {}

	// ── Entry point ───────────────────────────────────────────────────────

	public function render(): void {
		$queued  = null;
		$queuedN = isset( $_GET['iwp_ct_queued'] ) ? (int) $_GET['iwp_ct_queued'] : -1;

		if ( ! empty( $_POST['iwp_ct_nonce'] ) ) {
			$queued = $this->handleQueue();
			// Redirect to avoid form re-submission on reload
			if ( $queued !== null ) {
				wp_safe_redirect( add_query_arg( 'iwp_ct_queued', $queued, admin_url( 'admin.php?page=idiomatticwp-content' ) ) );
				exit;
			}
		}

		// Cancel action (clears the persistent queue)
		if ( isset( $_GET['iwp_cancel_bulk'] ) && check_admin_referer( 'iwp_cancel_bulk' ) ) {
			if ( current_user_can( 'manage_options' ) ) {
				$this->bulkBatch->cancel();
			}
			wp_safe_redirect( admin_url( 'admin.php?page=idiomatticwp-content' ) );
			exit;
		}

		$defaultLang = (string) $this->languageManager->getDefaultLanguage();
		$targetLangs = array_values( array_filter(
			array_map( 'strval', $this->languageManager->getActiveLanguages() ),
			fn( $l ) => $l !== $defaultLang
		) );

		$postTypes = array_values( array_filter(
			$this->getTranslatablePostTypes(),
			function ( $pt ) {
				$counts = (array) wp_count_posts( $pt );
				unset( $counts['trash'], $counts['auto-draft'] );
				return array_sum( $counts ) > 0;
			}
		) );
		$langData  = $this->languageManager->getAllSupportedLanguages();

		// Pre-compute matrix: post_type → lang → missing count
		$matrix     = [];
		$totalMissing = 0;
		foreach ( $postTypes as $ptSlug ) {
			$matrix[ $ptSlug ] = [];
			foreach ( $targetLangs as $lang ) {
				$count = $this->repository->countUntranslatedByPostTypeAndLang( $ptSlug, $lang );
				$matrix[ $ptSlug ][ $lang ] = $count;
				$totalMissing += $count;
			}
		}

		$isPro      = $this->licenseChecker->isPro();
		$nonce      = wp_create_nonce( 'iwp_ct_nonce' );
		$queueDepth = $this->bulkBatch->count();

		?>
		<div class="wrap iwp-ct-wrap">

			<?php /* ── Page header ──────────────────────────────────────── */ ?>
			<div class="iwp-page-header">
				<div class="iwp-page-header__text">
					<h1 class="iwp-page-title"><?php esc_html_e( 'Content Translation', 'idiomattic-wp' ); ?></h1>
					<p class="iwp-page-subtitle"><?php esc_html_e( 'Translate posts, pages, and custom content into all active languages', 'idiomattic-wp' ); ?></p>
				</div>
				<div class="iwp-page-header__actions">
					<?php if ( $totalMissing > 0 && $queueDepth === 0 ) : ?>
						<?php if ( $isPro ) : ?>
							<form method="post">
								<input type="hidden" name="iwp_ct_nonce"     value="<?php echo esc_attr( $nonce ); ?>">
								<input type="hidden" name="iwp_ct_post_type" value="">
								<input type="hidden" name="iwp_ct_lang"      value="">
								<button type="submit" class="iwp-btn iwp-btn--primary">
									<span class="dashicons dashicons-translation"></span>
									<?php
									printf(
										/* translators: %d: total untranslated count */
										esc_html__( 'Translate all missing (%d)', 'idiomattic-wp' ),
										$totalMissing
									);
									?>
								</button>
							</form>
						<?php else : ?>
							<span class="iwp-btn iwp-btn--primary iwp-pro-gate" title="<?php esc_attr_e( 'Requires Pro licence', 'idiomattic-wp' ); ?>">
								<span class="dashicons dashicons-translation"></span>
								<?php esc_html_e( 'Translate all missing', 'idiomattic-wp' ); ?>
								<span class="iwp-pro-badge">PRO</span>
							</span>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			</div>

			<?php /* ── Bulk queue progress banner ────────────────────────── */ ?>
			<?php if ( $queueDepth > 0 || $queuedN > 0 ) : ?>
				<?php
				$cancelUrl = wp_nonce_url(
					admin_url( 'admin.php?page=idiomatticwp-content&iwp_cancel_bulk=1' ),
					'iwp_cancel_bulk'
				);
				?>
				<div class="iwp-bulk-banner" id="iwp-bulk-banner">
					<div class="iwp-bulk-banner__icon">
						<span class="dashicons dashicons-update iwp-spin"></span>
					</div>
					<div class="iwp-bulk-banner__body">
						<strong><?php esc_html_e( 'Bulk translation in progress', 'idiomattic-wp' ); ?></strong>
						<span class="iwp-bulk-banner__count">
							— <span id="iwp-bulk-pending"><?php echo esc_html( number_format_i18n( $queueDepth ) ); ?></span>
							<?php esc_html_e( 'jobs remaining', 'idiomattic-wp' ); ?>
							(<?php printf(
								/* translators: %d: batch size */
								esc_html__( '%d per batch · every ~10 s', 'idiomattic-wp' ),
								$this->bulkBatch->getBatchSize()
							); ?>)
						</span>
					</div>
					<div class="iwp-bulk-banner__actions">
						<a href="<?php echo esc_url( $cancelUrl ); ?>"
						   class="iwp-bulk-cancel-btn"
						   onclick="return confirm('<?php echo esc_js( __( 'Cancel the remaining translation jobs?', 'idiomattic-wp' ) ); ?>');">
							<?php esc_html_e( 'Cancel queue', 'idiomattic-wp' ); ?>
						</a>
					</div>
				</div>
			<?php elseif ( $queuedN === 0 ) : ?>
				<div class="notice notice-info is-dismissible">
					<p><?php esc_html_e( 'All content already has translations for the selected languages.', 'idiomattic-wp' ); ?></p>
				</div>
			<?php endif; ?>

			<?php /* ── Empty state ─────────────────────────────────────── */ ?>
			<?php if ( empty( $targetLangs ) ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'Please configure at least two active languages in Settings → Languages before using Content Translation.', 'idiomattic-wp' ); ?></p>
				</div>
				<?php return; ?>
			<?php endif; ?>

			<?php if ( empty( $postTypes ) ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'No translatable post types configured. Go to Settings → Content and set at least one post type to "Translatable".', 'idiomattic-wp' ); ?></p>
				</div>
				<?php return; ?>
			<?php endif; ?>

			<?php /* ── All complete state ──────────────────────────────── */ ?>
			<?php if ( $totalMissing === 0 ) : ?>
				<div class="iwp-card iwp-ct-complete">
					<span class="iwp-ct-complete__icon dashicons dashicons-yes-alt"></span>
					<div>
						<strong><?php esc_html_e( 'All content is fully translated!', 'idiomattic-wp' ); ?></strong>
						<p class="iwp-section-desc"><?php esc_html_e( 'Every published post has a translation in every active language. Check back after publishing new content.', 'idiomattic-wp' ); ?></p>
					</div>
				</div>
			<?php endif; ?>

			<?php /* ── Post type cards ─────────────────────────────────── */ ?>
			<?php foreach ( $postTypes as $ptSlug ) : ?>
				<?php
				$ptObj      = get_post_type_object( $ptSlug );
				$ptLabel    = $ptObj ? $ptObj->labels->name : $ptSlug;
				$ptMissing  = array_sum( $matrix[ $ptSlug ] );
				$langsWithGap = array_filter( $matrix[ $ptSlug ], fn( $c ) => $c > 0 );
				?>
				<div class="iwp-card iwp-ct-card iwp-dashboard-section">

					<?php /* Card header */ ?>
					<div class="iwp-ct-card__header">
						<div class="iwp-ct-card__title-group">
							<h2 class="iwp-section-title"><?php echo esc_html( $ptLabel ); ?></h2>
							<?php if ( $ptMissing > 0 ) : ?>
								<span class="iwp-ct-missing-badge">
									<?php
									printf(
										/* translators: %d: untranslated count */
										esc_html( _n( '%d missing', '%d missing', $ptMissing, 'idiomattic-wp' ) ),
										$ptMissing
									);
									?>
								</span>
							<?php else : ?>
								<span class="iwp-ct-complete-badge">
									<span class="dashicons dashicons-yes"></span>
									<?php esc_html_e( 'Complete', 'idiomattic-wp' ); ?>
								</span>
							<?php endif; ?>
						</div>
						<?php if ( $ptMissing > 0 && $isPro ) : ?>
							<form method="post">
								<input type="hidden" name="iwp_ct_nonce"     value="<?php echo esc_attr( $nonce ); ?>">
								<input type="hidden" name="iwp_ct_post_type" value="<?php echo esc_attr( $ptSlug ); ?>">
								<input type="hidden" name="iwp_ct_lang"      value="">
								<button type="submit" class="iwp-btn iwp-btn--secondary iwp-btn--sm">
									<?php esc_html_e( 'Translate all languages', 'idiomattic-wp' ); ?>
								</button>
							</form>
						<?php endif; ?>
					</div>

					<?php /* Language rows */ ?>
					<table class="iwp-data-table iwp-ct-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Language', 'idiomattic-wp' ); ?></th>
								<th class="iwp-col-num"><?php esc_html_e( 'Missing', 'idiomattic-wp' ); ?></th>
								<th class="iwp-ct-col-bar"><?php esc_html_e( 'Progress', 'idiomattic-wp' ); ?></th>
								<th class="iwp-ct-col-action"><?php esc_html_e( 'Action', 'idiomattic-wp' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $targetLangs as $lang ) : ?>
								<?php
								$missing   = $matrix[ $ptSlug ][ $lang ];
								$ld        = $langData[ $lang ] ?? [];
								$langName  = $ld['name'] ?? strtoupper( $lang );
								$nativeName = $ld['native_name'] ?? $langName;
								$label     = $nativeName !== $langName ? "{$nativeName} ({$langName})" : $nativeName;
								$flagUrl   = IDIOMATTICWP_ASSETS_URL . 'flags/' . $lang . '.svg';
								$flagPath  = IDIOMATTICWP_PATH . 'assets/flags/' . $lang . '.svg';

								// Progress: total published posts for this type
								$totalPublished = (int) wp_count_posts( $ptSlug )->publish;
								$translated     = max( 0, $totalPublished - $missing );
								$pct            = $totalPublished > 0 ? min( 100, (int) round( ( $translated / $totalPublished ) * 100 ) ) : 100;
								$barColor       = $pct >= 80 ? '#46b450' : ( $pct >= 40 ? '#ffb900' : '#dc3232' );
								?>
								<tr>
									<td>
										<div class="iwp-ct-lang-cell">
											<?php if ( file_exists( $flagPath ) ) : ?>
												<img src="<?php echo esc_url( $flagUrl ); ?>" alt="<?php echo esc_attr( $lang ); ?>" width="22" height="15" class="iwp-ct-flag">
											<?php else : ?>
												<span class="iwp-flag-fallback"><?php echo esc_html( strtoupper( substr( $lang, 0, 2 ) ) ); ?></span>
											<?php endif; ?>
											<strong><?php echo esc_html( $label ); ?></strong>
											<code class="iwp-lang-code"><?php echo esc_html( $lang ); ?></code>
										</div>
									</td>
									<td class="iwp-col-num <?php echo $missing > 0 ? 'iwp-num--red' : 'iwp-num--green'; ?>">
										<?php if ( $missing > 0 ) : ?>
											<?php echo $missing; ?>
										<?php else : ?>
											<span class="dashicons dashicons-yes" style="color:#46b450;vertical-align:middle;"></span>
										<?php endif; ?>
									</td>
									<td class="iwp-ct-col-bar">
										<div class="iwp-coverage-bar">
											<div class="iwp-coverage-fill" style="width:<?php echo $pct; ?>%;background:<?php echo esc_attr( $barColor ); ?>;"></div>
										</div>
										<span class="iwp-coverage-pct"><?php echo $pct; ?>%</span>
									</td>
									<td class="iwp-ct-col-action">
										<?php if ( $missing > 0 ) : ?>
											<?php if ( $isPro ) : ?>
												<form method="post">
													<input type="hidden" name="iwp_ct_nonce"     value="<?php echo esc_attr( $nonce ); ?>">
													<input type="hidden" name="iwp_ct_post_type" value="<?php echo esc_attr( $ptSlug ); ?>">
													<input type="hidden" name="iwp_ct_lang"      value="<?php echo esc_attr( $lang ); ?>">
													<button type="submit" class="iwp-btn iwp-btn--secondary iwp-btn--sm">
														<span class="dashicons dashicons-controls-forward"></span>
														<?php esc_html_e( 'Queue AI translation', 'idiomattic-wp' ); ?>
													</button>
												</form>
											<?php else : ?>
												<span class="iwp-ct-pro-hint">
													<?php esc_html_e( 'AI translation requires Pro', 'idiomattic-wp' ); ?>
													<span class="iwp-pro-badge">PRO</span>
												</span>
											<?php endif; ?>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

				</div>
			<?php endforeach; ?>

		</div>

		<style>
		.iwp-ct-wrap { max-width: 1200px; }

		/* Complete state banner */
		.iwp-ct-complete {
			display: flex;
			align-items: center;
			gap: 16px;
			margin-bottom: 24px;
		}
		.iwp-ct-complete__icon {
			font-size: 32px !important;
			width: 32px !important;
			height: 32px !important;
			color: #46b450;
			flex-shrink: 0;
		}
		.iwp-ct-complete p { margin: 4px 0 0; }

		/* Card header: title + badge + action */
		.iwp-ct-card { margin-bottom: 20px; }
		.iwp-ct-card__header {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 12px;
			margin-bottom: 20px;
		}
		.iwp-ct-card__title-group {
			display: flex;
			align-items: center;
			gap: 10px;
		}

		/* Badges */
		.iwp-ct-missing-badge {
			display: inline-flex;
			align-items: center;
			padding: 2px 10px;
			background: #fce8e8;
			color: #c0392b;
			border-radius: 12px;
			font-size: 11px;
			font-weight: 600;
		}
		.iwp-ct-complete-badge {
			display: inline-flex;
			align-items: center;
			gap: 2px;
			padding: 2px 10px;
			background: #e8f5e9;
			color: #2d7a32;
			border-radius: 12px;
			font-size: 11px;
			font-weight: 600;
		}
		.iwp-ct-complete-badge .dashicons {
			font-size: 14px !important;
			width: 14px !important;
			height: 14px !important;
		}

		/* Table columns */
		.iwp-ct-table { margin-top: 0; }
		.iwp-ct-col-bar   { width: 180px; }
		.iwp-ct-col-action { width: 220px; text-align: right; }
		.iwp-ct-table th:last-child,
		.iwp-ct-table td:last-child { text-align: right; }

		/* Language cell with flag */
		.iwp-ct-lang-cell {
			display: flex;
			align-items: center;
			gap: 8px;
		}
		.iwp-ct-flag {
			border-radius: 3px;
			border: 1px solid #e2e4e7;
			object-fit: cover;
			flex-shrink: 0;
		}

		/* Action button icon */
		.iwp-btn .dashicons {
			font-size: 14px !important;
			width: 14px !important;
			height: 14px !important;
			vertical-align: middle;
			margin-right: 2px;
		}

		/* PRO gate styles */
		.iwp-pro-gate {
			opacity: .6;
			cursor: not-allowed;
		}
		.iwp-pro-badge {
			display: inline-block;
			padding: 1px 6px;
			background: #1e3a5f;
			color: #fff;
			border-radius: 4px;
			font-size: 9px;
			font-weight: 700;
			letter-spacing: .04em;
			vertical-align: middle;
			margin-left: 4px;
		}
		.iwp-ct-pro-hint {
			font-size: 12px;
			color: #8c8f94;
		}

		/* Bulk queue progress banner */
		.iwp-bulk-banner {
			display: flex;
			align-items: center;
			gap: 14px;
			background: #fff;
			border: 1px solid #c3d4e0;
			border-left: 4px solid #007cba;
			border-radius: 4px;
			padding: 14px 18px;
			margin-bottom: 20px;
		}
		.iwp-bulk-banner__icon { color: #007cba; }
		.iwp-bulk-banner__body { flex: 1; }
		.iwp-bulk-banner__count { color: #646970; font-size: 13px; }
		.iwp-bulk-cancel-btn {
			color: #d63638;
			text-decoration: none;
			font-size: 13px;
			white-space: nowrap;
		}
		.iwp-bulk-cancel-btn:hover { text-decoration: underline; }
		@keyframes iwp-spin { to { transform: rotate(360deg); } }
		.iwp-spin { display: inline-block; animation: iwp-spin 1.2s linear infinite; }
		</style>
		<script>
		(function() {
			var banner = document.getElementById('iwp-bulk-banner');
			if (!banner) return;
			var pendingEl = document.getElementById('iwp-bulk-pending');
			var nonce     = <?php echo wp_json_encode( wp_create_nonce( 'idiomatticwp_bulk_status' ) ); ?>;

			function poll() {
				fetch(ajaxurl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {'Content-Type': 'application/x-www-form-urlencoded'},
					body: 'action=idiomatticwp_bulk_status&_ajax_nonce=' + encodeURIComponent(nonce)
				})
				.then(function(r){ return r.json(); })
				.then(function(data) {
					if (!data.success) return;
					var pending = data.data.pending;
					if (pendingEl) pendingEl.textContent = pending.toLocaleString();
					if (pending > 0) {
						setTimeout(poll, 8000);
					} else {
						// Queue finished — reload so cards update
						window.location.reload();
					}
				})
				.catch(function(){ setTimeout(poll, 15000); });
			}

			setTimeout(poll, 8000);
		})();
		</script>
		<?php
	}

	// ── Queue handler ─────────────────────────────────────────────────────

	private function handleQueue(): ?int {
		if ( ! check_admin_referer( 'iwp_ct_nonce', 'iwp_ct_nonce' ) ) {
			return null;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return null;
		}
		if ( ! $this->licenseChecker->isPro() ) {
			return null;
		}

		$ptFilter   = sanitize_key( $_POST['iwp_ct_post_type'] ?? '' );
		$langFilter = sanitize_key( $_POST['iwp_ct_lang']      ?? '' );

		$defaultLang = (string) $this->languageManager->getDefaultLanguage();
		$allLangs    = array_values( array_filter(
			array_map( 'strval', $this->languageManager->getActiveLanguages() ),
			fn( $l ) => $l !== $defaultLang
		) );
		$allTypes    = $this->getTranslatablePostTypes();

		$postTypes = $ptFilter !== '' ? [ $ptFilter ] : $allTypes;
		$langs     = $langFilter !== '' ? [ $langFilter ] : $allLangs;

		// Collect all (post_id, source_lang, target_lang) tuples without
		// doing any translation work yet — the batch cron handles that.
		$jobs = [];

		foreach ( $postTypes as $ptSlug ) {
			foreach ( $langs as $lang ) {
				$postIds = $this->repository->getUntranslatedPostIdsByTypeAndLang(
					$ptSlug,
					$lang,
					self::BATCH_LIMIT
				);

				foreach ( $postIds as $postId ) {
					$jobs[] = [
						'post_id'     => (int) $postId,
						'source_lang' => $defaultLang,
						'target_lang' => $lang,
					];
				}
			}
		}

		return $this->bulkBatch->enqueue( $jobs );
	}

	// ── Private helpers ───────────────────────────────────────────────────

	private function getTranslatablePostTypes(): array {
		$config   = get_option( 'idiomatticwp_post_type_config', [] );
		$allTypes = get_post_types( [ 'public' => true ] );
		unset( $allTypes['attachment'] );

		$types = [];
		foreach ( array_keys( $allTypes ) as $postType ) {
			$mode = $config[ $postType ] ?? $this->registry->getPostTypeDefaultMode( $postType );
			if ( in_array( $mode, [ 'translate', 'show_as_translated' ], true ) ) {
				$types[] = $postType;
			}
		}

		return (array) apply_filters( 'idiomatticwp_translatable_post_types', $types );
	}
}
