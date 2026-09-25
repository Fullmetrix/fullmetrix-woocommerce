<?php
defined('ABSPATH') || exit;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

class Fullmetrix_Checkout_Block_Integration implements IntegrationInterface {

    const SCRIPT_HANDLE = 'fullmetrix-checkout-block';

    public function get_name() {
        return 'fullmetrix-checkout';
    }

    public function initialize() {
        $script_path = '/assets/js/checkout-block.js';
        $script_url = plugins_url($script_path, FULLMETRIX_PLUGIN_FILE);

        wp_register_script(
            self::SCRIPT_HANDLE,
            $script_url,
            array(
                'wc-blocks-checkout',
                'wp-element',
                'wp-i18n',
                'wp-plugins',
                'wp-components',
            ),
            FULLMETRIX_VERSION,
            true
        );
    }

    public function get_script_handles() {
        return array(self::SCRIPT_HANDLE);
    }

    public function get_editor_script_handles() {
        return array();
    }

    public function get_script_data() {
        $cfg = Fullmetrix_Checkout_Consent::get_config();

        if (!$cfg) {
            return array(
                'enabled' => false,
            );
        }

        return array(
            'enabled' => true,
            'label' => $cfg['label'],
            'defaultChecked' => !empty($cfg['defaultChecked']),
            'channels' => isset($cfg['channels']) && is_array($cfg['channels']) ? $cfg['channels'] : array(),
            'textColor' => isset($cfg['textColor']) ? $cfg['textColor'] : null,
            'accentColor' => isset($cfg['accentColor']) ? $cfg['accentColor'] : null,
        );
    }
}
