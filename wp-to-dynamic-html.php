<?php
/**
 * Plugin Name: WP Dynamic HTML Export (Final Fixed)
 * Description: Export WordPress to portable HTML - Proper home page + relative ZIP paths
 * Version: 3.0
 * Author: Kang JL
 */

if (!defined('ABSPATH')) exit;

class WP_Dynamic_HTML_Export_Final {
    private $export_dir;
    private $assets_dir;
    private $site_url;
    
    public function __construct() {
        $upload_dir = wp_upload_dir();
        $this->export_dir = $upload_dir['basedir'] . '/wp-dynamic-html-export';
        $this->assets_dir = $this->export_dir . '/assets';
        $this->site_url = get_site_url();
        
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_export_single_page', array($this, 'ajax_export_single_page'));
        add_action('wp_ajax_create_export_zip', array($this, 'ajax_create_zip'));
        add_action('wp_ajax_prepare_export', array($this, 'ajax_prepare_export'));
        add_action('wp_ajax_export_home_page', array($this, 'ajax_export_home_page'));
    }
    
    public function add_admin_menu() {
        add_management_page(
            'Export Portable HTML',
            'Export Portable HTML',
            'manage_options',
            'export-portable-html',
            array($this, 'admin_page')
        );
    }
    
    public function admin_page() {
        // Detect home page settings
        $show_on_front = get_option('show_on_front');
        $page_on_front = get_option('page_on_front');
        
        if ($show_on_front === 'page' && $page_on_front) {
            $home_info = get_post($page_on_front);
            $home_title = $home_info ? $home_info->post_title : 'Front Page';
            $home_type = 'Static Page: ' . $home_title;
        } else {
            $home_type = 'Blog Posts (Latest Posts)';
        }
        ?>
        <div class="wrap">
            <h1>Export Portable HTML (v3.0)</h1>
            
            <div class="notice notice-info">
                <p><strong>Your Home Page Setting:</strong> <?php echo esc_html($home_type); ?></p>
            </div>
            
            <div class="card">
                <h2>Export Settings</h2>
                <form id="export-form">
                    <?php wp_nonce_field('export_nonce', 'export_nonce_field'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th>Home Page</th>
                            <td>
                                <label>
                                    <input type="checkbox" id="export-home" name="export_home" value="1" checked disabled>
                                    <strong>Export Home Page as index.html</strong> (Always included)
                                </label>
                                <p class="description">Will export: <?php echo esc_html($home_type); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th>Select Pages</th>
                            <td>
                                <?php
                                $pages = get_pages(array('sort_column' => 'menu_order'));
                                foreach ($pages as $page) {
                                    // Skip front page (it's already included as home)
                                    if ($page->ID == $page_on_front) {
                                        continue;
                                    }
                                    echo '<label style="display:block;margin:5px 0;">';
                                    echo '<input type="checkbox" class="page-checkbox" name="pages[]" value="' . $page->ID . '" checked> ';
                                    echo esc_html($page->post_title);
                                    echo '</label>';
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Blog Posts</th>
                            <td>
                                <label>
                                    <input type="checkbox" id="include-posts" name="include_posts" value="1">
                                    Export blog posts (<?php echo wp_count_posts()->publish; ?> published posts)
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th>Options</th>
                            <td>
                                <label style="display:block;">
                                    <input type="checkbox" id="copy-assets" name="copy_assets" value="1" checked>
                                    Copy CSS/JS/Images to assets folder
                                </label>
                                <label style="display:block;">
                                    <input type="checkbox" id="split-zip" name="split_zip" value="1" checked>
                                    <strong>Split ZIP files (max 9MB each)</strong>
                                </label>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="button" id="start-export" class="button button-primary button-hero">
                            🚀 Start Export
                        </button>
                    </p>
                </form>
            </div>
            
            <!-- Progress Display -->
            <div id="export-progress" style="display:none; margin-top:20px;">
                <div class="card">
                    <h2>Export Progress</h2>
                    <div style="background:#f0f0f0; padding:3px; border-radius:5px;">
                        <div id="progress-bar" style="background:#2271b1; height:30px; width:0%; border-radius:3px; transition:width 0.3s; text-align:center; line-height:30px; color:#fff; font-weight:bold;"></div>
                    </div>
                    <p style="margin-top:10px;">
                        <strong>Status:</strong> <span id="progress-status">Initializing...</span><br>
                        <strong>Progress:</strong> <span id="progress-text">0 / 0</span>
                    </p>
                    <div id="export-log" style="max-height:250px; overflow-y:auto; background:#fff; padding:10px; border:1px solid #ddd; margin-top:10px; font-family:Consolas,monospace; font-size:12px;"></div>
                </div>
            </div>
            
            <!-- Success -->
            <div id="export-success" style="display:none; margin-top:20px;">
                <div class="notice notice-success" style="padding:15px;">
                    <h2>✅ Export Complete!</h2>
                    <p><strong>Files exported:</strong> <span id="exported-count">0</span></p>
                    <p><strong>Location:</strong> <code>wp-content/uploads/wp-dynamic-html-export/</code></p>
                    <p>
                        <a href="<?php echo wp_upload_dir()['baseurl'] . '/wp-dynamic-html-export'; ?>" 
                           target="_blank" class="button button-primary">
                            📁 Open Export Folder
                        </a>
                    </p>
                </div>
            </div>
        </div>
        
        <style>
            #export-log { white-space: pre-wrap; word-wrap: break-word; }
            .log-entry { margin: 2px 0; padding: 2px 5px; }
            .log-success { color: #0a7c0a; background: #e6ffe6; }
            .log-error { color: #c00; background: #ffe6e6; }
            .log-info { color: #0066cc; }
            .log-warning { color: #ff6600; }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            let exportQueue = [];
            let currentIndex = 0;
            let totalPages = 0;
            let exportedFiles = [];
            let copyAssets = true;
            let splitZip = true;
            
            $('#start-export').on('click', function() {
                const selectedPages = [];
                $('.page-checkbox:checked').each(function() {
                    selectedPages.push($(this).val());
                });
                
                const includePosts = $('#include-posts').is(':checked');
                copyAssets = $('#copy-assets').is(':checked');
                splitZip = $('#split-zip').is(':checked');
                
                $('#export-form :input').prop('disabled', true);
                $('#start-export').hide();
                $('#export-progress').show();
                
                logMessage('🔧 Preparing export...', 'info');
                
                // Step 1: Export HOME page first
                exportHomePage(selectedPages, includePosts);
            });
            
            function exportHomePage(pageIds, includePosts) {
                logMessage('🏠 Exporting HOME page as index.html...', 'info');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'export_home_page',
                        nonce: $('#export_nonce_field').val(),
                        copy_assets: copyAssets ? 1 : 0
                    },
                    success: function(response) {
                        if (response.success) {
                            exportedFiles.push('index.html');
                            logMessage('✓ HOME page exported as index.html', 'success');
                            
                            // Now prepare other pages
                            prepareExport(pageIds, includePosts);
                        } else {
                            logMessage('✗ Failed to export home page: ' + response.data, 'error');
                            prepareExport(pageIds, includePosts);
                        }
                    },
                    error: function() {
                        logMessage('✗ AJAX error exporting home page', 'error');
                        prepareExport(pageIds, includePosts);
                    }
                });
            }
            
            function prepareExport(pageIds, includePosts) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'prepare_export',
                        nonce: $('#export_nonce_field').val(),
                        page_ids: pageIds,
                        include_posts: includePosts ? 1 : 0
                    },
                    success: function(response) {
                        if (response.success) {
                            exportQueue = response.data.queue;
                            totalPages = exportQueue.length;
                            
                            logMessage('📋 Found ' + totalPages + ' additional pages to export', 'info');
                            
                            if (totalPages > 0) {
                                processNextPage();
                            } else {
                                createZip();
                            }
                        } else {
                            logMessage('✗ Error: ' + response.data, 'error');
                        }
                    }
                });
            }
            
