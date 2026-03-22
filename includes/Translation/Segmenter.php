<?php
/**
 * Segmenter — splits post content into translatable segments and reassembles them.
 *
 * The segmenter is the bridge between raw post content and the AI translation
 * pipeline. It works in two directions:
 *
 *   segment()     → extract text segments from HTML or plain text
 *   reconstruct() → replace those segments in the original DOM with their
 *                   translated equivalents, preserving all HTML structure
 *
 * ── HTML segmentation ─────────────────────────────────────────────────────────
 * DOMDocument is used to walk the node tree. Only XML_TEXT_NODE nodes (actual
 * visible text) are collected as segments — HTML tags, attributes, and HTML
 * comments are left untouched. This means Gutenberg block markers
 * (<!-- wp:paragraph -->) are naturally preserved: DOMDocument treats them as
 * XML_COMMENT_NODE, which we neither extract nor modify.
 *
 * script and style elements are skipped entirely so their content is never
 * sent to the translation provider.
 *
 * ── Text segmentation ─────────────────────────────────────────────────────────
 * Plain text (titles, excerpts, custom fields) is split on sentence boundaries
 * (. ! ? followed by whitespace). Fragments shorter than 3 characters are
 * discarded to avoid sending punctuation-only strings to the AI.
 *
 * @package IdiomatticWP\Translation
 */

declare( strict_types=1 );

namespace IdiomatticWP\Translation;

use IdiomatticWP\Core\CustomElementRegistry;

class Segmenter {

	public function __construct( private CustomElementRegistry $registry ) {}

	/**
	 * Split content into an array of translatable text segments.
	 *
	 * @param string $content     Raw HTML or plain text.
	 * @param string $contentType 'html' (default) or 'text'.
	 * @return string[] Ordered list of non-empty text segments.
	 */
	public function segment( string $content, string $contentType = 'html' ): array {
		if ( empty( trim( $content ) ) ) {
			return [];
		}

		if ( $contentType === 'text' ) {
			return $this->segmentText( $content );
		}

		return $this->segmentHtml( $content );
	}

	/**
	 * Reassemble content by replacing original segments with their translations.
	 *
	 * For HTML content, the original DOM structure (tags, attributes, Gutenberg
	 * block comments, shortcodes) is preserved. Only the text nodes that were
	 * extracted by segment() are replaced — in the same order.
	 *
	 * For plain text, the translated segments are joined with a single space.
	 *
	 * @param string   $originalContent   The original unmodified content.
	 * @param string[] $translatedSegments Translations in the same order as segment() returned them.
	 * @param string   $contentType        'html' or 'text'.
	 * @return string Reassembled content with translated text.
	 */
	public function reconstruct( string $originalContent, array $translatedSegments, string $contentType = 'html' ): string {
		if ( $contentType === 'text' ) {
			return implode( ' ', $translatedSegments );
		}

		$dom = new \DOMDocument();
		// LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD prevents DOMDocument from
		// wrapping the fragment in <html><body> tags on saveHTML().
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $originalContent, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		$segmentsToConsume = $translatedSegments;
		$this->processNodesForReconstruction( $dom->childNodes, $segmentsToConsume );

		$html = $dom->saveHTML();
		// Remove the XML encoding declaration inserted above for UTF-8 handling.
		return str_replace( '<?xml encoding="UTF-8">', '', $html );
	}

	/**
	 * Segment all translatable fields of a post (core + registered custom fields).
	 *
	 * Returns an associative array keyed by field name. Each value is an array
	 * of string segments:
	 *   [
	 *     'post_title'   => ['Hello world'],
	 *     'post_content' => ['First paragraph.', 'Second paragraph.'],
	 *     'post_excerpt' => ['A short summary.'],
	 *     'my_meta_key'  => ['Custom field value.'],
	 *   ]
	 *
	 * @param \WP_Post $post Source post whose content should be segmented.
	 * @return array<string, string[]> Map of field name → segments.
	 */
	public function segmentPostFields( \WP_Post $post ): array {
		$fields = [
			'post_title'   => [ $post->post_title ],
			'post_content' => $this->segment( $post->post_content, 'html' ),
			'post_excerpt' => $this->segment( $post->post_excerpt, 'text' ),
		];

		// Append any custom fields registered via the CustomElementRegistry.
		$elements = $this->registry->getElements();
		foreach ( $elements as $key => $el ) {
			if ( $el['type'] !== 'post_meta' ) {
				continue;
			}

			$value = get_post_meta( $post->ID, $key, true );
			if ( empty( $value ) || ! is_string( $value ) ) {
				continue;
			}

			$fields[ $key ] = $this->segment( $value, $el['field_type'] ?? 'text' );
		}

		return $fields;
	}

