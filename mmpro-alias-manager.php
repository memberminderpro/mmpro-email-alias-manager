<?php
/**
 * Plugin Name: MMPRO Email Alias Manager
 * Plugin URI: https://memberminderpro.com/
 * Description: Manage email aliases and forward rules with a JSON API endpoint for Cloudflare Workers.
 * Version: 1.0.2
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
        
        // API endpoint
        add_action('rest_api_init', array($this, 'register_api_endpoint'));
        add_action('init', array($this, 'add_rewrite_rules'));
        
        // Plugin activation hook
        register_activation_hook(__FILE__, array($this, 'activate_plugin'));
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
        if ('toplevel_page_mmpro-email-aliases' !== $hook) {
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
        
        // Clear cache when data is updated
        delete_transient('mmpro_email_aliases_cache');
        
        return $output;
    }
    
    /**
     * Render admin page
     */
    public function render_admin_page() {
        // Get saved data
        $aliases_data = get_option('mmpro_email_aliases_data', array());
        
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
            
            update_option('mmpro_email_aliases_data', $new_aliases);
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
                    update_option('mmpro_email_aliases_data', $import_data);
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
        
        ?>
        <div class="wrap">
            <h1><?php _e('Email Aliases Manager', 'mmpro-email-aliases'); ?></h1>
            
            <!-- Export buttons -->
            <div class="mmpro-export-buttons" style="margin: 15px 0;">
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=mmpro-email-aliases&action=export&format=json'), 'mmpro_export'); ?>" class="button" style="margin-right: 10px;">Export as JSON</a>
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=mmpro-email-aliases&action=export&format=csv'), 'mmpro_export'); ?>" class="button">Export as CSV</a>
            </div>
            
            <!-- Import form -->
            <div class="mmpro-import-form" style="margin: 15px 0; padding: 15px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2><?php _e('Import Aliases', 'mmpro-email-aliases'); ?></h2>
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
            
            <!-- Aliases form with repeater-like UI -->
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
                
                <p>
                    <button type="button" id="add-alias" class="button"><?php _e('Add New Alias', 'mmpro-email-aliases'); ?></button>
                    <input type="submit" name="submit_aliases" class="button button-primary" value="<?php _e('Save All Aliases', 'mmpro-email-aliases'); ?>">
                </p>
            </form>
            
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
            
            <script>
                jQuery(document).ready(function($) {
                    var nextIndex = <?php echo $index; ?>;
                    
                    // Add alias button
                    $('#add-alias').on('click', function() {
                        var template = $('#tmpl-alias-card').html();
                        template = template.replace(/\{\{data\.index\}\}/g, nextIndex);
                        
                        $('#mmpro-aliases-container').append(template);
                        nextIndex++;
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
                        $('#add-alias').trigger('click');
                    }
                });
            </script>
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
     * Register the API endpoint
     */
    public function register_api_endpoint() {
        register_rest_route('mmpro/v1', '/aliases', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_aliases_json'),
            'permission_callback' => '__return_true', // Public endpoint
        ));
    }
    
    /**
     * Add rewrite rules for API endpoint
     */
    public function add_rewrite_rules() {
        add_rewrite_rule('^api/aliases/?$', 'index.php?rest_route=/mmpro/v1/aliases', 'top');
    }
    
    /**
     * Plugin activation hook
     */
    public function activate_plugin() {
        // Initialize with empty data if it doesn't exist
        if (!get_option('mmpro_email_aliases_data')) {
            update_option('mmpro_email_aliases_data', array());
        }
        
        $this->add_rewrite_rules();
        flush_rewrite_rules();
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
            $formatted_aliases = get_option('mmpro_email_aliases_data', array());
            
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