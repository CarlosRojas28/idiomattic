<?php
/**
 * Supported languages configuration
 *
 * This file is the single source of truth for all languages Idiomattic WP supports.
 * To add a new language to the plugin, add an entry here. Nothing else needs changing.
 *
 * Array structure per language:
 *  code         — BCP-47 code used internally and in URLs ('en', 'pt-BR')
 *  locale       — WordPress locale format ('en_US', 'pt_BR')
 *  name         — English name ('Spanish', 'Portuguese (Brazil)')
 *  native_name  — Name in the language itself ('Español', 'Português (Brasil)')
 *  rtl          — true for right-to-left languages
 *  flag         — ISO 3166-1 alpha-2 country code for the flag SVG ('es', 'br')
 *
 * Flag SVG files are located in assets/flags/{code}.svg
 * All flags are bundled — no external CDN dependency.
 *
 * @package Idiomattic WP
 */

declare( strict_types=1 );

return [
    'ar'    => [ 'locale' => 'ar',      'name' => 'Arabic',                   'native_name' => 'العربية',              'rtl' => true,  'flag' => 'sa' ],
    'bg'    => [ 'locale' => 'bg_BG',   'name' => 'Bulgarian',                'native_name' => 'Български',            'rtl' => false, 'flag' => 'bg' ],
    'ca'    => [ 'locale' => 'ca',      'name' => 'Catalan',                  'native_name' => 'Català',               'rtl' => false, 'flag' => 'es-ct' ],
    'zh-CN' => [ 'locale' => 'zh_CN',   'name' => 'Chinese (Simplified)',     'native_name' => '中文 (简体)',            'rtl' => false, 'flag' => 'cn' ],
    'zh-TW' => [ 'locale' => 'zh_TW',   'name' => 'Chinese (Traditional)',    'native_name' => '中文 (繁體)',            'rtl' => false, 'flag' => 'tw' ],
    'hr'    => [ 'locale' => 'hr',      'name' => 'Croatian',                 'native_name' => 'Hrvatski',             'rtl' => false, 'flag' => 'hr' ],
    'cs'    => [ 'locale' => 'cs_CZ',   'name' => 'Czech',                    'native_name' => 'Čeština',              'rtl' => false, 'flag' => 'cz' ],
    'da'    => [ 'locale' => 'da_DK',   'name' => 'Danish',                   'native_name' => 'Dansk',                'rtl' => false, 'flag' => 'dk' ],
    'nl'    => [ 'locale' => 'nl_NL',   'name' => 'Dutch',                    'native_name' => 'Nederlands',           'rtl' => false, 'flag' => 'nl' ],
    'en'    => [ 'locale' => 'en_US',   'name' => 'English',                  'native_name' => 'English',              'rtl' => false, 'flag' => 'us' ],
    'en-GB' => [ 'locale' => 'en_GB',   'name' => 'English (UK)',             'native_name' => 'English (UK)',         'rtl' => false, 'flag' => 'gb' ],
    'fi'    => [ 'locale' => 'fi',      'name' => 'Finnish',                  'native_name' => 'Suomi',                'rtl' => false, 'flag' => 'fi' ],
    'fr'    => [ 'locale' => 'fr_FR',   'name' => 'French',                   'native_name' => 'Français',             'rtl' => false, 'flag' => 'fr' ],
    'de'    => [ 'locale' => 'de_DE',   'name' => 'German',                   'native_name' => 'Deutsch',              'rtl' => false, 'flag' => 'de' ],
    'el'    => [ 'locale' => 'el',      'name' => 'Greek',                    'native_name' => 'Ελληνικά',             'rtl' => false, 'flag' => 'gr' ],
    'he'    => [ 'locale' => 'he_IL',   'name' => 'Hebrew',                   'native_name' => 'עברית',                'rtl' => true,  'flag' => 'il' ],
    'hi'    => [ 'locale' => 'hi_IN',   'name' => 'Hindi',                    'native_name' => 'हिन्दी',                 'rtl' => false, 'flag' => 'in' ],
    'hu'    => [ 'locale' => 'hu_HU',   'name' => 'Hungarian',                'native_name' => 'Magyar',               'rtl' => false, 'flag' => 'hu' ],
    'id'    => [ 'locale' => 'id_ID',   'name' => 'Indonesian',               'native_name' => 'Bahasa Indonesia',     'rtl' => false, 'flag' => 'id' ],
    'it'    => [ 'locale' => 'it_IT',   'name' => 'Italian',                  'native_name' => 'Italiano',             'rtl' => false, 'flag' => 'it' ],
    'ja'    => [ 'locale' => 'ja',      'name' => 'Japanese',                 'native_name' => '日本語',                'rtl' => false, 'flag' => 'jp' ],
    'ko'    => [ 'locale' => 'ko_KR',   'name' => 'Korean',                   'native_name' => '한국어',                'rtl' => false, 'flag' => 'kr' ],
    'lv'    => [ 'locale' => 'lv',      'name' => 'Latvian',                  'native_name' => 'Latviešu',             'rtl' => false, 'flag' => 'lv' ],
    'lt'    => [ 'locale' => 'lt_LT',   'name' => 'Lithuanian',               'native_name' => 'Lietuvių',             'rtl' => false, 'flag' => 'lt' ],
    'ms'    => [ 'locale' => 'ms_MY',   'name' => 'Malay',                    'native_name' => 'Bahasa Melayu',        'rtl' => false, 'flag' => 'my' ],
    'nb'    => [ 'locale' => 'nb_NO',   'name' => 'Norwegian',                'native_name' => 'Norsk',                'rtl' => false, 'flag' => 'no' ],
    'fa'    => [ 'locale' => 'fa_IR',   'name' => 'Persian',                  'native_name' => 'فارسی',                'rtl' => true,  'flag' => 'ir' ],
    'pl'    => [ 'locale' => 'pl_PL',   'name' => 'Polish',                   'native_name' => 'Polski',               'rtl' => false, 'flag' => 'pl' ],
    'pt-BR' => [ 'locale' => 'pt_BR',   'name' => 'Portuguese (Brazil)',      'native_name' => 'Português (Brasil)',   'rtl' => false, 'flag' => 'br' ],
    'pt'    => [ 'locale' => 'pt_PT',   'name' => 'Portuguese (Portugal)',    'native_name' => 'Português (Portugal)', 'rtl' => false, 'flag' => 'pt' ],
    'ro'    => [ 'locale' => 'ro_RO',   'name' => 'Romanian',                 'native_name' => 'Română',               'rtl' => false, 'flag' => 'ro' ],
    'ru'    => [ 'locale' => 'ru_RU',   'name' => 'Russian',                  'native_name' => 'Русский',              'rtl' => false, 'flag' => 'ru' ],
    'sr'    => [ 'locale' => 'sr_RS',   'name' => 'Serbian',                  'native_name' => 'Српски',               'rtl' => false, 'flag' => 'rs' ],
    'sk'    => [ 'locale' => 'sk_SK',   'name' => 'Slovak',                   'native_name' => 'Slovenčina',           'rtl' => false, 'flag' => 'sk' ],
    'sl'    => [ 'locale' => 'sl_SI',   'name' => 'Slovenian',                'native_name' => 'Slovenščina',          'rtl' => false, 'flag' => 'si' ],
    'es'    => [ 'locale' => 'es_ES',   'name' => 'Spanish',                  'native_name' => 'Español',              'rtl' => false, 'flag' => 'es' ],
    'sv'    => [ 'locale' => 'sv_SE',   'name' => 'Swedish',                  'native_name' => 'Svenska',              'rtl' => false, 'flag' => 'se' ],
    'th'    => [ 'locale' => 'th',      'name' => 'Thai',                     'native_name' => 'ภาษาไทย',               'rtl' => false, 'flag' => 'th' ],
    'tr'    => [ 'locale' => 'tr_TR',   'name' => 'Turkish',                  'native_name' => 'Türkçe',               'rtl' => false, 'flag' => 'tr' ],
    'uk'    => [ 'locale' => 'uk',      'name' => 'Ukrainian',                'native_name' => 'Українська',           'rtl' => false, 'flag' => 'ua' ],
    'vi'    => [ 'locale' => 'vi',      'name' => 'Vietnamese',               'native_name' => 'Tiếng Việt',           'rtl' => false, 'flag' => 'vn' ],
];
