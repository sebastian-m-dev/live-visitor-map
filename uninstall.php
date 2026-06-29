<?php
if (!defined('WP_UNINSTALL_PLUGIN'))
    exit;

global $wpdb;

$tables = ['lvm_visits', 'lvm_sessions'];
foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}");
}

delete_option('lvm_exclude_roles');
delete_option('lvm_retention_days');
delete_option('lvm_anonymize_ip');
