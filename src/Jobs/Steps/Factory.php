<?php

declare(strict_types=1);

namespace LiftTeleport\Jobs\Steps;

use LiftTeleport\Jobs\JobRepository;
use LiftTeleport\Jobs\Steps\Export\BuildManifestStep;
use LiftTeleport\Jobs\Steps\Export\DumpDatabaseStep;
use LiftTeleport\Jobs\Steps\Export\FinalizeExportStep;
use LiftTeleport\Jobs\Steps\Export\PackageStep;
use LiftTeleport\Jobs\Steps\Export\ValidateExportStep;
use LiftTeleport\Jobs\Steps\Import\ExtractPackageStep;
use LiftTeleport\Jobs\Steps\Import\FinalizeImportStep;
use LiftTeleport\Jobs\Steps\Import\CaptureMergeAdminStep;
use LiftTeleport\Jobs\Steps\Import\PrecheckSpaceStep;
use LiftTeleport\Jobs\Steps\Import\ReadOnlyOnStep;
use LiftTeleport\Jobs\Steps\Import\RestoreDatabaseStep;
use LiftTeleport\Jobs\Steps\Import\SnapshotStep;
use LiftTeleport\Jobs\Steps\Import\SyncContentStep;
use LiftTeleport\Jobs\Steps\Import\ValidatePackageStep;
use LiftTeleport\Jobs\Steps\Unzipper\FinalizeStep as UnzipperFinalizeStep;
use LiftTeleport\Jobs\Steps\Unzipper\FullIntegrityStep as UnzipperFullIntegrityStep;
use LiftTeleport\Jobs\Steps\Unzipper\QuickScanStep as UnzipperQuickScanStep;
use LiftTeleport\Jobs\Steps\Unzipper\ValidatePackageStep as UnzipperValidatePackageStep;
use RuntimeException;

final class Factory
{
    /**
     * @return array<string,class-string<StepInterface>>
     */
    public static function map(): array
    {
        return [
            'export_validate' => ValidateExportStep::class,
            'export_dump_database' => DumpDatabaseStep::class,
            'export_build_manifest' => BuildManifestStep::class,
            'export_package' => PackageStep::class,
            'export_finalize' => FinalizeExportStep::class,

            'import_validate_package' => ValidatePackageStep::class,
            'import_precheck_space' => PrecheckSpaceStep::class,
            'import_capture_merge_admin' => CaptureMergeAdminStep::class,
            'import_snapshot' => SnapshotStep::class,
            'import_readonly_on' => ReadOnlyOnStep::class,
            'import_extract_package' => ExtractPackageStep::class,
            'import_restore_database' => RestoreDatabaseStep::class,
            'import_sync_content' => SyncContentStep::class,
            'import_finalize' => FinalizeImportStep::class,

            'unzipper_validate_package' => UnzipperValidatePackageStep::class,
            'unzipper_quick_scan' => UnzipperQuickScanStep::class,
            'unzipper_full_integrity' => UnzipperFullIntegrityStep::class,
            'unzipper_finalize' => UnzipperFinalizeStep::class,
        ];
    }

    public static function make(string $key, JobRepository $jobs): StepInterface
    {
        if ($key === 'import_maintenance_on') {
            do_action('lift_teleport_deprecated_step_alias_used', $key, 'import_readonly_on');
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Lift Teleport] Deprecated step alias import_maintenance_on used. Alias to import_readonly_on will be removed in the next release.');
            }
            $key = 'import_readonly_on';
        }

        $map = self::map();
        $class = $map[$key] ?? null;

        if ($class === null) {
            throw new RuntimeException(sprintf('Unknown job step: %s', $key));
        }

        return new $class($jobs);
    }

    public static function initialStepForType(string $type): string
    {
        return match ($type) {
            'import' => 'import_validate_package',
            'unzipper' => 'unzipper_validate_package',
            default => 'export_validate',
        };
    }
}
