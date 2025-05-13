<?php
/**
 * Plugin Name: MMPRO Email Alias Manager
 * Plugin URI: https://memberminderpro.com/
 * Description: Manage email aliases and forward rules with a JSON API endpoint for Cloudflare Workers.
 * Version: 1.0.0
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
        
        // Ajax handlers
        add_action('wp_ajax_mmpro_save_aliases', array($this, 'ajax_save_aliases'));
        
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
     * Sanitize aliases data
     */
    public function sanitize_aliases_data($input) {
        if (!is_array($input)) return array();
        
        $output = array();
        
        foreach ($input as $alias => $destinations) {
            // Sanitize alias (email)
            $alias = sanitize_email($alias);
            
            if (empty($alias)) continue;
            
            // Sanitize destinations (array of emails)
            $clean_destinations = array();
            
            if (is_array($destinations)) {
                foreach ($destinations as $dest) {
                    $clean_dest = sanitize_email($dest);
                    if (!empty($clean_dest)) {
                        $clean_destinations[] = $clean_dest;
                    }
                }
            }
            
            if (!empty($clean_destinations)) {
                $output[$alias] = $clean_destinations;
            }
        }
        
        // Clear cache when data is updated
        delete_transient('mmpro_email_aliases_cache');
        
        return $output;
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if ('toplevel_page_mmpro-email-aliases' !== $hook) {
            return;
        }
        
        wp_enqueue_style(
            'mmpro-email-aliases-admin',
            plugin_dir_url(__FILE__) . 'assets/css/admin.css',
            array(),
            '1.0.0'
        );
        
        wp_enqueue_script(
            'mmpro-email-aliases-admin',
            plugin_dir_url(__FILE__) . 'assets/js/admin.js',
            array('jquery', 'jquery-ui-sortable'),
            '1.0.0',
            true
        );
        
        wp_localize_script('mmpro-email-aliases-admin', 'mmpro_aliases', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mmpro_aliases_nonce'),
            'strings' => array(
                'confirmDelete' => __('Are you sure you want to delete this alias?', 'mmpro-email-aliases'),
                'saved' => __('Aliases saved successfully!', 'mmpro-email-aliases'),
                'error' => __('Error saving aliases.', 'mmpro-email-aliases'),
            )
        ));
    }
    
    /**
     * Render admin page
     */
    public function render_admin_page() {
        // Get saved data
        $aliases_data = get_option('mmpro_email_aliases_data', array());
        
        ?>
        <div class="wrap">
            <h1><?php _e('Email Aliases Manager', 'mmpro-email-aliases'); ?></h1>
            
            <div class="mmpro-aliases-container">
                <div class="mmpro-aliases-notice notice notice-success" style="display: none;"></div>
                
                <form id="mmpro-aliases-form">
                    <?php wp_nonce_field('mmpro_aliases_save', 'mmpro_aliases_nonce'); ?>
                    
                    <div class="mmpro-aliases-list">
                        <?php if (!empty($aliases_data)) : ?>
                            <?php foreach ($aliases_data as $alias => $destinations) : ?>
                                <div class="mmpro-alias-item">
                                    <div class="mmpro-alias-header">
                                        <span class="mmpro-alias-address"><?php echo esc_html($alias); ?></span>
                                        <div class="mmpro-alias-actions">
                                            <button type="button" class="button button-small mmpro-alias-edit"><?php _e('Edit', 'mmpro-email-aliases'); ?></button>
                                            <button type="button" class="button button-small mmpro-alias-delete"><?php _e('Delete', 'mmpro-email-aliases'); ?></button>
                                        </div>
                                    </div>
                                    
                                    <div class="mmpro-alias-content" style="display: none;">
                                        <div class="mmpro-alias-field">
                                            <label><?php _e('Alias Email Address', 'mmpro-email-aliases'); ?></label>
                                            <input type="email" name="aliases[<?php echo esc_attr($alias); ?>][address]" value="<?php echo esc_attr($alias); ?>" required>
                                        </div>
                                        
                                        <div class="mmpro-destinations">
                                            <label><?php _e('Forward To', 'mmpro-email-aliases'); ?></label>
                                            
                                            <?php foreach ($destinations as $dest) : ?>
                                                <div class="mmpro-destination-item">
                                                    <input type="email" name="aliases[<?php echo esc_attr($alias); ?>][destinations][]" value="<?php echo esc_attr($dest); ?>" required>
                                                    <button type="button" class="button button-small mmpro-remove-destination"><?php _e('Remove', 'mmpro-email-aliases'); ?></button>
                                                </div>
                                            <?php endforeach; ?>
                                            
                                            <button type="button" class="button mmpro-add-destination"><?php _e('Add Destination', 'mmpro-email-aliases'); ?></button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <div class="mmpro-no-aliases"><?php _e('No email aliases configured yet.', 'mmpro-email-aliases'); ?></div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="mmpro-aliases-actions">
                        <button type="button" class="button button-primary mmpro-add-alias"><?php _e('Add New Alias', 'mmpro-email-aliases'); ?></button>
                        <button type="button" class="button button-primary mmpro-save-aliases"><?php _e('Save All Aliases', 'mmpro-email-aliases'); ?></button>
                    </div>
                </form>
                
                <!-- Templates -->
                <script type="text/template" id="tmpl-alias-item">
                    <div class="mmpro-alias-item">
                        <div class="mmpro-alias-header">
                            <span class="mmpro-alias-address">{{{ data.address }}}</span>
                            <div class="mmpro-alias-actions">
                                <button type="button" class="button button-small mmpro-alias-edit"><?php _e('Edit', 'mmpro-email-aliases'); ?></button>
                                <button type="button" class="button button-small mmpro-alias-delete"><?php _e('Delete', 'mmpro-email-aliases'); ?></button>
                            </div>
                        </div>
                        
                        <div class="mmpro-alias-content">
                            <div class="mmpro-alias-field">
                                <label><?php _e('Alias Email Address', 'mmpro-email-aliases'); ?></label>
                                <input type="email" name="aliases[{{{ data.id }}}][address]" value="{{{ data.address }}}" required>
                            </div>
                            
                            <div class="mmpro-destinations">
                                <label><?php _e('Forward To', 'mmpro-email-aliases'); ?></label>
                                
                                <# for (var i = 0; i < data.destinations.length; i++) { #>
                                    <div class="mmpro-destination-item">
                                        <input type="email" name="aliases[{{{ data.id }}}][destinations][]" value="{{{ data.destinations[i] }}}" required>
                                        <button type="button" class="button button-small mmpro-remove-destination"><?php _e('Remove', 'mmpro-email-aliases'); ?></button>
                                    </div>
                                <# } #>
                                
                                <button type="button" class="button mmpro-add-destination"><?php _e('Add Destination', 'mmpro-email-aliases'); ?></button>
                            </div>
                        </div>
                    </div>
                </script>
                
                <script type="text/template" id="tmpl-destination-item">
                    <div class="mmpro-destination-item">
                        <input type="email" name="aliases[{{{ data.aliasId }}}][destinations][]" value="" required>
                        <button type="button" class="button button-small mmpro-remove-destination"><?php _e('Remove', 'mmpro-email-aliases'); ?></button>
                    </div>
                </script>
                
                <script type="text/template" id="tmpl-new-alias-item">
                    <div class="mmpro-alias-item">
                        <div class="mmpro-alias-header">
                            <span class="mmpro-alias-address"><?php _e('New Alias', 'mmpro-email-aliases'); ?></span>
                            <div class="mmpro-alias-actions">
                                <button type="button" class="button button-small mmpro-alias-edit"><?php _e('Edit', 'mmpro-email-aliases'); ?></button>
                                <button type="button" class="button button-small mmpro-alias-delete"><?php _e('Delete', 'mmpro-email-aliases'); ?></button>
                            </div>
                        </div>
                        
                        <div class="mmpro-alias-content">
                            <div class="mmpro-alias-field">
                                <label><?php _e('Alias Email Address', 'mmpro-email-aliases'); ?></label>
                                <input type="email" name="aliases[new-{{{ data.id }}}][address]" value="" required>
                            </div>
                            
                            <div class="mmpro-destinations">
                                <label><?php _e('Forward To', 'mmpro-email-aliases'); ?></label>
                                
                                <div class="mmpro-destination-item">
                                    <input type="email" name="aliases[new-{{{ data.id }}}][destinations][]" value="" required>
                                    <button type="button" class="button button-small mmpro-remove-destination"><?php _e('Remove', 'mmpro-email-aliases'); ?></button>
                                </div>
                                
                                <button type="button" class="button mmpro-add-destination"><?php _e('Add Destination', 'mmpro-email-aliases'); ?></button>
                            </div>
                        </div>
                    </div>
                </script>
            </div>
        </div>
        <?php
    }
    
    /**
     * Ajax handler for saving aliases
     */
    public function ajax_save_aliases() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mmpro_aliases_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'mmpro-email-aliases')));
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'mmpro-email-aliases')));
        }
        
        // Get and format the data
        $aliases_data = array();
        
        if (isset($_POST['aliases']) && is_array($_POST['aliases'])) {
            foreach ($_POST['aliases'] as $key => $alias) {
                if (empty($alias['address']) || empty($alias['destinations'])) continue;
                
                $alias_address = sanitize_email($alias['address']);
                
                if (empty($alias_address)) continue;
                
                $destinations = array();
                foreach ($alias['destinations'] as $dest) {
                    $dest = sanitize_email($dest);
                    if (!empty($dest)) {
                        $destinations[] = $dest;
                    }
                }
                
                if (!empty($destinations)) {
                    $aliases_data[$alias_address] = $destinations;
                }
            }
        }
        
        // Save the data
        update_option('mmpro_email_aliases_data', $aliases_data);
        
        // Clear cache
        delete_transient('mmpro_email_aliases_cache');
        
        wp_send_json_success(array(
            'message' => __('Aliases saved successfully!', 'mmpro-email-aliases'),
            'data' => $aliases_data
        ));
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
            
            // Cache the result for 1 hour
            set_transient($cache_key, $formatted_aliases, HOUR_IN_SECONDS);
        }
        
        return $formatted_aliases;
    }
}

