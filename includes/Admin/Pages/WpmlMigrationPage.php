<?php
/**
 * WpmlMigrationPage — admin UI for WPML migration.
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Admin\Pages;

use IdiomatticWP\Contracts\HookRegistrarInterface;
use IdiomatticWP\Migration\WpmlDetector;
use IdiomatticWP\Migration\WpmlMigrator;

class WpmlMigrationPage implements HookRegistrarInterface
{

    public function __construct(private
        WpmlDetector $detector, private
        WpmlMigrator $migrator
        )
    {
    }

    public function register(): void
    {
        add_action( 'admin_menu', function () {
            add_submenu_page(
                'idiomatticwp',
                __( 'WPML Migration', 'idiomattic-wp' ),
                __( 'WPML Migration', 'idiomattic-wp' ),
                'manage_options',
                'idiomatticwp-migration',
                [ $this, 'render' ]
            );
        } );

        add_action( 'wp_ajax_idiomatticwp_run_migration', [ $this, 'handleAjaxMigration' ] );
    }

    public function render(): void
    {
        $count = $this->detector->getTranslationCount();
        $isActive = $this->detector->isWpmlActive();
?>
        <div class="wrap">
            <h1><?php _e('WPML Migration Wizard', 'idiomattic-wp'); ?></h1>
            
            <?php if (!$isActive): ?>
                <div class="notice notice-warning"><p><?php _e('WPML is not active. Migration may not be possible.', 'idiomattic-wp'); ?></p></div>
            <?php
        endif; ?>

            <div class="card">
                <h2><?php _e('Step 1: Detection', 'idiomattic-wp'); ?></h2>
                <p><?php printf(__('Found %d translations in your WPML database.', 'idiomattic-wp'), $count); ?></p>
                
                <?php if ($count > 0): ?>
                    <button id="start-migration" class="button button-primary"><?php _e('Start Migration', 'idiomattic-wp'); ?></button>
                    <div id="migration-progress" style="margin-top: 20px; display: none;">
                        <div class="progress-bar-container" style="background:#eee; height:20px; width:100%; border-radius:10px;">
                            <div id="progress-bar" style="background:#2271b1; height:100%; width:0%; border-radius:10px;"></div>
                        </div>
                        <p id="progress-text"></p>
                    </div>
                <?php
        endif; ?>
            </div>
        </div>
        <script>
        jQuery(document).ready(function($) {
            $('#start-migration').on('click', function() {
                $(this).prop('disabled', true);
                $('#migration-progress').show();
                runBatch(0);
            });

            function runBatch(offset) {
                $.post(ajaxurl, {
                    action: 'idiomatticwp_run_migration',
                    offset: offset,
                    _ajax_nonce: '<?php echo wp_create_nonce('idiomatticwp_migration'); ?>'
                }, function(response) {
                    if (response.success) {
                        var nextOffset = offset + 100;
                        var progress = Math.min(100, (nextOffset / <?php echo $count; ?>) * 100);
                        $('#progress-bar').css('width', progress + '%');
                        $('#progress-text').text('Migrated ' + Math.min(<?php echo $count; ?>, nextOffset) + ' of <?php echo $count; ?>...');
                        
                        if (nextOffset < <?php echo $count; ?>) {
                            runBatch(nextOffset);
                        } else {
                            $('#progress-text').text('Migration complete!');
                        }
                    }
                });
            }
        });
        </script>
        <?php
    }

    public function handleAjaxMigration(): void
    {
        check_ajax_referer('idiomatticwp_migration');
        $offset = (int)$_POST['offset'];

        $report = $this->migrator->migrateBatch($offset);

        wp_send_json_success([
            'migrated' => $report->translationsMigrated,
            'errors' => $report->errors
        ]);
    }
}
