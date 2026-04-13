<?php
/**
 * Plugin Name: Network Site Stats
 * Plugin URI: https://yourwebsite.com/
 * Description: Plugin quản lý tập trung cho WordPress Multisite - Hiển thị thống kê các site con (số bài viết, dung lượng, ngày đăng mới nhất)
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://yourwebsite.com/
 * License: GPL v2 or later
 * Text Domain: network-site-stats
 * Network: true
 */

// Ngăn chặn truy cập trực tiếp
if (!defined('ABSPATH')) {
    exit;
}

// Định nghĩa các hằng số
define('NSS_VERSION', '1.0.0');
define('NSS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('NSS_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Class Network_Site_Stats
 * Lớp chính của plugin
 */
class Network_Site_Stats {

    /**
     * Constructor - Khởi tạo các hook
     */
    public function __construct() {
        // Kiểm tra môi trường Multisite
        add_action('admin_init', array($this, 'check_multisite_environment'));
        
        // Thêm menu vào Network Admin
        add_action('network_admin_menu', array($this, 'add_network_admin_menu'));
        
        // Đăng ký CSS và JS
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // Xử lý refresh dữ liệu bằng AJAX
        add_action('wp_ajax_nss_refresh_stats', array($this, 'ajax_refresh_stats'));
    }

    /**
     * Kiểm tra môi trường Multisite
     */
    public function check_multisite_environment() {
        if (!is_multisite() && current_user_can('manage_network')) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-warning"><p>';
                echo __('Plugin "Network Site Stats" chỉ hoạt động trong môi trường WordPress Multisite!', 'network-site-stats');
                echo '</p></div>';
            });
        }
    }

    /**
     * Thêm menu vào Network Admin Dashboard
     */
    public function add_network_admin_menu() {
        add_menu_page(
            'Network Site Stats',           // Page title
            'Site Stats',                   // Menu title
            'manage_network',               // Capability
            'network-site-stats',           // Menu slug
            array($this, 'render_admin_page'), // Callback function
            'dashicons-chart-area',         // Icon
            30                              // Position
        );
        
        add_submenu_page(
            'network-site-stats',
            'Site Statistics',
            'Statistics',
            'manage_network',
            'network-site-stats',
            array($this, 'render_admin_page')
        );
    }

    /**
     * Tải CSS/JS cho trang quản trị
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'network-site-stats') !== false) {
            wp_enqueue_style(
                'nss-admin-style',
                NSS_PLUGIN_URL . 'assets/css/admin-style.css',
                array(),
                NSS_VERSION
            );
            
            wp_enqueue_script(
                'nss-admin-script',
                NSS_PLUGIN_URL . 'assets/js/admin.js',
                array('jquery'),
                NSS_VERSION,
                true
            );
            
            wp_localize_script('nss-admin-script', 'nss_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('nss_refresh_nonce')
            ));
        }
    }

    /**
     * Lấy số lượng bài viết của một site
     * @param int $blog_id ID của site
     * @return int Số lượng bài viết
     */
    private function get_site_post_count($blog_id) {
        // Chuyển sang ngữ cảnh của site cần lấy dữ liệu
        switch_to_blog($blog_id);
        
        // Đếm số bài viết (post_type = 'post' và status = 'publish')
        $post_count = wp_count_posts('post');
        $count = $post_count->publish;
        
        // Quay trở lại site hiện tại
        restore_current_blog();
        
        return $count;
    }

    /**
     * Lấy ngày đăng bài viết mới nhất của site
     * @param int $blog_id ID của site
     * @return string Ngày đăng mới nhất (format: d/m/Y H:i) hoặc 'Chưa có bài viết'
     */
    private function get_latest_post_date($blog_id) {
        switch_to_blog($blog_id);
        
        $args = array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'orderby' => 'date',
            'order' => 'DESC'
        );
        
        $latest_posts = get_posts($args);
        
        if (!empty($latest_posts)) {
            $date = get_the_date('d/m/Y H:i', $latest_posts[0]);
            restore_current_blog();
            return $date;
        }
        
        restore_current_blog();
        return 'Chưa có bài viết';
    }

    /**
     * Tính dung lượng database của một site
     * @param int $blog_id ID của site
     * @return string Dung lượng đã format (ví dụ: 2.5 MB)
     */
    private function get_site_database_size($blog_id) {
        global $wpdb;
        
        $total_size = 0;
        $tables = array(
            $wpdb->prefix . $blog_id . '_posts',
            $wpdb->prefix . $blog_id . '_postmeta',
            $wpdb->prefix . $blog_id . '_options',
            $wpdb->prefix . $blog_id . '_comments',
            $wpdb->prefix . $blog_id . '_commentmeta',
            $wpdb->prefix . $blog_id . '_terms',
            $wpdb->prefix . $blog_id . '_term_taxonomy',
            $wpdb->prefix . $blog_id . '_term_relationships',
            $wpdb->prefix . $blog_id . '_usermeta',
        );
        
        foreach ($tables as $table) {
            $query = $wpdb->prepare(
                "SELECT (data_length + index_length) AS size 
                 FROM information_schema.tables 
                 WHERE table_schema = %s AND table_name = %s",
                DB_NAME,
                $table
            );
            $size = $wpdb->get_var($query);
            if ($size) {
                $total_size += $size;
            }
        }
        
        return $this->format_size($total_size);
    }

    /**
     * Format dung lượng từ bytes sang đơn vị dễ đọc
     * @param int $bytes Dung lượng bytes
     * @return string Dung lượng đã format
     */
    private function format_size($bytes) {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }

    /**
     * Hiển thị trang quản trị chính
     */
    public function render_admin_page() {
        // Kiểm tra quyền truy cập
        if (!current_user_can('manage_network')) {
            wp_die('Bạn không có quyền truy cập trang này.');
        }
        
        // Lấy danh sách tất cả các site
        $sites = get_sites(array(
            'number' => 100,  // Giới hạn số lượng site hiển thị
            'orderby' => 'id',
            'order' => 'ASC'
        ));
        
        ?>
        <div class="wrap nss-admin-wrap">
            <h1 class="wp-heading-inline">
                <span class="dashicons dashicons-chart-area"></span>
                <?php _e('Network Site Statistics', 'network-site-stats'); ?>
            </h1>
            <button id="nss-refresh-stats" class="page-title-action">
                <span class="dashicons dashicons-update"></span>
                <?php _e('Refresh Data', 'network-site-stats'); ?>
            </button>
            <div id="nss-loading" style="display: none;">
                <div class="spinner is-active"></div>
                <span>Đang tải dữ liệu...</span>
            </div>
            
            <div class="nss-stats-summary">
                <div class="nss-stat-box">
                    <span class="nss-stat-number"><?php echo count($sites); ?></span>
                    <span class="nss-stat-label">Total Sites</span>
                </div>
                <?php
                // Tính tổng số bài viết
                $total_posts = 0;
                foreach ($sites as $site) {
                    $total_posts += $this->get_site_post_count($site->blog_id);
                }
                ?>
                <div class="nss-stat-box">
                    <span class="nss-stat-number"><?php echo number_format($total_posts); ?></span>
                    <span class="nss-stat-label">Total Posts</span>
                </div>
            </div>
            
            <table class="wp-list-table widefat fixed striped nss-sites-table">
                <thead>
                    <tr>
                        <th scope="col" width="80">ID</th>
                        <th scope="col">Site Name</th>
                        <th scope="col">Domain / Path</th>
                        <th scope="col" width="120">Posts</th>
                        <th scope="col" width="180">Latest Post</th>
                        <th scope="col" width="150">Database Size</th>
                        <th scope="col" width="100">Actions</th>
                    </tr>
                </thead>
                <tbody id="nss-sites-list">
                    <?php foreach ($sites as $site): ?>
                        <?php
                        $blog_id = $site->blog_id;
                        $site_info = get_blog_details($blog_id);
                        $post_count = $this->get_site_post_count($blog_id);
                        $latest_post = $this->get_latest_post_date($blog_id);
                        $db_size = $this->get_site_database_size($blog_id);
                        ?>
                        <tr data-site-id="<?php echo esc_attr($blog_id); ?>">
                            <td><?php echo esc_html($blog_id); ?></td>
                            <td>
                                <strong><?php echo esc_html($site_info->blogname); ?></strong>
                                <?php if ($blog_id == 1): ?>
                                    <span class="nss-badge">Main Site</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url($site_info->siteurl); ?>" target="_blank">
                                    <?php echo esc_html($site_info->domain . $site_info->path); ?>
                                </a>
                            </td>
                            <td class="nss-post-count"><?php echo number_format($post_count); ?></td>
                            <td><?php echo esc_html($latest_post); ?></td>
                            <td><?php echo esc_html($db_size); ?></td>
                            <td>
                                <a href="<?php echo esc_url(get_admin_url($blog_id)); ?>" 
                                   class="button button-small" target="_blank">
                                    Dashboard
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th>ID</th>
                        <th>Site Name</th>
                        <th>Domain / Path</th>
                        <th>Posts</th>
                        <th>Latest Post</th>
                        <th>Database Size</th>
                        <th>Actions</th>
                    </tr>
                </tfoot>
            </table>
            
            <div class="nss-info-footer">
                <p>
                    <strong>Note:</strong> Dữ liệu được lấy trực tiếp từ database của từng site 
                    sử dụng hàm <code>switch_to_blog()</code>. Plugin hoạt động trên toàn bộ mạng lưới 
                    khi được Network Activate.
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * Xử lý AJAX refresh dữ liệu
     */
    public function ajax_refresh_stats() {
        // Kiểm tra nonce
        if (!check_ajax_referer('nss_refresh_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        // Kiểm tra quyền
        if (!current_user_can('manage_network')) {
            wp_send_json_error('Permission denied');
        }
        
        $site_id = isset($_POST['site_id']) ? intval($_POST['site_id']) : 0;
        
        if ($site_id) {
            // Refresh một site cụ thể
            $data = array(
                'post_count' => $this->get_site_post_count($site_id),
                'latest_post' => $this->get_latest_post_date($site_id),
                'db_size' => $this->get_site_database_size($site_id)
            );
            wp_send_json_success($data);
        } else {
            // Refresh tất cả các site
            $sites = get_sites();
            $all_data = array();
            foreach ($sites as $site) {
                $all_data[$site->blog_id] = array(
                    'post_count' => $this->get_site_post_count($site->blog_id),
                    'latest_post' => $this->get_latest_post_date($site->blog_id),
                    'db_size' => $this->get_site_database_size($site->blog_id)
                );
            }
            wp_send_json_success($all_data);
        }
    }
}

// Khởi tạo plugin
new Network_Site_Stats();