// Initialize the plugin
new MMPRO_Email_Alias_Manager();

/**
 * Create necessary files on plugin activation
 */
function mmpro_email_alias_manager_create_files() {
    // Create assets folder
    $assets_dir = plugin_dir_path(__FILE__) . 'assets';
    if (!file_exists($assets_dir)) {
        mkdir($assets_dir);
        mkdir($assets_dir . '/css');
        mkdir($assets_dir . '/js');
    }
    
    // Create CSS file
    $css_file = $assets_dir . '/css/admin.css';
    if (!file_exists($css_file)) {
        $css_content = <<<CSS
.mmpro-aliases-container {
    margin: 20px 0;
}

.mmpro-aliases-notice {
    margin: 10px 0;
}

.mmpro-alias-item {
    background: #fff;
    border: 1px solid #ccd0d4;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
    margin-bottom: 10px;
}

.mmpro-alias-header {
    padding: 12px 15px;
    border-bottom: 1px solid #ccd0d4;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #f9f9f9;
}

.mmpro-alias-address {
    font-weight: 600;
}

.mmpro-alias-content {
    padding: 15px;
}

.mmpro-alias-field, 
.mmpro-destinations {
    margin-bottom: 15px;
}

.mmpro-alias-field label, 
.mmpro-destinations label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
}

.mmpro-destination-item {
    display: flex;
    margin-bottom: 8px;
}

.mmpro-destination-item input {
    flex: 1;
    margin-right: 8px;
}

.mmpro-aliases-actions {
    margin-top: 15px;
}

.mmpro-add-destination {
    margin-top: 10px;
}

.mmpro-no-aliases {
    padding: 20px;
    background: #fff;
    border: 1px solid #ccd0d4;
    text-align: center;
}
CSS;
        file_put_contents($css_file, $css_content);
    }
    
    // Create JS file
    $js_file = $assets_dir . '/js/admin.js';
    if (!file_exists($js_file)) {
        $js_content = <<<JS
jQuery(document).ready(function($) {
    // Toggle alias content
    $(document).on('click', '.mmpro-alias-edit', function(e) {
        e.preventDefault();
        $(this).closest('.mmpro-alias-item').find('.mmpro-alias-content').slideToggle(200);
    });
    
    // Delete alias
    $(document).on('click', '.mmpro-alias-delete', function(e) {
        e.preventDefault();
        
        if (confirm(mmpro_aliases.strings.confirmDelete)) {
            $(this).closest('.mmpro-alias-item').remove();
        }
    });
    
    // Add destination
    $(document).on('click', '.mmpro-add-destination', function(e) {
        e.preventDefault();
        
        var aliasItem = $(this).closest('.mmpro-alias-item');
        var aliasId = aliasItem.find('.mmpro-alias-field input').attr('name').match(/aliases\[(.*?)\]/)[1];
        
        var template = wp.template('destination-item');
        $(this).before(template({ aliasId: aliasId }));
    });
    
    // Remove destination
    $(document).on('click', '.mmpro-remove-destination', function(e) {
        e.preventDefault();
        
        var destinations = $(this).closest('.mmpro-destinations').find('.mmpro-destination-item');
        
        // Don't remove if it's the last one
        if (destinations.length > 1) {
            $(this).closest('.mmpro-destination-item').remove();
        }
    });
    
    // Add new alias
    $('.mmpro-add-alias').on('click', function(e) {
        e.preventDefault();
        
        var template = wp.template('new-alias-item');
        var newId = Date.now();
        
        $('.mmpro-aliases-list').append(template({ id: newId }));
        
        // Show the content of the new alias
        $('.mmpro-aliases-list .mmpro-alias-item:last-child .mmpro-alias-content').show();
        
        // Focus on the new alias input
        $('.mmpro-aliases-list .mmpro-alias-item:last-child .mmpro-alias-field input').focus();
    });
    
    // Save aliases
    $('.mmpro-save-aliases').on('click', function(e) {
        e.preventDefault();
        
        var form = $('#mmpro-aliases-form');
        var formData = form.serializeArray();
        
        // Add nonce
        formData.push({
            name: 'action',
            value: 'mmpro_save_aliases'
        });
        
        formData.push({
            name: 'nonce',
            value: mmpro_aliases.nonce
        });
        
        // Disable the save button
        $(this).prop('disabled', true).text('Saving...');
        
        // Send AJAX request
        $.ajax({
            url: mmpro_aliases.ajaxurl,
            type: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
                    $('.mmpro-aliases-notice')
                        .removeClass('notice-error')
                        .addClass('notice-success')
                        .text(response.data.message)
                        .slideDown();
                    
                    // Hide notice after 3 seconds
                    setTimeout(function() {
                        $('.mmpro-aliases-notice').slideUp();
                    }, 3000);
                    
                    // Refresh the page to update the aliases list
                    location.reload();
                } else {
                    $('.mmpro-aliases-notice')
                        .removeClass('notice-success')
                        .addClass('notice-error')
                        .text(response.data.message)
                        .slideDown();
                }
            },
            error: function() {
                $('.mmpro-aliases-notice')
                    .removeClass('notice-success')
                    .addClass('notice-error')
                    .text(mmpro_aliases.strings.error)
                    .slideDown();
            },
            complete: function() {
                // Enable the save button
                $('.mmpro-save-aliases').prop('disabled', false).text('Save All Aliases');
            }
        });
    });
});
JS;
        file_put_contents($js_file, $js_content);
    }
}
register_activation_hook(__FILE__, 'mmpro_email_alias_manager_create_files');

