<?php
/**
 * TranslationsMetabox — renders the translation management panel in the sidebar.
 *
 * Shows each active language, its translation status for the current post,
 * and buttons to edit or create translations.
 *
 * @package IdiomatticWP\Admin\Metaboxes
 */

declare( strict_types=1 );

namespace IdiomatticWP\Admin\Metaboxes;

use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\Contracts\TranslationRepositoryInterface;

class TranslationsMetabox {

	public function __construct(
		private LanguageManager $languageManager,
		private TranslationRepositoryInterface $repository,
	) {}

	/**
	 * Render the metabox content.
	 */
	public function render( \WP_Post $post ): void {
		$activeLanguages = $this->languageManager->getActiveLanguages();
		$defaultLang     = (string) $this->languageManager->getDefaultLanguage();

		wp_nonce_field( 'idiomatticwp_metabox', 'idiomatticwp_metabox_nonce' );

		// Collect row data first so we can build the summary header.
		$rows         = [];
		$completeCount = 0;

		foreach ( $activeLanguages as $lang ) {
			$langCode = (string) $lang;
			if ( $langCode === $defaultLang ) {
				continue;
			}

			$translation = $this->repository->findBySourceAndLang( $post->ID, $lang );
			$status      = $translation ? $translation['status'] : 'missing';
			$translatedId = (int) ( $translation['translated_post_id'] ?? 0 );

			$rows[] = [
				'langCode'     => $langCode,
				'status'       => $status,
				'translatedId' => $translatedId,
			];

			if ( $status === 'complete' ) {
				$completeCount++;
			}
		}

		$totalCount = count( $rows );

		echo '<div class="idiomatticwp-metabox-content">';

		$this->renderSummaryHeader( $completeCount, $totalCount );

		echo '<div class="iwp-mb-list">';
		foreach ( $rows as $row ) {
			$this->renderRow( $post->ID, $row['langCode'], $row['status'], $row['translatedId'] );
		}
		echo '</div>';

		echo '</div>';

		$this->renderAssets();
	}

	// ── Summary header ─────────────────────────────────────────────────────

	private function renderSummaryHeader( int $completeCount, int $totalCount ): void {
		if ( $totalCount === 0 ) {
			return;
		}

		if ( $completeCount === $totalCount ) {
			echo '<div class="iwp-mb-summary iwp-mb-summary--complete">';
			echo '<span class="iwp-mb-summary__icon">&#10003;</span>';
			printf(
				'<span>' . esc_html__( 'Translated into %d languages', 'idiomattic-wp' ) . '</span>',
				$totalCount
			);
			echo '</div>';
		} else {
			echo '<div class="iwp-mb-summary iwp-mb-summary--partial">';
			printf(
				'<span>' . esc_html__( '%d of %d languages translated', 'idiomattic-wp' ) . '</span>',
				$completeCount,
				$totalCount
			);
			echo '</div>';
		}
	}

	// ── Row renderer ───────────────────────────────────────────────────────

	private function renderRow(
		int $sourcePostId,
		string $langCode,
		string $status,
		int $translatedId
	): void {
		$lang = \IdiomatticWP\ValueObjects\LanguageCode::from( $langCode );
		$name = $this->languageManager->getLanguageName( $lang );

		$dotClass = 'iwp-mb-dot iwp-mb-dot--' . esc_attr( $status );

		echo '<div class="iwp-mb-row">';

		// Flag
		$this->renderFlag( $langCode );

		// Language name
		printf(
			'<span class="iwp-mb-lang">%s <span style="color:#8c8f94;font-size:11px;">(%s)</span></span>',
			esc_html( $name ),
			esc_html( $langCode )
		);

		// Status dot
		printf( '<span class="%s" title="%s"></span>', esc_attr( $dotClass ), esc_attr( $this->getStatusLabel( $status ) ) );

		// Action area
		echo '<span class="iwp-mb-action">';

		if ( $translatedId ) {
			$editorUrl = add_query_arg(
				[ 'post' => $translatedId, 'action' => 'idiomatticwp_translate' ],
				admin_url( 'post.php' )
			);

			$btnLabel = match ( $status ) {
				'outdated'    => __( 'Update', 'idiomattic-wp' ),
				'draft'       => __( 'Edit draft', 'idiomattic-wp' ),
				'in_progress' => __( 'Continue', 'idiomattic-wp' ),
				'failed'      => __( 'Retry', 'idiomattic-wp' ),
				default       => __( 'Edit', 'idiomattic-wp' ),
			};

			printf(
				'<a href="%s" class="button">%s</a>',
				esc_url( $editorUrl ),
				esc_html( $btnLabel )
			);
		} else {
			$nonce = wp_create_nonce( 'idiomatticwp_nonce' );

			printf(
				'<button type="button"
					class="button button-primary idiomatticwp-create-translation"
					data-idiomatticwp-action="create-translation"
					data-post-id="%d"
					data-lang="%s">%s</button>',
				$sourcePostId,
				esc_attr( $langCode ),
				esc_html__( 'Translate now', 'idiomattic-wp' )
			);

			// "Link existing" — subtle text link that toggles the search widget.
			printf(
				'<span class="iwp-mb-link-existing"
					data-post-id="%d"
					data-lang="%s"
					data-nonce="%s">
					<a class="iwp-mb-link-toggle" href="#">%s</a>
					<span class="iwp-mb-link-body" style="display:none;">
						<input type="text" class="idiomatticwp-link-search" placeholder="%s" autocomplete="off" style="margin-top:4px;">
						<ul class="idiomatticwp-link-results" style="display:none;"></ul>
					</span>
				</span>',
				$sourcePostId,
				esc_attr( $langCode ),
				esc_attr( $nonce ),
				esc_html__( 'or link existing \u2192', 'idiomattic-wp' ),
				esc_attr__( 'Search by title\u2026', 'idiomattic-wp' )
			);
		}

		echo '</span>'; // .iwp-mb-action
		echo '</div>'; // .iwp-mb-row
	}

