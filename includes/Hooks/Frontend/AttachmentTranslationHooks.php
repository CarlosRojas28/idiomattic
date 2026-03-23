<?php
/**
 * AttachmentTranslationHooks — frontend hooks for media/attachment metadata translation.
 *
 * Intercepts WordPress's attachment metadata getters to serve per-language
 * translations of alt text stored in the idiomatticwp_strings table.
 *
 * @package IdiomatticWP\Hooks\Frontend
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Frontend;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\Repositories\StringRepository;

class AttachmentTranslationHooks implements HookRegistrarInterface {

	private const DOMAIN = 'idiomatticwp_attachment';

	public function __construct(
		private LanguageManager $lm,
		private StringRepository $repo,
	) {}

	// ── register ──────────────────────────────────────────────────────────

	public function register(): void {
		// Intercept get_post_meta() calls for alt text.
		add_filter( 'get_post_metadata', [ $this, 'translateAltMeta' ], 10, 4 );

		// Intercept wp_get_attachment_image() img attributes (covers alt + any future attrs).
		add_filter( 'wp_get_attachment_image_attributes', [ $this, 'translateImageAttributes' ], 10, 2 );
	}

	// ── filters ───────────────────────────────────────────────────────────

	/**
	 * Translate `_wp_attachment_image_alt` meta when a non-default language is active.
	 *
	 * @param mixed  $value    Current filter value (null means not intercepted yet).
	 * @param int    $objectId Post/attachment ID.
	 * @param string $metaKey  Meta key being retrieved.
	 * @param bool   $single   Whether a single value was requested.
	 * @return mixed Translated string (or array thereof), or original $value.
	 */
	public function translateAltMeta( mixed $value, int $objectId, string $metaKey, bool $single ): mixed {
		if ( '_wp_attachment_image_alt' !== $metaKey ) {
			return $value;
		}

		$lang    = (string) $this->lm->getCurrentLanguage();
		$default = (string) $this->lm->getDefaultLanguage();

		if ( $lang === $default ) {
			return $value;
		}

		$translated = $this->getAltTranslation( $objectId, $lang );
		if ( null === $translated ) {
			return $value;
		}

		return $single ? $translated : [ $translated ];
	}

	/**
	 * Translate the `alt` attribute on attachment images.
	 *
	 * This filter runs after WordPress already reads the alt meta, so it acts
	 * as a reliable second layer (and the primary layer when the meta filter
	 * returns early due to caching).
	 *
	 * @param array    $attr       Image tag attributes.
	 * @param \WP_Post $attachment The attachment post object.
	 * @return array
	 */
	public function translateImageAttributes( array $attr, \WP_Post $attachment ): array {
		$lang    = (string) $this->lm->getCurrentLanguage();
		$default = (string) $this->lm->getDefaultLanguage();

		if ( $lang === $default ) {
			return $attr;
		}

		$translated = $this->getAltTranslation( $attachment->ID, $lang );
		if ( null !== $translated ) {
			$attr['alt'] = $translated;
		}

		return $attr;
	}

	// ── helpers ───────────────────────────────────────────────────────────

	/**
	 * Look up the translated alt text for an attachment in a given language.
	 * Returns null when no translation is stored.
	 */
	private function getAltTranslation( int $attachmentId, string $lang ): ?string {
		// Remove our own filter temporarily to avoid infinite recursion when
		// reading the raw alt meta to compute the source hash.
		remove_filter( 'get_post_metadata', [ $this, 'translateAltMeta' ], 10 );
		$rawAlt = (string) ( get_post_meta( $attachmentId, '_wp_attachment_image_alt', true ) ?: '' );
		add_filter( 'get_post_metadata', [ $this, 'translateAltMeta' ], 10, 4 );

		if ( '' === $rawAlt ) {
			return null;
		}

		return $this->repo->getTranslation( self::DOMAIN, md5( $rawAlt ), $lang );
	}
}
