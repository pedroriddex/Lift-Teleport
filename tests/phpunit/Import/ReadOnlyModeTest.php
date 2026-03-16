<?php

declare(strict_types=1);

namespace LiftTeleport\Tests\Import;

use LiftTeleport\Import\ReadOnlyMode;
use PHPUnit\Framework\TestCase;

final class ReadOnlyModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        lift_test_reset_options();
    }

    public function testEnableAndDisableLock(): void
    {
        $mode = new ReadOnlyMode();

        self::assertFalse($mode->isEnabled());

        $mode->enable(12);
        self::assertTrue($mode->isEnabled());
        self::assertSame(12, $mode->currentJobId());

        $mode->disable(99);
        self::assertTrue($mode->isEnabled());

        $mode->disable(12);
        self::assertFalse($mode->isEnabled());
    }

    public function testStaleDetectionUsesEnabledAt(): void
    {
        $mode = new ReadOnlyMode();
        $mode->enable(33);

        $lock = get_option('lift_teleport_readonly_lock');
        self::assertIsArray($lock);

        $lock['enabled_at'] = time() - 7200;
        update_option('lift_teleport_readonly_lock', $lock, false);

        self::assertTrue($mode->isStale(3600));
        self::assertFalse($mode->isStale(10800));
    }
}