	// ── Flag renderer ──────────────────────────────────────────────────────

	private function renderFlag( string $langCode ): void {
		$flagUrl  = IDIOMATTICWP_ASSETS_URL . 'flags/' . $langCode . '.svg';
		$flagPath = IDIOMATTICWP_PATH . 'assets/flags/' . $langCode . '.svg';

		if ( file_exists( $flagPath ) ) {
			printf(
				'<img src="%s" alt="%s" class="idiomatticwp-flag" width="20" height="15" loading="lazy" style="flex-shrink:0;">',
				esc_url( $flagUrl ),
				esc_attr( $langCode )
			);
		} else {
			printf(
				'<span class="idiomatticwp-flag-fallback" style="flex-shrink:0;">%s</span>',
				esc_html( strtoupper( substr( $langCode, 0, 2 ) ) )
			);
		}
	}

	// ── Status label ───────────────────────────────────────────────────────

	private function getStatusLabel( string $status ): string {
		return match ( $status ) {
			'complete'    => __( 'Done', 'idiomattic-wp' ),
			'outdated'    => __( 'Outdated', 'idiomattic-wp' ),
			'draft'       => __( 'Draft', 'idiomattic-wp' ),
			'in_progress' => __( 'In progress', 'idiomattic-wp' ),
			'missing'     => __( 'Missing', 'idiomattic-wp' ),
			'failed'      => __( 'Failed', 'idiomattic-wp' ),
			default       => ucfirst( $status ),
		};
	}

	// ── Inline assets ─────────────────────────────────────────────────────

