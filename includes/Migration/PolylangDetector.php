<?php
/**
 * PolylangDetector — detects a Polylang installation and its translation data.
 *
 * @package IdiomatticWP\Migration
 */

declare( strict_types=1 );

namespace IdiomatticWP\Migration;

class PolylangDetector {

	public function __construct( private \wpdb $wpdb ) {}

	public function isPolylangActive(): bool {
		return defined( 'POLYLANG_VERSION' );
	}

	/**
	 * Count the number of `post_translations` taxonomy terms, each of which
	 * represents one translation group (source post + its translations).
	 */
	public function getGroupCount(): int {
		$count = $this->wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->wpdb->term_taxonomy} WHERE taxonomy = 'post_translations'"
		);
		return (int) $count;
	}

	/**
	 * Return the languages Polylang has configured (from pll_languages option).
	 * Falls back to reading distinct language slugs from the taxonomy if the option
	 * is not present (e.g., Polylang is deactivated but its data still exists).
	 *
	 * @return string[]  Language slugs, e.g. ['en', 'fr', 'es']
	 */
	public function getLanguageSlugs(): array {
		$option = get_option( 'polylang' );
		if ( is_array( $option ) && ! empty( $option['languages'] ) ) {
			return array_values( (array) $option['languages'] );
		}

		// Fallback: read slugs from the `language` taxonomy.
		$rows = $this->wpdb->get_col(
			"SELECT t.slug
			 FROM {$this->wpdb->terms} t
			 INNER JOIN {$this->wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
			 WHERE tt.taxonomy = 'language'"
		);

		return array_values( $rows ?: [] );
	}
}
