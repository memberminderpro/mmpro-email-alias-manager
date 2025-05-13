<?php
// If uninstall is not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Remove options from database
delete_option('mmpro_email_aliases_data');

// Remove transients
delete_transient('mmpro_email_aliases_cache');

// Re-flush rewrite rules
flush_rewrite_rules();