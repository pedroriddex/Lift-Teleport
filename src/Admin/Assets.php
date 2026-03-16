<?php

declare(strict_types=1);

namespace LiftTeleport\Admin;

final class Assets
{
    public function enqueue(string $hook): void
    {
        if ($hook !== 'toplevel_page_lift-teleport') {
            return;
        }

        $assetFile = LIFT_TELEPORT_DIR . 'build/index.asset.php';
        $asset = [
            'dependencies' => ['wp-element', 'wp-api-fetch', 'wp-components', 'wp-i18n'],
            'version' => LIFT_TELEPORT_VERSION,
        ];

        if (file_exists($assetFile)) {
            $loaded = include $assetFile;
            if (is_array($loaded)) {
                $asset = array_merge($asset, $loaded);
            }
        }

        $scriptPath = LIFT_TELEPORT_DIR . 'build/index.js';
        $stylePath = LIFT_TELEPORT_DIR . 'build/index.css';
        $assetVersion = (string) $asset['version'];
        $mtimeParts = [];
        if (file_exists($scriptPath)) {
            $mtimeParts[] = (string) ((int) filemtime($scriptPath));
        }
        if (file_exists($stylePath)) {
            $mtimeParts[] = (string) ((int) filemtime($stylePath));
        }
        if ($mtimeParts !== []) {
            $assetVersion = LIFT_TELEPORT_VERSION . '-' . implode('-', $mtimeParts);
        }

        if (file_exists($scriptPath)) {
            wp_enqueue_script(
                'lift-teleport-admin',
                LIFT_TELEPORT_URL . 'build/index.js',
                $asset['dependencies'],
                $assetVersion,
                true
            );
        }

        if (file_exists($stylePath)) {
            wp_enqueue_style(
                'lift-teleport-admin',
                LIFT_TELEPORT_URL . 'build/index.css',
                [],
                $assetVersion
            );

            wp_style_add_data('lift-teleport-admin', 'rtl', 'replace');
            wp_add_inline_style('lift-teleport-admin', '.toplevel_page_lift-teleport .notice{display:none;}');
        }

        $disableAuthCheck = (bool) apply_filters('lift_teleport_disable_wp_auth_check_on_admin_screen', true);
        if ($disableAuthCheck) {
            wp_dequeue_script('wp-auth-check');
            wp_deregister_script('wp-auth-check');
        }
    }
}