	// ── Private helpers ───────────────────────────────────────────────────

	/**
	 * Split plain text on sentence boundaries (. ! ? followed by whitespace).
	 * Fragments shorter than 3 characters are discarded.
	 *
	 * @param string $text Plain text content.
	 * @return string[] Segments.
	 */
	private function segmentText( string $text ): array {
		$segments = preg_split( '/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		return array_values(
			array_map( 'trim', array_filter( $segments, fn( $s ) => strlen( trim( $s ) ) >= 3 ) )
		);
	}

	/**
	 * Extract visible text nodes from an HTML fragment using DOMDocument.
	 *
	 * Only XML_TEXT_NODE nodes are collected. HTML tags, attributes, and
	 * HTML comments (including Gutenberg block markers like <!-- wp:paragraph -->)
	 * are not modified. script and style element subtrees are skipped.
	 *
	 * @param string $html HTML content.
	 * @return string[] Segments.
	 */
	private function segmentHtml( string $html ): array {
		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		$segments = [];
		$this->extractTextFromNodes( $dom->childNodes, $segments );

		return array_values(
			array_map( 'trim', array_filter( $segments, fn( $s ) => strlen( trim( $s ) ) >= 3 ) )
		);
	}

	/**
	 * Recursively walk the DOM tree and collect text node content.
	 *
	 * Skips script and style subtrees entirely. HTML comments (XML_COMMENT_NODE)
	 * are automatically ignored because only XML_TEXT_NODE is collected.
	 *
	 * @param \DOMNodeList $nodes   Node list to walk.
	 * @param string[]     $segments Collected segments (passed by reference).
	 */
	private function extractTextFromNodes( \DOMNodeList $nodes, array &$segments ): void {
		foreach ( $nodes as $node ) {
			if ( $node->nodeType === XML_TEXT_NODE ) {
				$text = trim( $node->nodeValue );
				if ( strlen( $text ) >= 3 ) {
					$segments[] = $text;
				}
			} elseif ( $node->hasChildNodes() ) {
				// Skip script/style content — must not be sent to translation providers.
				if ( in_array( strtolower( $node->nodeName ), [ 'script', 'style' ], true ) ) {
					continue;
				}

				$this->extractTextFromNodes( $node->childNodes, $segments );
			}
		}
	}

	/**
	 * Recursively replace text nodes with their translated equivalents.
	 *
	 * Consumes $segments in order (array_shift), which must match the order
	 * produced by extractTextFromNodes(). Only text nodes with 3+ characters
	 * are replaced, to stay in sync with segmentation.
	 *
	 * @param \DOMNodeList $nodes    Node list to walk.
	 * @param string[]     $segments Remaining translated segments (passed by reference).
	 */
	private function processNodesForReconstruction( \DOMNodeList $nodes, array &$segments ): void {
		foreach ( $nodes as $node ) {
			if ( $node->nodeType === XML_TEXT_NODE ) {
				$text = trim( $node->nodeValue );
				if ( strlen( $text ) >= 3 && ! empty( $segments ) ) {
					$node->nodeValue = array_shift( $segments );
				}
			} elseif ( $node->hasChildNodes() ) {
				if ( in_array( strtolower( $node->nodeName ), [ 'script', 'style' ], true ) ) {
					continue;
				}
				$this->processNodesForReconstruction( $node->childNodes, $segments );
			}
		}
	}
}
