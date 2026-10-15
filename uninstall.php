<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('cdair_settings');

global $wpdb;
$like = $wpdb->esc_like('_cdair_') . '%';
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $like));

$uploads = wp_upload_dir();
$backup_dir = trailingslashit($uploads['basedir']) . 'cdair-backups';
if (is_dir($backup_dir)) {
    cdair_uninstall_rmdir($backup_dir);
}

function cdair_uninstall_rmdir($dir) {
    $items = scandir($dir);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            cdair_uninstall_rmdir($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}
