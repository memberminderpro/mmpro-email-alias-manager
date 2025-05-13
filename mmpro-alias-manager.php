<?php
/**
 * Plugin Name: MMPRO Email Alias Manager
 * Plugin URI: https://memberminderpro.com/
 * Description: Manage email aliases and forward rules with a JSON API endpoint for Cloudflare Workers.
 * Version: 1.0.6-alpha.1
 * Author: Member Minder Pro, LLC
 * Author URI: https://memberminderpro.com/
 * Text Domain: mmpro-email-aliases
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

class MMPRO_Email_Alias_Manager {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Admin menu & settings
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // API endpoint - register on both hooks for better compatibility
        add_action('rest_api_init', array($this, 'register_api_endpoint'));
        add_action('init', array($this, 'add_rewrite_rules'));
        
        // REST API diagnostics
        add_action('admin_menu', array($this, 'add_diagnostics_page'));
        
        // Plugin activation hook
        register_activation_hook(__FILE__, array($this, 'activate_plugin'));
        
        // Debug data persistence
        add_action('admin_notices', array($this, 'debug_data_persistence'));
    }
    
    /**
     * Debug data persistence
     */
    public function debug_data_persistence() {
        // Only show on our admin pages
        $screen = get_current_screen();
        if ($screen->id !== 'toplevel_page_mmpro-email-aliases' && $screen->id !== 'email-aliases_page_mmpro-email-aliases-diagnostics') {
            return;
        }
        
        // Create debug log entry
        $debug_log = get_option('mmpro_email_aliases_debug_log', array());
        $debug_log[] = array(
            'time' => current_time('mysql'),
            'action' => 'page_load',
            'count' => count(get_option('mmpro_email_aliases_data', array()))
        );
        update_option('mmpro_email_aliases_debug_log', array_slice($debug_log, -10)); // Keep only last 10 entries
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Email Aliases', 'mmpro-email-aliases'),
            __('Email Aliases', 'mmpro-email-aliases'),
            'manage_options',
            'mmpro-email-aliases',
            array($this, 'render_admin_page'),
            'dashicons-email-alt',
            30
        );
    }
    
    /**
     * Add diagnostics submenu
     */
    public function add_diagnostics_page() {
        add_submenu_page(
            'mmpro-email-aliases',
            __('API Diagnostics', 'mmpro-email-aliases'),
            __('API Diagnostics', 'mmpro-email-aliases'),
            'manage_options',
            'mmpro-email-aliases-diagnostics',
            array($this, 'render_diagnostics_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting(
            'mmpro_email_aliases_options',
            'mmpro_email_aliases_data',
            array('sanitize_callback' => array($this, 'sanitize_aliases_data'))
        );
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if ('toplevel_page_mmpro-email-aliases' !== $hook && 'email-aliases_page_mmpro-email-aliases-diagnostics' !== $hook) {
            return;
        }
        
        // Enqueue core WP scripts
        wp_enqueue_script('jquery');
        wp_enqueue_script('jquery-ui-sortable');
        
        // Add our custom inline styles
        wp_add_inline_style('admin-bar', $this->get_admin_styles());
    }
    
    /**
     * Get admin styles
     */
    private function get_admin_styles() {
        return '
        .mmpro-alias-card {
            background: #fff;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
            margin-bottom: 20px;
        }
        
        .mmpro-alias-header {
            padding: 15px;
            background: #f9f9f9;
            border-bottom: 1px solid #ccd0d4;
            position: relative;
        }
        
        .mmpro-alias-header h3 {
            margin: 0;
        }
        
        .mmpro-alias-content {
            padding: 15px;
        }
        
        .mmpro-alias-field {
            margin-bottom: 15px;
        }
        
        .mmpro-alias-field label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }
        
        .mmpro-destinations {
            margin-top: 20px;
        }
        
        .mmpro-destination-item {
            display: flex;
            margin-bottom: 10px;
            align-items: center;
        }
        
        .mmpro-destination-item input {
            flex: 1;
            margin-right: 10px;
        }
        
        .mmpro-add-destination {
            margin-top: 10px;
        }
        
        .mmpro-alias-actions {
            position: absolute;
            right: 15px;
            top: 12px;
        }
        
        .mmpro-remove-alias {
            color: #a00;
            text-decoration: none;
        }
        
        .mmpro-remove-alias:hover {
            color: #dc3232;
        }
        
        /* Diagnostics styles */
        .mmpro-card {
            background: #fff;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .mmpro-card h2 {
            margin-top: 0;
        }
        
        .mmpro-status-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .mmpro-status-table th, 
        .mmpro-status-table td {
            padding: 10px;
            border-bottom: 1px solid #e5e5e5;
            text-align: left;
        }
        
        .mmpro-status-success {
            color: green;
        }
        
        .mmpro-status-error {
            color: red;
        }
        
        pre.mmpro-code {
            background: #f5f5f5;
            padding: 10px;
            overflow: auto;
            max-height: 300px;
        }

        .data-persistence-warning {
            border-left: 4px solid #dc3232;
            padding: 12px;
            margin: 20px 0;
            background: #fff8f8;
        }
        ';
    }
    
    /**
     * Sanitize aliases data
     */
    public function sanitize_aliases_data($input) {
        if (!is_array($input)) return array();
        
        $output = array();
        
        foreach ($input as $alias_id => $data) {
            if (empty($data['address']) || empty($data['destinations'])) continue;
            
            $alias = sanitize_email($data['address']);
            
            if (empty($alias)) continue;
            
            $destinations = array();
            
            foreach ($data['destinations'] as $dest) {
                $clean_dest = sanitize_email($dest);
                if (!empty($clean_dest)) {
                    $destinations[] = $clean_dest;
                }
            }
            
            if (!empty($destinations)) {
                $output[$alias] = $destinations;
            }
        }
        
        // Create backup of the data
        update_option('mmpro_email_aliases_data_backup', $output);
        
        // Log data update
        $debug_log = get_option('mmpro_email_aliases_debug_log', array());
        $debug_log[] = array(
            'time' => current_time('mysql'),
            'action' => 'data_save',
            'count' => count($output)
        );
        update_option('mmpro_email_aliases_debug_log', array_slice($debug_log, -10)); // Keep only last 10 entries
        
        // Clear cache when data is updated
        delete_transient('mmpro_email_aliases_cache');
        
        return $output;
    }
    /**
 * Render admin page with improved layout
 */
