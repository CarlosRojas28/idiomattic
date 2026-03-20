<?php
/**
 * LanguageSwitcher — renders the frontend language switcher.
 *
 * Visibility rules per post-type mode (Settings → Content):
 *
 *   translate (default) — each language has its own post version.
 *     Show only: the current post language + languages with an existing translation.
 *     Hide: languages with no translation yet.
 *
 *   show_as_translated — content is served in the default language for all locales.
 *     Show: all active languages (they all resolve to the same content).
 *     The switcher changes the session language but stays on the same URL.
 *
 *   ignore — post type is excluded from translation entirely.
 *     Show: all active languages using plain URL switching (no post lookup).
 *
 * When no post context is available (archives, home, search…) the switcher
 * falls back to showing all active languages with URL-based switching.
 *
 * @package IdiomatticWP\Frontend
 */

declare( strict_types=1 );

namespace IdiomatticWP\Frontend;

use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\Contracts\TranslationRepositoryInterface;
use IdiomatticWP\Contracts\UrlStrategyInterface;
use IdiomatticWP\ValueObjects\LanguageCode;

class LanguageSwitcher {

	public function __construct(
		private LanguageManager                 $languageManager,
		private UrlStrategyInterface            $urlStrategy,
		private ?TranslationRepositoryInterface $repository = null,
	) {}

	// ── Public API ────────────────────────────────────────────────────────

	public function render( array $args = [] ): string {
		$args = wp_parse_args( $args, [
			'style'             => 'list',
			'show_flags'        => true,
			'show_names'        => true,
			'show_native_names' => false,
			'hide_current'      => false,
		] );

		$languages = apply_filters(
			'idiomatticwp_language_switcher_languages',
			$this->languageManager->getActiveLanguages(),
			$args
		);

		if ( empty( $languages ) ) {
			return '';
		}

		global $wp;
		$currentUrl = home_url( add_query_arg( [], $wp->request ) );

		// ── Resolve the post currently on screen ──────────────────────────
		//
		// The switcher can be embedded in a block-theme template part (header/
		// footer), a classic sidebar widget, or inside the post loop. Each
		// context sets different globals, so we try them in priority order and
		// reject internal post types (wp_template_part, wp_navigation, etc.)
		// that are never the "page being visited".

		$currentPost   = $this->resolveCurrentPost();
		$currentPostId = $currentPost?->ID ?? 0;
		$postType      = $currentPost?->post_type ?? '';

		// ── Determine post-type mode ──────────────────────────────────────
		//
		//   translate        → only show languages with a real translation
		//   show_as_translated → show all languages (same content, lang switch)
		//   ignore / no post → show all languages with URL switching
		//   no config saved  → treat as 'translate' (strictest / safest default)

		$postTypeMode = $this->getPostTypeMode( $postType );

		// ── Build translation map: target_lang → translated_post_id ──────

		$sourcePostId   = null;
		$translationMap = []; // e.g. ['fr' => 16, 'es' => 24]
		$postLang       = (string) $this->languageManager->getDefaultLanguage();

		if ( $currentPostId && $this->repository ) {
			$ownRecord = $this->repository->findByTranslatedPost( $currentPostId );

			if ( $ownRecord ) {
				// Viewing a translated post — climb up to the source
				$sourcePostId = (int) $ownRecord['source_post_id'];
				$postLang     = $ownRecord['target_lang'];
			} else {
				// Viewing a source (original) post
				$sourcePostId = $currentPostId;
				// $postLang stays as default
			}

			foreach ( $this->repository->findAllForSource( $sourcePostId ) as $tr ) {
				$translationMap[ $tr['target_lang'] ] = (int) $tr['translated_post_id'];
			}
		}

		$defaultLang = (string) $this->languageManager->getDefaultLanguage();

		// ── Build item list ───────────────────────────────────────────────

		$items = [];

		foreach ( $languages as $lang ) {
			$langCode = (string) $lang;

			// Resolve the URL and whether a real translation exists
			if ( $sourcePostId && $langCode === $defaultLang ) {
				// Always available — it IS the source post
				$langUrl   = get_permalink( $sourcePostId ) ?: $this->urlStrategy->buildUrl( $currentUrl, $lang );
				$hasTranslation = true;
			} elseif ( isset( $translationMap[ $langCode ] ) ) {
				// A translated post exists in the DB for this language
				$langUrl   = get_permalink( $translationMap[ $langCode ] ) ?: $this->urlStrategy->buildUrl( $currentUrl, $lang );
				$hasTranslation = true;
			} else {
				// No translated post for this language
				$langUrl        = $this->urlStrategy->buildUrl( $currentUrl, $lang );
				$hasTranslation = false;
			}

			// Decide visibility based on post-type mode:
			//   translate        → hide languages without a translation
			//   show_as_translated / ignore / no post context → show all
			$shouldShow = match ( $postTypeMode ) {
				'translate' => $hasTranslation || ( $langCode === $postLang ),
				default     => true,
			};

			if ( ! $shouldShow ) {
				continue;
			}

			// "active" = visitor is already reading this post in this language
			$isActive = ( $langCode === $postLang );

			if ( $args['hide_current'] && $isActive ) {
				continue;
			}

			$items[] = [
				'code'           => $langCode,
				'name'           => $args['show_native_names']
					? $this->languageManager->getNativeLanguageName( $lang )
					: $this->languageManager->getLanguageName( $lang ),
				'url'            => $langUrl,
				'active'         => $isActive,
				'has_translation' => $hasTranslation,
				'flag'           => IDIOMATTICWP_ASSETS_URL . 'flags/' . strtolower( $lang->getBase() ) . '.svg',
			];
		}

		if ( empty( $items ) ) {
			return '';
		}

		$output = ( $args['style'] === 'dropdown' )
			? $this->renderDropdown( $items, $args )
			: $this->renderList( $items, $args );

		return apply_filters( 'idiomatticwp_language_switcher_html', $output, $items, $args );
	}

