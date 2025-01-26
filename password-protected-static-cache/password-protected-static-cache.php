<?php
/**
 * Plugin Name: PPSC - Protected Post Static Cache
 * Description: Caches unlocked password-protected posts (skips admin users). Denies direct public access to the cache directory. Provides an admin panel to manage/view cached files and logs, with the ability to preload, list all PW-protected posts (all post types), and a dedicated top-level menu.
 * Version: 1.5
 * Author: Your Name
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PPSC_NoLoopbackCache {

    private $cache_dir;
    private $debug_logs = array();
    private $log_file_path;

    public function __construct() {
        // Directory setup
        $this->cache_dir     = WP_CONTENT_DIR . '/pp-static-cache/';
        $this->log_file_path = $this->cache_dir . 'ppsc-debug.log';

        // Set no-cache headers if the post is still locked
        add_action( 'template_redirect', array( $this, 'maybe_set_no_cache_headers' ), 1 );

        // Serve from cache at a later priority so WP handles password cookies first
        add_action( 'template_redirect', array( $this, 'maybe_serve_cached_content' ), 100 );

        // Capture output if unlocked (skip admin), also at a very late priority
        add_filter( 'template_include', array( $this, 'capture_template_output' ), 9999 );

        // Admin debug + cache manager in a top-level menu
        add_action( 'admin_menu', array( $this, 'add_debug_menu' ) );

        // On activation, create dir, .htaccess, set perms
        register_activation_hook( __FILE__, array( $this, 'activate_plugin' ) );
    }

    /**
     * On plugin activation, ensure cache directory, .htaccess, etc.
     */
    public function activate_plugin() {
        $this->create_cache_dir();
        $this->write_htaccess();
        $this->init_log_file();
    }

    /**
     * Creates the cache directory with 0700. Also corrects perms if it already exists.
     */
    private function create_cache_dir() {
        if ( ! is_dir( $this->cache_dir ) ) {
            mkdir( $this->cache_dir, 0700, true );
        }
        chmod( $this->cache_dir, 0700 );
    }

    /**
     * Writes a .htaccess file to deny all direct access in the cache directory.
     */
    private function write_htaccess() {
        $htaccess_path = $this->cache_dir . '.htaccess';
        // For Apache 2.4, you might do "Require all denied"
        $htaccess_rule = "Order allow,deny\nDeny from all\n";
        file_put_contents( $htaccess_path, $htaccess_rule );
        chmod( $htaccess_path, 0600 );
    }

    /**
     * Ensures our log file exists (touch) and sets perms 0600.
     */
    private function init_log_file() {
        if ( ! file_exists( $this->log_file_path ) ) {
            touch( $this->log_file_path );
        }
        chmod( $this->log_file_path, 0600 );
    }

    /**
     * If post is password-protected but not unlocked, set no-cache headers.
     */
    public function maybe_set_no_cache_headers() {
        if ( ! is_singular() ) {
            return;
        }
        global $post;
        if ( $post && ! empty( $post->post_password ) && post_password_required( $post ) ) {
            $this->add_log( "Setting no-cache headers for post {$post->ID}" );
            nocache_headers();
        }
    }

    /**
     * Serve from cache if post is unlocked, file exists, and skip if admin user.
     */
    public function maybe_serve_cached_content() {
        if ( ! is_singular() ) {
            return;
        }

        global $post;
        if ( ! $post || empty( $post->post_password ) ) {
            return;
        }

        if ( post_password_required( $post ) ) {
            $this->add_log( "Post {$post->ID} locked => password form." );
            return;
        }

        // If admin user visits, skip serving file (avoid admin bar).
        if ( current_user_can( 'manage_options' ) ) {
            $this->add_log( "Admin user => ignoring cache for post {$post->ID}." );
            return;
        }

        $cache_file = $this->get_cache_file_path( $post->ID );
        if ( file_exists( $cache_file ) ) {
            $this->add_log( "Serving cache => {$cache_file}" );
            error_log( "PPSC: Serving cache => {$cache_file}" );
            echo "<!-- Cached by PPSC -->\n";
            readfile( $cache_file );
            exit;
        }

        $this->add_log( "No cache file for unlocked post {$post->ID} => normal render." );
    }

    /**
     * Late hook: capture output if unlocked, skip caching if admin, store file with 0600 perms
     */
    public function capture_template_output( $template ) {
        if ( ! is_singular() ) {
            return $template;
        }

        global $post;
        if ( ! $post || empty( $post->post_password ) ) {
            return $template;
        }

        // Double-check to ensure it's actually unlocked
        if ( post_password_required( $post ) ) {
            $this->add_log( "capture_template_output => Post still locked, skipping cache." );
            return $template;
        }

        $cache_file = $this->get_cache_file_path( $post->ID );
        if ( file_exists( $cache_file ) ) {
            return $template;
        }

        // If user is admin => skip caching
        if ( current_user_can( 'manage_options' ) ) {
            $this->add_log( "Admin user => skip caching for post {$post->ID}." );
            return $template;
        }

        // Capture
        $this->add_log( "Capturing output for post {$post->ID} => no cache yet." );
        ob_start();
        include $template;
        $html = ob_get_clean();

        // Check if password form is still there
        if ( strpos( $html, 'post-password-form' ) !== false || strpos( $html, 'name="post_password_form"' ) !== false ) {
            $this->add_log( "Password form found => skip cache for post {$post->ID}." );
            echo "<!-- Not cached by PPSC -->\n";
            echo $html;
            exit;
        }

        // Write cache file with 0600 perms
        $this->create_cache_dir();
        if ( false === file_put_contents( $cache_file, $html ) ) {
            $this->add_log( "Failed to write => {$cache_file}" );
            error_log( "PPSC: Failed to write cache => {$cache_file}" );
            echo "<!-- Not cached by PPSC -->\n";
            echo $html;
            exit;
        }
        chmod( $cache_file, 0600 );
        $this->add_log( "Cache file created => {$cache_file}" );

        // Serve
        echo "<!-- Cached by PPSC -->\n";
        echo $html;
        exit;
    }

    /**
     * Build path to the cache file
     */
    private function get_cache_file_path( $post_id ) {
        return $this->cache_dir . 'cache-' . $post_id . '.html';
    }

    /**
     * Add a top-level admin menu
     */
    public function add_debug_menu() {
        add_menu_page(
            'ACWebDev PW Protected Cache',
            'ACWebDev PW Protected Cache',
            'manage_options',
            'ppsc-debug',
            array( $this, 'render_debug_page' ),
            'dashicons-lock'
        );
    }

    /**
     * Renders debug page & cache manager, reading ppsc-debug.log from newest to oldest.
     */
    public function render_debug_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Handle posted actions
        if ( isset( $_POST['ppsc_action'] ) && check_admin_referer( 'ppsc_cache_action', 'ppsc_nonce' ) ) {
            if ( $_POST['ppsc_action'] === 'clear_all' ) {
                $this->clear_all_cache();
            } elseif ( $_POST['ppsc_action'] === 'clear_one' && isset( $_POST['ppsc_post_id'] ) ) {
                $this->clear_single_cache( intval( $_POST['ppsc_post_id'] ) );
            } elseif ( $_POST['ppsc_action'] === 'preload_all' ) {
                $this->preload_all_cache();
            }
        }

        echo '<div class="wrap">';
        echo '<h1>ACWebDev PW Protected Cache</h1>';
        echo '<p>Log file located at ' . esc_html( $this->log_file_path ) . '</p>';

        // Cache Manager
        echo '<h2>Cache Manager</h2>';

        // Clear all
        echo '<form method="post" style="margin-bottom:20px;">';
        wp_nonce_field( 'ppsc_cache_action', 'ppsc_nonce' );
        echo '<input type="hidden" name="ppsc_action" value="clear_all" />';
        echo '<button type="submit" class="button button-secondary">Clear All Cache</button>';
        echo '</form>';

        // Preload all
        echo '<form method="post" style="margin-bottom:20px;">';
        wp_nonce_field( 'ppsc_cache_action', 'ppsc_nonce' );
        echo '<input type="hidden" name="ppsc_action" value="preload_all" />';
        echo '<button type="submit" class="button button-secondary">Preload All</button>';
        echo '</form>';

        // List all password-protected posts (ALL post types, all statuses)
        $all_post_types = get_post_types( array(), 'names' );
        $pw_posts = get_posts( array(
            'post_type'      => $all_post_types,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'has_password'   => true,
        ) );

        if ( empty( $pw_posts ) ) {
            echo '<p>No password-protected posts found (across all post types).</p>';
        } else {
            echo '<table class="widefat"><thead><tr>';
            echo '<th>Post ID</th>';
            echo '<th>Title</th>';
            echo '<th>Post Type</th>';
            echo '<th>Status</th>';
            echo '<th>Cached?</th>';
            echo '<th>Cache File</th>';
            echo '<th>Created/Modified</th>';
            echo '<th></th>';
            echo '</tr></thead><tbody>';

            foreach ( $pw_posts as $p ) {
                $cache_file    = $this->get_cache_file_path( $p->ID );
                $is_cached     = file_exists( $cache_file );
                $cache_status  = $is_cached ? 'Yes' : 'No';
                $cache_path    = $is_cached ? $cache_file : '';
                $modified_time = $is_cached ? date( 'Y-m-d H:i:s', filemtime( $cache_file ) ) : '';

                echo '<tr>';
                echo '<td>' . esc_html( $p->ID ) . '</td>';
                echo '<td>' . esc_html( get_the_title( $p ) ) . '</td>';
                echo '<td>' . esc_html( $p->post_type ) . '</td>';
                echo '<td>' . esc_html( $p->post_status ) . '</td>';
                echo '<td>' . esc_html( $cache_status ) . '</td>';
                echo '<td>' . esc_html( $cache_path ) . '</td>';
                echo '<td>' . esc_html( $modified_time ) . '</td>';
                echo '<td>';
                if ( $is_cached ) {
                    echo '<form method="post" style="display:inline;">';
                    wp_nonce_field( 'ppsc_cache_action', 'ppsc_nonce' );
                    echo '<input type="hidden" name="ppsc_action" value="clear_one" />';
                    echo '<input type="hidden" name="ppsc_post_id" value="' . esc_attr( $p->ID ) . '" />';
                    echo '<button type="submit" class="button button-secondary">Clear This Cache</button>';
                    echo '</form>';
                }
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        // Debug Logs (newest to oldest)
        echo '<h2>Debug Log (Newest First)</h2>';
        echo '<pre style="height:400px;overflow:auto;">';
        $logs = $this->read_log_file_newest_first();
        if ( empty( $logs ) ) {
            echo 'No log entries found.';
        } else {
            foreach ( $logs as $line ) {
                echo esc_html( $line ) . "\n";
            }
        }
        echo '</pre>';

        echo '</div>';
    }

    /**
     * Attempt to preload all password-protected posts
     */
    private function preload_all_cache() {
        // Very naive approach: loops over published password-protected posts and does a GET.
        // If there's a single password (shared) and your environment has that cookie set,
        // it might generate caches. Otherwise, it may not unlock them.
        $all_post_types = get_post_types( array(), 'names' );
        $all_posts = get_posts( array(
            'post_type'      => $all_post_types,
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'has_password'   => true,
        ) );

        foreach ( $all_posts as $post_id ) {
            $post = get_post( $post_id );
            if ( ! empty( $post->post_password ) ) {
                $url = get_permalink( $post_id );
                wp_remote_get( $url ); // ignoring response
            }
        }

        $this->add_log( "Preload all triggered by admin." );
    }

    /**
     * Read ppsc-debug.log from newest to oldest lines
     */
    private function read_log_file_newest_first() {
        if ( ! file_exists( $this->log_file_path ) ) {
            return array();
        }
        $lines = file( $this->log_file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        if ( ! $lines ) {
            return array();
        }
        return array_reverse( $lines );
    }

    /**
     * Clear all HTML caches
     */
    private function clear_all_cache() {
        $files = glob( $this->cache_dir . 'cache-*.html' );
        if ( $files ) {
            foreach ( $files as $f ) {
                @unlink( $f );
            }
        }
        $this->add_log( "All cache cleared by admin." );
    }

    /**
     * Clear cache for a specific post ID
     */
    private function clear_single_cache( $post_id ) {
        $file_path = $this->get_cache_file_path( $post_id );
        if ( file_exists( $file_path ) ) {
            @unlink( $file_path );
            $this->add_log( "Cache file removed for post {$post_id}." );
        } else {
            $this->add_log( "Cache file not found for post {$post_id}." );
        }
    }

    /**
     * List all cached HTML files => returns array( post_id => path )
     */
    private function list_cached_files() {
        $files = glob( $this->cache_dir . 'cache-*.html' );
        $result = array();
        if ( $files ) {
            foreach ( $files as $file_path ) {
                if ( preg_match( '/cache-(\d+)\.html$/', $file_path, $m ) ) {
                    $post_id = intval( $m[1] );
                    $result[ $post_id ] = $file_path;
                }
            }
        }
        return $result;
    }

    /**
     * Add a log entry to memory, error_log, and ppsc-debug.log (0600).
     */
    private function add_log( $message ) {
        $this->debug_logs[] = $message;
        error_log( 'PPSC: ' . $message );

        if ( ! file_exists( $this->log_file_path ) ) {
            $this->init_log_file();
        }
        $timestamp = date( 'Y-m-d H:i:s' );
        $entry     = "[{$timestamp}] {$message}\n";
        file_put_contents( $this->log_file_path, $entry, FILE_APPEND );
        @chmod( $this->log_file_path, 0600 );
    }
}

// Initialize
new PPSC_NoLoopbackCache();