public function render_admin_page() {
    // Check for data loss issue
    $this->check_for_data_loss();
    
    // Get saved data with backup fallback
    $aliases_data = $this->get_aliases_data_with_backup();
    
    // Process form submissions
    $this->process_form_submissions($aliases_data);
    
    ?>
    <div class="wrap mmpro-admin-wrapper">
        <h1><?php _e('Email Aliases Manager', 'mmpro-email-aliases'); ?></h1>
        
        <?php if ($this->has_data_persistence_issue()): ?>
            <div class="data-persistence-warning">
                <h3><?php _e('⚠️ Data Persistence Issue Detected', 'mmpro-email-aliases'); ?></h3>
                <p><?php _e('We\'ve detected that your email aliases data is being lost between page loads. This could be caused by:', 'mmpro-email-aliases'); ?></p>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li><?php _e('Another plugin interfering with WordPress options', 'mmpro-email-aliases'); ?></li>
                    <li><?php _e('Object cache configuration issues', 'mmpro-email-aliases'); ?></li>
                    <li><?php _e('Database permission issues', 'mmpro-email-aliases'); ?></li>
                </ul>
                <p>
                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=mmpro-email-aliases&action=recover_data'), 'mmpro_recover_data'); ?>" class="button button-primary">
                        <?php _e('Attempt Data Recovery', 'mmpro-email-aliases'); ?>
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=mmpro-email-aliases-diagnostics'); ?>" class="button">
                        <?php _e('View Diagnostics', 'mmpro-email-aliases'); ?>
                    </a>
                </p>
            </div>
        <?php endif; ?>
        
        <div class="mmpro-admin-columns">
            <!-- Main Content Column -->
            <div class="mmpro-main-column">
                <!-- Main Instructions Card -->
                <div class="mmpro-card">
                    <h2><?php _e('Email Aliases Management', 'mmpro-email-aliases'); ?></h2>
                    <p><?php _e('Create and manage email aliases that will forward to one or more destination addresses.', 'mmpro-email-aliases'); ?></p>
                    <p><?php _e('Each alias will be available through the API for use with Cloudflare Email Workers.', 'mmpro-email-aliases'); ?></p>
                    
                    <div class="mmpro-button-row">
                        <button type="button" id="add-alias-top" class="button"><?php _e('Add New Alias', 'mmpro-email-aliases'); ?></button>
                        <button type="submit" form="mmpro-aliases-form" name="submit_aliases" class="button button-primary"><?php _e('Save All Aliases', 'mmpro-email-aliases'); ?></button>
                    </div>
                </div>
                
                <!-- Aliases Form -->
                <form method="post" action="" id="mmpro-aliases-form">
                    <?php wp_nonce_field('mmpro_save_aliases', 'mmpro_nonce'); ?>
                    
                    <div id="mmpro-aliases-container">
                        <?php
                        $index = 0;
                        if (!empty($aliases_data)) {
                            foreach ($aliases_data as $alias => $destinations) {
                                $this->render_alias_card($index, $alias, $destinations);
                                $index++;
                            }
                        }
                        ?>
                    </div>
                    
                    <div class="mmpro-button-row" style="margin-top: 20px;">
                        <button type="button" id="add-alias-bottom" class="button"><?php _e('Add New Alias', 'mmpro-email-aliases'); ?></button>
                        <input type="submit" name="submit_aliases" class="button button-primary" value="<?php _e('Save All Aliases', 'mmpro-email-aliases'); ?>">
                    </div>
                </form>
            </div>
            
            <!-- Sidebar Column -->
            <div class="mmpro-sidebar-column">
                <!-- Import/Export Card -->
                <div class="mmpro-card">
                    <h3><?php _e('Import & Export', 'mmpro-email-aliases'); ?></h3>
                    <div class="mmpro-export-buttons" style="margin-bottom: 15px;">
                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=mmpro-email-aliases&action=export&format=json'), 'mmpro_export'); ?>" class="button"><?php _e('Export as JSON', 'mmpro-email-aliases'); ?></a>
                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=mmpro-email-aliases&action=export&format=csv'), 'mmpro_export'); ?>" class="button"><?php _e('Export as CSV', 'mmpro-email-aliases'); ?></a>
                    </div>
                    
                    <h4><?php _e('Import Aliases', 'mmpro-email-aliases'); ?></h4>
                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field('mmpro_import_aliases', 'mmpro_import_nonce'); ?>
                        <p>
                            <label for="import_file"><?php _e('Select File:', 'mmpro-email-aliases'); ?></label>
                            <input type="file" name="import_file" id="import_file" required>
                        </p>
                        <p>
                            <label for="format"><?php _e('Format:', 'mmpro-email-aliases'); ?></label>
                            <select name="format" id="format">
                                <option value="json">JSON</option>
                                <option value="csv">CSV</option>
                            </select>
                        </p>
                        <p>
                            <input type="submit" name="import_aliases" class="button button-primary" value="<?php _e('Import', 'mmpro-email-aliases'); ?>">
                        </p>
                    </form>
                </div>
                
                <!-- API Status Card -->
                <div class="mmpro-card">
                    <h3><?php _e('API Status', 'mmpro-email-aliases'); ?></h3>
                    <?php
                    $registration_time = get_option('mmpro_rest_api_registered');
                    if ($registration_time) {
                        echo '<p><span class="dashicons dashicons-yes-alt" style="color:green;"></span> ';
                        echo sprintf(__('REST API registered at: %s', 'mmpro-email-aliases'), 
                                    date('Y-m-d H:i:s', $registration_time)) . '</p>';
                    } else {
                        echo '<p><span class="dashicons dashicons-warning" style="color:red;"></span> ';
                        echo __('REST API not registered', 'mmpro-email-aliases') . '</p>';
                    }
                    ?>
                    
                    <p><strong><?php _e('API Endpoints:', 'mmpro-email-aliases'); ?></strong></p>
                    <ul>
                        <li>
                            <a href="<?php echo esc_url(rest_url('mmpro/v1/aliases')); ?>" target="_blank">
                                <?php _e('Standard REST API', 'mmpro-email-aliases'); ?>
                            </a>
                        </li>
                        <li>
                            <a href="<?php echo esc_url(home_url('api/aliases/')); ?>" target="_blank">
                                <?php _e('Pretty URL', 'mmpro-email-aliases'); ?>
                            </a>
                        </li>
                        <?php if (file_exists(ABSPATH . 'direct-api/aliases.php')): ?>
                        <li>
                            <a href="<?php echo esc_url(home_url('direct-api/aliases.php')); ?>" target="_blank">
                                <?php _e('Direct API', 'mmpro-email-aliases'); ?>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                    
                    <p>
                        <a href="<?php echo admin_url('admin.php?page=mmpro-email-aliases-diagnostics'); ?>" class="button">
                            <?php _e('API Diagnostics', 'mmpro-email-aliases'); ?>
                        </a>
                        <?php if (!file_exists(ABSPATH . 'direct-api/aliases.php')): ?>
                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=mmpro-email-aliases&action=direct_access'), 'mmpro_direct_access'); ?>" class="button">
                            <?php _e('Setup Direct API', 'mmpro-email-aliases'); ?>
                        </a>
                        <?php endif; ?>
                    </p>
                </div>
                
                <!-- Data Status Card -->
                <div class="mmpro-card">
                    <h3><?php _e('Data Status', 'mmpro-email-aliases'); ?></h3>
                    <p>
                        <?php printf(__('Primary Data: <strong>%d aliases</strong>', 'mmpro-email-aliases'), count(get_option('mmpro_email_aliases_data', array()))); ?>
                    </p>
                    <p>
                        <?php printf(__('Backup Data: <strong>%d aliases</strong>', 'mmpro-email-aliases'), count(get_option('mmpro_email_aliases_data_backup', array()))); ?>
                    </p>
                    <p>
                        <?php printf(__('Alternate Data: <strong>%d aliases</strong>', 'mmpro-email-aliases'), count(get_option('mmpro_email_aliases_data_alt', array()))); ?>
                    </p>
                    
                    <?php if ($this->has_data_persistence_issue()): ?>
                    <p>
                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=mmpro-email-aliases&action=recover_data'), 'mmpro_recover_data'); ?>" class="button">
                            <?php _e('Recover Data', 'mmpro-email-aliases'); ?>
                        </a>
                    </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Templates for JavaScript -->
        <script type="text/html" id="tmpl-alias-card">
            <?php $this->render_alias_card('{{data.index}}', '', array('')); ?>
        </script>
        
        <script type="text/html" id="tmpl-destination-item">
            <div class="mmpro-destination-item">
                <input type="email" name="aliases[{{data.aliasIndex}}][destinations][]" value="" class="regular-text" required>
                <button type="button" class="button button-small mmpro-remove-destination"><?php _e('Remove', 'mmpro-email-aliases'); ?></button>
            </div>
        </script>
        
        <style type="text/css">
            .mmpro-admin-wrapper {
                margin-right: 20px;
            }
            
            .mmpro-admin-columns {
                display: flex;
                gap: 20px;
                margin-top: 20px;
            }
            
            .mmpro-main-column {
                flex: 3;
                min-width: 0; /* Fix for flexbox overflow */
            }
            
            .mmpro-sidebar-column {
                flex: 1;
                min-width: 250px;
                max-width: 350px;
            }
            
            .mmpro-card {
                background: #fff;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
                padding: 15px;
                margin-bottom: 20px;
            }
            
            .mmpro-card h2, .mmpro-card h3, .mmpro-card h4 {
                margin-top: 0;
                margin-bottom: 15px;
            }
            
            .mmpro-button-row {
                display: flex;
                gap: 10px;
                margin-top: 15px;
            }
            
            @media (max-width: 782px) {
                .mmpro-admin-columns {
                    flex-direction: column;
                }
                
                .mmpro-sidebar-column {
                    max-width: none;
                }
            }
        </style>
        
        <script>
            jQuery(document).ready(function($) {
                var nextIndex = <?php echo $index; ?>;
                
                // Function to add new alias card
                function addNewAliasCard() {
                    var template = $('#tmpl-alias-card').html();
                    template = template.replace(/\{\{data\.index\}\}/g, nextIndex);
                    
                    $('#mmpro-aliases-container').append(template);
                    nextIndex++;
                }
                
                // Add alias buttons (both top and bottom)
                $('#add-alias-top, #add-alias-bottom').on('click', function() {
                    addNewAliasCard();
                });
                
                // Remove alias button
                $(document).on('click', '.mmpro-remove-alias', function(e) {
                    e.preventDefault();
                    
                    if (confirm('<?php _e('Are you sure you want to remove this alias?', 'mmpro-email-aliases'); ?>')) {
                        $(this).closest('.mmpro-alias-card').remove();
                    }
                });
                
                // Add destination button
                $(document).on('click', '.mmpro-add-destination', function() {
                    var aliasCard = $(this).closest('.mmpro-alias-card');
                    var aliasIndex = aliasCard.data('index');
                    var destinationsContainer = aliasCard.find('.mmpro-destinations-container');
                    
                    var template = $('#tmpl-destination-item').html();
                    template = template.replace(/\{\{data\.aliasIndex\}\}/g, aliasIndex);
                    
                    destinationsContainer.append(template);
                });
                
                // Remove destination button
                $(document).on('click', '.mmpro-remove-destination', function() {
                    var destinationItems = $(this).closest('.mmpro-destinations-container').find('.mmpro-destination-item');
                    
                    // Don't remove if it's the last one
                    if (destinationItems.length > 1) {
                        $(this).closest('.mmpro-destination-item').remove();
                    } else {
                        alert('<?php _e('You must have at least one destination email address.', 'mmpro-email-aliases'); ?>');
                    }
                });
                
                // If no aliases exist, add one empty card
                if ($('.mmpro-alias-card').length === 0) {
                    addNewAliasCard();
                }
            });
        </script>
    </div>
    <?php
}

