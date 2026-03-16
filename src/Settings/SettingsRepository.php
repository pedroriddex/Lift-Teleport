<?php

declare(strict_types=1);

namespace LiftTeleport\Settings;

final class SettingsRepository
{
    public const OPTION_KEY = 'lift_teleport_settings';

    /**
     * @return array{save_for_backup:bool,merge_admin:bool,updated_at:string,updated_by:int}
     */
    public function get(): array
    {
        $defaults = $this->defaults();
        $stored = get_option(self::OPTION_KEY, []);
        if (! is_array($stored)) {
            $stored = [];
        }

        return [
            'save_for_backup' => $this->normalizeBool($stored['save_for_backup'] ?? $defaults['save_for_backup']),
            'merge_admin' => $this->normalizeBool($stored['merge_admin'] ?? $defaults['merge_admin']),
            'updated_at' => isset($stored['updated_at']) && is_string($stored['updated_at']) ? $stored['updated_at'] : '',
            'updated_by' => isset($stored['updated_by']) ? (int) $stored['updated_by'] : 0,
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array{save_for_backup:bool,merge_admin:bool,updated_at:string,updated_by:int}
     */
    public function update(array $input, int $userId): array
    {
        $current = $this->get();

        if (array_key_exists('save_for_backup', $input)) {
            $current['save_for_backup'] = $this->normalizeBool($input['save_for_backup']);
        }

        if (array_key_exists('merge_admin', $input)) {
            $current['merge_admin'] = $this->normalizeBool($input['merge_admin']);
        }

        $current['updated_at'] = gmdate(DATE_ATOM);
        $current['updated_by'] = max(0, $userId);

        update_option(self::OPTION_KEY, $current, false);

        return $current;
    }

    /**
     * @return array{save_for_backup:bool,merge_admin:bool}
     */
    public function forJobPayload(): array
    {
        $settings = $this->get();

        return [
            'save_for_backup' => (bool) $settings['save_for_backup'],
            'merge_admin' => (bool) $settings['merge_admin'],
        ];
    }

    /**
     * @return array{save_for_backup:bool,merge_admin:bool}
     */
    private function defaults(): array
    {
        $defaults = apply_filters('lift_teleport_settings_defaults', [
            'save_for_backup' => false,
            'merge_admin' => false,
        ]);

        if (! is_array($defaults)) {
            $defaults = [];
        }

        return [
            'save_for_backup' => $this->normalizeBool($defaults['save_for_backup'] ?? false),
            'merge_admin' => $this->normalizeBool($defaults['merge_admin'] ?? false),
        ];
    }

    private function normalizeBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return ((int) $value) !== 0;
        }

        if (is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return false;
    }
}
