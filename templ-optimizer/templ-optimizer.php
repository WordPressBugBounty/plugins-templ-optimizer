<?php
/**
 * Plugin Name: Templ Optimizer
 * Description: An easy-to-use optimization plugin that lets you clean your database and tweak various performance related settings on your WordPress site.
 * Version: 2.4.0
 * Author: Templ
 * Author URI: https://templ.io/
 * Requires at least: 6.3
 * Tested up to: 7.1
 * Requires PHP: 8.0
 * License: GNU GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: templ-optimizer
 *
 * @package Templ_Optimizer
 */

declare(strict_types=1);

defined('ABSPATH') || die();

define('TEMPL_OPTIMIZER_PATH', plugin_dir_path(__FILE__));
define('TEMPL_OPTIMIZER_URL', plugin_dir_url(__FILE__));
define('TEMPL_OPTIMIZER_BASENAME', plugin_basename(__FILE__));

/**
 * Main plugin controller.
 *
 * Boots the sub-modules (database tools, tweaks, WP-CLI, wp-config transformer),
 * registers the admin page and REST routes, and exposes the shared option
 * helpers reused by the sub-module classes.
 *
 * @since 2.0.0
 */
class templOptimizer {

    /**
     * Database tools instance.
     *
     * @var templOptimizerDb|null
     */
    protected $db = null;

    /**
     * Tweaks instance.
     *
     * @var templOptimizerTweaks|null
     */
    protected $tweaks = null;

    /**
     * High-performance indexing instance.
     *
     * @var templOptimizerIndexes|null
     */
    protected $indexes = null;

    /**
     * WP-CLI command instance.
     *
     * @var templOptimizerCli|null
     */
    protected $cli = null;

    /**
     * HTTP request diagnostics instance.
     *
     * @var templOptimizerHttpLog|null
     */
    protected $http_log = null;

    /**
     * wp-config.php transformer instance.
     *
     * @var templOptimizerConfigTransformer|null
     */
    protected $config = null;

    /**
     * Capability required to manage the plugin.
     *
     * @var string
     */
    private $capability = 'manage_options';

    /**
     * Admin page slug for the plugin settings screen.
     *
     * @var string
     */
    private $admin_page = 'tools.php?page=templ-optimizer';

    /**
     * External documentation URL.
     *
     * @var string
     */
    private $docs_link = 'https://help.templ.io/en/articles/5749500-templ-optimizer';

    /**
     * Parsed plugin header data.
     *
     * @var array
     */
    private $plugin_data;

    /**
     * Default plugin settings created on activation.
     *
     * @var array
     */
    private $default_settings = array(
        'heartbeat_interval'            => 'default',
        'wp_rocket_preload_interval'    => 'default',
    );

    /**
     * Boots the plugin: loads sub-modules and registers WordPress hooks.
     */
    function __construct() {

        if( ! function_exists('get_plugin_data') ){
            require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
        }
        // Pass $translate = false so header lookup does not load the plugin
        // textdomain before the 'init' hook, which triggers the WP 6.7+
        // "_load_textdomain_just_in_time was called incorrectly" notice.
        $this->plugin_data = get_plugin_data(__FILE__, false, false);

        // Include sub-modules
        require_once( TEMPL_OPTIMIZER_PATH . 'includes/vendor/WPConfigTransformer.php' );
        require_once( TEMPL_OPTIMIZER_PATH . 'includes/db-optimizations.php' );
        $this->db = new templOptimizerDb();
        require_once( TEMPL_OPTIMIZER_PATH . 'includes/db-indexes.php' );
        $this->indexes = new templOptimizerIndexes();
        require_once( TEMPL_OPTIMIZER_PATH . 'includes/tweaks.php' );
        $this->tweaks = new templOptimizerTweaks();
        require_once( TEMPL_OPTIMIZER_PATH . 'includes/http-log.php' );
        $this->http_log = new templOptimizerHttpLog();

        // Define WP CLI commands
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            require_once( TEMPL_OPTIMIZER_PATH . 'includes/cli.php' );
            $this->cli = new templOptimizerCli();
        }

