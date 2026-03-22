<?php
/**
 * PostTranslationsDisplay — injects "Also available in:" translation links into post content.
 *
 * When a post has translations in other active languages, this class appends
 * (or prepends) a small notice with links to those translations. This is the
 * equivalent of WPML's "Post Translations" language switcher position.
 *
 * ── Configuration ─────────────────────────────────────────────────────────────
 *
 * Controlled by the `idiomatticwp_post_translations_display` option:
 *   'after'  (default) — notice appears after the post content
 *   'before'           — notice appears before the post content
 *   'none'             — feature disabled
 *
 * The position can also be changed via filter at runtime:
 *
 *   add_filter( 'idiomatticwp_post_translations_display_position', fn() => 'before' );
 *
 * The full HTML output is filterable:
 *
 *   add_filter( 'idiomatticwp_post_translations_html', function( $html, $links, $post ) {
 *       // $links: array of ['url' => string, 'name' => string, 'code' => string, 'flag' => string]
 *       return $html;
 *   }, 10, 3 );
 *
 * ── Visibility rules ──────────────────────────────────────────────────────────
 *
 * The notice is shown only when ALL of the following are true:
 *   - A single post/page is being displayed (is_singular()).
 *   - The post type is configured in 'translate' mode (not 'show_as_translated'/'ignore').
 *   - At least one translated post exists in the database for an active language.
 *   - The filter `idiomatticwp_post_translations_display_position` does not return 'none'.
 *
 * @package IdiomatticWP\Frontend
 */

declare( strict_types=1 );

namespace IdiomatticWP\Frontend;

use IdiomatticWP\Contracts\TranslationRepositoryInterface;
use IdiomatticWP\Core\LanguageManager;

class PostTranslationsDisplay {

	public function __construct(
		private LanguageManager                $languageManager,
		private TranslationRepositoryInterface $repository,
	) {}

	// ── Public API ────────────────────────────────────────────────────────

	/**
	 * Filter callback for `the_content`.
	 *
	 * Prepends or appends the translation notice to the post content.
	 * Registered at priority 20 so it runs after most content filters.
	 *
	 * @param string $content Original post content.
	 * @return string Content with translation notice injected, or unchanged.
	 */
	public function injectIntoContent( string $content ): string {
		if ( ! is_singular() ) {
			return $content;
		}

		$post = get_post();
		if ( ! $post instanceof \WP_Post ) {
			return $content;
		}

		// Only for post types in 'translate' mode
		if ( $this->getPostTypeMode( $post->post_type ) !== 'translate' ) {
			return $content;
		}

		$position = $this->getPosition();
		if ( $position === 'none' ) {
			return $content;
		}

		$notice = $this->buildNotice( $post );
		if ( $notice === '' ) {
			return $content;
		}

		return $position === 'before'
			? $notice . $content
			: $content . $notice;
	}

	// ── Private helpers ───────────────────────────────────────────────────

	/**
	 * Build the translation notice HTML for a given post.
	 *
	 * Returns an empty string when no translations are available to link to.
	 *
	 * @param \WP_Post $post The post currently being displayed.
	 * @return string HTML notice, or empty string.
	 */
	private function buildNotice( \WP_Post $post ): string {
		$links = $this->resolveLinks( $post );
		if ( empty( $links ) ) {
			return '';
		}

		// Build link HTML items
		$linksHtml = '';
		foreach ( $links as $link ) {
			$linksHtml .= sprintf(
				'<a href="%s" hreflang="%s" lang="%s" class="idiomatticwp-post-trans__link">'
				. '<img src="%s" alt="" width="16" height="12" loading="lazy"> <span>%s</span>'
				. '</a>',
				esc_url( $link['url'] ),
				esc_attr( $link['code'] ),
				esc_attr( $link['code'] ),
				esc_url( $link['flag'] ),
				esc_html( $link['name'] )
			);
		}

		$html = sprintf(
			'<p class="idiomatticwp-post-trans">%s %s</p>',
			esc_html__( 'This post is also available in:', 'idiomattic-wp' ),
			$linksHtml
		);

		return apply_filters( 'idiomatticwp_post_translations_html', $html, $links, $post );
	}

	/**
	 * Resolve the list of available translation links for a post.
	 *
	 * Determines whether $post is a source or a translation, then finds
	 * all sibling translations in other active languages. Returns only
	 * languages that have a real translated post in the DB — languages
	 * without a translation are silently omitted.
	 *
	 * @param \WP_Post $post
	 * @return array<int, array{url: string, name: string, code: string, flag: string}>
	 */
	private function resolveLinks( \WP_Post $post ): array {
		$activeLanguages = $this->languageManager->getActiveLanguages();
		$defaultLang     = (string) $this->languageManager->getDefaultLanguage();
		$currentLang     = (string) $this->languageManager->getCurrentLanguage();

		// Climb to the source post if we're viewing a translation
		$ownRecord = $this->repository->findByTranslatedPost( $post->ID );
		if ( $ownRecord ) {
			$sourceId    = (int) $ownRecord['source_post_id'];
			$currentLang = $ownRecord['target_lang'];
		} else {
			$sourceId = $post->ID;
		}

		// Build a map: lang_code → translated_post_id (translations of sourceId)
		$translationMap = [];
		foreach ( $this->repository->findAllForSource( $sourceId ) as $tr ) {
			$translationMap[ $tr['target_lang'] ] = (int) $tr['translated_post_id'];
		}

		$links = [];

		foreach ( $activeLanguages as $lang ) {
			$langCode = (string) $lang;

			// Skip the language the visitor is already reading
			if ( $langCode === $currentLang ) {
				continue;
			}

			$permalink = null;

			if ( $langCode === $defaultLang ) {
				// Default language → link to the source post
				$permalink = get_permalink( $sourceId ) ?: null;
			} elseif ( isset( $translationMap[ $langCode ] ) ) {
				$permalink = get_permalink( $translationMap[ $langCode ] ) ?: null;
			}

			// Only include languages that have an actual translated post
			if ( $permalink === null ) {
				continue;
			}

			$links[] = [
				'url'  => $permalink,
				'name' => $this->languageManager->getLanguageName( $lang ),
				'code' => $langCode,
				'flag' => IDIOMATTICWP_ASSETS_URL . 'flags/' . strtolower( $lang->getBase() ) . '.svg',
			];
		}

		return $links;
	}

	/**
	 * Return the display position setting, filtered.
	 *
	 * @return string 'after'|'before'|'none'
	 */
	private function getPosition(): string {
		$position = get_option( 'idiomatticwp_post_translations_display', 'after' );
		$position = apply_filters( 'idiomatticwp_post_translations_display_position', $position );
		return in_array( $position, [ 'after', 'before', 'none' ], true ) ? (string) $position : 'after';
	}

	/**
	 * Return the configured mode for a post type.
	 *
	 * @param string $postType
	 * @return string 'translate'|'show_as_translated'|'ignore'|'none'
	 */
	private function getPostTypeMode( string $postType ): string {
		if ( $postType === '' ) {
			return 'none';
		}
		$config = get_option( 'idiomatticwp_post_type_config', [] );
		if ( ! is_array( $config ) || empty( $config ) ) {
			return 'translate';
		}
		return $config[ $postType ] ?? 'translate';
	}
}
