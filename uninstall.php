<?php

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$jobs = $wpdb->prefix . 'lift_jobs';
$events = $wpdb->prefix . 'lift_job_events';

$wpdb->query("DROP TABLE IF EXISTS {$events}");
$wpdb->query("DROP TABLE IF EXISTS {$jobs}");

$root = WP_CONTENT_DIR . '/lift-teleport-data';
if (is_dir($root)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($root);
}
