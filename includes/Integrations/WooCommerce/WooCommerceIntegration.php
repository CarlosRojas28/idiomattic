<?php
/**
 * WooCommerceIntegration — support for products, taxonomies, cart/checkout
 * strings, transactional emails, and product variations.
 *
 * @package IdiomatticWP\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace IdiomatticWP\Integrations\WooCommerce;

use IdiomatticWP\Contracts\IntegrationInterface;
use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\Core\CustomElementRegistry;

class WooCommerceIntegration implements IntegrationInterface
{
    /**
     * Common WooCommerce product attribute taxonomy prefixes registered for
     * translation. Wildcard registration (pa_*) is handled by enumerating
     * the most common ones; dynamic attributes are picked up via the
     * woocommerce_attribute_taxonomies filter at runtime.
     */
    private const COMMON_PRODUCT_ATTRIBUTES = [
        'pa_color',
        'pa_colour',
        'pa_size',
        'pa_material',
        'pa_weight',
        'pa_style',
        'pa_brand',
        'pa_pattern',
    ];

    /**
     * Order object captured from the woocommerce_email_before_order_table
     * action, used to resolve the correct locale for outgoing emails.
     */
    private ?\WC_Order $currentEmailOrder = null;

    private MultiCurrency $multiCurrency;

    public function __construct(
        private LanguageManager $languageManager,
        private CustomElementRegistry $registry
    ) {
        $this->multiCurrency = new MultiCurrency( $this->languageManager );
    }

    // ── IntegrationInterface ───────────────────────────────────────────────

    public function isAvailable(): bool
    {
        return class_exists('WooCommerce');
    }

    public function register(): void
    {
        // ── Multi-currency ────────────────────────────────────────────────
        $this->multiCurrency->register();

        // ── Order language ────────────────────────────────────────────────
        add_action('woocommerce_checkout_order_created', [$this, 'saveOrderLanguage']);

        // ── Email locale ──────────────────────────────────────────────────
        // Capture the order object early so switchEmailLocale() can use it.
        add_action('woocommerce_email_before_order_table', [$this, 'captureEmailOrder'], 1, 1);
        add_filter('woocommerce_email_setup_locale', [$this, 'switchEmailLocale']);

        // ── Translatable fields & taxonomies ──────────────────────────────
        add_action('init', [$this, 'registerProductFields'], 25);

        // ── Cart / checkout display strings ───────────────────────────────
        add_filter('woocommerce_cart_item_name', [$this, 'filterCartItemName'], 10, 3);
        add_filter('woocommerce_product_title', [$this, 'filterProductTitle'], 10, 2);

        // ── Taxonomy term translation ─────────────────────────────────────
        add_filter('woocommerce_get_product_terms', [$this, 'filterProductTermNames'], 10, 4);

        // ── Post-type list ────────────────────────────────────────────────
        add_filter('idiomatticwp_translatable_post_types', [$this, 'addWooPostTypes']);
    }

    // ── Order language persistence ─────────────────────────────────────────

    public function saveOrderLanguage(\WC_Order $order): void
    {
        $lang = $this->languageManager->getCurrentLanguage();
        $order->update_meta_data('_idiomatticwp_order_lang', (string) $lang);
        $order->save_meta_data();
    }

    // ── Email locale handling ──────────────────────────────────────────────

    /**
     * Fired by woocommerce_email_before_order_table — captures the real order
     * object so we don't have to rely on query-string parameters.
     *
     * @param \WC_Order|\WC_Order_Refund $order
     */
    public function captureEmailOrder(mixed $order): void
    {
        if ($order instanceof \WC_Order) {
            $this->currentEmailOrder = $order;
        }
    }

    /**
     * Return the WordPress locale that matches the language the customer used
     * when placing the order.  Falls back to $locale (WooCommerce default) if
     * no order or no stored language can be found.
     */
    public function switchEmailLocale(string $locale): string
    {
        $order = $this->resolveEmailOrder();
        if ($order === null) {
            return $locale;
        }

        $lang = $order->get_meta('_idiomatticwp_order_lang');
        if (!$lang) {
            return $locale;
        }

        foreach ($this->languageManager->getActiveLanguages() as $languageCode) {
            if ((string) $languageCode === $lang) {
                return $languageCode->toLocale();
            }
        }

        return $locale;
    }

    /**
     * Try to find the WC_Order for the email being sent.
     *
     * Priority:
     *   1. The order captured from woocommerce_email_before_order_table.
     *   2. Order meta from woocommerce_order_get_formatted_billing_address
     *      (not easily available here, so we skip to next).
     *   3. The current WooCommerce email object, if accessible.
     *   4. Nothing — return null.
     */
    private function resolveEmailOrder(): ?\WC_Order
    {
        // Best case: already captured via the action hook.
        if ($this->currentEmailOrder !== null) {
            return $this->currentEmailOrder;
        }

        // Fallback: WooCommerce sometimes stores the current email globally.
        if (isset($GLOBALS['woocommerce']) && method_exists(\WC_Emails::class, 'instance')) {
            $mailer = \WC_Emails::instance();
            $emails = $mailer->get_emails();
            foreach ($emails as $email) {
                if (!empty($email->object) && $email->object instanceof \WC_Order) {
                    return $email->object;
                }
            }
        }

        return null;
    }

    // ── Field & taxonomy registration ──────────────────────────────────────

    public function registerProductFields(): void
    {
        // ── Core product meta fields ──────────────────────────────────────
        $this->registry->registerPostField(
            ['product', 'product_variation'],
            '_short_description',
            ['label' => 'Short Description', 'field_type' => 'textarea']
        );

        // Fields to copy (not translate) across languages
        foreach (['_regular_price', '_sale_price', '_sku', '_stock'] as $field) {
            $this->registry->registerPostField(
                ['product', 'product_variation'],
                $field,
                ['mode' => 'copy']
            );
        }

        // ── Product variation-specific fields ─────────────────────────────
        $this->registry->registerPostField(
            ['product_variation'],
            '_variation_description',
            ['label' => 'Variation Description', 'field_type' => 'textarea']
        );

        // ── Core WooCommerce taxonomies ───────────────────────────────────
        $this->registry->registerTaxonomy(
            ['product'],
            'product_cat',
            ['label' => 'Product Categories', 'hierarchical' => true]
        );

        $this->registry->registerTaxonomy(
            ['product'],
            'product_tag',
            ['label' => 'Product Tags', 'hierarchical' => false]
        );

        // ── Common product attribute taxonomies (pa_*) ────────────────────
        foreach (self::COMMON_PRODUCT_ATTRIBUTES as $taxonomy) {
            $this->registry->registerTaxonomy(
                ['product', 'product_variation'],
                $taxonomy,
                ['label' => ucfirst(str_replace('pa_', '', $taxonomy)), 'hierarchical' => false]
            );
        }

        // ── Dynamic product attribute taxonomies ──────────────────────────
        // Register any custom attributes the store owner has created.
        $this->registerDynamicProductAttributes();
    }

    /**
     * Register attribute taxonomies created dynamically through the
     * WooCommerce "Attributes" admin screen.  Uses wc_get_attribute_taxonomies()
     * when WooCommerce is fully bootstrapped; silently skips otherwise.
     */
    private function registerDynamicProductAttributes(): void
    {
        if (!function_exists('wc_get_attribute_taxonomies')) {
            return;
        }

        $attributeTaxonomies = wc_get_attribute_taxonomies();
        if (empty($attributeTaxonomies)) {
            return;
        }

        $alreadyRegistered = self::COMMON_PRODUCT_ATTRIBUTES;

        foreach ($attributeTaxonomies as $attribute) {
            $taxonomy = wc_attribute_taxonomy_name($attribute->attribute_name);
            if (in_array($taxonomy, $alreadyRegistered, true)) {
                continue; // already handled above
            }

            $this->registry->registerTaxonomy(
                ['product', 'product_variation'],
                $taxonomy,
                [
                    'label'        => $attribute->attribute_label,
                    'hierarchical' => false,
                ]
            );
        }
    }

    // ── Cart / checkout string filters ─────────────────────────────────────

    /**
     * Show the translated product name in the cart line-item name.
     *
     * @param string                $name     Current item name HTML.
     * @param array                 $cartItem Cart item array.
     * @param string                $cartItemKey
     * @return string
     */
    public function filterCartItemName(string $name, array $cartItem, string $cartItemKey): string
    {
        $productId = (int) ($cartItem['variation_id'] ?? $cartItem['product_id'] ?? 0);
        if (!$productId) {
            return $name;
        }

        $translatedTitle = $this->getTranslatedPostTitle($productId);
        if ($translatedTitle === null) {
            return $name;
        }

        // Preserve any wrapping <a> tag that WooCommerce may have added.
        if (str_contains($name, '<a ')) {
            return preg_replace(
                '/(<a[^>]*>)([^<]*)(<\/a>)/i',
                '$1' . esc_html($translatedTitle) . '$3',
                $name,
                1
            ) ?? $name;
        }

        return esc_html($translatedTitle);
    }

    /**
     * Translate the product title wherever WooCommerce renders it via the
     * woocommerce_product_title filter (e.g. order details, emails).
     *
     * @param string      $title   Current title.
     * @param \WC_Product $product Product object.
     * @return string
     */
    public function filterProductTitle(string $title, \WC_Product $product): string
    {
        $translatedTitle = $this->getTranslatedPostTitle($product->get_id());
        return $translatedTitle ?? $title;
    }

    /**
     * Look up whether a translated version of $postId exists for the current
     * language and return its title, or null if no translation is available.
     */
    private function getTranslatedPostTitle(int $postId): ?string
    {
        $currentLang = $this->languageManager->getCurrentLanguage();
        $defaultLang = $this->languageManager->getDefaultLanguage();

        // No translation needed when viewing in the default language.
        if ($currentLang->equals($defaultLang)) {
            return null;
        }

        // Use the public filter so other parts of the plugin (or third parties)
        // can supply the translated post ID.
        $translatedId = (int) apply_filters(
            'idiomatticwp_translated_post_id',
            0,
            $postId,
            (string) $currentLang
        );

        if ($translatedId && $translatedId !== $postId) {
            $post = get_post($translatedId);
            if ($post) {
                return $post->post_title;
            }
        }

        return null;
    }

    // ── Taxonomy term translation ──────────────────────────────────────────

    /**
     * Translate term names returned by wc_get_product_terms().
     *
     * WooCommerce calls get_the_terms() internally, then passes the result
     * through this filter, so it is the right place to swap in translated names.
     *
     * @param \WP_Term[] $terms      Array of term objects.
     * @param int        $productId  Product post ID.
     * @param string     $taxonomy   Taxonomy slug.
     * @param array      $args       Original query args.
     * @return \WP_Term[]
     */
    public function filterProductTermNames(array $terms, int $productId, string $taxonomy, array $args): array
    {
        $currentLang = $this->languageManager->getCurrentLanguage();
        $defaultLang = $this->languageManager->getDefaultLanguage();

        if ($currentLang->equals($defaultLang) || empty($terms)) {
            return $terms;
        }

        $langCode = (string) $currentLang;

        foreach ($terms as $term) {
            if (!($term instanceof \WP_Term)) {
                continue;
            }

            // Check for a translated name stored in term meta.
            $translatedName = get_term_meta(
                $term->term_id,
                "_idiomatticwp_name_{$langCode}",
                true
            );

            if ($translatedName) {
                // Clone-like update: WP_Term is not sealed so we can assign.
                $term->name = $translatedName;
            }

            // Translate the term description if present.
            $translatedDesc = get_term_meta(
                $term->term_id,
                "_idiomatticwp_description_{$langCode}",
                true
            );

            if ($translatedDesc) {
                $term->description = $translatedDesc;
            }
        }

        return $terms;
    }

    // ── Post-type list extension ───────────────────────────────────────────

    public function addWooPostTypes(array $postTypes): array
    {
        return array_merge($postTypes, ['product', 'product_variation']);
    }
}
