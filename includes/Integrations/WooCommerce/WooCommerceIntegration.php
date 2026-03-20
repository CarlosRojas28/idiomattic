<?php
/**
 * WooCommerceIntegration — support for products and transactional emails.
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Integrations\WooCommerce;

use IdiomatticWP\Contracts\IntegrationInterface;
use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\Core\CustomElementRegistry;

class WooCommerceIntegration implements IntegrationInterface
{

    public function __construct(private
        LanguageManager $languageManager, private
        CustomElementRegistry $registry
        )
    {
    }

    public function isAvailable(): bool
    {
        return class_exists('WooCommerce');
    }

    public function register(): void
    {
        // Save order language
        add_action('woocommerce_checkout_order_created', [$this, 'saveOrderLanguage']);

        // Switch locale for emails
        add_filter('woocommerce_email_setup_locale', [$this, 'switchEmailLocale']);

        // Register translatable fields
        add_action('init', [$this, 'registerProductFields'], 25);

        // Add WooCommerce post types to translation list
        add_filter('idiomatticwp_translatable_post_types', [$this, 'addWooPostTypes']);
    }

    public function saveOrderLanguage($order): void
    {
        $lang = $this->languageManager->getCurrentLanguage();
        $order->update_meta_data('_idiomatticwp_order_lang', (string)$lang);
        $order->save_meta_data();
    }

    public function switchEmailLocale($locale): string
    {
        $orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
        if (!$orderId)
            return $locale;

        $order = wc_get_order($orderId);
        if (!$order)
            return $locale;

        $lang = $order->get_meta('_idiomatticwp_order_lang');
        if ($lang) {
            $langs = $this->languageManager->getActiveLanguages();
            foreach ($langs as $l) {
                if ((string)$l === $lang) {
                    return $l->toLocale();
                }
            }
        }

        return $locale;
    }

    public function registerProductFields(): void
    {
        $this->registry->registerPostField(['product'], '_short_description', ['label' => 'Short Description', 'field_type' => 'textarea']);

        // Fields to copy instead of translate
        $this->registry->registerPostField(['product'], '_regular_price', ['mode' => 'copy']);
        $this->registry->registerPostField(['product'], '_sale_price', ['mode' => 'copy']);
        $this->registry->registerPostField(['product'], '_sku', ['mode' => 'copy']);
        $this->registry->registerPostField(['product'], '_stock', ['mode' => 'copy']);
    }

    public function addWooPostTypes(array $postTypes): array
    {
        return array_merge($postTypes, ['product', 'product_variation']);
    }
}
