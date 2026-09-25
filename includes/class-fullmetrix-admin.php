<?php
defined('ABSPATH') || exit;

class Fullmetrix_Admin {

    public static function add_menu() {
        add_submenu_page(
            'woocommerce',
            __('Fullmetrix', 'fullmetrix'),
            __('Fullmetrix', 'fullmetrix'),
            'manage_woocommerce',
            'fullmetrix',
            array(__CLASS__, 'render_settings_page')
        );
    }

    public static function register_settings() {
        register_setting('fullmetrix_settings', 'fullmetrix_connection_code', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ));
    }

    public static function render_settings_page() {
        $connection_code = get_option('fullmetrix_connection_code', '');
        $is_registered = get_option('fullmetrix_registered', false);
        $message = '';
        $message_type = '';

        if (isset($_POST['fullmetrix_save_code']) && check_admin_referer('fullmetrix_save_code_action')) {
            $new_code = isset($_POST['fullmetrix_connection_code']) ? sanitize_text_field(wp_unslash($_POST['fullmetrix_connection_code'])) : '';

            if (empty($new_code)) {
                $message = __('Please enter a connection code.', 'fullmetrix');
                $message_type = 'error';
            } elseif (!self::validate_code_format($new_code)) {
                $message = __('Invalid code format. The code must be in FMTX-XXXX-XXXX-XXXX format.', 'fullmetrix');
                $message_type = 'error';
            } else {
                update_option('fullmetrix_connection_code', $new_code);
                $connection_code = $new_code;

                $result = Fullmetrix_API::register_with_fullmetrix();

                if ($result === true) {
                    $is_registered = true;
                    $message = __('Connection successful! Your store is now connected to Fullmetrix.', 'fullmetrix');
                    $message_type = 'success';
                } else {
                    $message = $result;
                    $message_type = 'error';
                }
            }
        }

        if (isset($_POST['fullmetrix_disconnect']) && check_admin_referer('fullmetrix_disconnect_action')) {
            delete_option('fullmetrix_connection_code');
            delete_option('fullmetrix_connection_secret');
            update_option('fullmetrix_registered', false);
            delete_option('fullmetrix_webhooks_enabled');
            delete_option('fullmetrix_last_sync');
            delete_option('fullmetrix_export_count');
            delete_transient('fullmetrix_sync_in_progress');
            Fullmetrix_Connector::forget_config();
            $connection_code = '';
            $is_registered = false;
            $message = __('Successfully disconnected.', 'fullmetrix');
            $message_type = 'success';
        }

        ?>
        <div class="wrap fullmetrix-settings">
            <h1 class="screen-reader-text"><?php echo esc_html__('Fullmetrix', 'fullmetrix'); ?></h1>

            <?php if ($message): ?>
                <div class="notice notice-<?php echo esc_attr($message_type); ?> is-dismissible">
                    <p><?php echo esc_html($message); ?></p>
                </div>
            <?php endif; ?>

            <?php self::render_connection_tab($connection_code, $is_registered); ?>
        </div>
        <?php
    }

    private static function render_connection_tab($connection_code, $is_registered) {
        $steps = array(
            __('Create your Fullmetrix account at fullmetrix.com', 'fullmetrix'),
            __('Copy the connection code shown in your Fullmetrix dashboard', 'fullmetrix'),
            __('Paste the code below and click Connect', 'fullmetrix'),
        );
        ?>
        <div class="fullmetrix-card">
            <div class="fullmetrix-brand">
                <img class="fullmetrix-brand-logo" src="<?php echo esc_url(plugins_url('assets/logo.png', FULLMETRIX_PLUGIN_FILE)); ?>" alt="Fullmetrix" />
                <div class="fullmetrix-brand-text">
                    <div class="fullmetrix-brand-name">Fullmetrix</div>
                    <p class="fullmetrix-brand-tagline"><?php echo esc_html__('E-commerce analytics and reporting', 'fullmetrix'); ?></p>
                </div>
                <?php if ($is_registered): ?>
                    <span class="fullmetrix-pill fullmetrix-pill-success">
                        <span class="fullmetrix-pill-dot"></span><?php echo esc_html__('Connected', 'fullmetrix'); ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php if ($is_registered): ?>
                <label class="fullmetrix-label" for="fullmetrix_connection_code">
                    <?php echo esc_html__('Connection code', 'fullmetrix'); ?>
                </label>
                <form method="post" class="fullmetrix-row">
                    <?php wp_nonce_field('fullmetrix_disconnect_action'); ?>
                    <input type="text" id="fullmetrix_connection_code" class="fullmetrix-code-input" value="<?php echo esc_attr($connection_code); ?>" readonly />
                    <button type="submit" name="fullmetrix_disconnect" class="fullmetrix-btn fullmetrix-btn-secondary">
                        <?php echo esc_html__('Disconnect', 'fullmetrix'); ?>
                    </button>
                </form>

            <?php else: ?>
                <ol class="fullmetrix-steps">
                    <?php foreach ($steps as $index => $step): ?>
                        <li>
                            <span class="fullmetrix-step-number"><?php echo esc_html(number_format_i18n($index + 1)); ?></span>
                            <span class="fullmetrix-step-text"><?php echo esc_html($step); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>

                <label class="fullmetrix-label" for="fullmetrix_connection_code">
                    <?php echo esc_html__('Connection code', 'fullmetrix'); ?>
                </label>
                <form method="post" class="fullmetrix-row">
                    <?php wp_nonce_field('fullmetrix_save_code_action'); ?>
                    <input
                        type="text"
                        id="fullmetrix_connection_code"
                        name="fullmetrix_connection_code"
                        value="<?php echo esc_attr($connection_code); ?>"
                        placeholder="FMTX-XXXX-XXXX-XXXX"
                        class="fullmetrix-code-input"
                        required
                    />
                    <button type="submit" name="fullmetrix_save_code" class="fullmetrix-btn fullmetrix-btn-primary">
                        <?php echo esc_html__('Connect', 'fullmetrix'); ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($is_registered): ?>
            <?php
            $sync_in_progress = get_transient('fullmetrix_sync_in_progress');
            $last_sync = get_option('fullmetrix_last_sync', null);
            ?>
            <div class="fullmetrix-card">
                <h2 class="fullmetrix-card-title"><?php echo esc_html__('Sync activity', 'fullmetrix'); ?></h2>

                <?php if ($sync_in_progress): ?>
                    <span class="fullmetrix-pill fullmetrix-pill-active">
                        <span class="fullmetrix-pill-dot"></span><?php echo esc_html__('Sync in progress', 'fullmetrix'); ?>
                    </span>

                <?php elseif ($last_sync && isset($last_sync['completed_at'])): ?>
                    <span class="fullmetrix-pill fullmetrix-pill-success">
                        <span class="fullmetrix-pill-dot"></span><?php echo esc_html__('Sync complete', 'fullmetrix'); ?>
                    </span>

                    <?php if (!empty($last_sync['entities']) && is_array($last_sync['entities'])): ?>
                        <div class="fullmetrix-tiles">
                            <?php foreach ($last_sync['entities'] as $entity_label => $count): ?>
                                <?php if ($count > 0): ?>
                                    <div class="fullmetrix-tile">
                                        <span class="fullmetrix-tile-count"><?php echo esc_html(number_format_i18n($count)); ?></span>
                                        <span class="fullmetrix-tile-label"><?php echo esc_html($entity_label); ?></span>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <span class="fullmetrix-pill fullmetrix-pill-waiting">
                        <span class="fullmetrix-pill-dot"></span><?php echo esc_html__('Waiting for sync', 'fullmetrix'); ?>
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php
    }


    private static function validate_code_format($code) {
        return preg_match('/^FMTX-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $code);
    }
}