	private function renderAssets(): void {
		static $rendered = false;
		if ( $rendered ) {
			return;
		}
		$rendered = true;
		?>
		<style>
		/* Summary header */
		.iwp-mb-summary { display:flex; align-items:center; gap:8px; padding:8px 12px; border-radius:4px; font-size:13px; margin-bottom:12px; font-weight:600; }
		.iwp-mb-summary--complete { background:#edfaef; color:#1a6b1f; }
		.iwp-mb-summary--partial  { background:#fef8e7; color:#6b4c00; }
		.iwp-mb-summary__icon { font-size:16px; }

		/* Language rows */
		.iwp-mb-row { display:flex; align-items:center; gap:8px; padding:7px 0; border-bottom:1px solid #f0f0f1; }
		.iwp-mb-row:last-child { border-bottom:none; }

		/* Status dot */
		.iwp-mb-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
		.iwp-mb-dot--complete    { background:#46b450; }
		.iwp-mb-dot--outdated    { background:#dea000; }
		.iwp-mb-dot--draft       { background:#8c8f94; }
		.iwp-mb-dot--missing     { background:#dc3232; }
		.iwp-mb-dot--in_progress { background:#2271b1; }
		.iwp-mb-dot--failed      { background:#b91c1c; }

		/* Language name */
		.iwp-mb-lang { flex:1; font-size:13px; }

		/* Action button */
		.iwp-mb-action .button { padding:2px 8px; font-size:11px; height:auto; line-height:1.6; }

		/* "or link existing" text link */
		.iwp-mb-link-existing { display:block; margin-top:4px; }
		.iwp-mb-link-toggle { font-size:11px; color:#8c8f94; text-decoration:underline; cursor:pointer; }
		.iwp-mb-link-toggle:hover { color:#0073aa; }

		/* Link search widget */
		.idiomatticwp-link-search {
			width: 100%;
			box-sizing: border-box;
			font-size: 12px;
		}
		.idiomatticwp-link-results {
			list-style: none;
			margin: 4px 0 0;
			padding: 0;
			border: 1px solid #c3c4c7;
			border-radius: 3px;
			max-height: 160px;
			overflow-y: auto;
			background: #fff;
		}
		.idiomatticwp-link-results li {
			padding: 6px 10px;
			font-size: 12px;
			cursor: pointer;
			border-bottom: 1px solid #f0f0f1;
		}
		.idiomatticwp-link-results li:last-child { border-bottom: none; }
		.idiomatticwp-link-results li:hover { background: #f0f6fc; }
		.idiomatticwp-link-results .iwp-link-empty {
			color: #8c8f94;
			font-style: italic;
			cursor: default;
		}
		</style>
		<script>
		(function () {
			'use strict';

			var debounceTimers = {};

			// Toggle the link-existing search widget.
			document.addEventListener('click', function(e) {
				var toggle = e.target.closest('.iwp-mb-link-toggle');
				if ( ! toggle ) { return; }
				e.preventDefault();
				var wrapper = toggle.closest('.iwp-mb-link-existing');
				var body    = wrapper.querySelector('.iwp-mb-link-body');
				var input   = wrapper.querySelector('.idiomatticwp-link-search');
				if ( body.style.display === 'none' ) {
					body.style.display = 'block';
					if ( input ) { input.focus(); }
				} else {
					body.style.display = 'none';
				}
			});

			document.addEventListener('input', function(e) {
				var input = e.target;
				if ( ! input.classList.contains('idiomatticwp-link-search') ) { return; }

				var wrapper = input.closest('.iwp-mb-link-existing');
				var results = wrapper.querySelector('.idiomatticwp-link-results');
				var q       = input.value.trim();

				if ( q.length < 2 ) {
					results.style.display = 'none';
					results.innerHTML     = '';
					return;
				}

				clearTimeout( debounceTimers[wrapper.dataset.lang] );
				debounceTimers[wrapper.dataset.lang] = setTimeout(function() {
					var fd = new FormData();
					fd.append('action',  'idiomatticwp_search_posts');
					fd.append('nonce',   wrapper.dataset.nonce);
					fd.append('q',       q);
					fd.append('exclude', wrapper.dataset.postId);

					fetch(ajaxurl, { method: 'POST', body: fd })
						.then(function(r) { return r.json(); })
						.then(function(res) {
							results.innerHTML = '';
							if ( ! res.success || ! res.data.length ) {
								results.innerHTML = '<li class="iwp-link-empty"><?php echo esc_js( __( 'No posts found.', 'idiomattic-wp' ) ); ?></li>';
								results.style.display = 'block';
								return;
							}
							res.data.forEach(function(post) {
								var li        = document.createElement('li');
								li.textContent = post.title + ' [' + post.type + ' #' + post.id + ']';
								li.dataset.postId = post.id;
								results.appendChild(li);
							});
							results.style.display = 'block';
						})
						.catch(function() {});
				}, 300);
			});

			document.addEventListener('click', function(e) {
				var li = e.target.closest('.idiomatticwp-link-results li:not(.iwp-link-empty)');
				if ( ! li ) { return; }

				var results  = li.closest('.idiomatticwp-link-results');
				var wrapper  = results.closest('.iwp-mb-link-existing');
				var targetId = parseInt(li.dataset.postId, 10);

				if ( ! confirm('<?php echo esc_js( __( 'Link this post as the translation? Any previous relationship for this language will be blocked (no duplicate allowed).', 'idiomattic-wp' ) ); ?>') ) {
					return;
				}

				var fd = new FormData();
				fd.append('action',    'idiomatticwp_link_translation');
				fd.append('nonce',     wrapper.dataset.nonce);
				fd.append('source_id', wrapper.dataset.postId);
				fd.append('target_id', targetId);
				fd.append('lang',      wrapper.dataset.lang);

				fetch(ajaxurl, { method: 'POST', body: fd })
					.then(function(r) { return r.json(); })
					.then(function(res) {
						if ( res.success ) {
							window.location.href = res.data.edit_url;
						} else {
							alert((res.data && res.data.message) ? res.data.message : '<?php echo esc_js( __( 'Could not link post.', 'idiomattic-wp' ) ); ?>');
						}
					})
					.catch(function() {});
			});
		}());
		</script>
		<?php
	}
}
