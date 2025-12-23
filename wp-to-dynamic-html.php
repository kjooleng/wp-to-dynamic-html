<?php
/**
 * Plugin Name: WP To Dynamic HTML (Portable Export)
 * Description: Export WordPress pages/posts to portable static HTML with split ZIPs.
 * Version: 1.0.0
 * Author: Kang JL
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_To_Dynamic_HTML_Portable {
    private $export_dir;
    private $assets_dir;
    private $site_url;

    public function __construct() {
        $upload_dir       = wp_upload_dir();
        $this->export_dir = $upload_dir['basedir'] . '/wp-dynamic-html-export';
        $this->assets_dir = $this->export_dir . '/assets';
        $this->site_url   = get_site_url();

        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_export_home_page', array($this, 'ajax_export_home_page'));
        add_action('wp_ajax_prepare_export', array($this, 'ajax_prepare_export'));
        add_action('wp_ajax_export_single_page', array($this, 'ajax_export_single_page'));
        add_action('wp_ajax_create_export_zip', array($this, 'ajax_create_zip'));
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
        $show_on_front = get_option('show_on_front');
        $page_on_front = get_option('page_on_front');

        if ($show_on_front === 'page' && $page_on_front) {
            $home_info = get_post($page_on_front);
            $home_title = $home_info ? $home_info->post_title : 'Front Page';
            $home_type  = 'Static Page: ' . $home_title;
        } else {
            $home_type = 'Blog Posts (Latest Posts)';
        }
        ?>
        <div class="wrap">
            <h1>Export Portable HTML</h1>

            <div class="notice notice-info">
                <p><strong>Home page setting:</strong> <?php echo esc_html($home_type); ?></p>
            </div>

            <div class="card">
                <h2>Export Settings</h2>
                <form id="export-form">
                    <?php wp_nonce_field('export_nonce', 'export_nonce_field'); ?>

                    <table class="form-table">
                        <tr>
                            <th>Home page</th>
                            <td>
                                <label>
                                    <input type="checkbox" checked disabled>
                                    Home page will always be exported as <code>index.html</code>.
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th>Pages</th>
                            <td>
                                <?php
                                $pages = get_pages(array('sort_column' => 'menu_order'));
                                foreach ($pages as $page) {
                                    if ($page->ID == $page_on_front) {
                                        // Home is handled separately
                                        continue;
                                    }
                                    echo '<label style="display:block;margin:4px 0;">';
                                    echo '<input type="checkbox" class="page-checkbox" value="' . intval($page->ID) . '" checked> ';
                                    echo esc_html($page->post_title);
                                    echo '</label>';
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Posts</th>
                            <td>
                                <label>
                                    <input type="checkbox" id="include-posts" value="1">
                                    Include all blog posts (<?php echo intval(wp_count_posts()->publish); ?> published).
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th>Options</th>
                            <td>
                                <label style="display:block;">
                                    <input type="checkbox" id="copy-assets" value="1" checked>
                                    Copy CSS/JS/images into <code>assets/</code>.
                                </label>
                                <label style="display:block;">
                                    <input type="checkbox" id="split-zip" value="1" checked>
                                    Split ZIP files (each part &lt; 9MB).
                                </label>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="button" id="start-export" class="button button-primary button-hero">
                            Start Export
                        </button>
                    </p>
                </form>
            </div>

            <div id="export-progress" style="display:none;margin-top:20px;">
                <div class="card">
                    <h2>Progress</h2>
                    <div style="background:#f0f0f0;padding:3px;border-radius:5px;">
                        <div id="progress-bar" style="background:#2271b1;height:30px;width:0%;border-radius:3px;text-align:center;line-height:30px;color:#fff;font-weight:bold;"></div>
                    </div>
                    <p style="margin-top:10px;">
                        <strong>Status:</strong> <span id="progress-status">Waiting…</span><br>
                        <strong>Progress:</strong> <span id="progress-text">0 / 0</span>
                    </p>
                    <div id="export-log" style="max-height:260px;overflow-y:auto;background:#fff;border:1px solid #ddd;padding:8px;font-family:Consolas,monospace;font-size:12px;"></div>
                </div>
            </div>

            <div id="export-success" style="display:none;margin-top:20px;">
                <div class="notice notice-success">
                    <p><strong>Export complete.</strong> Exported <span id="exported-count">0</span> files.</p>
                    <p>
                        Output folder:
                        <code>wp-content/uploads/wp-dynamic-html-export/</code>
                    </p>
                </div>
            </div>
        </div>

        <style>
            #export-log { white-space: pre-wrap; word-wrap: break-word; }
            .log-entry { margin:1px 0; }
            .log-success { color:#0a7c0a; }
            .log-error { color:#c00; }
            .log-info { color:#0066cc; }
        </style>

        <script>
        jQuery(function($) {
            let queue = [];
            let idx = 0;
            let total = 0;
            let exportedFiles = [];
            let copyAssets = true;
            let splitZip = true;

            function log(msg, type) {
                type = type || 'info';
                const ts = new Date().toLocaleTimeString();
                $('#export-log').append(
                    '<div class="log-entry log-' + type + '">[' + ts + '] ' + msg + '</div>'
                );
                const el = $('#export-log')[0];
                el.scrollTop = el.scrollHeight;
            }

            $('#start-export').on('click', function() {
                const pageIds = [];
                $('.page-checkbox:checked').each(function() {
                    pageIds.push($(this).val());
                });

                const includePosts = $('#include-posts').is(':checked');
                copyAssets = $('#copy-assets').is(':checked');
                splitZip = $('#split-zip').is(':checked');

                $('#start-export').prop('disabled', true);
                $('#export-form :input').prop('disabled', true);
                $('#export-progress').show();
                log('Preparing export…', 'info');

                exportHome(pageIds, includePosts);
            });

            function exportHome(pageIds, includePosts) {
                $.post(ajaxurl, {
                    action: 'export_home_page',
                    nonce: $('#export_nonce_field').val(),
                    copy_assets: copyAssets ? 1 : 0
                }).done(function(resp) {
                    if (resp.success) {
                        exportedFiles.push('index.html');
                        log('Home page exported as index.html', 'success');
                    } else {
                        log('Home page export failed: ' + resp.data, 'error');
                    }
                    prepareQueue(pageIds, includePosts);
                }).fail(function() {
                    log('AJAX error while exporting home page', 'error');
                    prepareQueue(pageIds, includePosts);
                });
            }

            function prepareQueue(pageIds, includePosts) {
                $.post(ajaxurl, {
                    action: 'prepare_export',
                    nonce: $('#export_nonce_field').val(),
                    page_ids: pageIds,
                    include_posts: includePosts ? 1 : 0
                }).done(function(resp) {
                    if (!resp.success) {
                        log('Prepare export failed: ' + resp.data, 'error');
                        return;
                    }
                    queue = resp.data.queue || [];
                    idx = 0;
                    total = queue.length;
                    $('#progress-text').text('1 / ' + (total + 1));
                    $('#progress-status').text('Exporting additional pages/posts…');
                    if (total === 0) {
                        createZip();
                    } else {
                        nextItem();
                    }
                }).fail(function() {
                    log('AJAX error while preparing export', 'error');
                });
            }

            function nextItem() {
                if (idx >= total) {
                    createZip();
                    return;
                }

                const postId = queue[idx];
                const progress = ((idx + 1) / (total + 1) * 100).toFixed(0);
                $('#progress-bar').css('width', progress + '%').text(progress + '%');
                $('#progress-text').text((idx + 2) + ' / ' + (total + 1));

                $.post(ajaxurl, {
                    action: 'export_single_page',
                    nonce: $('#export_nonce_field').val(),
                    post_id: postId,
                    copy_assets: copyAssets ? 1 : 0
                }).done(function(resp) {
                    if (resp.success) {
                        exportedFiles.push(resp.data.filename);
                        log('Exported: ' + resp.data.title + ' → ' + resp.data.filename, 'success');
                    } else {
                        log('Export failed: ' + resp.data, 'error');
                    }
                    idx++;
                    nextItem();
                }).fail(function() {
                    log('AJAX error for post ID ' + postId, 'error');
                    idx++;
                    nextItem();
                });
            }

            function createZip() {
                $('#progress-status').text('Creating ZIP archives…');
                $.post(ajaxurl, {
                    action: 'create_export_zip',
                    nonce: $('#export_nonce_field').val(),
                    files: exportedFiles,
                    split_zip: splitZip ? 1 : 0
                }).done(function(resp) {
                    if (resp.success) {
                        $('#progress-bar').css('width', '100%').text('100%');
                        log('ZIP creation complete', 'success');
                        $('#export-progress').hide();
                        $('#export-success').show();
                        $('#exported-count').text(exportedFiles.length);
                    } else {
                        log('ZIP creation failed: ' + resp.data, 'error');
                    }
                }).fail(function() {
                    log('AJAX error during ZIP creation', 'error');
                });
            }
        });
        </script>
        <?php
    }

    public function ajax_export_home_page() {
        check_ajax_referer('export_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $copy_assets = !empty($_POST['copy_assets']);
        $this->prepare_directories();

        $show_on_front = get_option('show_on_front');

        if ($show_on_front === 'page') {
            $front_id   = get_option('page_on_front');
            $front_post = $front_id ? get_post($front_id) : null;
            if (!$front_post) {
                wp_send_json_error('Front page not found');
            }
            $html = $this->get_complete_html($front_post);
        } else {
            // Blog posts as home
            global $wp_query, $post;

            $original_query = $wp_query;
            $original_post  = $post;

            $wp_query = new WP_Query(array(
                'post_type'      => 'post',
                'posts_per_page' => get_option('posts_per_page'),
                'paged'          => 1,
            ));

            ob_start();
            $template = get_home_template();
            if (!$template) {
                $template = get_index_template();
            }
            include $template;
            $html = ob_get_clean();

            $wp_query = $original_query;
            $post     = $original_post;
            wp_reset_postdata();
        }

        if (empty($html) || strlen($html) < 100) {
            wp_send_json_error('Invalid home page HTML');
        }

        $html = $this->make_portable($html, 'index.html', $copy_assets);

        $path = $this->export_dir . '/index.html';
        file_put_contents($path, $html);

        wp_send_json_success(array(
            'filename' => 'index.html',
            'title'    => 'Home Page',
        ));
    }

    public function ajax_prepare_export() {
        check_ajax_referer('export_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $page_ids      = isset($_POST['page_ids']) ? array_map('intval', (array) $_POST['page_ids']) : array();
        $include_posts = !empty($_POST['include_posts']);

        $this->prepare_directories();

        $queue = array();

        // pages (excluding front page)
        $front_page_id = (int) get_option('page_on_front');
        foreach ($page_ids as $pid) {
            if ($pid && $pid !== $front_page_id) {
                $queue[] = $pid;
            }
        }

        if ($include_posts) {
            $posts = get_posts(array(
                'numberposts' => -1,
                'post_status' => 'publish',
                'fields'      => 'ids',
            ));
            $queue = array_merge($queue, $posts);
        }

        wp_send_json_success(array(
            'queue' => $queue,
            'total' => count($queue),
        ));
    }

    public function ajax_export_single_page() {
        check_ajax_referer('export_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $post_id    = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $copyAssets = !empty($_POST['copy_assets']);

        if (!$post_id) {
            wp_send_json_error('Invalid post ID');
        }

        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') {
            wp_send_json_error('Post not found or not published');
        }
		
		error_log('EXPORT_SINGLE post_id=' . $post_id);
		$post = get_post($post_id);
		if (!$post) {
			error_log('EXPORT_SINGLE get_post FAILED for ID=' . $post_id);
		}

        $filename = $this->export_page($post, $copyAssets);
        if (!$filename) {
            wp_send_json_error('Failed to export post ID ' . $post_id);
        }

        wp_send_json_success(array(
            'filename' => $filename,
            'title'    => $post->post_title,
        ));
    }

    public function ajax_create_zip() {
        check_ajax_referer('export_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $files    = isset($_POST['files']) ? (array) $_POST['files'] : array();
        $splitZip = !empty($_POST['split_zip']);

        if ($splitZip) {
            $ok = $this->create_split_zip_files($files);
        } else {
            $ok = $this->create_zip_file($files);
        }

        if (!$ok) {
            wp_send_json_error('ZIP creation failed');
        }

        wp_send_json_success(array('message' => 'ZIP created'));
    }

    private function prepare_directories() {
        if (!file_exists($this->export_dir)) {
            wp_mkdir_p($this->export_dir);
        }
        $subdirs = array('css', 'js', 'images', 'fonts');
        foreach ($subdirs as $sub) {
            $p = $this->assets_dir . '/' . $sub;
            if (!file_exists($p)) {
                wp_mkdir_p($p);
            }
        }
    }

    private function export_page($post, $copy_assets = true) {
        if (!$post instanceof WP_Post) {
			error_log('EXPORT_PAGE invalid $post for ID param=' . (is_object($post) ? $post->ID : 'NULL'));
			return false;
		}
		error_log('EXPORT_PAGE using ID=' . $post->ID . ' type=' . $post->post_type);
		
		if (!$post || !($post instanceof WP_Post) || $post->post_status !== 'publish') {
            return false;
        }

        $slug = $post->post_name ? $post->post_name : sanitize_title($post->post_title);
        $filename = $slug . '.html';

        $html = $this->get_complete_html($post);
        if (empty($html) || strlen($html) < 100) {
            error_log('WP Export: empty HTML for post ' . $post->ID);
            return false;
        }

        $html = $this->make_portable($html, $filename, $copy_assets);

        file_put_contents($this->export_dir . '/' . $filename, $html);

        return $filename;
    }

	private function get_complete_html($post) {
		// Use the passed-in $post, do NOT overwrite it from globals
		if (!$post instanceof WP_Post) {
			error_log('WP Export: get_complete_html called with invalid $post');
			return '';
		}

		global $wp_query;          // only bring in $wp_query, NOT $post

		$original_query = $wp_query;
		$original_post  = $post;   // backup of the function-parameter post

		// Query ONLY this post
		$wp_query = new WP_Query(array(
			'p'              => $post->ID,
			'post_type'      => $post->post_type,
			'posts_per_page' => 1,
		));
		$post = $original_post;
		setup_postdata($post);

		ob_start();

		if ($post->post_type === 'page') {
			$template = get_page_template();
			if (!$template) {
				$template = get_query_template('page');
			}
		} else {
			$template = get_single_template();
			if (!$template) {
            $template = get_query_template('single');
			}
		}
		if (!$template) {
			$template = get_query_template('index');
		}

		include $template;

		$html = ob_get_clean();

		$wp_query = $original_query;
		$post     = $original_post;
		wp_reset_postdata();

		if (stripos($html, '<html') === false || stripos($html, '</html>') === false) {
			error_log('WP Export: invalid HTML for post ' . $post->ID . ' (internal render)');
			return '';
		}

		return $html;
	}


    private function make_portable($html, $current_filename, $copy_assets) {
        // convert internal links
        $html = $this->convert_internal_links($html);

        if ($copy_assets) {
            $html = $this->convert_and_copy_assets($html);
        }

        // remove admin bar etc.
        $html = preg_replace('/<div id="wpadminbar"[^>]*>.*?<\/div>/is', '', $html);
        $html = $this->remove_wordpress_elements($html);

        // fix absolute URLs
        $html = $this->fix_absolute_urls($html);

        return $html;
    }

    private function convert_internal_links($html) {
        $pages = get_pages(array('sort_column' => 'menu_order'));
        $posts = get_posts(array('numberposts' => -1, 'post_status' => 'publish'));

        $map = array();

        // home
        $home_url = home_url('/');
        $map[$home_url] = 'index.html';
        $map[rtrim($home_url, '/')] = 'index.html';

        $front_id = (int) get_option('page_on_front');

        foreach ($pages as $p) {
            $permalink = get_permalink($p->ID);
            $slug      = $p->post_name ? $p->post_name : sanitize_title($p->post_title);

            if ($p->ID === $front_id) {
                $map[$permalink] = 'index.html';
                $map[rtrim($permalink, '/')] = 'index.html';
            } else {
                $map[$permalink] = $slug . '.html';
                $map[rtrim($permalink, '/')] = $slug . '.html';
            }
        }

        foreach ($posts as $p) {
            $permalink = get_permalink($p->ID);
            $slug      = $p->post_name ? $p->post_name : sanitize_title($p->post_title);
            $map[$permalink] = $slug . '.html';
            $map[rtrim($permalink, '/')] = $slug . '.html';
        }

        foreach ($map as $wp_url => $file) {
            $html = str_replace('href="' . $wp_url . '"', 'href="' . $file . '"', $html);
            $html = str_replace("href='" . $wp_url . "'", "href='" . $file . "'", $html);
        }

        return $html;
    }

    private function convert_and_copy_assets($html) {
        $html = preg_replace_callback(
            '/<link[^>]*href=["\']([^"\']+\.css[^"\']*)["\'][^>]*>/i',
            array($this, 'process_css_link'),
            $html
        );
        $html = preg_replace_callback(
            '/<script[^>]*src=["\']([^"\']+\.js[^"\']*)["\'][^>]*>/i',
            array($this, 'process_js_link'),
            $html
        );
        $html = preg_replace_callback(
            '/src=["\']([^"\']+\.(jpg|jpeg|png|gif|svg|webp|ico)[^"\']*)["\']/i',
            array($this, 'process_image'),
            $html
        );
        $html = preg_replace_callback(
            '/url\(["\']?([^"\')]+\.(jpg|jpeg|png|gif|svg|webp))["\']?\)/i',
            array($this, 'process_bg_image'),
            $html
        );
        $html = preg_replace_callback(
            '/srcset=["\']([^"\']+)["\']/i',
            array($this, 'process_srcset'),
            $html
        );

        return $html;
    }

    private function process_css_link($m) {
        return $this->process_asset_link($m, 'css');
    }

    private function process_js_link($m) {
        return $this->process_asset_link($m, 'js');
    }

    private function process_asset_link($m, $type) {
        $full = $m[0];
        $url  = $m[1];

        if ($this->is_external_url($url)) {
            return $full;
        }

        $new = $this->copy_asset($url, $type);
        return $new ? str_replace($url, $new, $full) : $full;
    }

    private function process_image($m) {
        $full = $m[0];
        $url  = $m[1];

        if ($this->is_external_url($url)) {
            return $full;
        }
        $new = $this->copy_asset($url, 'images');
        return $new ? str_replace($url, $new, $full) : $full;
    }

    private function process_bg_image($m) {
        $url = $m[1];

        if ($this->is_external_url($url)) {
            return $m[0];
        }
        $new = $this->copy_asset($url, 'images');
        return $new ? str_replace($url, $new, $m[0]) : $m[0];
    }

    private function process_srcset($m) {
        $srcset = $m[1];
        $parts  = explode(',', $srcset);
        $out    = array();

        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') continue;
            $bits = preg_split('/\s+/', $p);
            $url  = $bits[0];
            $desc = isset($bits[1]) ? $bits[1] : '';

            if ($this->is_external_url($url)) {
                $out[] = $p;
                continue;
            }

            $new = $this->copy_asset($url, 'images');
            $out[] = ($new ?: $url) . ($desc ? ' ' . $desc : '');
        }

        return 'srcset="' . implode(', ', $out) . '"';
    }

    private function is_external_url($url) {
        if (preg_match('/^https?:\\/\\//', $url)) {
            return strpos($url, $this->site_url) === false;
        }
        return false;
    }

    private function copy_asset($url, $type) {
        $url        = $this->normalize_url($url);
        $local_path = $this->url_to_local_path($url);

        if (!file_exists($local_path) || !is_readable($local_path)) {
            return false;
        }

        if (filesize($local_path) > 5 * 1024 * 1024) {
            // skip big files >5MB
            return false;
        }

        $filename = basename(parse_url($url, PHP_URL_PATH));
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
        if ($filename === '') {
            return false;
        }

        $parts    = pathinfo($filename);
        $base     = $parts['filename'];
        $ext      = isset($parts['extension']) ? '.' . $parts['extension'] : '';
        $dest_dir = $this->assets_dir . '/' . $type;
        $dest     = $dest_dir . '/' . $filename;
        $i        = 1;

        while (file_exists($dest)) {
            $filename = $base . '-' . $i . $ext;
            $dest     = $dest_dir . '/' . $filename;
            $i++;
        }

        if (!@copy($local_path, $dest)) {
            return false;
        }

        return 'assets/' . $type . '/' . $filename;
    }

    private function normalize_url($url) {
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        } elseif (strpos($url, '/') === 0) {
            $url = $this->site_url . $url;
        } elseif (!preg_match('/^https?:\\/\\//', $url)) {
            $url = trailingslashit($this->site_url) . ltrim($url, './');
        }
        return $url;
    }

    private function url_to_local_path($url) {
        $path = str_replace($this->site_url, '', $url);
        $path = ltrim($path, '/');
        $path = preg_replace('/\\?.*$/', '', $path);
        return ABSPATH . $path;
    }

    private function remove_wordpress_elements($html) {
        $html = preg_replace('/<meta name="generator"[^>]*>/i', '', $html);
        $html = preg_replace('/<link[^>]*api\\.w\\.org[^>]*>/i', '', $html);
        $html = preg_replace('/<link[^>]*shortlink[^>]*>/i', '', $html);
        $html = preg_replace('/<link[^>]*EditURI[^>]*>/i', '', $html);
        $html = preg_replace('/<link[^>]*wlwmanifest[^>]*>/i', '', $html);
        $html = preg_replace('/<script[^>]*wp-emoji[^>]*>.*?<\\/script>/is', '', $html);
        $html = preg_replace('/<style[^>]*wp-emoji[^>]*>.*?<\\/style>/is', '', $html);
        $html = preg_replace('/<link[^>]*pingback[^>]*>/i', '', $html);
        return $html;
    }

    private function fix_absolute_urls($html) {
        $site = trailingslashit($this->site_url);
        return str_replace($site, '', $html);
    }

    private function create_zip_file($files) {
        if (!class_exists('ZipArchive')) {
            return false;
        }

        $timestamp   = date('Y-m-d-His');
        $zip_name    = 'wp-export-' . $timestamp . '.zip';
        $zip_path    = $this->export_dir . '/' . $zip_name;
        $zip         = new ZipArchive();
        $open_result = $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($open_result !== true) {
            return false;
        }

        // HTML
        foreach ($files as $f) {
            $path = $this->export_dir . '/' . $f;
            if (file_exists($path)) {
                $zip->addFile($path, $f);
            }
        }

        // index.html
        $index = $this->export_dir . '/index.html';
        if (file_exists($index) && !in_array('index.html', $files, true)) {
            $zip->addFile($index, 'index.html');
        }

        // assets
        $this->add_directory_to_zip($zip, $this->assets_dir, 'assets');

        $zip->close();

        $this->create_readme(1, $timestamp);
        return true;
    }

    private function create_split_zip_files($files) {
        if (!class_exists('ZipArchive')) {
            return false;
        }

        $max_size  = 8 * 1024 * 1024; // 8MB raw size cap
        $timestamp = date('Y-m-d-His');
        $part      = 1;
        $size      = 0;

        // measure sizes
        $sizes = array();
        foreach ($files as $f) {
            $path = $this->export_dir . '/' . $f;
            if (file_exists($path)) {
                $sizes[$f] = filesize($path);
            }
        }

        arsort($sizes);

        $zip_name = "wp-export-{$timestamp}-part{$part}.zip";
        $zip_path = $this->export_dir . '/' . $zip_name;
        $zip      = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        // index.html first in part1
        $index = $this->export_dir . '/index.html';
        if (file_exists($index) && $part === 1) {
            $index_size = filesize($index);
            $zip->addFile($index, 'index.html');
            $size += $index_size;
        }

        foreach ($sizes as $file => $fs) {
            $path = $this->export_dir . '/' . $file;
            if (!file_exists($path)) continue;

            if ($size + $fs > $max_size && $size > 0) {
                $zip->close();
                $part++;
                $size    = 0;
                $zip_name = "wp-export-{$timestamp}-part{$part}.zip";
                $zip_path = $this->export_dir . '/' . $zip_name;
                $zip      = new ZipArchive();
                if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    return false;
                }
            }

            $zip->addFile($path, $file);
            $size += $fs;
        }

        $zip->close();

        // split assets too
        if (is_dir($this->assets_dir)) {
            $this->create_split_assets_zip($timestamp, $max_size);
        }

        $this->create_readme($part, $timestamp);
        return true;
    }

    private function create_split_assets_zip($timestamp, $max_size) {
        if (!class_exists('ZipArchive')) {
            return false;
        }

        $part = 1;
        $size = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->assets_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $zip_name = "wp-export-{$timestamp}-assets-part{$part}.zip";
        $zip_path = $this->export_dir . '/' . $zip_name;
        $zip      = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        foreach ($iterator as $file) {
            if ($file->isDir()) continue;
            $path = $file->getRealPath();
            $fs   = filesize($path);

            if ($size + $fs > $max_size && $size > 0) {
                $zip->close();
                $part++;
                $size    = 0;
                $zip_name = "wp-export-{$timestamp}-assets-part{$part}.zip";
                $zip_path = $this->export_dir . '/' . $zip_name;
                $zip      = new ZipArchive();
                if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    return false;
                }
            }

            $relative = 'assets/' . substr($path, strlen($this->assets_dir) + 1);
            $relative = str_replace('\\', '/', $relative);
            $zip->addFile($path, $relative);
            $size += $fs;
        }

        $zip->close();
        return true;
    }

    private function add_directory_to_zip($zip, $dir, $zip_dir) {
        if (!is_dir($dir)) return;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) continue;
            $path     = $file->getRealPath();
            $relative = $zip_dir . '/' . substr($path, strlen($dir) + 1);
            $relative = str_replace('\\', '/', $relative);
            $zip->addFile($path, $relative);
        }
    }

    private function create_readme($html_parts, $timestamp) {
        $text = "WordPress Portable HTML Export\n"
              . "===============================\n\n"
              . "Export Date: {$timestamp}\n"
              . "HTML Parts: {$html_parts}\n\n"
              . "Each ZIP part is kept under 9MB.\n\n"
              . "Usage:\n"
              . "1. Download ALL wp-export-{$timestamp}-part*.zip files and any wp-export-{$timestamp}-assets-part*.zip files.\n"
              . "2. Extract everything into the SAME folder.\n"
              . "3. You should end up with index.html, other .html files, and an assets/ folder.\n"
              . "4. Open index.html in a browser or upload all files to any static hosting.\n";
        file_put_contents($this->export_dir . '/README.txt', $text);
    }
}

new WP_To_Dynamic_HTML_Portable();
?>