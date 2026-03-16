<?php

declare(strict_types=1);

namespace LiftTeleport\Tests\Jobs;

use LiftTeleport\Jobs\Steps\StepFailure;
use PHPUnit\Framework\TestCase;

final class StepFailureTest extends TestCase
{
    public function testFatalFactoryBuildsNonRetryableFailure(): void
    {
        $error = StepFailure::fatal(
            'lift_import_invalid',
            'Import package is invalid.',
            'Re-upload package.',
            ['step' => 'import_validate_package']
        );

        self::assertSame('lift_import_invalid', $error->errorCodeName());
        self::assertFalse($error->isRetryable());
        self::assertSame('Re-upload package.', $error->hint());
        self::assertSame('import_validate_package', $error->context()['step'] ?? '');
    }

    public function testRetryableFactoryBuildsRetryableFailure(): void
    {
        $error = StepFailure::retryable(
            'lift_export_temp_io',
            'Temporary write failed.',
            'Retry.',
            ['path' => '/tmp/demo']
        );

        self::assertSame('lift_export_temp_io', $error->errorCodeName());
        self::assertTrue($error->isRetryable());
        self::assertSame('Retry.', $error->hint());
        self::assertSame('/tmp/demo', $error->context()['path'] ?? '');
    }
}

