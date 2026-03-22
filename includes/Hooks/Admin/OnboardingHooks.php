<?php
declare(strict_types=1);
namespace IdiomatticWP\Hooks\Admin;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Core\LanguageManager;
use IdiomatticWP\ValueObjects\LanguageCode;

class OnboardingHooks implements HookRegistrarInterface {
    public function __construct(private LanguageManager $languageManager) {}

    public function register(): void {
        add_action('admin_post_iwp_onboarding_step1', [$this, 'handleStep1']);
        add_action('admin_post_iwp_onboarding_step2', [$this, 'handleStep2']);
    }

    public function handleStep1(): void {
        check_admin_referer('iwp_onboarding_step1', 'iwp_onboarding_nonce');
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        $code = sanitize_key($_POST['default_lang'] ?? '');
        if ($code !== '') {
            try {
                $lang = LanguageCode::from($code);
                $this->languageManager->setDefaultLanguage($lang);
                // Ensure it's also active
                $active = $this->languageManager->getActiveLanguages();
                $alreadyActive = false;
                foreach ($active as $l) {
                    if ($l->equals($lang)) { $alreadyActive = true; break; }
                }
                if (!$alreadyActive) {
                    $this->languageManager->setActiveLanguages(array_merge($active, [$lang]));
                }
            } catch (\Throwable $e) {}
        }

        wp_safe_redirect(admin_url('admin.php?page=idiomatticwp&iwp_step=2'));
        exit;
    }

    public function handleStep2(): void {
        check_admin_referer('iwp_onboarding_step2', 'iwp_onboarding_nonce');
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        $codes  = (array) ($_POST['active_langs'] ?? []);
        $default = $this->languageManager->getDefaultLanguage();
        $langs  = [$default];

        foreach ($codes as $code) {
            $code = sanitize_key($code);
            try {
                $lang = LanguageCode::from($code);
                if (!$lang->equals($default)) $langs[] = $lang;
            } catch (\Throwable $e) {}
        }

        $this->languageManager->setActiveLanguages($langs);

        wp_safe_redirect(admin_url('admin.php?page=idiomatticwp&iwp_step=3'));
        exit;
    }
}