	// ── Private: context resolution ───────────────────────────────────────

	/**
	 * Return the WP_Post that represents the page currently being viewed,
	 * ignoring internal block-theme post types that are never "the page".
	 */
	private function resolveCurrentPost(): ?\WP_Post {
		// Block-theme: get_queried_object() is set correctly before template parts render
		$queried = get_queried_object();
		if ( $queried instanceof \WP_Post && $this->isPublicPostType( $queried->post_type ) ) {
			return $queried;
		}

		// Classic theme / shortcode inside the loop
		$loopId = (int) get_the_ID();
		if ( $loopId > 0 ) {
			$loopPost = get_post( $loopId );
			if ( $loopPost instanceof \WP_Post && $this->isPublicPostType( $loopPost->post_type ) ) {
				return $loopPost;
			}
		}

		return null;
	}

	/**
	 * Returns true for user-facing post types, false for internal WP types.
	 */
	private function isPublicPostType( string $postType ): bool {
		static $publicTypes = null;
		if ( $publicTypes === null ) {
			$publicTypes = get_post_types( [ 'public' => true ] );
		}
		return isset( $publicTypes[ $postType ] );
	}

	/**
	 * Return the configured mode for a post type.
	 *
	 * Modes (set in Settings → Content):
	 *   translate          — independent translation per language (default)
	 *   show_as_translated — same content shown for all languages
	 *   ignore             — excluded from translation
	 *
	 * Falls back to 'translate' when no configuration has been saved yet,
	 * or when the post type is not found in the config.
	 */
	private function getPostTypeMode( string $postType ): string {
		if ( $postType === '' ) {
			return 'none'; // No post context (archive, home, search…)
		}

		$config = get_option( 'idiomatticwp_post_type_config', [] );

		if ( ! is_array( $config ) || empty( $config ) ) {
			// No config saved yet → default to 'translate' (show only translated)
			return 'translate';
		}

		return $config[ $postType ] ?? 'translate';
	}

	// ── Private: rendering ────────────────────────────────────────────────