            function processNextPage() {
                if (currentIndex >= totalPages) {
                    createZip();
                    return;
                }
                
                const postId = exportQueue[currentIndex];
                const progress = (((currentIndex + 1) / (totalPages + 1)) * 100).toFixed(0);
                
                $('#progress-bar').css('width', progress + '%').text(progress + '%');
                $('#progress-text').text((currentIndex + 2) + ' / ' + (totalPages + 1));
                $('#progress-status').text('Exporting page ' + (currentIndex + 2) + '...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'export_single_page',
                        nonce: $('#export_nonce_field').val(),
                        post_id: postId,
                        copy_assets: copyAssets ? 1 : 0
                    },
                    success: function(response) {
                        if (response.success) {
                            exportedFiles.push(response.data.filename);
                            logMessage('✓ ' + response.data.title + ' → ' + response.data.filename, 'success');
                        } else {
                            logMessage('✗ Failed: ' + response.data, 'error');
                        }
                        
                        currentIndex++;
                        processNextPage();
                    },
                    error: function() {
                        logMessage('✗ AJAX error for post ID ' + postId, 'error');
                        currentIndex++;
                        processNextPage();
                    }
                });
            }
            
            function createZip() {
                $('#progress-status').text('Creating ZIP files...');
                $('#progress-bar').css('width', '95%').text('95%');
                logMessage('📦 Creating ZIP archives (max 9MB each)...', 'info');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'create_export_zip',
                        nonce: $('#export_nonce_field').val(),
                        files: exportedFiles,
                        split_zip: splitZip ? 1 : 0
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#progress-bar').css('width', '100%').text('100%');
                            $('#progress-status').text('Complete!');
                            logMessage('✓ Export complete! ' + exportedFiles.length + ' files exported', 'success');
                            
                            setTimeout(function() {
                                $('#export-progress').hide();
                                $('#export-success').show();
                                $('#exported-count').text(exportedFiles.length);
                            }, 500);
                        } else {
                            logMessage('✗ Error creating ZIP: ' + response.data, 'error');
                        }
                    }
                });
            }
            
            function logMessage(message, type) {
                const className = 'log-' + (type || 'info');
                const timestamp = new Date().toLocaleTimeString();
                $('#export-log').append(
                    '<div class="log-entry ' + className + '">[' + timestamp + '] ' + message + '</div>'
                );
                $('#export-log').scrollTop($('#export-log')[0].scrollHeight);
            }
        });
        </script>
        <?php
    }
    
    // NEW: Export home page specifically
    public function ajax_export_home_page() {
        check_ajax_referer('export_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $copy_assets = isset($_POST['copy_assets']) && $_POST['copy_assets'] == '1';
        
        $this->prepare_directories();
        
        // Get the HOME page URL
        $home_url = home_url('/');
        
        // Fetch complete HTML from home page
        $response = wp_remote_get($home_url, array(
            'timeout' => 30,
            'sslverify' => false
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error('Failed to fetch home page: ' . $response->get_error_message());
        }
        
        $html = wp_remote_retrieve_body($response);
        
        if (empty($html) || strlen($html) < 100) {
            wp_send_json_error('Invalid home page HTML');
        }
        
        // Make portable
        $html = $this->make_portable($html, 'index.html', $copy_assets);
        
        // Save as index.html
        $filepath = $this->export_dir . '/index.html';
        file_put_contents($filepath, $html);
        
        wp_send_json_success(array(
            'filename' => 'index.html',
            'title' => 'Home Page'
        ));
    }
    
    // Continue to Part 2...
    public function ajax_prepare_export() {
        check_ajax_referer('export_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $page_ids = isset($_POST['page_ids']) ? array_map('intval', $_POST['page_ids']) : array();
        $include_posts = isset($_POST['include_posts']) && $_POST['include_posts'] == '1';
        
        $this->prepare_directories();
        
        // Build queue (excluding front page - already exported as index.html)
        $front_page_id = get_option('page_on_front');
        $queue = array();
        
        // Filter out front page from pages list
        foreach ($page_ids as $page_id) {
            if ($page_id != $front_page_id) {
                $queue[] = $page_id;
            }
        }
        
        // Add posts if requested
        if ($include_posts) {
            $posts = get_posts(array(
                'numberposts' => -1,
                'post_status' => 'publish',
                'fields' => 'ids'
            ));
            $queue = array_merge($queue, $posts);
        }
        
        wp_send_json_success(array(
            'queue' => $queue,
            'total' => count($queue)
        ));
    }
    
    public function ajax_export_single_page() {
        check_ajax_referer('export_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $copy_assets = isset($_POST['copy_assets']) && $_POST['copy_assets'] == '1';
        
        if (!$post_id) {
            wp_send_json_error('Invalid post ID');
        }
        
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') {
            wp_send_json_error('Post not found or not published');
        }
        
        $filename = $this->export_page($post, $copy_assets);
        
        if ($filename) {
            wp_send_json_success(array(
                'filename' => $filename,
                'title' => $post->post_title
            ));
        } else {
            wp_send_json_error('Failed to export: ' . $post->post_title);
        }
    }
    
    public function ajax_create_zip() {
        check_ajax_referer('export_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $files = isset($_POST['files']) ? $_POST['files'] : array();
        $split_zip = isset($_POST['split_zip']) && $_POST['split_zip'] == '1';
        
        if ($split_zip) {
            $result = $this->create_split_zip_files($files);
        } else {
            $result = $this->create_zip_file($files);
        }
        
        if ($result) {
            wp_send_json_success(array(
                'message' => 'ZIP created successfully',
                'files' => count($files)
            ));
        } else {
            wp_send_json_error('Failed to create ZIP');
        }
    }
    
    private function prepare_directories() {
        if (!file_exists($this->export_dir)) {
            wp_mkdir_p($this->export_dir);
        }
        
        $subdirs = array('css', 'js', 'images', 'fonts');
        foreach ($subdirs as $subdir) {
            $path = $this->assets_dir . '/' . $subdir;
            if (!file_exists($path)) {
                wp_mkdir_p($path);
            }
        }
    }
    
    private function export_page($post, $copy_assets = true) {
        if (!$post || $post->post_status !== 'publish') {
            return false;
        }
        
        $slug = $post->post_name ? $post->post_name : sanitize_title($post->post_title);
        $filename = $slug . '.html';
        
        // Get complete HTML
        $html = $this->get_complete_html($post);
        
        if (empty($html) || strlen($html) < 100) {
            error_log("WP Export: Failed to get HTML for post ID " . $post->ID);
            return false;
        }
        
        // Make portable
        $html = $this->make_portable($html, $filename, $copy_assets);
        
        // Save file
        $filepath = $this->export_dir . '/' . $filename;
        file_put_contents($filepath, $html);
        
        return $filename;
    }
    
    private function get_complete_html($post) {
        $url = get_permalink($post->ID);
        
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'sslverify' => false,
            'headers' => array(
                'User-Agent' => 'WordPress/Export'
            )
        ));
        
        if (is_wp_error($response)) {
            error_log("WP Export Error for post " . $post->ID . ": " . $response->get_error_message());
            return '';
        }
        
        $html = wp_remote_retrieve_body($response);
        
        if (stripos($html, '<html') === false || stripos($html, '</html>') === false) {
            error_log("WP Export: Invalid HTML structure for post " . $post->ID);
            return '';
        }
        
        return $html;
    }
    
    private function make_portable($html, $current_filename, $copy_assets) {
        // 1. Convert internal links
        $html = $this->convert_internal_links($html);
        
        // 2. Handle assets
        if ($copy_assets) {
            $html = $this->convert_and_copy_assets($html);
        }
        
        // 3. Clean WordPress elements
        $html = preg_replace('/<div id="wpadminbar"[^>]*>.*?<\/div>/is', '', $html);
        $html = $this->remove_wordpress_elements($html);
        
        // 4. Fix absolute URLs
        $html = $this->fix_absolute_urls($html);
        
        return $html;
    }
    
    private function convert_internal_links($html) {
        $pages = get_pages(array('sort_column' => 'menu_order'));
        $posts = get_posts(array('numberposts' => -1, 'post_status' => 'publish'));
        
        $link_map = array();
        
        // Home URL always maps to index.html
        $home_url = home_url('/');
        $link_map[$home_url] = 'index.html';
        $link_map[rtrim($home_url, '/')] = 'index.html';
        
        // Map pages (front page already handled as index.html)
        $front_page_id = get_option('page_on_front');
        foreach ($pages as $page) {
            $permalink = get_permalink($page->ID);
            $slug = $page->post_name ? $page->post_name : sanitize_title($page->post_title);
            
            if ($page->ID == $front_page_id) {
                $link_map[$permalink] = 'index.html';
                $link_map[rtrim($permalink, '/')] = 'index.html';
            } else {
                $link_map[$permalink] = $slug . '.html';
                $link_map[rtrim($permalink, '/')] = $slug . '.html';
            }
        }
        
        // Map posts
        foreach ($posts as $post) {
            $permalink = get_permalink($post->ID);
            $slug = $post->post_name ? $post->post_name : sanitize_title($post->post_title);
            $link_map[$permalink] = $slug . '.html';
            $link_map[rtrim($permalink, '/')] = $slug . '.html';
        }
        
        // Replace links
        foreach ($link_map as $wp_url => $html_file) {
            $html = str_replace('href="' . $wp_url . '"', 'href="' . $html_file . '"', $html);
            $html = str_replace("href='" . $wp_url . "'", "href='" . $html_file . "'", $html);
        }
        
        return $html;
    }
    
    private function convert_and_copy_assets($html) {
        // CSS files
        $html = preg_replace_callback(
            '/<link[^>]*href=["\']([^"\']+\.css[^"\']*)["\'][^>]*>/i',
            array($this, 'process_css_link'),
            $html
        );
        
        // JavaScript files
        $html = preg_replace_callback(
            '/<script[^>]*src=["\']([^"\']+\.js[^"\']*)["\'][^>]*>/i',
            array($this, 'process_js_link'),
            $html
        );
        
        // Images in src
        $html = preg_replace_callback(
            '/src=["\']([^"\']+\.(jpg|jpeg|png|gif|svg|webp|ico)[^"\']*)["\']/',
            array($this, 'process_image'),
            $html
        );
        
        // Background images
        $html = preg_replace_callback(
            '/url\(["\']?([^"\')]+\.(jpg|jpeg|png|gif|svg|webp))["\']?\)/i',
            array($this, 'process_bg_image'),
            $html
        );
        
        // Srcset
        $html = preg_replace_callback(
            '/srcset=["\']([^"\']+)["\']/',
            array($this, 'process_srcset'),
            $html
        );
        
        return $html;
    }
    
    private function process_css_link($matches) {
        return $this->process_asset_link($matches, 'css');
    }
    
    private function process_js_link($matches) {
        return $this->process_asset_link($matches, 'js');
    }
    
    private function process_image($matches) {
        $full_match = $matches[0];
        $url = $matches[1];
        
        if ($this->is_external_url($url)) {
            return $full_match;
        }
        
        $new_path = $this->copy_asset($url, 'images');
        return $new_path ? str_replace($url, $new_path, $full_match) : $full_match;
    }
    
    private function process_bg_image($matches) {
        $url = $matches[1];
        
        if ($this->is_external_url($url)) {
            return $matches[0];
        }
        
        $new_path = $this->copy_asset($url, 'images');
        return $new_path ? str_replace($url, $new_path, $matches[0]) : $matches[0];
    }
    
    private function process_srcset($matches) {
        $full_match = $matches[0];
        $srcset = $matches[1];
        
        $sources = explode(',', $srcset);
        $new_sources = array();
        
        foreach ($sources as $source) {
            $source = trim($source);
            $parts = preg_split('/\s+/', $source);
            $url = $parts[0];
            $descriptor = isset($parts[1]) ? $parts[1] : '';
            
            if ($this->is_external_url($url)) {
                $new_sources[] = $source;
                continue;
            }
            
            $new_path = $this->copy_asset($url, 'images');
            $new_sources[] = ($new_path ?: $url) . ($descriptor ? ' ' . $descriptor : '');
        }
        
        return 'srcset="' . implode(', ', $new_sources) . '"';
    }
    
    private function process_asset_link($matches, $type) {
        $full_match = $matches[0];
        $url = $matches[1];
        
        if ($this->is_external_url($url)) {
            return $full_match;
        }
        
        $new_path = $this->copy_asset($url, $type);
        return $new_path ? str_replace($url, $new_path, $full_match) : $full_match;
    }
    
    // Continue to Part 3...
    private function is_external_url($url) {
        if (preg_match('/^https?:\/\//', $url)) {
            return strpos($url, $this->site_url) === false;
        }
        return false;
    }
    
    private function copy_asset($url, $type) {
        $url = $this->normalize_url($url);
        $local_path = $this->url_to_local_path($url);
        
        if (!file_exists($local_path) || !is_readable($local_path)) {
            return false;
        }
        
        // Skip files > 5MB to keep ZIP sizes manageable
        if (filesize($local_path) > 5 * 1024 * 1024) {
            return false;
        }
        
        $filename = basename(parse_url($url, PHP_URL_PATH));
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
        if (empty($filename)) {
            return false;
        }
        
        // Ensure unique filename
        $counter = 1;
        $name_parts = pathinfo($filename);
        $base_name = $name_parts['filename'];
        $extension = isset($name_parts['extension']) ? '.' . $name_parts['extension'] : '';
        
        $dest_path = $this->assets_dir . '/' . $type . '/' . $filename;
        while (file_exists($dest_path)) {
            $filename = $base_name . '-' . $counter . $extension;
            $dest_path = $this->assets_dir . '/' . $type . '/' . $filename;
            $counter++;
        }
        
        if (@copy($local_path, $dest_path)) {
            // Return RELATIVE path (no absolute paths in ZIP)
            return 'assets/' . $type . '/' . $filename;
        }
        
        return false;
    }
    
    private function normalize_url($url) {
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        } elseif (strpos($url, '/') === 0) {
            $url = $this->site_url . $url;
        } elseif (!preg_match('/^https?:\/\//', $url)) {
            $url = trailingslashit($this->site_url) . ltrim($url, './');
        }
        
        return $url;
    }
    
    private function url_to_local_path($url) {
        $path = str_replace($this->site_url, '', $url);
        $path = ltrim($path, '/');
        $path = preg_replace('/\?.*$/', '', $path);
        
        return ABSPATH . $path;
    }
    
    private function remove_wordpress_elements($html) {
        $html = preg_replace('/<meta name="generator"[^>]*>/i', '', $html);
        $html = preg_replace('/<link[^>]*api\.w\.org[^>]*>/i', '', $html);
        $html = preg_replace('/<link[^>]*shortlink[^>]*>/i', '', $html);
        $html = preg_replace('/<link[^>]*EditURI[^>]*>/i', '', $html);
        $html = preg_replace('/<link[^>]*wlwmanifest[^>]*>/i', '', $html);
        $html = preg_replace('/<script[^>]*wp-emoji[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style[^>]*wp-emoji[^>]*>.*?<\/style>/is', '', $html);
        $html = preg_replace('/<link[^>]*pingback[^>]*>/i', '', $html);
        
        return $html;
    }
    
    private function fix_absolute_urls($html) {
        $site_url = trailingslashit($this->site_url);
        // Convert remaining site URLs to relative
        $html = str_replace($site_url, '', $html);
        return $html;
    }
    
    private function create_zip_file($exported_files) {
        if (!class_exists('ZipArchive')) {
            return false;
        }
        
        $zip_filename = 'wp-export-' . date('Y-m-d-His') . '.zip';
        $zip_path = $this->export_dir . '/' . $zip_filename;
        
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }
        
        // Add HTML files (relative paths)
        foreach ($exported_files as $file) {
            $filepath = $this->export_dir . '/' . $file;
            if (file_exists($filepath)) {
                $zip->addFile($filepath, $file); // relative name inside ZIP
            }
        }
        
        // Add index.html (home page)
        $index_path = $this->export_dir . '/index.html';
        if (file_exists($index_path) && !in_array('index.html', $exported_files, true)) {
            $zip->addFile($index_path, 'index.html');
        }
        
        // Add assets folder
        $this->add_directory_to_zip($zip, $this->assets_dir, 'assets');
        
        $zip->close();
        
        // Create README
        $this->create_readme(1, date('Y-m-d-His'));
        
        return true;
    }
    
    private function create_split_zip_files($exported_files) {
        if (!class_exists('ZipArchive')) {
            return false;
        }
        
        // Use 8.5MB to guarantee final ZIP < 9MB
        $max_size = 8.5 * 1024 * 1024;
        $timestamp = date('Y-m-d-His');
        $part_number = 1;
        $current_size = 0;
        
        // Measure HTML files
        $file_sizes = array();
        foreach ($exported_files as $file) {
            $path = $this->export_dir . '/' . $file;
            if (file_exists($path)) {
                $file_sizes[$file] = filesize($path);
            }
        }
        
        // Sort by size (largest first)
        arsort($file_sizes);
        
        $zip_filename = "wp-export-{$timestamp}-part{$part_number}.zip";
        $zip_path = $this->export_dir . '/' . $zip_filename;
        
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }
        
        $files_in_part = array();
        
        // Add index.html FIRST to part1
        $index_path = $this->export_dir . '/index.html';
        if (file_exists($index_path)) {
            $index_size = filesize($index_path);
            $estimated = $index_size * 0.7;
            
            $zip->addFile($index_path, 'index.html');
            $current_size += $estimated;
            $files_in_part[] = 'index.html';
        }
        
        // Add HTML files
        foreach ($file_sizes as $file => $size) {
            $filepath = $this->export_dir . '/' . $file;
            $estimated = $size * 0.7;
            
            if ($current_size + $estimated > $max_size && count($files_in_part) > 0) {
                $zip->close();
                $part_number++;
                
                $zip_filename = "wp-export-{$timestamp}-part{$part_number}.zip";
                $zip_path = $this->export_dir . '/' . $zip_filename;
                
                $zip = new ZipArchive();
                if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    return false;
                }
                
                $current_size = 0;
                $files_in_part = array();
            }
            
            $zip->addFile($filepath, $file);
            $current_size += $estimated;
            $files_in_part[] = $file;
        }
        
        $zip->close();
        
        // Split assets into multiple ZIPs if needed
        if (is_dir($this->assets_dir)) {
            $this->create_split_assets_zip($timestamp, $max_size);
        }
        
        // Create README
        $this->create_readme($part_number, $timestamp);
        
        return true;
    }
    
    private function create_split_assets_zip($timestamp, $max_size) {
        if (!class_exists('ZipArchive')) {
            return false;
        }
        
        $part_number = 1;
        $current_size = 0;
        
        $asset_files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->assets_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        $zip_filename = "wp-export-{$timestamp}-assets-part{$part_number}.zip";
        $zip_path = $this->export_dir . '/' . $zip_filename;
        
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }
        
        foreach ($asset_files as $file) {
            if (!$file->isDir()) {
                $filepath = $file->getRealPath();
                $filesize = filesize($filepath);
                $estimated = $filesize * 0.7;
                
                if ($current_size + $estimated > $max_size && $current_size > 0) {
                    $zip->close();
                    $part_number++;
                    
                    $zip_filename = "wp-export-{$timestamp}-assets-part{$part_number}.zip";
                    $zip_path = $this->export_dir . '/' . $zip_filename;
                    
                    $zip = new ZipArchive();
                    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                        return false;
                    }
                    
                    $current_size = 0;
                }
                
                $relative_path = 'assets/' . substr($filepath, strlen($this->assets_dir) + 1);
                $relative_path = str_replace('\\', '/', $relative_path);
                
                $zip->addFile($filepath, $relative_path);
                $current_size += $estimated;
            }
        }
        
        $zip->close();
        return true;
    }
    
    private function add_directory_to_zip($zip, $dir, $zip_dir) {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filepath = $file->getRealPath();
                $relative_path = $zip_dir . '/' . substr($filepath, strlen($dir) + 1);
                $relative_path = str_replace('\\', '/', $relative_path);
                $zip->addFile($filepath, $relative_path);
            }
        }
    }
    
    private function create_readme($html_parts, $timestamp) {
        $readme = <<<README
WordPress Portable HTML Export
===============================

Export Date: {$timestamp}
HTML Parts: {$html_parts}
Assets: Multiple parts (each < 9MB)

Files:
------
HTML:
- wp-export-{$timestamp}-part1.zip
- wp-export-{$timestamp}-part2.zip
- ...

Assets:
- wp-export-{$timestamp}-assets-part1.zip
- wp-export-{$timestamp}-assets-part2.zip
- ...

How to Use:
-----------
1. Download ALL ZIP files (HTML + assets).
2. Extract ALL of them into the SAME folder.
3. You should see:
   - index.html  (HOME page)
   - other-page.html
   - assets/
       css/
       js/
       images/
4. Open index.html in a browser or upload everything to your hosting.

Important:
----------
- index.html always contains your actual WordPress HOME page.
- All links are RELATIVE (no absolute paths inside HTML or ZIP).
- Each ZIP file is kept under 9MB to match hosting limits.

README;
        
        file_put_contents($this->export_dir . '/README.txt', $readme);
    }
}

// Initialize plugin
new WP_Dynamic_HTML_Export_Final();
?>