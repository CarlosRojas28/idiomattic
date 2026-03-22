<?php
declare(strict_types=1);
namespace IdiomatticWP\Admin\Pages;

use IdiomatticWP\Core\LanguageManager;

class OnboardingPage {
    public function __construct(private LanguageManager $languageManager) {}

    public function render(): void {
        $step = max(1, min(3, (int) ($_GET['iwp_step'] ?? 1)));
        ?>
        <div class="wrap iwp-onboarding">
            <div style="text-align:center;margin-bottom:32px;">
                <h1 style="font-size:28px;font-weight:700;margin-bottom:8px;">
                    <?php esc_html_e('Welcome to Idiomattic', 'idiomattic-wp'); ?> 🌍
                </h1>
                <p style="color:#50575e;font-size:15px;margin:0;">
                    <?php esc_html_e("Let's set up your multilingual site in 3 simple steps.", 'idiomattic-wp'); ?>
                </p>
            </div>

            <!-- Step indicators -->
            <div class="iwp-onboarding__steps">
                <?php
                $steps = [
                    1 => __('Your language', 'idiomattic-wp'),
                    2 => __('Target languages', 'idiomattic-wp'),
                    3 => __("You're ready", 'idiomattic-wp'),
                ];
                foreach ($steps as $n => $label) :
                    $cls = $n < $step ? 'is-done' : ($n === $step ? 'is-active' : '');
                ?>
                <div class="iwp-onboarding__step <?php echo esc_attr($cls); ?>">
                    <div class="iwp-onboarding__step-num">
                        <?php echo $n < $step ? '✓' : $n; ?>
                    </div>
                    <div><?php echo esc_html($label); ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Panel content -->
            <div class="iwp-onboarding__panel iwp-card" style="padding:40px;">
                <?php
                match($step) {
                    1 => $this->renderStep1(),
                    2 => $this->renderStep2(),
                    3 => $this->renderStep3(),
                };
                ?>
            </div>
        </div>
        <?php
    }

    private function renderStep1(): void {
        $allLangs = $this->languageManager->getAllSupportedLanguages();
        $current  = (string) $this->languageManager->getDefaultLanguage();
        ?>
        <h2 style="margin-top:0;"><?php esc_html_e('What language is your content written in?', 'idiomattic-wp'); ?></h2>
        <p style="color:#50575e;"><?php esc_html_e('This is the language you write posts and pages in. You can change it later.', 'idiomattic-wp'); ?></p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('iwp_onboarding_step1', 'iwp_onboarding_nonce'); ?>
            <input type="hidden" name="action" value="iwp_onboarding_step1">

            <input type="text" id="iwp-ob-search" placeholder="<?php esc_attr_e('Search languages…', 'idiomattic-wp'); ?>"
                   style="width:100%;padding:10px 14px;font-size:14px;border:1px solid #dcdcde;border-radius:6px;margin-bottom:12px;">

            <div class="iwp-onboarding__lang-grid" id="iwp-ob-grid">
                <?php foreach ($allLangs as $code => $data) :
                    $name   = $data['name'] ?? $code;
                    $native = $data['native_name'] ?? $name;
                    $label  = $native !== $name ? "{$native} ({$name})" : $name;
                ?>
                <label class="iwp-onboarding__lang-option <?php echo $code === $current ? 'is-selected' : ''; ?>"
                       data-name="<?php echo esc_attr(strtolower($label)); ?>">
                    <input type="radio" name="default_lang" value="<?php echo esc_attr($code); ?>"
                           <?php checked($code, $current); ?> style="margin:0;">
                    <?php echo esc_html($label); ?>
                </label>
                <?php endforeach; ?>
            </div>

            <div style="margin-top:32px;text-align:right;">
                <button type="submit" class="iwp-btn iwp-btn--primary" style="font-size:15px;padding:10px 28px;">
                    <?php esc_html_e('Continue →', 'idiomattic-wp'); ?>
                </button>
            </div>
        </form>

        <script>
        document.getElementById('iwp-ob-search').addEventListener('input', function() {
            var q = this.value.toLowerCase();
            document.querySelectorAll('.iwp-onboarding__lang-option').forEach(function(el) {
                el.style.display = el.dataset.name.includes(q) ? '' : 'none';
            });
        });
        document.querySelectorAll('.iwp-onboarding__lang-option input').forEach(function(inp) {
            inp.addEventListener('change', function() {
                document.querySelectorAll('.iwp-onboarding__lang-option').forEach(function(el) {
                    el.classList.remove('is-selected');
                });
                this.closest('.iwp-onboarding__lang-option').classList.add('is-selected');
            });
        });
        </script>
        <?php
    }