/**
 * Add Import/Export functionality
 */
function mmpro_add_import_export_buttons() {
    // Determine if we're on the right page
    $screen = get_current_screen();
    
    // For ACF Pro version
    if ($screen->id === 'toplevel_page_mmpro-email-aliases' || 
        $screen->id === 'mmpro-email-aliases') {
        
        // Add the export buttons
        echo '<div class="mmpro-import-export-buttons" style="margin: 15px 0;">';
        echo '<a href="' . admin_url('admin.php?page=mmpro-email-aliases&action=export&format=json&_wpnonce=' . wp_create_nonce('mmpro_export_nonce')) . '" class="button" style="margin-right: 10px;">Export as JSON</a>';
        echo '<a href="' . admin_url('admin.php?page=mmpro-email-aliases&action=export&format=csv&_wpnonce=' . wp_create_nonce('mmpro_export_nonce')) . '" class="button" style="margin-right: 10px;">Export as CSV</a>';
        
        // Add import form
        echo '<form method="post" enctype="multipart/form-data" style="display: inline-block;">';
        echo '<input type="hidden" name="action" value="mmpro_import_aliases">';
        wp_nonce_field('mmpro_import_nonce', 'mmpro_import_nonce');
        echo '<input type="file" name="import_file" style="display: inline-block;">';
        echo '<select name="format" style="margin: 0 10px;">';
        echo '<option value="json">JSON</option>';
        echo '<option value="csv">CSV</option>';
        echo '</select>';
        echo '<input type="submit" class="button" value="Import">';
        echo '</form>';
        
        echo '</div>';
    }
}

