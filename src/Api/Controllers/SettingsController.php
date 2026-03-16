<?php

declare(strict_types=1);

namespace LiftTeleport\Api\Controllers;

use LiftTeleport\Settings\SettingsRepository;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;

final class SettingsController
{
    public function __construct(private ControllerContext $ctx)
    {
    }

    public function getSettings(WP_REST_Request $request): WP_REST_Response
    {
        $settings = (new SettingsRepository())->get();

        return new WP_REST_Response([
            'settings' => $settings,
            'note' => 'Changes apply to your next export/import job.',
        ]);
    }

    public function updateSettings(WP_REST_Request $request)
    {
        try {
            $params = $request->get_json_params();
            if (! is_array($params)) {
                $params = $request->get_params();
            }
            if (! is_array($params)) {
                $params = [];
            }

            $settings = (new SettingsRepository())->update($params, get_current_user_id());

            return new WP_REST_Response([
                'settings' => $settings,
                'updated' => true,
                'note' => 'Changes apply to your next export/import job.',
            ]);
        } catch (Throwable $error) {
            return $this->ctx->error(
                'lift_settings_update_failed',
                'Unable to update settings.',
                500,
                [
                    'retryable' => true,
                    'hint' => 'Retry in a few seconds.',
                    'details' => $error->getMessage(),
                ]
            );
        }
    }
}
