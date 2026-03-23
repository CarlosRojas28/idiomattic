<?php
/**
 * MultiCurrency — per-language currency switching for WooCommerce.
 *
 * Reads exchange-rate configuration saved under the
 * `idiomatticwp_wc_currencies` option and applies it to prices,
 * currency codes, symbols, and orders for the current language.
 *
 * @package IdiomatticWP\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace IdiomatticWP\Integrations\WooCommerce;

use IdiomatticWP\Core\LanguageManager;

class MultiCurrency {

	public function __construct( private LanguageManager $lm ) {}

	// ── Registration ───────────────────────────────────────────────────────

	public function register(): void {
		if ( ! $this->isWooActive() ) {
			return;
		}

		// Override WooCommerce currency for the current request.
		add_filter( 'woocommerce_currency', [ $this, 'switchCurrency' ] );

		// Override currency symbol.
		add_filter( 'woocommerce_currency_symbol', [ $this, 'switchCurrencySymbol' ], 10, 2 );

		// Multiply prices by exchange rate.
		add_filter( 'woocommerce_product_get_price',                    [ $this, 'convertPrice' ], 10, 2 );
		add_filter( 'woocommerce_product_get_regular_price',            [ $this, 'convertPrice' ], 10, 2 );
		add_filter( 'woocommerce_product_get_sale_price',               [ $this, 'convertPrice' ], 10, 2 );
		add_filter( 'woocommerce_product_variation_get_price',          [ $this, 'convertPrice' ], 10, 2 );
		add_filter( 'woocommerce_product_variation_get_regular_price',  [ $this, 'convertPrice' ], 10, 2 );
		add_filter( 'woocommerce_product_variation_get_sale_price',     [ $this, 'convertPrice' ], 10, 2 );

		// Store order in the customer's currency.
		add_action( 'woocommerce_checkout_create_order', [ $this, 'saveOrderCurrency' ], 10, 2 );
	}

	// ── Filter / action callbacks ──────────────────────────────────────────

	public function switchCurrency( string $currency ): string {
		$config = $this->getCurrencyConfig();
		return $config['code'] ?: $currency;
	}

	public function switchCurrencySymbol( string $symbol, string $currency ): string {
		$config = $this->getCurrencyConfig();
		return $config['symbol'] ?: $symbol;
	}

	/**
	 * @param mixed       $price   Raw price string from WooCommerce (may be '').
	 * @param object      $product WC_Product or WC_Product_Variation instance.
	 * @return mixed
	 */
	public function convertPrice( mixed $price, object $product ): mixed {
		if ( $price === '' || $price === null ) {
			return $price;
		}

		$rate = $this->getExchangeRate();
		if ( $rate === 1.0 ) {
			return $price;
		}

		return round( (float) $price * $rate, wc_get_price_decimals() );
	}

	/**
	 * @param \WC_Order $order The order being created.
	 * @param array     $data  Checkout POST data.
	 */
	public function saveOrderCurrency( \WC_Order $order, array $data ): void {
		$config = $this->getCurrencyConfig();
		if ( $config['code'] ) {
			$order->set_currency( $config['code'] );
		}
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Return the currency config array for the current language.
	 *
	 * Shape: [ 'code' => string, 'symbol' => string, 'rate' => float ]
	 *
	 * Falls back to defaults (empty code/symbol, rate 1.0) when no config
	 * is stored for the active language or when viewing the default language.
	 *
	 * @return array{code: string, symbol: string, rate: float}
	 */
	private function getCurrencyConfig(): array {
		$lang       = (string) $this->lm->getCurrentLanguage();
		$currencies = get_option( 'idiomatticwp_wc_currencies', [] );

		return $currencies[ $lang ] ?? [ 'code' => '', 'symbol' => '', 'rate' => 1.0 ];
	}

	private function getExchangeRate(): float {
		$config = $this->getCurrencyConfig();
		return (float) ( $config['rate'] ?? 1.0 );
	}

	private function isWooActive(): bool {
		return class_exists( 'WooCommerce' );
	}
}
