<?php
/**
 * TermTranslationHooks — admin hooks for taxonomy term translation fields.
 *
 * Adds translation input fields (name, slug, description) to the term edit
 * and add-term screens for every registered taxonomy. Saves submissions via
 * the create_term and edit_term WordPress actions.
 *
 * @package IdiomatticWP\Hooks\Admin
 */

declare( strict_types=1 );

namespace IdiomatticWP\Hooks\Admin;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Contracts\TermTranslationRepositoryInterface;
use IdiomatticWP\Core\LanguageManager;

class TermTranslationHooks implements HookRegistrarInterface {

	public function __construct(
		private TermTranslationRepositoryInterface $repo,
		private LanguageManager $languageManager,
	) {}

	// ── register ──────────────────────────────────────────────────────────

	public function register(): void {
		// Hook taxonomies registered after this point.
		add_action( 'registered_taxonomy', [ $this, 'hookTaxonomy' ], 10, 1 );

		// Hook all taxonomies already registered during admin_init.
		add_action( 'admin_init', [ $this, 'hookAllTaxonomies' ] );

		// Save on term create and term edit.
		add_action( 'create_term', [ $this, 'saveTerm' ], 10, 3 );
		add_action( 'edit_term',   [ $this, 'saveTerm' ], 10, 3 );
	}

	// ── taxonomy hooking ──────────────────────────────────────────────────

	public function hookAllTaxonomies(): void {
		foreach ( get_taxonomies() as $taxonomy ) {
			$this->hookTaxonomy( $taxonomy );
		}
	}

	public function hookTaxonomy( string $taxonomy ): void {
		add_action( "{$taxonomy}_edit_form_fields", [ $this, 'renderEditFields' ], 10, 2 );
		add_action( "{$taxonomy}_add_form_fields",  [ $this, 'renderAddFields' ],  10, 1 );
	}

	// ── rendering ─────────────────────────────────────────────────────────

	public function renderEditFields( \WP_Term $term, string $taxonomy ): void {
		$languages = $this->getNonDefaultLanguages();
		if ( empty( $languages ) ) {
			return;
		}

		$existing = $this->repo->findAllForTerm( $term->term_id );

		wp_nonce_field( 'idiomatticwp_term_translation_' . $term->term_id, '_iwp_term_nonce' );

		foreach ( $languages as $lang ) {
			$langCode = (string) $lang;
			$langName = $this->languageManager->getLanguageName( $lang );
			$trans    = $existing[ $langCode ] ?? [ 'name' => '', 'slug' => '', 'description' => '' ];
			?>
			<tr class="form-field">
				<th scope="row" colspan="2">
					<strong><?php echo esc_html( sprintf( __( 'Translation: %s', 'idiomattic-wp' ), $langName ) ); ?></strong>
				</th>
			</tr>
			<tr class="form-field">
				<th scope="row">
					<label for="iwp_term_trans_<?php echo esc_attr( $langCode ); ?>_name">
						<?php echo esc_html( sprintf( __( '%s — Name', 'idiomattic-wp' ), $langName ) ); ?>
					</label>
				</th>
				<td>
					<input
						type="text"
						id="iwp_term_trans_<?php echo esc_attr( $langCode ); ?>_name"
						name="iwp_term_trans[<?php echo esc_attr( $langCode ); ?>][name]"
						value="<?php echo esc_attr( $trans['name'] ?? '' ); ?>"
						class="regular-text"
					/>
				</td>
			</tr>
			<tr class="form-field">
				<th scope="row">
					<label for="iwp_term_trans_<?php echo esc_attr( $langCode ); ?>_slug">
						<?php echo esc_html( sprintf( __( '%s — Slug', 'idiomattic-wp' ), $langName ) ); ?>
					</label>
				</th>
				<td>
					<input
						type="text"
						id="iwp_term_trans_<?php echo esc_attr( $langCode ); ?>_slug"
						name="iwp_term_trans[<?php echo esc_attr( $langCode ); ?>][slug]"
						value="<?php echo esc_attr( $trans['slug'] ?? '' ); ?>"
						class="regular-text"
					/>
				</td>
			</tr>
			<tr class="form-field">
				<th scope="row">
					<label for="iwp_term_trans_<?php echo esc_attr( $langCode ); ?>_description">
						<?php echo esc_html( sprintf( __( '%s — Description', 'idiomattic-wp' ), $langName ) ); ?>
					</label>
				</th>
				<td>
					<textarea
						id="iwp_term_trans_<?php echo esc_attr( $langCode ); ?>_description"
						name="iwp_term_trans[<?php echo esc_attr( $langCode ); ?>][description]"
						rows="4"
						class="large-text"
					><?php echo esc_textarea( $trans['description'] ?? '' ); ?></textarea>
				</td>
			</tr>
			<?php
		}
	}