    private function renderStep2(): void {
        $allLangs    = $this->languageManager->getAllSupportedLanguages();
        $defaultLang = (string) $this->languageManager->getDefaultLanguage();
        ?>
        <h2 style="margin-top:0;"><?php esc_html_e('Which languages do you want to translate into?', 'idiomattic-wp'); ?></h2>
        <p style="color:#50575e;"><?php esc_html_e('Select all the languages you want your site to be available in. You can add more later.', 'idiomattic-wp'); ?></p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('iwp_onboarding_step2', 'iwp_onboarding_nonce'); ?>
            <input type="hidden" name="action" value="iwp_onboarding_step2">

            <input type="text" id="iwp-ob-search2" placeholder="<?php esc_attr_e('Search languages…', 'idiomattic-wp'); ?>"
                   style="width:100%;padding:10px 14px;font-size:14px;border:1px solid #dcdcde;border-radius:6px;margin-bottom:12px;">

            <div class="iwp-onboarding__lang-grid" id="iwp-ob-grid2">
                <?php foreach ($allLangs as $code => $data) :
                    if ($code === $defaultLang) continue;
                    $name   = $data['name'] ?? $code;
                    $native = $data['native_name'] ?? $name;
                    $label  = $native !== $name ? "{$native} ({$name})" : $name;
                ?>
                <label class="iwp-onboarding__lang-option"
                       data-name="<?php echo esc_attr(strtolower($label)); ?>">
                    <input type="checkbox" name="active_langs[]" value="<?php echo esc_attr($code); ?>" style="margin:0;">
                    <?php echo esc_html($label); ?>
                </label>
                <?php endforeach; ?>
            </div>

            <div style="margin-top:32px;display:flex;justify-content:space-between;align-items:center;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=idiomatticwp&iwp_step=1')); ?>" class="iwp-btn iwp-btn--secondary">
                    ← <?php esc_html_e('Back', 'idiomattic-wp'); ?>
                </a>
                <button type="submit" class="iwp-btn iwp-btn--primary" style="font-size:15px;padding:10px 28px;">
                    <?php esc_html_e('Continue →', 'idiomattic-wp'); ?>
                </button>
            </div>
        </form>

        <script>
        document.getElementById('iwp-ob-search2').addEventListener('input', function() {
            var q = this.value.toLowerCase();
            document.querySelectorAll('#iwp-ob-grid2 .iwp-onboarding__lang-option').forEach(function(el) {
                el.style.display = el.dataset.name.includes(q) ? '' : 'none';
            });
        });
        document.querySelectorAll('#iwp-ob-grid2 .iwp-onboarding__lang-option').forEach(function(lbl) {
            lbl.addEventListener('click', function() {
                this.classList.toggle('is-selected', this.querySelector('input').checked);
            });
        });
        </script>
        <?php
    }

    private function renderStep3(): void {
        $active  = $this->languageManager->getActiveLanguages();
        $default = $this->languageManager->getDefaultLanguage();
        $langData = $this->languageManager->getAllSupportedLanguages();
        ?>
        <div style="text-align:center;padding:20px 0;">
            <div style="font-size:64px;margin-bottom:16px;">🎉</div>
            <h2 style="font-size:24px;margin:0 0 12px;"><?php esc_html_e("You're all set!", 'idiomattic-wp'); ?></h2>
            <p style="color:#50575e;font-size:15px;margin:0 0 32px;">
                <?php
                printf(
                    esc_html__('Your site is ready to speak %d languages.', 'idiomattic-wp'),
                    count($active)
                );
                ?>
            </p>

            <div style="display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin-bottom:40px;">
                <?php foreach ($active as $lang) :
                    $code = (string) $lang;
                    $ld   = $langData[$code] ?? [];
                    $name = $ld['native_name'] ?? $ld['name'] ?? $code;
                    $isDefault = $lang->equals($default);
                ?>
                <span style="padding:6px 14px;background:<?php echo $isDefault ? '#edfaef' : '#deeeff'; ?>;
                             border:1px solid <?php echo $isDefault ? '#46b450' : '#2271b1'; ?>;
                             border-radius:20px;font-size:13px;font-weight:600;">
                    <?php echo esc_html($name); ?>
                    <?php if ($isDefault) echo ' ✓'; ?>
                </span>
                <?php endforeach; ?>
            </div>

            <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=idiomatticwp-content')); ?>"
                   class="iwp-btn iwp-btn--primary" style="font-size:15px;padding:12px 32px;">
                    <?php esc_html_e('Start translating →', 'idiomattic-wp'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=idiomatticwp-settings')); ?>"
                   class="iwp-btn iwp-btn--secondary">
                    <?php esc_html_e('Configure settings', 'idiomattic-wp'); ?>
                </a>
            </div>

            <?php
            // Mark onboarding as complete
            update_option('idiomatticwp_onboarding_done', true);
            ?>
        </div>
        <?php
    }
}