	private function renderList( array $items, array $args ): string {
		$html = sprintf(
			'<ul class="idiomatticwp-switcher idiomatticwp-switcher--list" role="navigation" aria-label="%s">',
			esc_attr__( 'Language switcher', 'idiomattic-wp' )
		);

		foreach ( $items as $item ) {
			$liClass = 'idiomatticwp-lang';
			if ( $item['active'] ) {
				$liClass .= ' idiomatticwp-lang--active';
			}

			$html .= sprintf(
				'<li class="%s"%s>',
				esc_attr( $liClass ),
				$item['active'] ? ' aria-current="true"' : ''
			);

			$content = '';
			if ( $args['show_flags'] ) {
				$content .= sprintf(
					'<img src="%s" alt="%s" width="20" height="15" loading="lazy"> ',
					esc_url( $item['flag'] ),
					esc_attr( $item['name'] )
				);
			}
			if ( $args['show_names'] ) {
				$content .= sprintf( '<span>%s</span>', esc_html( $item['name'] ) );
			}

			if ( $item['active'] ) {
				// Current language: plain text, no link
				$html .= $content;
			} else {
				// All other visible languages: always a clickable link
				$html .= sprintf(
					'<a href="%s" hreflang="%s" lang="%s">%s</a>',
					esc_url( $item['url'] ),
					esc_attr( $item['code'] ),
					esc_attr( $item['code'] ),
					$content
				);
			}

			$html .= '</li>';
		}

		return $html . '</ul>';
	}

	private function renderDropdown( array $items, array $args ): string {
		$html  = '<div class="idiomatticwp-switcher idiomatticwp-switcher--dropdown">';
		$html .= sprintf(
			'<select onchange="window.location.href=this.value" aria-label="%s">',
			esc_attr__( 'Select Language', 'idiomattic-wp' )
		);

		foreach ( $items as $item ) {
			$html .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_url( $item['url'] ),
				selected( $item['active'], true, false ),
				esc_html( $item['name'] )
			);
		}

		return $html . '</select></div>';
	}
}

class LanguageSwitcherWidget extends \WP_Widget {

	public function __construct() {
		parent::__construct(
			'idiomatticwp_switcher',
			__( 'Idiomattic Language Switcher', 'idiomattic-wp' ),
			[ 'description' => __( 'Display a language switcher.', 'idiomattic-wp' ) ]
		);
	}

	public function widget( $args, $instance ): void {
		echo $args['before_widget'];
		if ( ! empty( $instance['title'] ) ) {
			echo $args['before_title']
				. apply_filters( 'widget_title', $instance['title'] )
				. $args['after_title'];
		}
		$switcher = \IdiomatticWP\Core\Plugin::getInstance()->getContainer()->get( LanguageSwitcher::class );
		echo $switcher->render( [
			'style'             => $instance['style']             ?? 'list',
			'show_flags'        => ! empty( $instance['show_flags'] ),
			'show_names'        => ! empty( $instance['show_names'] ),
			'show_native_names' => ! empty( $instance['show_native_names'] ),
		] );
		echo $args['after_widget'];
	}

	public function form( $instance ): void {
		$style = $instance['style'] ?? 'list';
		?>
		<p>
			<label><?php _e( 'Title:' ); ?></label>
			<input class="widefat" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text"
				value="<?php echo esc_attr( $instance['title'] ?? '' ); ?>">
		</p>
		<p>
			<label><?php _e( 'Style:' ); ?></label>
			<select class="widefat" name="<?php echo $this->get_field_name( 'style' ); ?>">
				<option value="list"     <?php selected( $style, 'list' ); ?>>List</option>
				<option value="dropdown" <?php selected( $style, 'dropdown' ); ?>>Dropdown</option>
			</select>
		</p>
		<p>
			<input type="checkbox" <?php checked( ! isset( $instance['show_flags'] ) || $instance['show_flags'] ); ?>
				name="<?php echo $this->get_field_name( 'show_flags' ); ?>">
			<label><?php _e( 'Show flags' ); ?></label>
		</p>
		<p>
			<input type="checkbox" <?php checked( ! isset( $instance['show_names'] ) || $instance['show_names'] ); ?>
				name="<?php echo $this->get_field_name( 'show_names' ); ?>">
			<label><?php _e( 'Show names' ); ?></label>
		</p>
		<p>
			<input type="checkbox" <?php checked( ! empty( $instance['show_native_names'] ) ); ?>
				name="<?php echo $this->get_field_name( 'show_native_names' ); ?>">
			<label><?php _e( 'Show native names' ); ?></label>
		</p>
		<?php
	}

	public function update( $new, $old ): array {
		return [
			'title'             => strip_tags( $new['title'] ),
			'style'             => $new['style'],
			'show_flags'        => ! empty( $new['show_flags'] ),
			'show_names'        => ! empty( $new['show_names'] ),
			'show_native_names' => ! empty( $new['show_native_names'] ),
		];
	}
}