// Hook for ACF Pro version
add_action('acf/input/admin_head', 'mmpro_add_import_export_buttons');
// Hook for standalone version
add_action('admin_head-toplevel_page_mmpro-email-aliases', 'mmpro_add_import_export_buttons');

/**
 * Handle export functionality
 */
function mmpro_handle_export() {
    if (!isset($_GET['page']) || $_GET['page'] !== 'mmpro-email-aliases' || 
        !isset($_GET['action']) || $_GET['action'] !== 'export' ||
        !isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'mmpro_export_nonce')) {
        return;
    }
    
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to export data.', 'mmpro-email-aliases'));
    }
    
    $format = isset($_GET['format']) ? sanitize_text_field($_GET['format']) : 'json';
    
    // Get the data - works with both plugin versions
    if (function_exists('get_field')) {
        // ACF Pro version
        $aliases_data = get_field('mmpro_email_aliases', 'option');
        
        if ($aliases_data && is_array($aliases_data)) {
            $formatted_data = array();
            
            foreach ($aliases_data as $alias) {
                if (empty($alias['alias_address']) || empty($alias['destinations'])) {
                    continue;
                }
                
                $destinations = array();
                foreach ($alias['destinations'] as $dest) {
                    if (!empty($dest['destination_email'])) {
                        $destinations[] = $dest['destination_email'];
                    }
                }
                
                if (!empty($destinations)) {
                    $formatted_data[$alias['alias_address']] = $destinations;
                }
            }
        } else {
            $formatted_data = array();
        }
    } else {
        // Standalone version
        $formatted_data = get_option('mmpro_email_aliases_data', array());
    }
    
    // Export based on format
    if ($format === 'json') {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="email-aliases-' . date('Y-m-d') . '.json"');
        echo json_encode($formatted_data, JSON_PRETTY_PRINT);
        exit;
    } elseif ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="email-aliases-' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // CSV Header
        fputcsv($output, array('Alias', 'Destinations'));
        
        // CSV Data
        foreach ($formatted_data as $alias => $destinations) {
            fputcsv($output, array($alias, implode(', ', $destinations)));
        }
        
        fclose($output);
        exit;
    }
}
add_action('admin_init', 'mmpro_handle_export');

