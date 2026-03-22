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

	/**
	 * Render the language switcher as a self-contained HTML element.
	 *
	 * @param array $args {
	 *   @type string $style             list|dropdown|nav-dropdown|flags-only|floating  (default: list)
	 *   @type bool   $show_flags        Display flag images.                            (default: true)
	 *   @type bool   $show_names        Display language name text.                     (default: true)
	 *   @type bool   $show_native_names Use native language names (e.g. "Français").    (default: false)
	 *   @type bool   $hide_current      Omit the currently active language.             (default: false)
	 * }
	 * @return string HTML output, or empty string when fewer than two languages resolve.
	 */
	public function render( array $args = [] ): string {
		$args = wp_parse_args( $args, [
			'style'             => 'list',
			'show_flags'        => true,
			'show_names'        => true,
			'show_native_names' => false,
			'hide_current'      => false,
		] );

		$items = $this->buildItems( $args );

		if ( empty( $items ) ) {
			return '';
		}

		$output = match ( $args['style'] ) {
			'dropdown'     => $this->renderDropdown( $items, $args ),
			'nav-dropdown' => $this->renderNavDropdown( $items, $args ),
			'flags-only'   => $this->renderFlagsOnly( $items ),
			'floating'     => $this->renderFloating( $items, $args ),
			default        => $this->renderList( $items, $args ),
		};

		return apply_filters( 'idiomatticwp_language_switcher_html', $output, $items, $args );
	}

	/**
	 * Render language items as `<li>` elements suitable for injection into a
	 * WordPress nav menu (no wrapping `<ul>`).
	 *
	 * Generates `<li class="menu-item idiomatticwp-menu-lang …">` items
	 * that blend naturally with any theme's nav menu markup.
	 *
	 * @param array $args Same options as render(). 'style' is ignored here.
	 * @return string HTML `<li>` items, or empty string.
	 */
	public function renderMenuItems( array $args = [] ): string {
		$args = wp_parse_args( $args, [
			'show_flags'        => true,
			'show_names'        => true,
			'show_native_names' => false,
			'hide_current'      => false,
		] );

		$items = $this->buildItems( $args );
		if ( empty( $items ) ) {
			return '';
		}

		$html = '';
		foreach ( $items as $item ) {
			$classes = 'menu-item idiomatticwp-menu-lang idiomatticwp-menu-lang--' . esc_attr( $item['code'] );
			if ( $item['active'] ) {
				$classes .= ' current-menu-item idiomatticwp-menu-lang--active';
			}

			$inner = '';
			if ( $args['show_flags'] ) {
				$inner .= sprintf(
					'<img src="%s" alt="%s" width="16" height="12" loading="lazy"> ',
					esc_url( $item['flag'] ),
					esc_attr( $item['name'] )
				);
			}
			if ( $args['show_names'] ) {
				$inner .= esc_html( $item['name'] );
			}

			if ( $item['active'] ) {
				$html .= sprintf(
					'<li class="%s" aria-current="true"><span class="idiomatticwp-menu-lang__current">%s</span></li>',
					esc_attr( $classes ),
					$inner
				);
			} else {
				$html .= sprintf(
					'<li class="%s"><a href="%s" hreflang="%s" lang="%s">%s</a></li>',
					esc_attr( $classes ),
					esc_url( $item['url'] ),
					esc_attr( $item['code'] ),
					esc_attr( $item['code'] ),
					$inner
				);
			}
		}

		return $html;
	}

	// ── Private: item building ────────────────────────────────────────────

	/**
	 * Build the normalised item array that all render methods consume.
	 *
	 * Reads the current post context, determines the post-type translation mode,
	 * looks up available translations, and returns an ordered array of items
	 * ready to be handed to a renderer.
	 *
	 * Each item:
	 *   code            string  BCP-47 language code
	 *   name            string  Display name (native or English per args)
	 *   url             string  Absolute URL for this language
	 *   active          bool    True when the visitor is already in this language
	 *   has_translation bool    True when a translated post exists in the DB
	 *   flag            string  Absolute URL to the SVG flag image
	 *
	 * @param array $args render()/renderMenuItems() args.
	 * @return array<int, array> Item list (may be empty).
	 */
	private function buildItems( array $args ): array {
		$languages = apply_filters(
			'idiomatticwp_language_switcher_languages',
			$this->languageManager->getActiveLanguages(),
			$args
		);

		if ( empty( $languages ) ) {
			return [];
		}

		global $wp;
		$currentUrl = home_url( add_query_arg( [], $wp->request ) );

		$currentPost   = $this->resolveCurrentPost();
		$currentPostId = $currentPost?->ID ?? 0;
		$postType      = $currentPost?->post_type ?? '';

		$postTypeMode = $this->getPostTypeMode( $postType );

		$sourcePostId   = null;
		$translationMap = [];
		$postLang       = (string) $this->languageManager->getDefaultLanguage();

		if ( $currentPostId && $this->repository ) {
			$ownRecord = $this->repository->findByTranslatedPost( $currentPostId );

			if ( $ownRecord ) {
				$sourcePostId = (int) $ownRecord['source_post_id'];
				$postLang     = $ownRecord['target_lang'];
			} else {
				$sourcePostId = $currentPostId;
			}

			foreach ( $this->repository->findAllForSource( $sourcePostId ) as $tr ) {
				$translationMap[ $tr['target_lang'] ] = (int) $tr['translated_post_id'];
			}
		}

		$defaultLang = (string) $this->languageManager->getDefaultLanguage();
		$items       = [];

		foreach ( $languages as $lang ) {
			$langCode = (string) $lang;

			if ( $sourcePostId && $langCode === $defaultLang ) {
				$langUrl        = get_permalink( $sourcePostId ) ?: $this->urlStrategy->buildUrl( $currentUrl, $lang );
				$hasTranslation = true;
			} elseif ( isset( $translationMap[ $langCode ] ) ) {
				$langUrl        = get_permalink( $translationMap[ $langCode ] ) ?: $this->urlStrategy->buildUrl( $currentUrl, $lang );
				$hasTranslation = true;
			} else {
				$langUrl        = $this->urlStrategy->buildUrl( $currentUrl, $lang );
				$hasTranslation = false;
			}

			$shouldShow = match ( $postTypeMode ) {
				'translate' => $hasTranslation || ( $langCode === $postLang ),
				default     => true,
			};

			if ( ! $shouldShow ) {
				continue;
			}

			$isActive = ( $langCode === $postLang );

			if ( $args['hide_current'] && $isActive ) {
				continue;
			}

			$items[] = [
				'code'            => $langCode,
				'name'            => $args['show_native_names']
					? $this->languageManager->getNativeLanguageName( $lang )
					: $this->languageManager->getLanguageName( $lang ),
				'url'             => $langUrl,
				'active'          => $isActive,
				'has_translation' => $hasTranslation,
				'flag'            => IDIOMATTICWP_ASSETS_URL . 'flags/' . strtolower( $lang->getBase() ) . '.svg',
			];
		}

		return $items;
	}

	// ── Private: context resolution ───────────────────────────────────────

	/**
	 * Return the WP_Post that represents the page currently being viewed,
	 * ignoring internal block-theme post types that are never "the page".
	 */
	private function resolveCurrentPost(): ?\WP_Post {
		$queried = get_queried_object();
		if ( $queried instanceof \WP_Post && $this->isPublicPostType( $queried->post_type ) ) {
			return $queried;
		}

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
			return 'none';
		}

		$config = get_option( 'idiomatticwp_post_type_config', [] );

		if ( ! is_array( $config ) || empty( $config ) ) {
			return 'translate';
		}

		return $config[ $postType ] ?? 'translate';
	}

	// ── Private: rendering ────────────────────────────────────────────────

	/**
	 * Horizontal list with flags and names.
	 */
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
				$html .= $content;
			} else {
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

	/**
	 * Native `<select>` dropdown — works without JavaScript enabled.
	 *
	 * Changes language immediately on selection via a small inline onchange handler.
	 * Suitable for sidebars and footers where a compact control is needed.
	 */
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

	/**
	 * CSS-only hover dropdown — displays current language with an expandable
	 * submenu of available languages.
	 *
	 * No JavaScript required. Uses `:focus-within` for keyboard accessibility.
	 * Integrates visually with nav menus and header bars.
	 */
	private function renderNavDropdown( array $items, array $args ): string {
		$current = null;
		foreach ( $items as $item ) {
			if ( $item['active'] ) {
				$current = $item;
				break;
			}
		}
		$current = $current ?? $items[0] ?? null;
		if ( $current === null ) {
			return '';
		}

		$html  = sprintf(
			'<nav class="idiomatticwp-switcher idiomatticwp-switcher--nav-dropdown" aria-label="%s">',
			esc_attr__( 'Language switcher', 'idiomattic-wp' )
		);
		$html .= '<ul class="idiomatticwp-nav-dd">';
		$html .= '<li class="idiomatticwp-nav-dd__current">';

		// Trigger: current language label
		$triggerInner = '';
		if ( $args['show_flags'] ) {
			$triggerInner .= sprintf(
				'<img src="%s" alt="%s" width="18" height="14" loading="lazy"> ',
				esc_url( $current['flag'] ),
				esc_attr( $current['name'] )
			);
		}
		if ( $args['show_names'] ) {
			$triggerInner .= sprintf( '<span>%s</span>', esc_html( $current['name'] ) );
		}
		$triggerInner .= '<span class="idiomatticwp-nav-dd__arrow" aria-hidden="true">&#9660;</span>';

		$html .= sprintf(
			'<button type="button" class="idiomatticwp-nav-dd__trigger" aria-haspopup="true" aria-expanded="false">%s</button>',
			$triggerInner
		);

		// Submenu
		$html .= sprintf(
			'<ul class="idiomatticwp-nav-dd__menu" role="menu" aria-label="%s">',
			esc_attr__( 'Available languages', 'idiomattic-wp' )
		);
		foreach ( $items as $item ) {
			$inner = '';
			if ( $args['show_flags'] ) {
				$inner .= sprintf(
					'<img src="%s" alt="" width="18" height="14" loading="lazy"> ',
					esc_url( $item['flag'] )
				);
			}
			if ( $args['show_names'] ) {
				$inner .= sprintf( '<span lang="%s">%s</span>', esc_attr( $item['code'] ), esc_html( $item['name'] ) );
			}

			if ( $item['active'] ) {
				$html .= sprintf(
					'<li role="menuitem" class="idiomatticwp-nav-dd__item idiomatticwp-nav-dd__item--active" aria-current="true">%s</li>',
					$inner
				);
			} else {
				$html .= sprintf(
					'<li role="menuitem" class="idiomatticwp-nav-dd__item"><a href="%s" hreflang="%s" lang="%s">%s</a></li>',
					esc_url( $item['url'] ),
					esc_attr( $item['code'] ),
					esc_attr( $item['code'] ),
					$inner
				);
			}
		}
		$html .= '</ul>'; // .idiomatticwp-nav-dd__menu

		$html .= '</li>'; // .idiomatticwp-nav-dd__current
		$html .= '</ul>'; // .idiomatticwp-nav-dd
		$html .= '</nav>';

		return $html;
	}

	/**
	 * Flags-only strip: a horizontal row of clickable flag images, no text.
	 */
	private function renderFlagsOnly( array $items ): string {
		$html = sprintf(
			'<div class="idiomatticwp-switcher idiomatticwp-switcher--flags" role="navigation" aria-label="%s">',
			esc_attr__( 'Language switcher', 'idiomattic-wp' )
		);

		foreach ( $items as $item ) {
			if ( $item['active'] ) {
				$html .= sprintf(
					'<span class="idiomatticwp-flag idiomatticwp-flag--active" aria-current="true" title="%s"><img src="%s" alt="%s" width="28" height="21" loading="lazy"></span>',
					esc_attr( $item['name'] ),
					esc_url( $item['flag'] ),
					esc_attr( $item['name'] )
				);
			} else {
				$html .= sprintf(
					'<a class="idiomatticwp-flag" href="%s" hreflang="%s" lang="%s" title="%s"><img src="%s" alt="%s" width="28" height="21" loading="lazy"></a>',
					esc_url( $item['url'] ),
					esc_attr( $item['code'] ),
					esc_attr( $item['code'] ),
					esc_attr( $item['name'] ),
					esc_url( $item['flag'] ),
					esc_attr( $item['name'] )
				);
			}
		}

		return $html . '</div>';
	}

	/**
	 * Floating sticky widget: a fixed-position button (current flag) that
	 * expands into a panel listing all available languages.
	 * Uses a CSS checkbox toggle — no JavaScript required.
	 */
	private function renderFloating( array $items, array $args ): string {
		$uid     = 'iwp-float-' . wp_rand( 1000, 9999 );
		$current = null;
		foreach ( $items as $item ) {
			if ( $item['active'] ) {
				$current = $item;
				break;
			}
		}
		$current = $current ?? $items[0] ?? null;
		if ( $current === null ) {
			return '';
		}

		$html  = '<div class="idiomatticwp-switcher idiomatticwp-switcher--floating">';
		$html .= sprintf( '<input type="checkbox" id="%s" class="idiomatticwp-float-toggle" aria-hidden="true">', esc_attr( $uid ) );
		$html .= sprintf(
			'<label for="%s" class="idiomatticwp-float-btn" aria-label="%s" title="%s">',
			esc_attr( $uid ),
			esc_attr__( 'Select language', 'idiomattic-wp' ),
			esc_attr( $current['name'] )
		);
		$html .= sprintf(
			'<img src="%s" alt="%s" width="24" height="18" loading="lazy">',
			esc_url( $current['flag'] ),
			esc_attr( $current['name'] )
		);
		$html .= '</label>';

		$html .= sprintf(
			'<ul class="idiomatticwp-float-panel" role="navigation" aria-label="%s">',
			esc_attr__( 'Language switcher', 'idiomattic-wp' )
		);
		foreach ( $items as $item ) {
			$inner = '';
			if ( $args['show_flags'] ) {
				$inner .= sprintf(
					'<img src="%s" alt="" width="20" height="15" loading="lazy"> ',
					esc_url( $item['flag'] )
				);
			}
			if ( $args['show_names'] ) {
				$inner .= sprintf( '<span>%s</span>', esc_html( $item['name'] ) );
			}

			if ( $item['active'] ) {
				$html .= sprintf(
					'<li class="idiomatticwp-float-item idiomatticwp-float-item--active" aria-current="true">%s</li>',
					$inner
				);
			} else {
				$html .= sprintf(
					'<li class="idiomatticwp-float-item"><a href="%s" hreflang="%s" lang="%s">%s</a></li>',
					esc_url( $item['url'] ),
					esc_attr( $item['code'] ),
					esc_attr( $item['code'] ),
					$inner
				);
			}
		}
		$html .= '</ul>';
		$html .= '</div>';

		return $html;
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
				<option value="list"         <?php selected( $style, 'list' ); ?>>List (flags + names)</option>
				<option value="dropdown"     <?php selected( $style, 'dropdown' ); ?>>Dropdown (select)</option>
				<option value="nav-dropdown" <?php selected( $style, 'nav-dropdown' ); ?>>Dropdown (hover)</option>
				<option value="flags-only"   <?php selected( $style, 'flags-only' ); ?>>Flags only</option>
				<option value="floating"     <?php selected( $style, 'floating' ); ?>>Floating sticky button</option>
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
		$allowedStyles = [ 'list', 'dropdown', 'nav-dropdown', 'flags-only', 'floating' ];
		$style         = in_array( $new['style'] ?? '', $allowedStyles, true ) ? $new['style'] : 'list';

		return [
			'title'             => sanitize_text_field( $new['title'] ?? '' ),
			'style'             => $style,
			'show_flags'        => ! empty( $new['show_flags'] ),
			'show_names'        => ! empty( $new['show_names'] ),
			'show_native_names' => ! empty( $new['show_native_names'] ),
		];
	}
}
