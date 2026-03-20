<?php
/**
 * Segmenter — splits HTML/text into translatable segments and reconstructs them.
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Translation;

use IdiomatticWP\Core\CustomElementRegistry;

class Segmenter
{

    public function __construct(private
        CustomElementRegistry $registry
        )
    {
    }

    /**
     * Split content into segments.
     */
    public function segment(string $content, string $contentType = 'html'): array
    {
        if (empty(trim($content)))
            return [];

        if ($contentType === 'text') {
            return $this->segmentText($content);
        }

        return $this->segmentHtml($content);
    }

    /**
     * Reconstruct content from translated segments.
     */
    public function reconstruct(string $originalContent, array $translatedSegments, string $contentType = 'html'): string
    {
        if ($contentType === 'text') {
            return implode(' ', $translatedSegments);
        }

        $dom = new \DOMDocument();
        // Use LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD to avoid adding <html>/<body> tags
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $originalContent, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $segmentsToConsume = $translatedSegments;
        $this->processNodesForReconstruction($dom->childNodes, $segmentsToConsume);

        $html = $dom->saveHTML();
        // Remove the XML encoding tag added for DOMDocument
        return str_replace('<?xml encoding="UTF-8">', '', $html);
    }

    /**
     * Segment all translatable fields of a post.
     */
    public function segmentPostFields(\WP_Post $post): array
    {
        $fields = [
            'post_title' => [$post->post_title],
            'post_content' => $this->segment($post->post_content, 'html'),
            'post_excerpt' => $this->segment($post->post_excerpt, 'text'),
        ];

        // Add custom fields from registry
        $elements = $this->registry->getElements();
        foreach ($elements as $key => $el) {
            if ($el['type'] !== 'post_meta')
                continue;

            $value = get_post_meta($post->ID, $key, true);
            if (empty($value) || !is_string($value))
                continue;

            $fields[$key] = $this->segment($value, $el['field_type'] ?? 'text');
        }

        return $fields;
    }

    private function segmentText(string $text): array
    {
        // Split by sentence boundaries: . ! ? followed by space or end
        $segments = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        return array_map('trim', array_filter($segments, fn($s) => strlen(trim($s)) >= 3));
    }

    private function segmentHtml(string $html): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        // Add encoding to handle UTF-8 correctly
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $segments = [];
        $this->extractTextFromNodes($dom->childNodes, $segments);

        return array_map('trim', array_filter($segments, fn($s) => strlen(trim($s)) >= 3));
    }

    private function extractTextFromNodes(\DOMNodeList $nodes, array &$segments): void
    {
        foreach ($nodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE) {
                $text = trim($node->nodeValue);
                if (strlen($text) >= 3) {
                    $segments[] = $text;
                }
            }
            elseif ($node->hasChildNodes()) {
                // Skip script/style tags
                if (in_array(strtolower($node->nodeName), ['script', 'style']))
                    continue;

                // Treat Gutenberg blocks and shortcodes as opaque if needed (TBD)

                $this->extractTextFromNodes($node->childNodes, $segments);
            }
        }
    }

    private function processNodesForReconstruction(\DOMNodeList $nodes, array &$segments): void
    {
        foreach ($nodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE) {
                $text = trim($node->nodeValue);
                if (strlen($text) >= 3 && !empty($segments)) {
                    $node->nodeValue = array_shift($segments);
                }
            }
            elseif ($node->hasChildNodes()) {
                if (in_array(strtolower($node->nodeName), ['script', 'style']))
                    continue;
                $this->processNodesForReconstruction($node->childNodes, $segments);
            }
        }
    }
}
