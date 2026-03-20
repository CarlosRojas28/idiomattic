<?php
/**
 * CustomElementRegistry — central hub for translatable elements.
 *
 * Allows registration of post meta, term meta, options, shortcodes,
 * blocks, and Elementor widgets that need translation.
 *
 * @package IdiomatticWP\Core
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Core;

class CustomElementRegistry
{

    private array $elements = [];

    public function __construct()
    {
        // 1. Register built-in WordPress fields (source = 'core' so the
        //    Settings UI can exclude them from the Custom Fields section).
        $this->registerPostField('*', 'post_title',   ['label' => 'Title',   'source' => 'core']);
        $this->registerPostField('*', 'post_content', ['label' => 'Content', 'field_type' => 'html',     'source' => 'core']);
        $this->registerPostField('*', 'post_excerpt', ['label' => 'Excerpt', 'field_type' => 'textarea', 'source' => 'core']);

        // 2. Scan JSON configs from themes and plugins
        $this->scanJsonConfigs();

        // 3. Scan wpml-config.xml files from themes and plugins
        $this->scanWpmlConfigs();

        // 4. Apply manual filter for programmatic registration
        $this->elements = apply_filters('idiomatticwp_registered_elements', $this->elements);

        // 5. Auto-detect ACF fields if ACF is active
		if (function_exists('acf_get_field_groups')) {
			$this->autoDetectAcfFields();
		}
    }

    /**
     * Register a post meta field as translatable.
     */
    public function registerPostField(string|array $postTypes, string $metaKey, array $options = []): void
    {
        $postTypes = is_string($postTypes) ? [$postTypes] : $postTypes;

        foreach ($postTypes as $postType) {
            $id = "post_meta:{$postType}:{$metaKey}";
            $this->elements[$id] = array_merge([
                'id' => $id,
                'type' => 'post_meta',
                'post_types' => $postTypes,
                'key' => $metaKey,
                'field_type' => 'text',
                'mode' => 'translate',
                'label' => $metaKey,
            ], $options);
        }

        do_action('idiomatticwp_field_registered', $postTypes, $metaKey, $options);
    }

    /**
     * Register a term meta field.
     */
    public function registerTermField(string|array $taxonomies, string $metaKey, array $options = []): void
    {
        $taxonomies = is_string($taxonomies) ? [$taxonomies] : $taxonomies;
        foreach ($taxonomies as $tax) {
            $id = "term_meta:{$tax}:{$metaKey}";
            $this->elements[$id] = array_merge([
                'id' => $id,
                'type' => 'term_meta',
                'taxonomies' => $taxonomies,
                'key' => $metaKey,
                'field_type' => 'text',
                'mode' => 'translate',
            ], $options);
        }
    }

    /**
     * Register a block as having translatable attributes.
     */
    public function registerBlock(string $blockName, array $attributes, array $options = []): void
    {
        $id = "block_attribute:{$blockName}";
        $this->elements[$id] = array_merge([
            'id' => $id,
            'type' => 'block_attribute',
            'key' => $blockName,
            'attributes' => $attributes,
            'mode' => 'translate',
        ], $options);
    }

    /**
     * Register translatable fields for a shortcode.
     */
    public function registerShortcode(string $tag, array $attributes, array $options = []): void
    {
        $id = "shortcode:{$tag}";
        $this->elements[$id] = array_merge([
            'id' => $id,
            'type' => 'shortcode',
            'key' => $tag,
            'attributes' => $attributes,
            'mode' => 'translate',
        ], $options);
    }

    /**
     * Register an option as translatable.
     */
    public function registerOption(string $optionName, array $options = []): void
    {
        $id = "option:{$optionName}";
        $this->elements[$id] = array_merge([
            'id' => $id,
            'type' => 'option',
            'key' => $optionName,
            'field_type' => 'text',
            'mode' => 'translate',
        ], $options);
    }

    /**
     * Register fields for Elementor widgets.
     */
    public function registerElementorWidget(string $widgetName, array $controlIds, array $options = []): void
    {
        $id = "elementor_field:{$widgetName}";
        $this->elements[$id] = array_merge([
            'id' => $id,
            'type' => 'elementor_field',
            'key' => $widgetName,
            'attributes' => $controlIds,
            'mode' => 'translate',
        ], $options);
    }

    /**
     * Register a custom source with callbacks.
     */
    public function registerCustom(string $id, callable $getter, callable $setter, array $options = []): void
    {
        $this->elements[$id] = array_merge([
            'id' => $id,
            'type' => 'custom',
            'getter' => $getter,
            'setter' => $setter,
            'mode' => 'translate',
        ], $options);
    }

    /**
     * Get translatable elements for a specific post type.
     */
    public function getFieldsForPostType(string $postType): array
    {
        $found = array_filter($this->elements, function ($el) use ($postType) {
            return $el['type'] === 'post_meta' &&
            (in_array($postType, $el['post_types']) || in_array('*', $el['post_types']));
        });

        return apply_filters('idiomatticwp_fields_for_post_type', $found, $postType);
    }

    public function getBlocks(): array
    {
        return array_filter($this->elements, fn($el) => $el['type'] === 'block_attribute');
    }

    public function getOptions(): array
    {
        return array_filter($this->elements, fn($el) => $el['type'] === 'option');
    }

    public function getShortcodes(): array
    {
        return array_filter($this->elements, fn($el) => $el['type'] === 'shortcode');
    }

    public function getElementorWidgets(): array
    {
        return array_filter($this->elements, fn($el) => $el['type'] === 'elementor_field');
    }

    public function getByKey(string $key): ?array
    {
        foreach ($this->elements as $element) {
            if ($element['key'] === $key)
                return $element;
        }
        return null;
    }

    /**
    * Collect directories for active theme(s) + active plugins.
    */
    private function collectActiveDirs(): array
    {
    $dirs = [ get_stylesheet_directory(), get_template_directory() ];

    $activePlugins = get_option( 'active_plugins' );
    if ( is_array( $activePlugins ) ) {
    foreach ( $activePlugins as $plugin ) {
    $dirs[] = dirname( WP_PLUGIN_DIR . '/' . $plugin );
    }
    }

    return array_unique( $dirs );
    }

    /**
    * Scan for idiomattic-elements.json in active theme and plugins.
    */
    private function scanJsonConfigs(): void
	{
		foreach ( $this->collectActiveDirs() as $dir ) {
			$file = $dir . '/idiomattic-elements.json';
			if ( file_exists( $file ) ) {
				$this->loadJsonConfig( $file );
			}
		}
	}

	/**
	 * Scan for wpml-config.xml in active theme and plugins.
	 * Only processes files for plugins/themes that do NOT already have
	 * an idiomattic-elements.json (to avoid double-registration).
	 */
	private function scanWpmlConfigs(): void
	{
		foreach ( $this->collectActiveDirs() as $dir ) {
			// Skip if the plugin/theme already has a native idiomattic file
			if ( file_exists( $dir . '/idiomattic-elements.json' ) ) {
				continue;
			}
			$wpmlFile = $dir . '/wpml-config.xml';
			if ( file_exists( $wpmlFile ) ) {
				$this->loadWpmlConfig( $wpmlFile );
			}
		}
	}

	/**
	 * Parse a single wpml-config.xml and register its elements.
	 * Uses a lightweight inline parser to avoid a circular dependency
	 * on WpmlConfigParser (which depends on this class).
	 */
	private function loadWpmlConfig( string $path ): void
	{
		try {
			$xml = new \SimpleXMLElement( file_get_contents( $path ) );
		} catch ( \Exception $e ) {
			return;
		}

		$modeMap = [
			'translate'  => 'translate',
			'copy'       => 'copy',
			'copy-once'  => 'copy',
		];

		// custom-fields → post meta
		foreach ( $xml->{'custom-fields'} ?? [] as $section ) {
			foreach ( $section->{'custom-field'} ?? [] as $field ) {
				$key    = trim( (string) $field );
				$action = strtolower( (string) ( $field['action'] ?? 'translate' ) );
				$mode   = $modeMap[ $action ] ?? 'translate';
				$pt     = trim( (string) ( $field['post_type'] ?? '*' ) ) ?: '*';

				if ( $key === '' || $action === 'ignore' ) continue;

				$this->registerPostField( $pt, $key, [
					'label'  => $this->humaniseKey( $key ),
					'mode'   => $mode,
					'source' => 'wpml-config',
				] );
			}
		}

		// custom-options → options
		foreach ( $xml->{'custom-options'} ?? [] as $section ) {
			foreach ( $section->{'custom-option'} ?? [] as $option ) {
				$key    = trim( (string) $option );
				$action = strtolower( (string) ( $option['action'] ?? 'translate' ) );
				$mode   = $modeMap[ $action ] ?? 'translate';

				if ( $key === '' || $action === 'ignore' ) continue;

				$this->registerOption( $key, [
					'label'  => $this->humaniseKey( $key ),
					'mode'   => $mode,
					'source' => 'wpml-config',
				] );
			}
		}

		// admin-texts → options (leaf nodes of nested key trees)
		foreach ( $xml->{'admin-texts'} ?? [] as $section ) {
			foreach ( $section->key ?? [] as $keyNode ) {
				$this->loadWpmlAdminTextKey( $keyNode, '' );
			}
		}

		// shortcodes
		foreach ( $xml->shortcodes ?? [] as $section ) {
			foreach ( $section->shortcode ?? [] as $sc ) {
				$tag = trim( (string) ( $sc->tag ?? '' ) );
				if ( $tag === '' ) continue;

				$attrs = [];
				foreach ( $sc->attributes->attribute ?? [] as $attr ) {
					$n = trim( (string) ( $attr->name ?? '' ) );
					if ( $n !== '' ) $attrs[] = $n;
				}
				$this->registerShortcode( $tag, $attrs, [ 'source' => 'wpml-config' ] );
			}
		}
	}

	private function loadWpmlAdminTextKey( \SimpleXMLElement $node, string $prefix ): void
	{
		$name = trim( (string) ( $node['name'] ?? '' ) );
		if ( $name === '' ) return;

		$fullKey = $prefix !== '' ? "{$prefix}.{$name}" : $name;

		if ( count( $node->key ) === 0 ) {
			$this->registerOption( $fullKey, [
				'label'  => $this->humaniseKey( $name ),
				'mode'   => 'translate',
				'source' => 'wpml-config',
			] );
		} else {
			foreach ( $node->key as $child ) {
				$this->loadWpmlAdminTextKey( $child, $fullKey );
			}
		}
	}

	private function humaniseKey( string $key ): string
	{
		$key   = ltrim( $key, '_' );
		$parts = explode( '.', $key );
		$last  = end( $parts );
		return ucwords( str_replace( [ '_', '-' ], ' ', $last ) );
	}

    private function loadJsonConfig(string $path): void
    {
        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data))
            return;

        if (isset($data['post_fields'])) {
            foreach ($data['post_fields'] as $field) {
                $this->registerPostField($field['post_types'] ?? '*', $field['key'], $field);
            }
        }
        if (isset($data['term_fields'])) {
            foreach ($data['term_fields'] as $field) {
                $this->registerTermField($field['taxonomies'] ?? '*', $field['key'], $field);
            }
        }
        if (isset($data['options'])) {
            foreach ($data['options'] as $option) {
                $this->registerOption($option['key'], $option);
            }
        }
        if (isset($data['shortcodes'])) {
            foreach ($data['shortcodes'] as $sc) {
                $this->registerShortcode($sc['key'], $sc['attributes'] ?? [], $sc);
            }
        }
        if (isset($data['blocks'])) {
            foreach ($data['blocks'] as $block) {
                $this->registerBlock($block['key'], $block['attributes'] ?? [], $block);
            }
        }
        if (isset($data['elementor_widgets'])) {
            foreach ($data['elementor_widgets'] as $widget) {
                $this->registerElementorWidget($widget['key'], $widget['attributes'] ?? [], $widget);
            }
        }
    }

    /**
     * Auto-detect ACF fields if ACF is active.
     */
    private function autoDetectAcfFields(): void
    {
        if (!function_exists('acf_get_field_groups'))
            return;

        foreach (acf_get_field_groups() as $group) {
            $postTypes = [];
            if (isset($group['location'])) {
                foreach ($group['location'] as $rules) {
                    foreach ($rules as $rule) {
                        if ($rule['param'] === 'post_type' && $rule['operator'] === '==') {
                            $postTypes[] = $rule['value'];
                        }
                    }
                }
            }

            if (empty($postTypes))
                $postTypes = '*';

            foreach (acf_get_fields($group) as $field) {
                $this->registerAcfField($postTypes, $field);
            }
        }
    }

    private function registerAcfField(string|array $postTypes, array $field): void
    {
        $mode = match ($field['type']) {
                'text', 'textarea', 'wysiwyg', 'url' => 'translate',
                'image', 'file', 'gallery' => 'copy',
                default => 'ignore',
            };

        $this->registerPostField($postTypes, $field['name'], [
            'label' => $field['label'],
            'field_type' => $field['type'] === 'wysiwyg' ? 'rich_text' : 'text',
            'mode' => $mode
        ]);

        if (!empty($field['sub_fields'])) {
            foreach ($field['sub_fields'] as $sub) {
                $this->registerAcfField($postTypes, $sub);
            }
        }
    }

    public function getElements(): array
    {
        return $this->elements;
    }

    /**
     * Return all registered wp_options elements.
     * Key = option name, value = element config array.
     *
     * @return array<string, array>
     */
    public function getRegisteredOptions(): array {
        $options = [];
        foreach ( $this->elements as $id => $config ) {
            if ( ( $config['type'] ?? '' ) === 'option' ) {
                $key           = $config['option_name'] ?? $config['key'] ?? null;
                if ( $key ) {
                    $options[ $key ] = $config;
                }
            }
        }
        return $options;
    }
}