        // Default settings
        register_activation_hook( __FILE__, array( $this, 'set_default_settings' ) );
        add_action( 'templ_optimizer_before_settings_page', array( $this, 'set_default_settings' ) ); // Recreate default settings if they would go missing

        // Admin page stuff
        add_filter( 'plugin_action_links_' . TEMPL_OPTIMIZER_BASENAME, array( $this, 'add_plugin_actions_links' ) );
        add_filter( 'plugin_row_meta', array( $this, 'add_plugin_info_links' ), 10, 4 );

        add_action( 'admin_menu', array( $this, 'add_admin_menu_page' ) );
        add_action( 'admin_notices', array( $this, 'index_drift_notice' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_filter( 'script_loader_tag', array($this, 'script_tag_filter'), 10, 3) ;

        add_action( 'rest_api_init', array($this, 'register_rest_routes') );

        // Arm HTTP request capture only while enabled, to avoid any overhead
        // on sites that are not actively diagnosing outbound calls.
        if ( $this->http_log->is_enabled() ) {
            $this->http_log->arm();
        }
        add_action( templOptimizerHttpLog::CRON_HOOK, array( $this->http_log, 'prune' ) );

    }

    /**
     * Creates the default settings option if it does not already exist.
     *
     * Runs on activation and on `templ_optimizer_before_settings_page`.
     *
     * @return void
     */
    function set_default_settings(): void {
        if( get_option( 'templ_optimizer_settings' ) ) {
            return;
        }
        update_option( 'templ_optimizer_settings', $this->default_settings, true );
    }

    /**
     * Adds a "Settings" link to the plugin's row actions.
     *
     * Hooked to `plugin_action_links_{basename}`.
     *
     * @param array $links Existing action links.
     * @return array Action links with the settings link prepended.
     */
    public function add_plugin_actions_links( array $links ): array {
        $links = array_merge(
            array( '<a href="' . esc_url( admin_url( $this->admin_page ) ) . '">' . __('Settings', 'templ-optimizer') . '</a>' ),
            $links
        );
        return $links;
    }

    /**
     * Adds a "Docs" link to the plugin's row meta.
     *
     * Hooked to `plugin_row_meta`.
     *
     * @param array  $links            Existing meta links.
     * @param string $plugin_file_name Plugin file path being filtered.
     * @param array  $plugin_data      Plugin header data.
     * @param string $status           Plugin status context.
     * @return array Meta links, with the docs link appended for this plugin.
     */
    public function add_plugin_info_links( array $links, string $plugin_file_name, array $plugin_data, string $status ): array {
        if( strpos( $plugin_file_name, basename(__FILE__) ) ) {
            $links = array_merge(
                $links,
                array( '<a href="' . esc_url( $this->docs_link ) . '" target="_blank">' . __('Docs', 'templ-optimizer') . '</a>' )
            );
        }
        return $links;
    }

    /**
     * Registers the "Templ Optimizer" page under the Tools menu.
     *
     * Hooked to `admin_menu`.
     *
     * @return void
     */
    function add_admin_menu_page(): void {
        if ( ! current_user_can( $this->capability ) ) {
            return;
        }
        // Add "Templ Optimizer" sub-page
        add_management_page(
            __( 'Templ Optimizer', 'templ-optimizer' ),
            __( 'Templ Optimizer', 'templ-optimizer' ),
            $this->capability,
            'templ-optimizer',
            array( $this, 'show_settings_page' )
        );
    }

    /**
     * Renders the settings page mount point and localizes the admin app.
     *
     * @return void
     */
    function show_settings_page(): void {
        wp_enqueue_script('templ-optimizer-vue');
        wp_enqueue_style('templ-optimizer-vue');
        $localization = array(
            'baseUrl' => get_rest_url( null, 'templ-optimizer/v1' ),
            'cronUrl' => site_url( 'wp-cron.php' ),
            'nonce' => wp_create_nonce('wp_rest'),
            'permission_check' => $this->permission_check(),
        );
        wp_localize_script('templ-optimizer-vue', 'templOptimizer', $localization);
        echo "<div id=\"templ-optimizer\" class=\"templ-optimizer\"></div>";
    }

    /**
     * Registers the admin app script and style handles.
     *
     * Hooked to `admin_enqueue_scripts`.
     *
     * @param string $hook_suffix Current admin page hook suffix.
     * @return void
     */
    function enqueue_scripts( string $hook_suffix ): void {
        $build_time = date( "U", filemtime( TEMPL_OPTIMIZER_PATH.'assets/index.js' ) );
        wp_register_script('templ-optimizer-vue', TEMPL_OPTIMIZER_URL.'assets/index.js', array(), $build_time, true);
        wp_register_style('templ-optimizer-vue', TEMPL_OPTIMIZER_URL.'assets/index.css', array(), $build_time);
    }

    /**
     * Forces the admin app script tag to load as an ES module.
     *
     * Hooked to `script_loader_tag`.
     *
     * @param string $tag    The full script tag markup.
     * @param string $handle Registered script handle.
     * @param string $src    Script source URL.
     * @return string The (possibly modified) script tag.
     */
    function script_tag_filter( string $tag, string $handle, string $src ): string {
        if ( 'templ-optimizer-vue' !== $handle ) {
            return $tag;
        }
        $tag = '<script src="' . esc_url( $src ) . '" type="module"></script>';
        return $tag;
    }

    /**
     * Builds the full settings + stats payload for the admin app.
     *
     * Also serves as the `GET /templ-optimizer/v1/get/` REST callback.
     *
     * @return array Plugin data, current settings, database stats and feature flags.
     */
    function get_settings(): array {
        global $wpdb;
        $settings = array(
            'plugin_data' => $this->plugin_data,
            'current_settings' => array(
                'DISABLE_WP_CRON' => (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) ? 'disabled' : 'enabled',
                'WP_POST_REVISIONS' => (defined('WP_POST_REVISIONS') && WP_POST_REVISIONS !== true ) ? WP_POST_REVISIONS : null, // Default is true
                'WP_MEMORY_LIMIT' => defined('WP_MEMORY_LIMIT') ? WP_MEMORY_LIMIT : null,
                'heartbeat_interval' => $this->get_option('heartbeat_interval'),
                'wp_rocket_preload_interval' => $this->get_option('wp_rocket_preload_interval'),
            ),
            'current_stats' => array(
                'database_size' => $this->db->get_database_size(),
                'delete_trashed_posts' => $this->db->count_trashed_posts(),
                'delete_revisions' => $this->db->count_revisions(),
                'delete_auto_drafts' => $this->db->count_auto_drafts(),
                'delete_orphaned_postmeta' => $this->db->count_orphaned_postmeta(),
                'delete_expired_transients' => $this->db->count_expired_transients(),
                'drop_tables_with_different_prefix' => array(
                    'count' => $this->db->count_tables_with_different_prefix(),
                    'table_list' => $this->db->list_tables_with_different_prefix(),
                    'prefix' => $wpdb->prefix,
                ),
                'convert_to_innodb' => array(
                    'count' => $this->db->count_myisam_tables(),
                    'table_list' => $this->db->list_myisam_tables(),
                ),
                'optimize_tables' => $this->db->count_tables(),
            ),
            'index_status' => $this->indexes->get_index_status(),
            'disabled_features' => array(),
            'hosted_by_templ' => $this->hosted_by_templ(),
        );
        if( ! $this->is_wp_rocket_enabled() ) {
            $settings['disabled_features'] []= 'wp_rocket_preload_interval';
        }
        return $settings;
    }

    /**
     * Determines whether the current user may use the plugin's REST routes.
     *
     * @return bool True if the user has the required capability.
     */
    function permission_check(): bool {
        return (bool) apply_filters('templ_optimizer_permission_check', current_user_can('manage_options'));
    }

    /**
     * Registers the plugin's REST API routes.
     *
     * Hooked to `rest_api_init`.
     *
     * @return void
     */
    function register_rest_routes(): void {
        register_rest_route( 'templ-optimizer/v1', '/get/', array(
            'methods' => 'GET',
            'permission_callback' => array($this, 'permission_check'),
            'callback' => array($this, 'get_settings'),
        ) );
        register_rest_route( 'templ-optimizer/v1', '/optimize-db/(?P<tool>.*)', array(
            'methods' => 'POST',
            'permission_callback' => array($this, 'permission_check'),
            'callback' => array($this, 'optimize_db'),
        ) );
        register_rest_route( 'templ-optimizer/v1', '/set/', array(
            'methods' => 'POST',
            'permission_callback' => array($this, 'permission_check'),
            'callback' => array($this, 'set_setting'),
        ) );
        register_rest_route( 'templ-optimizer/v1', '/index-status/', array(
            'methods' => 'GET',
            'permission_callback' => array($this, 'permission_check'),
            'callback' => array($this, 'get_index_status'),
        ) );
        register_rest_route( 'templ-optimizer/v1', '/index/(?P<action>enable|disable)/(?P<table>[a-z_]+)', array(
            'methods' => 'POST',
            'permission_callback' => array($this, 'permission_check'),
            'callback' => array($this, 'index_action'),
        ) );
        register_rest_route( 'templ-optimizer/v1', '/http-log/status/', array(
            'methods' => 'GET',
            'permission_callback' => array($this, 'permission_check'),
            'callback' => array($this, 'http_log_status'),
        ) );
        register_rest_route( 'templ-optimizer/v1', '/http-log/list/', array(
            'methods' => 'GET',
            'permission_callback' => array($this, 'permission_check'),
            'callback' => array($this, 'http_log_list'),
        ) );
        register_rest_route( 'templ-optimizer/v1', '/http-log/summary/', array(
            'methods' => 'GET',
            'permission_callback' => array($this, 'permission_check'),
            'callback' => array($this, 'http_log_summary'),
        ) );
        register_rest_route( 'templ-optimizer/v1', '/http-log/(?P<action>enable|disable|clear)/', array(
            'methods' => 'POST',
            'permission_callback' => array($this, 'permission_check'),
            'callback' => array($this, 'http_log_action'),
        ) );
    }

    /**
     * Returns the HTTP diagnostics capture status.
     *
     * REST callback for `GET /templ-optimizer/v1/http-log/status/`.
     *
     * @return array Capture status payload.
     */
    function http_log_status(): array {
        return $this->http_log->get_status();
    }

    /**
     * Returns a bounded, filtered page of captured calls.
     *
     * REST callback for `GET /templ-optimizer/v1/http-log/list/`.
     *
     * @param WP_REST_Request $req Request with filter query parameters.
     * @return array Query result payload.
     */
    function http_log_list( WP_REST_Request $req ): array {
        return $this->http_log->query( $req->get_query_params() );
    }

    /**
     * Returns aggregated captured calls grouped by host or caller.
     *
     * REST callback for `GET /templ-optimizer/v1/http-log/summary/`.
     *
     * @param WP_REST_Request $req Request with filter query parameters.
     * @return array Summary payload.
     */
    function http_log_summary( WP_REST_Request $req ): array {
        return $this->http_log->summary( $req->get_query_params() );
    }

    /**
     * Enables, disables or clears HTTP diagnostics capture.
     *
     * REST callback for `POST /templ-optimizer/v1/http-log/{action}/`.
     *
     * @param WP_REST_Request $req Request with the `action` param.
     * @return array Refreshed capture status.
     */
    function http_log_action( WP_REST_Request $req ): array {
        switch ( $req['action'] ) {
            case 'enable':
                $this->http_log->enable();
                break;
            case 'disable':
                $this->http_log->disable();
                break;
            case 'clear':
                $this->http_log->clear();
                break;
        }
        return $this->http_log->get_status();
    }

    /**
     * Returns the current high-performance index status.
     *
     * REST callback for `GET /templ-optimizer/v1/index-status/`.
     *
     * @return array Index status payload.
     */
    function get_index_status(): array {
        return $this->indexes->get_index_status();
    }

    /**
     * Enables or reverts high-performance keys on one table.
     *
     * REST callback for `POST /templ-optimizer/v1/index/{action}/{table}`. Large
     * tables are refused inline (they would exceed the request timeout) and the
     * WP-CLI command is returned instead.
     *
     * @param WP_REST_Request $req Request with `action` and `table` params.
     * @return array|WP_Error Action result plus the refreshed index status.
     */
    function index_action( WP_REST_Request $req ) {
        $action = $req['action'];
        $table  = $req['table'];

        // JSON/query parameters take precedence over route captures in WordPress.
        // Validate effective values before selecting an operation or typed call.
        if (!is_string($action) || !in_array($action, array('enable', 'disable'), true)
            || !is_string($table)) {
            return new WP_Error('invalid_index_parameters', 'Invalid index action or table.', array('status' => 400));
        }
        if( ! $this->indexes->is_supported( $table ) ) {
            return new WP_Error( 'unsupported_table', 'Unknown or unsupported table', array( 'status' => 400 ) );
        }

        $status = $this->indexes->get_index_status();

        // Both adding and reverting keys can rebuild the clustered primary
        // index and outlast an HTTP request. Keep both paths CLI-only at scale.
        if( isset( $status['tables'][ $table ] ) && $status['tables'][ $table ]['too_large'] ) {
            return array(
                'result' => array(
                    'slug'      => $table,
                    'too_large' => true,
                    'cli'       => "wp templ-optimizer indexes {$action} {$table}",
                ),
                'index_status' => $status,
            );
        }

        $result = $action === 'enable'
            ? $this->indexes->enable_keys( $table )
            : $this->indexes->disable_keys( $table );

        return array(
            'result'       => $result,
            'index_status' => $this->indexes->get_index_status(),
        );
    }

    /**
     * Shows a dashboard notice when high-performance keys have drifted.
     *
     * A WordPress or WooCommerce core upgrade can silently revert our keys to
     * WordPress defaults; this surfaces that so the user can re-apply. Hooked to
     * `admin_notices`.
     *
     * @return void
     */
    function index_drift_notice(): void {
        if( ! current_user_can( $this->capability ) ) {
            return;
        }
        // Cheap guard: only inspect the live schema when at least one table is
        // supposed to have high-performance keys.
        $state = $this->get_option( 'index_state' );
        if( ! is_array( $state ) || ! in_array( 'high_performance', $state, true ) ) {
            return;
        }
        $drifted = $this->indexes->drifted_tables();
        if( ! $drifted ) {
            return;
        }
        $url = esc_url( admin_url( $this->admin_page . '#/database-indexes' ) );
        printf(
            '<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
            esc_html__( 'Templ Optimizer: a recent update removed some high-performance database keys.', 'templ-optimizer' ),
            $url,
            esc_html__( 'Re-apply them', 'templ-optimizer' )
        );
    }

    /**
     * Runs a named database tool and returns the refreshed settings payload.
     *
     * REST callback for `POST /templ-optimizer/v1/optimize-db/{tool}`.
     *
     * @param WP_REST_Request $req Request whose `tool` param names a db method.
     * @return array|WP_Error Refreshed settings, or an error if the tool is invalid.
     */
    function optimize_db( WP_REST_Request $req ) {
        $tool = $req['tool'];
        $tools = array('delete_trashed_posts', 'delete_revisions', 'delete_auto_drafts',
            'delete_orphaned_postmeta', 'delete_expired_transients',
            'drop_tables_with_different_prefix', 'convert_to_innodb', 'optimize_tables', 'optimize_all');
        if (!is_string($tool) || !in_array($tool, $tools, true)) {
            return new WP_Error('invalid_tool', 'Unknown database tool.', array('status' => 400));
        }
        if ($tool === 'drop_tables_with_different_prefix' && $req->get_param('confirm') !== true) {
            return new WP_Error('confirmation_required', 'Explicit confirmation is required to drop foreign tables.', array('status' => 400));
        }
        try {
            $this->db->{$tool}();
        } catch (RuntimeException $error) {
            return new WP_Error('database_operation_failed', 'Database operation failed; no further operations were attempted.', array('status' => 500));
        }
        return $this->get_settings();
    }

    /**
     * Applies a single tweak setting from the request body.
     *
     * REST callback for `POST /templ-optimizer/v1/set/`.
     *
     * @param WP_REST_Request $req Request with `setting` and `value` JSON params.
     * @return mixed Result of the matching tweak setter.
     */
    function set_setting( WP_REST_Request $req ) {
        $data = $req->get_json_params();

        if (!is_array($data) || !isset($data['setting']) || !array_key_exists('value', $data)
            || !is_string($data['setting'])) {
            return new WP_Error('invalid_setting', 'A setting name and value are required.', array('status' => 400));
        }
        $setting = strtolower($data['setting']);
        $value = $data['value'];
        if (!templOptimizerTweaks::valid_setting($setting, $value)) {
            return new WP_Error('invalid_setting', 'Unsupported setting or value.', array('status' => 400));
        }
        $external_cron_confirmed = ($data['external_cron_confirmed'] ?? false) === true;
        if ($setting === 'disable_wp_cron' && $value === 'disabled' && !$external_cron_confirmed) {
            return new WP_Error('external_cron_confirmation_required', 'Configure and test a recurring external scheduler, then explicitly confirm it before disabling the automatic WP-Cron trigger.', array('status' => 400));
        }
        try {
            if ($setting === 'disable_wp_cron') {
                return $this->tweaks->set_disable_wp_cron($value, $external_cron_confirmed);
            }
            return $this->tweaks->{'set_' . $setting}($value);
        } catch (Throwable $error) {
            return new WP_Error('setting_write_failed', 'Unable to save this setting.', array('status' => 500));
        }
    }

    /**
     * Reads a single plugin setting from the stored options array.
     *
     * @param string $option_name Setting key to read.
     * @return mixed Stored value, or false if the key is absent.
     */
    function get_option( string $option_name ): mixed {
        $settings = get_option( 'templ_optimizer_settings', array() );
        if( ! array_key_exists( $option_name, $settings ) ) {
            return false;
        }
        return $settings[$option_name];
    }

    /**
     * Writes a single plugin setting into the stored options array.
     *
     * @param string $option_name Setting key to write.
     * @param mixed  $value       Value to store.
     * @return void
     */
    function update_option( string $option_name, $value ): void {
        $settings = get_option( 'templ_optimizer_settings' );
        $settings[$option_name] = $value;
        update_option( 'templ_optimizer_settings', $settings, true );
    }

    /**
     * Detects whether the site is hosted on the Templ platform.
     *
     * @return mixed Truthy when hosted by Templ; filterable via `hosted_by_templ`.
     */
    function hosted_by_templ(): mixed {
        $hosted_by_templ = isset($_SERVER['TEMPL_APP_ID']) || get_option('templio_app_id');
        return apply_filters('hosted_by_templ', $hosted_by_templ);
    }

    /**
     * Determines whether WP Rocket is active.
     *
     * @return bool True if the WP_ROCKET_VERSION constant is defined.
     */
    function is_wp_rocket_enabled(): bool {
        return defined('WP_ROCKET_VERSION');
    }

}

$templ_optimizer = new templOptimizer();
