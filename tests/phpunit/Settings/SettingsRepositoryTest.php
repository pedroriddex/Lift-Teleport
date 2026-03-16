<?php

declare(strict_types=1);

namespace LiftTeleport\Tests\Settings;

use LiftTeleport\Settings\SettingsRepository;
use PHPUnit\Framework\TestCase;

final class SettingsRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        if (function_exists('lift_test_reset_options')) {
            lift_test_reset_options();
        }
    }

    public function testDefaultsAreDisabled(): void
    {
        $repository = new SettingsRepository();
        $settings = $repository->get();

        self::assertFalse($settings['save_for_backup']);
        self::assertFalse($settings['merge_admin']);
        self::assertSame('', $settings['updated_at']);
        self::assertSame(0, $settings['updated_by']);
    }

    public function testUpdatePersistsSanitizedValues(): void
    {
        $repository = new SettingsRepository();
        $updated = $repository->update([
            'save_for_backup' => '1',
            'merge_admin' => 'false',
            'ignored_key' => 'value',
        ], 42);

        self::assertTrue($updated['save_for_backup']);
        self::assertFalse($updated['merge_admin']);
        self::assertSame(42, $updated['updated_by']);
        self::assertNotSame('', $updated['updated_at']);

        $payloadSettings = $repository->forJobPayload();
        self::assertSame([
            'save_for_backup' => true,
            'merge_admin' => false,
        ], $payloadSettings);
    }
}