/**
 * Process form submissions
 */
private function process_form_submissions(&$aliases_data) {
    // Handle flush rules action
    if (isset($_GET['action']) && $_GET['action'] === 'flush_rules' && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'mmpro_flush_rules')) {
        $this->add_rewrite_rules();
        flush_rewrite_rules();
        echo '<div class="notice notice-success is-dismissible"><p>' . __('Rewrite rules flushed successfully!', 'mmpro-email-aliases') . '</p></div>';
    }
    
    // Handle direct API access setup
    if (isset($_GET['action']) && $_GET['action'] === 'direct_access' && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'mmpro_direct_access')) {
        $this->setup_direct_api_access();
        echo '<div class="notice notice-success is-dismissible"><p>' . __('Direct API access has been set up at: ', 'mmpro-email-aliases');
        echo '<a href="' . home_url('direct-api/aliases.php') . '" target="_blank">' . home_url('direct-api/aliases.php') . '</a></p></div>';
    }
    
    // Handle save aliases
    if (isset($_POST['submit_aliases']) && check_admin_referer('mmpro_save_aliases', 'mmpro_nonce')) {
        // Process form submission
        $new_aliases = array();
        
        if (isset($_POST['aliases']) && is_array($_POST['aliases'])) {
            foreach ($_POST['aliases'] as $index => $data) {
                if (empty($data['address']) || !isset($data['destinations']) || !is_array($data['destinations'])) {
                    continue;
                }
                
                $alias = sanitize_email($data['address']);
                $destinations = array_map('sanitize_email', $data['destinations']);
                $destinations = array_filter($destinations);
                
                if (!empty($alias) && !empty($destinations)) {
                    $new_aliases[$alias] = $destinations;
                }
            }
        }
        
        // Save with multiple methods for redundancy
        update_option('mmpro_email_aliases_data', $new_aliases);
        update_option('mmpro_email_aliases_data_backup', $new_aliases);
        
        // Also save as a separate name to check for conflicts
        update_option('mmpro_email_aliases_data_alt', $new_aliases);
        
        // Log successful save
        $this->log_data_operation('save', count($new_aliases));
        
        delete_transient('mmpro_email_aliases_cache');
        $aliases_data = $new_aliases;
        
        echo '<div class="notice notice-success is-dismissible"><p>' . __('Aliases saved successfully!', 'mmpro-email-aliases') . '</p></div>';
    }
    
    // Handle import
    if (isset($_POST['import_aliases']) && check_admin_referer('mmpro_import_aliases', 'mmpro_import_nonce')) {
        if (!empty($_FILES['import_file']['tmp_name'])) {
            $format = isset($_POST['format']) ? sanitize_text_field($_POST['format']) : 'json';
            $file = $_FILES['import_file']['tmp_name'];
            $import_data = array();
            
            if ($format === 'json') {
                $content = file_get_contents($file);
                $data = json_decode($content, true);
                
                if ($data && is_array($data)) {
                    $import_data = $data;
                }
            } elseif ($format === 'csv') {
                if (($handle = fopen($file, 'r')) !== false) {
                    // Skip header row
                    fgetcsv($handle);
                    
                    while (($row = fgetcsv($handle)) !== false) {
                        if (count($row) >= 2 && !empty($row[0])) {
                            $alias = sanitize_email($row[0]);
                            $destinations = array_map('trim', explode(',', $row[1]));
                            $destinations = array_map('sanitize_email', $destinations);
                            $destinations = array_filter($destinations);
                            
                            if (!empty($alias) && !empty($destinations)) {
                                $import_data[$alias] = $destinations;
                            }
                        }
                    }
                    
                    fclose($handle);
                }
            }
            
            if (!empty($import_data)) {
                // Save with multiple methods for redundancy
                update_option('mmpro_email_aliases_data', $import_data);
                update_option('mmpro_email_aliases_data_backup', $import_data);
                
                // Also save as a separate name to check for conflicts
                update_option('mmpro_email_aliases_data_alt', $import_data);
                
                // Log successful import
                $this->log_data_operation('import', count($import_data));
                
                delete_transient('mmpro_email_aliases_cache');
                $aliases_data = $import_data;
                echo '<div class="notice notice-success is-dismissible"><p>' . __('Aliases imported successfully!', 'mmpro-email-aliases') . '</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>' . __('Invalid import file or format.', 'mmpro-email-aliases') . '</p></div>';
            }
        }
    }
    
    // Handle export
    if (isset($_GET['action']) && $_GET['action'] === 'export' && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'mmpro_export')) {
        $format = isset($_GET['format']) ? sanitize_text_field($_GET['format']) : 'json';
        
        if ($format === 'json') {
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="email-aliases-' . date('Y-m-d') . '.json"');
            echo json_encode($aliases_data, JSON_PRETTY_PRINT);
            exit;
        } elseif ($format === 'csv') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="email-aliases-' . date('Y-m-d') . '.csv"');
            
            $output = fopen('php://output', 'w');
            fputcsv($output, array('Alias', 'Destinations'));
            
            foreach ($aliases_data as $alias => $destinations) {
                fputcsv($output, array($alias, implode(', ', $destinations)));
            }
            
            fclose($output);
            exit;
        }
    }
    
    // Handle data recovery
    if (isset($_GET['action']) && $_GET['action'] === 'recover_data' && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'mmpro_recover_data')) {
        $backup_data = get_option('mmpro_email_aliases_data_backup', array());
        $alt_data = get_option('mmpro_email_aliases_data_alt', array());
        
        // Choose the non-empty backup source
        $recovery_data = !empty($backup_data) ? $backup_data : $alt_data;
        
        if (!empty($recovery_data)) {
            update_option('mmpro_email_aliases_data', $recovery_data);
            delete_transient('mmpro_email_aliases_cache');
            
            // Log recovery operation
            $this->log_data_operation('recovery', count($recovery_data));
            
            $aliases_data = $recovery_data;
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p>' . sprintf(__('Successfully recovered %d aliases from backup!', 'mmpro-email-aliases'), count($recovery_data)) . '</p>';
            echo '</div>';
        } else {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p>' . __('No backup data found to recover.', 'mmpro-email-aliases') . '</p>';
            echo '</div>';
        }
    }
}
    
    /**
     * Show data persistence debug info
     */
    private function show_data_persistence_debug() {
        // Only show in debug mode
        if (!isset($_GET['debug']) || $_GET['debug'] !== '1') {
            return;
        }
        
        $debug_log = get_option('mmpro_email_aliases_debug_log', array());
        
        echo '<div class="mmpro-card">';
        echo '<h3>' . __('Data Operation Log', 'mmpro-email-aliases') . '</h3>';
        
        if (empty($debug_log)) {
            echo '<p>' . __('No log entries found.', 'mmpro-email-aliases') . '</p>';
        } else {
            echo '<table class="mmpro-status-table">';
            echo '<tr><th>Time</th><th>Action</th><th>Count</th></tr>';
            
            foreach (array_reverse($debug_log) as $entry) {
                echo '<tr>';
                echo '<td>' . esc_html($entry['time']) . '</td>';
                echo '<td>' . esc_html($entry['action']) . '</td>';
                echo '<td>' . esc_html($entry['count']) . '</td>';
                echo '</tr>';
            }
            
            echo '</table>';
        }
        
        echo '</div>';
    }
    
    /**
     * Log data operation
     */
    private function log_data_operation($action, $count) {
        $debug_log = get_option('mmpro_email_aliases_debug_log', array());
        $debug_log[] = array(
            'time' => current_time('mysql'),
            'action' => $action,
            'count' => $count
        );
        update_option('mmpro_email_aliases_debug_log', array_slice($debug_log, -10)); // Keep only last 10 entries
    }
    
    /**
     * Check for data loss issue
     */
    private function check_for_data_loss() {
        $current_count = count(get_option('mmpro_email_aliases_data', array()));
        $backup_count = count(get_option('mmpro_email_aliases_data_backup', array()));
        $alt_count = count(get_option('mmpro_email_aliases_data_alt', array()));
        
        // If we have a significant discrepancy, try to recover
        if ($current_count == 0 && ($backup_count > 0 || $alt_count > 0)) {
            $recovery_source = $backup_count > $alt_count ? 'backup' : 'alt';
            $recovery_data = $recovery_source === 'backup' 
                ? get_option('mmpro_email_aliases_data_backup', array())
                : get_option('mmpro_email_aliases_data_alt', array());
            
            // Auto-recover
            update_option('mmpro_email_aliases_data', $recovery_data);
            $this->log_data_operation('auto_recovery_' . $recovery_source, count($recovery_data));
            
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p>' . sprintf(
                __('Data loss detected! Automatically recovered %d aliases from %s storage.', 'mmpro-email-aliases'),
                count($recovery_data),
                $recovery_source === 'backup' ? 'backup' : 'alternate'
            ) . '</p>';
            echo '</div>';
        }
    }
    
    /**
     * Get aliases data with backup fallback
     */
    private function get_aliases_data_with_backup() {
        $data = get_option('mmpro_email_aliases_data', array());
        
        // If primary data is empty, try to use backup data
        if (empty($data)) {
            $backup_data = get_option('mmpro_email_aliases_data_backup', array());
            $alt_data = get_option('mmpro_email_aliases_data_alt', array());
            
            // Use the backup with more data
            if (count($backup_data) > count($data)) {
                $data = $backup_data;
                update_option('mmpro_email_aliases_data', $data); // Restore from backup
                $this->log_data_operation('restore_from_backup', count($data));
            } elseif (count($alt_data) > count($data)) {
                $data = $alt_data;
                update_option('mmpro_email_aliases_data', $data); // Restore from alt
                $this->log_data_operation('restore_from_alt', count($data));
            }
        }
        
        return $data;
    }
    
    /**
     * Check if there's a data persistence issue
     */
    private function has_data_persistence_issue() {
        $debug_log = get_option('mmpro_email_aliases_debug_log', array());
        
        // Need at least 3 log entries to detect pattern
        if (count($debug_log) < 3) {
            return false;
        }
        
        // Look for pattern where data disappears between operations
        $save_imports = 0;
        $zero_loads = 0;
        
        foreach ($debug_log as $entry) {
            if (in_array($entry['action'], array('save', 'import')) && $entry['count'] > 0) {
                $save_imports++;
            }
            if ($entry['action'] === 'page_load' && $entry['count'] === 0) {
                $zero_loads++;
            }
        }
        
        // If we have saves with data followed by page loads with no data, that's a persistence issue
        return ($save_imports > 0 && $zero_loads > 0);
    }
    
    /**
     * Set up direct API access (no REST API)
     */
    private function setup_direct_api_access() {
        // Create directory if it doesn't exist
        $upload_dir = wp_upload_dir();
        $direct_api_dir = ABSPATH . 'direct-api';
        
        if (!file_exists($direct_api_dir)) {
            mkdir($direct_api_dir);
        }
        
        // Create the direct API file
        $api_file = $direct_api_dir . '/aliases.php';
        $api_content = <<<EOT
<?php
// Direct API endpoint for MMPRO Email Alias Manager
// Version: 1.0

// Load WordPress
define('WP_USE_THEMES', false);
require_once(dirname(dirname(__FILE__)) . '/wp-load.php');

// Set headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Get the aliases data
\$aliases_data = get_option('mmpro_email_aliases_data', array());
\$backup_data = get_option('mmpro_email_aliases_data_backup', array());
\$alt_data = get_option('mmpro_email_aliases_data_alt', array());

// Use the data source with the most entries
if (empty(\$aliases_data) && !empty(\$backup_data)) {
    \$aliases_data = \$backup_data;
}

if (count(\$alt_data) > count(\$aliases_data)) {
    \$aliases_data = \$alt_data;
}

// Output as JSON
echo json_encode(\$aliases_data);
EOT;

        file_put_contents($api_file, $api_content);
        
        // Create an .htaccess file for security
        $htaccess_file = $direct_api_dir . '/.htaccess';
        $htaccess_content = <<<EOT
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule ^(.*)$ aliases.php [L]
</IfModule>

# Allow PHP files to be executed
<Files "aliases.php">
    Order Allow,Deny
    Allow from all
</Files>

# Deny access to other files
<FilesMatch "^\.">
    Order Deny,Allow
    Deny from all
</FilesMatch>
EOT;

        file_put_contents($htaccess_file, $htaccess_content);
        
        return true;
    }
    
    /**
     * Render diagnostics page
     */
    public function render_diagnostics_page() {
        // Test REST API registration
        if (isset($_GET['action']) && $_GET['action'] === 'manual_register' && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'mmpro_manual_register')) {
            $this->register_api_endpoint();
            echo '<div class="notice notice-success is-dismissible"><p>' . __('API endpoints manually registered.', 'mmpro-email-aliases') . '</p></div>';
        }
        
        // Flush rewrite rules
        if (isset($_GET['action']) && $_GET['action'] === 'flush_rules' && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'mmpro_flush_rules')) {
            $this->add_rewrite_rules();
            flush_rewrite_rules();
            echo '<div class="notice notice-success is-dismissible"><p>' . __('Rewrite rules flushed successfully!', 'mmpro-email-aliases') . '</p></div>';
        }
        
        // Direct API test
        if (isset($_GET['action']) && $_GET['action'] === 'direct_api_test' && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'mmpro_direct_api_test')) {
            $aliases_data = $this->get_aliases_json();
            
            echo '<div class="mmpro-card">';
            echo '<h2>' . __('Direct API Test Result', 'mmpro-email-aliases') . '</h2>';
            echo '<pre class="mmpro-code">' . json_encode($aliases_data, JSON_PRETTY_PRINT) . '</pre>';
            echo '</div>';
        }
        
        // Restore from backup
        if (isset($_GET['action']) && $_GET['action'] === 'restore_backup' && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'mmpro_restore_backup')) {
            $backup_data = get_option('mmpro_email_aliases_data_backup', array());
            
            if (!empty($backup_data)) {
                update_option('mmpro_email_aliases_data', $backup_data);
                $this->log_data_operation('manual_restore_backup', count($backup_data));
                
                echo '<div class="notice notice-success is-dismissible">';
                echo '<p>' . sprintf(__('Successfully restored %d aliases from backup!', 'mmpro-email-aliases'), count($backup_data)) . '</p>';
                echo '</div>';
            } else {
                echo '<div class="notice notice-error is-dismissible">';
                echo '<p>' . __('No backup data found to restore.', 'mmpro-email-aliases') . '</p>';
                echo '</div>';
            }
        }
        
        // Direct API setup
        if (isset($_GET['action']) && $_GET['action'] === 'setup_direct_api' && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'mmpro_setup_direct_api')) {
            $this->setup_direct_api_access();
            
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p>' . __('Direct API endpoint has been set up at:', 'mmpro-email-aliases') . ' ';
            echo '<a href="' . home_url('direct-api/aliases.php') . '" target="_blank">' . home_url('direct-api/aliases.php') . '</a></p>';
            echo '</div>';
        }
        
        ?>
        <div class="wrap">
            <h1><?php _e('Email Aliases API Diagnostics', 'mmpro-email-aliases'); ?></h1>
            
            <div class="mmpro-card">
                <h2><?php _e('Data Persistence Status', 'mmpro-email-aliases'); ?></h2>
                <table class="mmpro-status-table">
                    <tr>
                        <th><?php _e('Data Storage', 'mmpro-email-aliases'); ?></th>
                        <th><?php _e('Count', 'mmpro-email-aliases'); ?></th>
                        <th><?php _e('Actions', 'mmpro-email-aliases'); ?></th>
                    </tr>
                    <tr>
                        <td><?php _e('Primary Data', 'mmpro-email-aliases'); ?></td>
                        <td>
                            <?php
                            $primary_count = count(get_option('mmpro_email_aliases_data', array()));
                            echo $primary_count;
                            ?>
                        </td>
                        <td>
                            <?php if ($primary_count === 0): ?>
                                <span class="mmpro-status-error">✗ <?php _e('Empty!', 'mmpro-email-aliases'); ?></span>
                            <?php else: ?>
                                <span class="mmpro-status-success">✓ <?php _e('Contains data', 'mmpro-email-aliases'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><?php _e('Backup Data', 'mmpro-email-aliases'); ?></td>
                        <td>
                            <?php
                            $backup_count = count(get_option('mmpro_email_aliases_data_backup', array()));
                            echo $backup_count;
                            ?>
                        </td>
                        <td>
                            <?php if ($backup_count > 0): ?>
                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=mmpro-email-aliases-diagnostics&action=restore_backup'), 'mmpro_restore_backup'); ?>" class="button button-small">
                                    <?php _e('Restore from Backup', 'mmpro-email-aliases'); ?>
                                </a>
                            <?php else: ?>
                                <span class="mmpro-status-error">✗ <?php _e('No backup available', 'mmpro-email-aliases'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><?php _e('Alternate Data', 'mmpro-email-aliases'); ?></td>
                        <td>
                            <?php
                            $alt_count = count(get_option('mmpro_email_aliases_data_alt', array()));
                            echo $alt_count;
                            ?>
                        </td>
                        <td>
                            <?php if ($alt_count > 0): ?>
                                <button class="button button-small" onclick="restoreFromAlt()">
                                    <?php _e('Restore from Alternate', 'mmpro-email-aliases'); ?>
                                </button>
                                <script>
                                    function restoreFromAlt() {
                                        if (confirm('<?php _e('Restore data from alternate storage?', 'mmpro-email-aliases'); ?>')) {
                                            jQuery.post(
                                                ajaxurl,
                                                {
                                                    action: 'mmpro_restore_alt',
                                                    _wpnonce: '<?php echo wp_create_nonce('mmpro_restore_alt'); ?>'
                                                },
                                                function(response) {
                                                    alert(response.data.message);
                                                    location.reload();
                                                }
                                            );
                                        }
                                    }
                                </script>
                            <?php else: ?>
                                <span class="mmpro-status-error">✗ <?php _e('No alternate data', 'mmpro-email-aliases'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="mmpro-card">
                <h2><?php _e('REST API Status', 'mmpro-email-aliases'); ?></h2>
                <table class="mmpro-status-table">
                    <tr>
                        <th><?php _e('Test', 'mmpro-email-aliases'); ?></th>
                        <th><?php _e('Result', 'mmpro-email-aliases'); ?></th>
                    </tr>
                    <tr>
                        <td><?php _e('WP REST API Enabled', 'mmpro-email-aliases'); ?></td>
                        <td>
                            <?php 
                            $rest_url = get_rest_url();
                            $response = wp_remote_get($rest_url);
                            $code = wp_remote_retrieve_response_code($response);
                            
                            if ($code >= 200 && $code < 300) {
                                echo '<span class="mmpro-status-success">✓ ' . __('Working', 'mmpro-email-aliases') . '</span>';
                            } else {
                                echo '<span class="mmpro-status-error">✗ ' . __('Not Working', 'mmpro-email-aliases') . ' (' . $code . ')</span>';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td><?php _e('Plugin API Registration', 'mmpro-email-aliases'); ?></td>
                        <td>
                            <?php
                            $registration_time = get_option('mmpro_rest_api_registered');
                            
                            if ($registration_time) {
                                echo '<span class="mmpro-status-success">✓ ' . sprintf(__('Registered at %s', 'mmpro-email-aliases'), date('Y-m-d H:i:s', $registration_time)) . '</span>';
                            } else {
                                echo '<span class="mmpro-status-error">✗ ' . __('Not Registered', 'mmpro-email-aliases') . '</span>';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td><?php _e('Permalink Structure', 'mmpro-email-aliases'); ?></td>
                        <td>
                            <?php
                            $permalink_structure = get_option('permalink_structure');
                            if (empty($permalink_structure)) {
                                echo '<span class="mmpro-status-error">✗ ' . __('Default (not compatible with pretty URLs)', 'mmpro-email-aliases') . '</span>';
                            } else {
                                echo '<span class="mmpro-status-success">✓ ' . esc_html($permalink_structure) . '</span>';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td><?php _e('Registered REST Routes', 'mmpro-email-aliases'); ?></td>
                        <td>
                            <?php
                            global $wp_rest_server;
                            $routes = $wp_rest_server->get_routes();
                            
                            if (isset($routes['/mmpro/v1/aliases'])) {
                                echo '<span class="mmpro-status-success">✓ ' . __('mmpro/v1/aliases route found', 'mmpro-email-aliases') . '</span>';
                            } else {
                                echo '<span class="mmpro-status-error">✗ ' . __('mmpro/v1/aliases route NOT found', 'mmpro-email-aliases') . '</span>';
                            }
                            
                            if (isset($routes['/mmpro/v1/test'])) {
                                echo '<br><span class="mmpro-status-success">✓ ' . __('mmpro/v1/test route found', 'mmpro-email-aliases') . '</span>';
                            } else {
                                echo '<br><span class="mmpro-status-error">✗ ' . __('mmpro/v1/test route NOT found', 'mmpro-email-aliases') . '</span>';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td><?php _e('Standard API Response', 'mmpro-email-aliases'); ?></td>
                        <td>
                            <?php
                            $api_url = rest_url('mmpro/v1/aliases');
                            $response = wp_remote_get($api_url);
                            $code = wp_remote_retrieve_response_code($response);
                            $body = wp_remote_retrieve_body($response);
                            
                            if ($code >= 200 && $code < 300) {
                                echo '<span class="mmpro-status-success">✓ ' . sprintf(__('Working (HTTP %s)', 'mmpro-email-aliases'), $code) . '</span>';
                                
                                $data = json_decode($body, true);
                                if (empty($data)) {
                                    echo '<br><span class="mmpro-status-error">✗ ' . __('API returns empty data!', 'mmpro-email-aliases') . '</span>';
                                } else {
                                    echo '<br><span class="mmpro-status-success">✓ ' . sprintf(__('Contains %d aliases', 'mmpro-email-aliases'), count($data)) . '</span>';
                                }
                            } else {
                                echo '<span class="mmpro-status-error">✗ ' . sprintf(__('Failed (HTTP %s)', 'mmpro-email-aliases'), $code) . '</span>';
                                if (!empty($body)) {
                                    echo '<br><pre class="mmpro-code">' . esc_html($body) . '</pre>';
                                }
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td><?php _e('Pretty URL Response', 'mmpro-email-aliases'); ?></td>
                        <td>
                            <?php
                            $pretty_url = home_url('api/aliases/');
                            $response = wp_remote_get($pretty_url);
                            $code = wp_remote_retrieve_response_code($response);
                            $body = wp_remote_retrieve_body($response);
                            
                            if ($code >= 200 && $code < 300) {
                                echo '<span class="mmpro-status-success">✓ ' . sprintf(__('Working (HTTP %s)', 'mmpro-email-aliases'), $code) . '</span>';
                                
                                $data = json_decode($body, true);
                                if (empty($data)) {
                                    echo '<br><span class="mmpro-status-error">✗ ' . __('API returns empty data!', 'mmpro-email-aliases') . '</span>';
                                } else {
                                    echo '<br><span class="mmpro-status-success">✓ ' . sprintf(__('Contains %d aliases', 'mmpro-email-aliases'), count($data)) . '</span>';
                                }
                            } else {
                                echo '<span class="mmpro-status-error">✗ ' . sprintf(__('Failed (HTTP %s)', 'mmpro-email-aliases'), $code) . '</span>';
                                if (!empty($body)) {
                                    echo '<br><pre class="mmpro-code">' . esc_html($body) . '</pre>';
                                }
                            }
                            ?>
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="mmpro-card">
                <h2><?php _e('REST API URLs', 'mmpro-email-aliases'); ?></h2>
                <table class="mmpro-status-table">
                    <tr>
                        <th><?php _e('Endpoint', 'mmpro-email-aliases'); ?></th>
                        <th><?php _e('URL', 'mmpro-email-aliases'); ?></th>
                    </tr>
                    <tr>
                        <td><?php _e('WP REST Base', 'mmpro-email-aliases'); ?></td>
                        <td><a href="<?php echo esc_url(rest_url()); ?>" target="_blank"><?php echo esc_html(rest_url()); ?></a></td>
                    </tr>
                    <tr>
                        <td><?php _e('Plugin Endpoint (Standard)', 'mmpro-email-aliases'); ?></td>
                        <td><a href="<?php echo esc_url(rest_url('mmpro/v1/aliases')); ?>" target="_blank"><?php echo esc_html(rest_url('mmpro/v1/aliases')); ?></a></td>
                    </tr>
                    <tr>
                        <td><?php _e('Plugin Endpoint (Pretty)', 'mmpro-email-aliases'); ?></td>
                        <td><a href="<?php echo esc_url(home_url('api/aliases/')); ?>" target="_blank"><?php echo esc_html(home_url('api/aliases/')); ?></a></td>
                    </tr>
                    <tr>
                        <td><?php _e('Test Endpoint', 'mmpro-email-aliases'); ?></td>
                        <td><a href="<?php echo esc_url(rest_url('mmpro/v1/test')); ?>" target="_blank"><?php echo esc_html(rest_url('mmpro/v1/test')); ?></a></td>
                    </tr>
                    <tr>
                        <td><?php _e('Direct API Endpoint', 'mmpro-email-aliases'); ?></td>
                        <td>
                            <?php if (file_exists(ABSPATH . 'direct-api/aliases.php')): ?>
                                <a href="<?php echo esc_url(home_url('direct-api/aliases.php')); ?>" target="_blank"><?php echo esc_html(home_url('direct-api/aliases.php')); ?></a>
                            <?php else: ?>
                                <em><?php _e('Not set up yet', 'mmpro-email-aliases'); ?></em>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="mmpro-card">
                <h2><?php _e('Troubleshooting Tools', 'mmpro-email-aliases'); ?></h2>
                <p>
                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=mmpro-email-aliases-diagnostics&action=manual_register'), 'mmpro_manual_register'); ?>" class="button"><?php _e('Manually Register API Endpoints', 'mmpro-email-aliases'); ?></a>
                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=mmpro-email-aliases-diagnostics&action=flush_rules'), 'mmpro_flush_rules'); ?>" class="button"><?php _e('Flush Rewrite Rules', 'mmpro-email-aliases'); ?></a>
                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=mmpro-email-aliases-diagnostics&action=direct_api_test'), 'mmpro_direct_api_test'); ?>" class="button"><?php _e('Test Direct API Access', 'mmpro-email-aliases'); ?></a>
                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=mmpro-email-aliases-diagnostics&action=setup_direct_api'), 'mmpro_setup_direct_api'); ?>" class="button button-primary"><?php _e('Set Up Direct API Access', 'mmpro-email-aliases'); ?></a>
                </p>
                
                <h3><?php _e('Recommended Actions for Data Issues', 'mmpro-email-aliases'); ?></h3>
                <ol>
                    <li><?php _e('Re-import your aliases JSON file', 'mmpro-email-aliases'); ?></li>
                    <li><?php _e('Click "Set Up Direct API Access" to create a direct PHP endpoint', 'mmpro-email-aliases'); ?></li>
                    <li><?php _e('Update your Cloudflare worker to use the direct API instead of REST API', 'mmpro-email-aliases'); ?></li>
                </ol>
                
                <h3><?php _e('Alternative Cloudflare Worker Code', 'mmpro-email-aliases'); ?></h3>
                <p><?php _e('If you\'re experiencing data persistence issues, use this worker code that tries multiple endpoints:', 'mmpro-email-aliases'); ?></p>
                <pre class="mmpro-code">export default {
  // Cache for storing the routing map
  routingMapCache: null,
  routingMapExpiry: 0,
  cacheTTL: 3600000, // Cache TTL: 1 hour in milliseconds
  
  async email(message, env, ctx) {
    try {
      // Get site-specific API endpoint from environment variables
      // Fallback to the domain of the incoming email if not specified
      const apiDomain = env.API_DOMAIN || message.to.split('@')[1];
      
      // Get routing map from API with caching
      const routingMap = await this.getRoutingMap(apiDomain, env);
      
      // Extract local part and full email
      const [localPart, domain] = message.to.split("@");
      const fullEmail = message.to.toLowerCase();
      const localPartLower = localPart.toLowerCase();
      
      // Try multiple possible key formats
      const recipients = 
        routingMap[fullEmail] || 
        routingMap[localPartLower + "@" + domain] ||
        routingMap[localPartLower];
      
      // If no mapping found, log and drop
      if (!recipients || !recipients.length) {
        console.log(`MMPRO Email Forwarding: No mapping found for ${message.to}`);
        return;
      }
      
      // Forward to each recipient
      for (const recipient of recipients) {
        await message.forward(recipient);
      }
      
    } catch (error) {
      // Log error details for monitoring
      console.error(`MMPRO Email Forwarding error for ${message.to}: ${error.message}`);
      
      // Send alert if webhook is configured
      if (env.ALERT_WEBHOOK) {
        try {
          await fetch(env.ALERT_WEBHOOK, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              service: "MMPRO Email Forwarding",
              error: `Email routing error: ${error.message}`,
              email: message.to,
              timestamp: new Date().toISOString()
            })
          });
        } catch (webhookError) {
          console.error("MMPRO Email Forwarding: Failed to send alert:", webhookError);
        }
      }
      
      // Return without processing (email will be held in queue per Cloudflare behavior)
      return;
    }
  },
  
  async getRoutingMap(domain, env) {
    const now = Date.now();
    
    // Return cached map if it exists and hasn't expired
    if (this.routingMapCache && now < this.routingMapExpiry) {
      return this.routingMapCache;
    }
    
    // Configure API endpoints - try multiple options
    const directApiUrl = `https://${domain}/direct-api/aliases.php`;
    const prettyApiUrl = `https://${domain}/api/aliases/`;
    const standardApiUrl = `https://${domain}/wp-json/mmpro/v1/aliases`;
    const customApiUrl = env.API_URL || null;
    
    // Try each endpoint in order of preference
    const endpoints = [
      customApiUrl,
      directApiUrl,
      prettyApiUrl, 
      standardApiUrl
    ].filter(Boolean); // Remove null values
    
    let routingMap = {};
    let successful = false;
    
    for (const apiUrl of endpoints) {
      try {
        console.log(`MMPRO Email Forwarding: Trying ${apiUrl}`);
        
        const response = await fetch(apiUrl, {
          cf: {
            cacheTTL: 300,
            cacheEverything: true
          }
        });
        
        if (!response.ok) {
          console.log(`API ${apiUrl} returned ${response.status}`);
          continue; // Try next endpoint
        }
        
        routingMap = await response.json();
        
        // If we got data, consider it successful
        if (routingMap && Object.keys(routingMap).length > 0) {
          console.log(`Successfully loaded ${Object.keys(routingMap).length} aliases from ${apiUrl}`);
          successful = true;
          break;
        } else {
          console.log(`API ${apiUrl} returned empty data`);
        }
      } catch (error) {
        console.log(`Error fetching from ${apiUrl}: ${error.message}`);
        // Continue to next endpoint
      }
    }
    
    if (!successful) {
      throw new Error(`Failed to fetch aliases from all endpoints`);
    }
    
    // Store in cache with expiry time
    this.routingMapCache = routingMap;
    this.routingMapExpiry = now + this.cacheTTL;
    
    return routingMap;
  }
}</pre>
            </div>
            
            <div class="mmpro-card">
                <h2><?php _e('Data Log', 'mmpro-email-aliases'); ?></h2>
                <?php
                $debug_log = get_option('mmpro_email_aliases_debug_log', array());
                
                if (empty($debug_log)) {
                    echo '<p>' . __('No log entries found.', 'mmpro-email-aliases') . '</p>';
                } else {
                    echo '<table class="mmpro-status-table">';
                    echo '<tr><th>Time</th><th>Action</th><th>Count</th></tr>';
                    
                    foreach (array_reverse($debug_log) as $entry) {
                        echo '<tr>';
                        echo '<td>' . esc_html($entry['time']) . '</td>';
                        echo '<td>' . esc_html($entry['action']) . '</td>';
                        echo '<td>' . esc_html($entry['count']) . '</td>';
                        echo '</tr>';
                    }
                    
                    echo '</table>';
                }
                ?>
            </div>
            
            <div class="mmpro-card">
                <h2><?php _e('Server Information', 'mmpro-email-aliases'); ?></h2>
                <table class="mmpro-status-table">
                    <tr>
                        <td><?php _e('WordPress Version', 'mmpro-email-aliases'); ?></td>
                        <td><?php echo get_bloginfo('version'); ?></td>
                    </tr>
                    <tr>
                        <td><?php _e('PHP Version', 'mmpro-email-aliases'); ?></td>
                        <td><?php echo phpversion(); ?></td>
                    </tr>
                    <tr>
                        <td><?php _e('Plugin Version', 'mmpro-email-aliases'); ?></td>
                        <td>1.0.5</td>
                    </tr>
                    <tr>
                        <td><?php _e('REST API Base', 'mmpro-email-aliases'); ?></td>
                        <td><?php echo rest_get_url_prefix(); ?></td>
                    </tr>
                    <tr>
                        <td><?php _e('Permalink Structure', 'mmpro-email-aliases'); ?></td>
                        <td><?php echo get_option('permalink_structure'); ?></td>
                    </tr>
                    <tr>
                        <td><?php _e('Object Cache', 'mmpro-email-aliases'); ?></td>
                        <td><?php echo wp_using_ext_object_cache() ? __('External Object Cache Enabled', 'mmpro-email-aliases') : __('Using Database Options', 'mmpro-email-aliases'); ?></td>
                    </tr>
                    <tr>
                        <td><?php _e('Active Plugins', 'mmpro-email-aliases'); ?></td>
                        <td>
                            <?php 
                            $active_plugins = get_option('active_plugins');
                            echo count($active_plugins) . ' ' . __('plugins active', 'mmpro-email-aliases');
                            ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render a single alias card
     */
    private function render_alias_card($index, $alias = '', $destinations = array()) {
        ?>
        <div class="mmpro-alias-card" data-index="<?php echo esc_attr($index); ?>">
            <div class="mmpro-alias-header">
                <h3><?php echo empty($alias) ? __('New Alias', 'mmpro-email-aliases') : esc_html($alias); ?></h3>
                <div class="mmpro-alias-actions">
                    <a href="#" class="mmpro-remove-alias"><?php _e('Remove', 'mmpro-email-aliases'); ?></a>
                </div>
            </div>
            
            <div class="mmpro-alias-content">
                <div class="mmpro-alias-field">
                    <label for="alias-address-<?php echo esc_attr($index); ?>"><?php _e('Alias Email Address', 'mmpro-email-aliases'); ?></label>
                    <input type="email" name="aliases[<?php echo esc_attr($index); ?>][address]" id="alias-address-<?php echo esc_attr($index); ?>" value="<?php echo esc_attr($alias); ?>" class="regular-text" required>
                </div>
                
                <div class="mmpro-destinations">
                    <label><?php _e('Forward To', 'mmpro-email-aliases'); ?></label>
                    
                    <div class="mmpro-destinations-container">
                        <?php 
                        if (empty($destinations)) {
                            $destinations = array(''); // Add one empty destination
                        }
                        
                        foreach ($destinations as $dest) {
                            ?>
                            <div class="mmpro-destination-item">
                                <input type="email" name="aliases[<?php echo esc_attr($index); ?>][destinations][]" value="<?php echo esc_attr($dest); ?>" class="regular-text" required>
                                <button type="button" class="button button-small mmpro-remove-destination"><?php _e('Remove', 'mmpro-email-aliases'); ?></button>
                            </div>
                            <?php
                        }
                        ?>
                    </div>
                    
                    <button type="button" class="button mmpro-add-destination"><?php _e('Add Destination', 'mmpro-email-aliases'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Register the API endpoints
     */
    public function register_api_endpoint() {
        // Add this debug code to verify the hook is running
        update_option('mmpro_rest_api_registered', time());
        
        register_rest_route('mmpro/v1', '/aliases', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_aliases_json'),
            'permission_callback' => '__return_true', // Public endpoint
        ));
        
        // Register a test endpoint
        register_rest_route('mmpro/v1', '/test', array(
            'methods' => 'GET',
            'callback' => function() {
                return array(
                    'status' => 'success',
                    'message' => 'MMPRO Email Aliases API is working!',
                    'time' => current_time('mysql'),
                    'registration_time' => get_option('mmpro_rest_api_registered')
                );
            },
            'permission_callback' => '__return_true',
        ));
    }
    
    /**
     * Add rewrite rules for API endpoint
     */
    public function add_rewrite_rules() {
        // Standard rewrite rule
        add_rewrite_rule('^api/aliases/?$', 'index.php?rest_route=/mmpro/v1/aliases', 'top');
        
        // Also support without trailing slash
        add_rewrite_rule('^api/aliases$', 'index.php?rest_route=/mmpro/v1/aliases', 'top');
    }
    
    /**
     * Plugin activation hook
     */
    public function activate_plugin() {
        // Initialize with empty data if it doesn't exist
        if (!get_option('mmpro_email_aliases_data')) {
            update_option('mmpro_email_aliases_data', array());
        }
        
        // Register rewrite rules
        $this->add_rewrite_rules();
        flush_rewrite_rules();
        
        // Set flag to flush rules again on next page load
        update_option('mmpro_flush_on_next_load', 1);
    }
    
    /**
     * Get aliases as JSON
     */
    public function get_aliases_json() {
        // Try to get cached data first
        $cache_key = 'mmpro_email_aliases_cache';
        $formatted_aliases = get_transient($cache_key);
        
        // If no cache exists, get fresh data
        if (false === $formatted_aliases) {
            $formatted_aliases = $this->get_aliases_data_with_backup();
            
            // Apply filter for extensions
            $formatted_aliases = apply_filters('mmpro_email_aliases_api_data', $formatted_aliases);
            
            // Cache the result for 1 hour
            $cache_time = apply_filters('mmpro_email_aliases_cache_expiration', HOUR_IN_SECONDS);
            set_transient($cache_key, $formatted_aliases, $cache_time);
        }
        
        return $formatted_aliases;
    }
}

// Initialize the plugin
new MMPRO_Email_Alias_Manager();

// Flush rewrite rules on next page load after activation
function mmpro_flush_rewrite_rules() {
    if (get_option('mmpro_flush_on_next_load')) {
        delete_option('mmpro_flush_on_next_load');
        flush_rewrite_rules();
    }
}
add_action('init', 'mmpro_flush_rewrite_rules', 20);

// AJAX handler for restoring from alternate storage
function mmpro_ajax_restore_alt() {
    check_ajax_referer('mmpro_restore_alt', '_wpnonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'mmpro-email-aliases')));
    }
    
    $alt_data = get_option('mmpro_email_aliases_data_alt', array());
    
    if (empty($alt_data)) {
        wp_send_json_error(array('message' => __('No alternate data found to restore.', 'mmpro-email-aliases')));
    }
    
    update_option('mmpro_email_aliases_data', $alt_data);
    update_option('mmpro_email_aliases_data_backup', $alt_data); // Also update backup
    delete_transient('mmpro_email_aliases_cache');
    
    // Add to debug log
    $debug_log = get_option('mmpro_email_aliases_debug_log', array());
    $debug_log[] = array(
        'time' => current_time('mysql'),
        'action' => 'restore_from_alt',
        'count' => count($alt_data)
    );
    update_option('mmpro_email_aliases_debug_log', array_slice($debug_log, -10)); // Keep only last 10 entries
    
    wp_send_json_success(array(
        'message' => sprintf(__('Successfully restored %d aliases from alternate storage!', 'mmpro-email-aliases'), count($alt_data))
    ));
}
add_action('wp_ajax_mmpro_restore_alt', 'mmpro_ajax_restore_alt');