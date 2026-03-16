<?php

declare(strict_types=1);

namespace LiftTeleport\Admin;

use LiftTeleport\Support\Environment;

final class Menu
{
    private Assets $assets;

    private string $hookSuffix = '';

    public function __construct(Assets $assets)
    {
        $this->assets = $assets;
    }

    public function register(): void
    {
        $this->hookSuffix = add_menu_page(
            __('Lift Teleport', 'lift-teleport'),
            __('Lift Teleport', 'lift-teleport'),
            'manage_options',
            'lift-teleport',
            [$this, 'render'],
            'none',
            56
        );

        add_action('admin_head', [$this, 'printMenuIconStyle']);

        add_submenu_page(
            'lift-teleport',
            __('Export / Import', 'lift-teleport'),
            __('Export / Import', 'lift-teleport'),
            'manage_options',
            'lift-teleport',
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'lift-teleport'));
        }

        $props = [
            'restRoot' => esc_url_raw(rest_url('lift/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'pluginVersion' => LIFT_TELEPORT_VERSION,
            'diagnostics' => Environment::diagnostics(),
            'uploadChunkSize' => 8 * 1024 * 1024,
            'maxUploadBytes' => (int) apply_filters('lift_teleport_max_upload_bytes', 20 * 1024 * 1024 * 1024),
            'unzipperEntriesPageSize' => 200,
            'unzipperRetentionMode' => 'cleanup_on_close',
            'i18n' => [
                'confirmImport' => __('This will overwrite the current website. Continue?', 'lift-teleport'),
            ],
        ];

        include LIFT_TELEPORT_DIR . 'templates/admin-app.php';
    }

    public function getHookSuffix(): string
    {
        return $this->hookSuffix;
    }

    public function printMenuIconStyle(): void
    {
        $iconUrl = esc_url_raw(LIFT_TELEPORT_URL . 'assets/icons/lift-teleport.svg');
        if ($iconUrl === '') {
            return;
        }

        echo '<style id="lift-teleport-menu-icon">';
        echo '#adminmenu #toplevel_page_lift-teleport .wp-menu-image{color:inherit;}';
        echo '#adminmenu #toplevel_page_lift-teleport .wp-menu-image:before{content:"";display:block;width:20px;height:20px;margin:0 auto;background-color:currentColor;-webkit-mask-image:url("' . esc_url($iconUrl) . '");mask-image:url("' . esc_url($iconUrl) . '");-webkit-mask-repeat:no-repeat;mask-repeat:no-repeat;-webkit-mask-position:center;mask-position:center;-webkit-mask-size:20px 20px;mask-size:20px 20px;}';
        echo '#adminmenu #toplevel_page_lift-teleport .wp-menu-image img{display:none;}';
        echo '</style>';
    }
}