	public function renderAddFields( string $taxonomy ): void {
		$languages = $this->getNonDefaultLanguages();
		if ( empty( $languages ) ) {
			return;
		}

		// On the add-term screen the term_id is not yet known; use 0 as a
		// placeholder — the nonce is verified against 0 on create_term.
		wp_nonce_field( 'idiomatticwp_term_translation_0', '_iwp_term_nonce' );

		foreach ( $languages as $lang ) {
			$langCode = (string) $lang;
			$langName = $this->languageManager->getLanguageName( $lang );
			?>
			<div class="form-field">
				<label for="iwp_term_trans_<?php echo esc_attr( $langCode ); ?>_name">
					<?php echo esc_html( sprintf( __( '%s — Name', 'idiomattic-wp' ), $langName ) ); ?>
				</label>
				<input
					type="text"
					id="iwp_term_trans_<?php echo esc_attr( $langCode ); ?>_name"
					name="iwp_term_trans[<?php echo esc_attr( $langCode ); ?>][name]"
					value=""
					class="regular-text"
				/>
			</div>
			<div class="form-field">
				<label for="iwp_term_trans_<?php echo esc_attr( $langCode ); ?>_slug">
					<?php echo esc_html( sprintf( __( '%s — Slug', 'idiomattic-wp' ), $langName ) ); ?>
				</label>
				<input
					type="text"
					id="iwp_term_trans_<?php echo esc_attr( $langCode ); ?>_slug"
					name="iwp_term_trans[<?php echo esc_attr( $langCode ); ?>][slug]"
					value=""
					class="regular-text"
				/>
			</div>
			<div class="form-field">
				<label for="iwp_term_trans_<?php echo esc_attr( $langCode ); ?>_description">
					<?php echo esc_html( sprintf( __( '%s — Description', 'idiomattic-wp' ), $langName ) ); ?>
				</label>
				<textarea
					id="iwp_term_trans_<?php echo esc_attr( $langCode ); ?>_description"
					name="iwp_term_trans[<?php echo esc_attr( $langCode ); ?>][description]"
					rows="4"
					class="large-text"
				></textarea>
			</div>
			<?php
		}
	}

	// ── saving ────────────────────────────────────────────────────────────

	/**
	 * @param int    $termId   The saved term ID.
	 * @param int    $ttId     The term-taxonomy ID (unused but required by WP hook signature).
	 * @param string $taxonomy The taxonomy name.
	 */
	public function saveTerm( int $termId, int $ttId, string $taxonomy ): void {
		// The nonce on the add form uses 0; on edit it uses the real term_id.
		// We check both to handle the create_term case where term_id is now
		// known but the nonce was generated with 0.
		$nonce = sanitize_text_field( wp_unslash( $_POST['_iwp_term_nonce'] ?? '' ) );
		$validEdit   = wp_verify_nonce( $nonce, 'idiomatticwp_term_translation_' . $termId );
		$validCreate = wp_verify_nonce( $nonce, 'idiomatticwp_term_translation_0' );

		if ( ! $validEdit && ! $validCreate ) {
			return;
		}

		$rawTranslations = $_POST['iwp_term_trans'] ?? [];
		if ( ! is_array( $rawTranslations ) ) {
			return;
		}

		$activeLanguages = $this->getNonDefaultLanguages();
		$activeCodes     = array_map( fn( $l ) => (string) $l, $activeLanguages );

		foreach ( $rawTranslations as $langCode => $fields ) {
			$langCode = sanitize_text_field( (string) $langCode );

			// Only process known active non-default languages.
			if ( ! in_array( $langCode, $activeCodes, true ) ) {
				continue;
			}

			if ( ! is_array( $fields ) ) {
				continue;
			}

			$name        = sanitize_text_field( wp_unslash( $fields['name'] ?? '' ) );
			$slug        = sanitize_title( sanitize_text_field( wp_unslash( $fields['slug'] ?? '' ) ) );
			$description = wp_kses_post( wp_unslash( $fields['description'] ?? '' ) );

			// If all fields are empty, delete any existing translation.
			if ( '' === $name && '' === $slug && '' === $description ) {
				$this->repo->delete( $termId, $langCode );
				continue;
			}

			$this->repo->save( $termId, $taxonomy, $langCode, [
				'name'        => $name,
				'slug'        => $slug,
				'description' => $description,
			] );
		}
	}

	// ── helpers ───────────────────────────────────────────────────────────

	/**
	 * Return all active languages except the site default.
	 *
	 * @return \IdiomatticWP\ValueObjects\LanguageCode[]
	 */
	private function getNonDefaultLanguages(): array {
		$default = (string) $this->languageManager->getDefaultLanguage();

		return array_filter(
			$this->languageManager->getActiveLanguages(),
			fn( $lang ) => (string) $lang !== $default
		);
	}
}