/**
 * Handle import functionality
 */
function mmpro_handle_import() {
    if (!isset($_POST['action']) || $_POST['action'] !== 'mmpro_import_aliases') {
        return;
    }
    
    if (!isset($_POST['mmpro_import_nonce']) || !wp_verify_nonce($_POST['mmpro_import_nonce'], 'mmpro_import_nonce')) {
        wp_die(__('Security check failed.', 'mmpro-email-aliases'));
    }
    
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to import data.', 'mmpro-email-aliases'));
    }
    
    // Check file upload
    if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
        wp_die(__('File upload failed.', 'mmpro-email-aliases'));
    }
    
    $format = isset($_POST['format']) ? sanitize_text_field($_POST['format']) : 'json';
    $file = $_FILES['import_file']['tmp_name'];
    
    // Parse the file based on format
    $aliases_data = array();
    
    if ($format === 'json') {
        $content = file_get_contents($file);
        $data = json_decode($content, true);
        
        if ($data && is_array($data)) {
            $aliases_data = $data;
        } else {
            wp_die(__('Invalid JSON format.', 'mmpro-email-aliases'));
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
                        $aliases_data[$alias] = $destinations;
                    }
                }
            }
            
            fclose($handle);
        } else {
            wp_die(__('Could not open CSV file.', 'mmpro-email-aliases'));
        }
    } else {
        wp_die(__('Invalid format.', 'mmpro-email-aliases'));
    }
    
    // Save the data - works with both plugin versions
    if (function_exists('update_field') && function_exists('acf_get_field')) {
        // ACF Pro version
        $acf_data = array();
        
        foreach ($aliases_data as $alias => $destinations) {
            $dest_array = array();
            
            foreach ($destinations as $dest) {
                $dest_array[] = array(
                    'destination_email' => $dest
                );
            }
            
            $acf_data[] = array(
                'alias_address' => $alias,
                'destinations' => $dest_array
            );
        }
        
        update_field('mmpro_email_aliases', $acf_data, 'option');
    } else {
        // Standalone version
        update_option('mmpro_email_aliases_data', $aliases_data);
    }
    
    // Clear cache
    if (function_exists('get_field')) {
        delete_transient('mmpro_email_aliases_data');
    } else {
        delete_transient('mmpro_email_aliases_cache');
    }
    
    // Redirect back with success message
    wp_redirect(add_query_arg('import', 'success', admin_url('admin.php?page=mmpro-email-aliases')));
    exit;
}
add_action('admin_init', 'mmpro_handle_import');

/**
 * Display import success message
 */
function mmpro_display_import_message() {
    if (isset($_GET['import']) && $_GET['import'] === 'success') {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e('Email aliases imported successfully!', 'mmpro-email-aliases'); ?></p>
        </div>
        <?php
    }
}
add_action('admin_notices', 'mmpro_display_import_message');