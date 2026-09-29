<?php
/**
 * Outbound HTTP request diagnostics.
 *
 * Captures WordPress HTTP API calls (wp_remote_get()/wp_remote_post() and the
 * rest) into a dedicated, per-site database table so administrators can find
 * outbound calls that slow PHP requests down even when CPU load and database
 * query time look normal. Backs the admin app (via REST) and the WP-CLI command.
 *
 * Coverage is limited to the WordPress HTTP API after this plugin loads: direct
 * cURL, independent SDK transports and calls made before this plugin loads are
 * not captured. Durations are wall-clock timings of the WordPress request, never
 * total page-generation time. See docs/plans/http-request-diagnostics.md.
 *
 * @package Templ_Optimizer
 */

declare(strict_types=1);

defined('ABSPATH') or die();

/**
 * Captures, stores, queries and prunes outbound HTTP request records.
 *
 * Extends {@see templOptimizer} to reuse its option helpers; the empty
 * constructor deliberately skips the parent bootstrap so instantiating this
 * class does not register the plugin's hooks a second time. The main controller
 * arms {@see start_timer()} / {@see log_request()} only while capture is on.
 *
 * @since 2.4.0
 */
class templOptimizerHttpLog extends templOptimizer {

    /**
     * Bare table name (without the site prefix).
     *
     * @var string
     */
    const TABLE = 'templ_optimizer_http_log';

    /**
     * Schema version; bump to trigger a dbDelta migration.
     *
     * @var int
     */
    const SCHEMA_VERSION = 1;

    /**
     * Settings key holding the per-site capture on/off flag.
     *
     * @var string
     */
    const ENABLED_OPTION = 'http_log_enabled';

    /**
     * Settings key holding the last storage-failure message.
     *
     * @var string
     */
    const ERROR_OPTION = 'http_log_error';

    /**
     * Option holding the installed schema version.
     *
     * @var string
     */
    const SCHEMA_OPTION = 'templ_optimizer_http_log_schema';

    /**
     * Option holding the last prune unix timestamp (throttle backstop).
     *
     * @var string
     */
    const PRUNE_OPTION = 'templ_optimizer_http_log_pruned';

    /**
     * Cron hook that prunes expired/overflow records as a backstop.
     *
     * @var string
     */
    const CRON_HOOK = 'templ_optimizer_http_log_prune';

    /**
     * LIFO stack of pending request start times (microtime floats).
     *
     * Nested synchronous requests pair correctly because each request pushes in
     * `pre_http_request` and pops in `http_api_debug`.
     *
     * @var float[]
     */
    private $timers = array();

    /**
     * Per-execution identifier, generated once and reused for grouping.
     *
     * @var string|null
     */
    private $execution_id = null;

    /**
     * Re-entrancy guard so logging never records its own storage queries.
     *
     * @var bool
     */
    private $logging = false;

    /**
     * Instantiates the tool set without running the parent bootstrap.
     */
    function __construct() {}

    /**
     * Maximum record age in days before a record is eligible for pruning.
     *
     * @return int
     */
    public function max_age_days(): int {
        return max(1, (int) apply_filters('templ_optimizer_http_log_max_age_days', 7));
    }

    /**
     * Maximum number of retained records per site.
     *
     * @return int
     */
    public function max_rows(): int {
        return max(1, (int) apply_filters('templ_optimizer_http_log_max_rows', 100000));
    }

    /**
     * Fully-qualified log table name for the current site.
     *
     * @return string
     */
    public function table(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Whether capture is currently enabled for this site.
     *
     * @return bool
     */
    public function is_enabled(): bool {
        return (bool) $this->get_option(self::ENABLED_OPTION);
    }

    /**
     * Reports whether the plugin observed a storage failure and stopped capture.
     *
     * @return string Empty string, or the last stored failure message.
     */
    public function last_error(): string {
        $error = $this->get_option(self::ERROR_OPTION);
        return is_string($error) ? $error : '';
    }

    /**
     * Registers the capture hooks. Called by the main controller when enabled.
     *
     * `pre_http_request` runs at PHP_INT_MAX so it observes the final
     * short-circuit decision: it only arms a timer when no other plugin
     * short-circuits the request, keeping a 1:1 pairing with `http_api_debug`.
     *
     * @return void
     */
    public function arm(): void {
        add_filter('pre_http_request', array($this, 'start_timer'), PHP_INT_MAX, 3);
        add_action('http_api_debug', array($this, 'log_request'), PHP_INT_MAX, 5);
    }

    /**
     * Records a request start time unless the request was short-circuited.
     *
     * Hooked to `pre_http_request`; returns the preempt value unchanged.
     *
     * @param mixed  $preempt     Short-circuit response (false if none).
     * @param array  $parsed_args Request arguments (unused).
     * @param string $url         Request URL (unused).
     * @return mixed The unchanged preempt value.
     */
    public function start_timer($preempt, $parsed_args = array(), $url = '') {
        // A non-false preempt means another plugin served the request; WordPress
        // will not fire http_api_debug, so we must not arm a timer for it.
        if ($preempt === false) {
            $this->timers[] = microtime(true);
        }
        return $preempt;
    }

    /**
     * Stores a completed request. Hooked to `http_api_debug`.
     *
     * @param mixed  $response    WP_Error or the response array.
     * @param string $context     Debug context (always 'response' here).
     * @param string $class       Transport class name (unused).
     * @param array  $parsed_args Parsed request arguments.
     * @param string $url         Request URL.
     * @return void
     */
    public function log_request($response, $context = 'response', $class = '', $parsed_args = array(), $url = ''): void {

        $start = array_pop($this->timers);

        // Guard against re-entrancy and a disabled/failed state that may have
        // changed after hooks were armed.
        if ($this->logging || ! $this->is_enabled()) {
            return;
        }
        if (! is_array($parsed_args)) {
            $parsed_args = array();
        }

        $duration_ms = $start !== null ? (int) round((microtime(true) - $start) * 1000) : 0;
        $record = $this->build_record($response, $parsed_args, (string) $url, $duration_ms);

        $this->logging = true;
        try {
            $this->insert($record);
        } finally {
            $this->logging = false;
        }
    }

    /**
     * Builds a sanitized record array from a completed request.
     *
     * @param mixed  $response    WP_Error or response array.
     * @param array  $parsed_args Parsed request arguments.
     * @param string $url         Request URL.
     * @param int    $duration_ms Measured duration in milliseconds.
     * @return array<string,mixed>
     */
    public function build_record($response, array $parsed_args, string $url, int $duration_ms): array {

        $method   = isset($parsed_args['method']) ? strtoupper((string) $parsed_args['method']) : 'GET';
        $blocking = array_key_exists('blocking', $parsed_args) ? (bool) $parsed_args['blocking'] : true;

        list($host, $path) = $this->split_url($url);

        if (is_wp_error($response)) {
            $status  = null;
            $outcome = 'error';
        } else {
            $status  = (int) wp_remote_retrieve_response_code($response);
            $outcome = $this->classify_status($status);
            if ($status === 0) {
                $status = null;
            }
        }

        return array(
            'logged_at'    => gmdate('Y-m-d H:i:s'),
            'duration_ms'  => max(0, $duration_ms),
            'method'       => substr(preg_replace('/[^A-Z]/', '', $method) ?: 'GET', 0, 10),
            'host'         => $host,
            'path'         => $path,
            'status_code'  => $status,
            'outcome'      => $outcome,
            'blocking'     => $blocking ? 1 : 0,
            'context'      => $this->detect_context(),
            'request_uri'  => $this->request_uri(),
            'caller'       => $this->detect_caller(),
            'execution_id' => $this->execution_id(),
        );
    }

    /**
     * Classifies an HTTP status code into a coarse, safe outcome label.
     *
     * @param int $status HTTP status code (0 when unknown).
     * @return string One of success|redirect|client_error|server_error|other.
     */
    public function classify_status(int $status): string {
        if ($status >= 200 && $status < 300) {
            return 'success';
        }
        if ($status >= 300 && $status < 400) {
            return 'redirect';
        }
        if ($status >= 400 && $status < 500) {
            return 'client_error';
        }
        if ($status >= 500 && $status < 600) {
            return 'server_error';
        }
        return 'other';
    }

    /**
     * Splits a URL into host and redacted path, discarding credentials/query.
     *
     * Query values are never stored. Recognizably sensitive path segments
     * (emails, token-like strings) are replaced with `***` before storage;
     * arbitrary paths may still contain personal data.
     *
     * @param string $url Request URL.
     * @return array{0:string,1:string} Host and redacted path.
     */
    public function split_url(string $url): array {
        $parts = wp_parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return array('invalid', '');
        }
        $host = substr(strtolower((string) $parts['host']), 0, 255);
        $path = isset($parts['path']) ? (string) $parts['path'] : '/';
        $path = $this->redact_path($path);
        return array($host, substr($path, 0, 255));
    }

    /**
     * Redacts recognizably sensitive segments of a URL path.
     *
     * @param string $path URL path (no query string).
     * @return string Redacted path.
     */
    public function redact_path(string $path): string {
        $segments = explode('/', $path);
        foreach ($segments as $i => $segment) {
            if ($segment === '') {
                continue;
            }
            // Email addresses.
            if (strpos($segment, '@') !== false && preg_match('/[^@\s]+@[^@\s]+/', $segment)) {
                $segments[$i] = '***';
                continue;
            }
            // Token-like: long, mixed alphanumeric or long hex/base64-ish runs.
            if (preg_match('/[A-Za-z0-9_\-]{20,}/', $segment)) {
                $segments[$i] = '***';
            }
        }
        return implode('/', $segments);
    }

    /**
     * Detects the execution context of the current request.
     *
     * @return string frontend|admin|rest|ajax|cron|cli|xmlrpc.
     */
    public function detect_context(): string {
        $flag = static function (string $const): bool {
            return defined($const) && constant($const);
        };
        if ($flag('WP_CLI')) {
            return 'cli';
        }
        if (wp_doing_cron()) {
            return 'cron';
        }
        if ($flag('REST_REQUEST')) {
            return 'rest';
        }
        if (wp_doing_ajax()) {
            return 'ajax';
        }
        if ($flag('XMLRPC_REQUEST')) {
            return 'xmlrpc';
        }
        if (is_admin()) {
            return 'admin';
        }
        return 'frontend';
    }

    /**
     * Returns the sanitized originating request path (no query string).
     *
     * @return string
     */
    public function request_uri(): string {
        if (empty($_SERVER['REQUEST_URI'])) {
            return '';
        }
        $uri  = (string) wp_unslash($_SERVER['REQUEST_URI']);
        $path = explode('?', $uri, 2)[0];
        return substr($this->redact_path($path), 0, 255);
    }

    /**
     * Best-effort attribution of the responsible plugin, theme or core.
     *
     * Walks a bounded, argument-free backtrace for the first frame owned by a
     * plugin/theme other than this one. Returns 'unknown' when no attributable
     * frame is found rather than blaming the wrong component.
     *
     * @param array<int,array<string,mixed>>|null $frames Backtrace frames to
     *              inspect; defaults to the live call stack. Injectable for tests.
     * @return string e.g. plugins/woocommerce, themes/foo, mu-plugins/x.php,
     *                core, or unknown.
     */
    public function detect_caller(?array $frames = null): string {

        if ($frames === null) {
            $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30);
        }
        $self     = wp_normalize_path(TEMPL_OPTIMIZER_PATH);
        $plugins  = defined('WP_PLUGIN_DIR') ? wp_normalize_path(WP_PLUGIN_DIR) : '';
        $mu       = defined('WPMU_PLUGIN_DIR') ? wp_normalize_path(WPMU_PLUGIN_DIR) : '';
        $themes   = function_exists('get_theme_root') ? wp_normalize_path(get_theme_root()) : '';
        $includes = wp_normalize_path(ABSPATH . WPINC);
        $admin    = wp_normalize_path(ABSPATH . 'wp-admin');
        $core_hit = false;

        foreach ($frames as $frame) {
            if (empty($frame['file'])) {
                continue;
            }
            $file = wp_normalize_path($frame['file']);

            if ($self !== '' && strpos($file, $self) === 0) {
                continue; // Never attribute to ourselves.
            }
            if ($mu !== '' && strpos($file, $mu) === 0) {
                return 'mu-plugins/' . $this->first_segment(substr($file, strlen($mu) + 1));
            }
            if ($plugins !== '' && strpos($file, $plugins) === 0) {
                return 'plugins/' . $this->first_segment(substr($file, strlen($plugins) + 1));
            }
            if ($themes !== '' && strpos($file, $themes) === 0) {
                return 'themes/' . $this->first_segment(substr($file, strlen($themes) + 1));
            }
            if (($includes !== '' && strpos($file, $includes) === 0) || ($admin !== '' && strpos($file, $admin) === 0)) {
                $core_hit = true; // Remember, but prefer a plugin/theme frame.
            }
        }

        return $core_hit ? 'core' : 'unknown';
    }

    /**
     * Returns the first path segment (a plugin/theme slug or file name).
     *
     * @param string $relative Path relative to a plugin/theme root.
     * @return string
     */
    private function first_segment(string $relative): string {
        $relative = ltrim($relative, '/');
        $segment  = explode('/', $relative)[0];
        return substr(sanitize_text_field($segment), 0, 160);
    }

    /**
     * Returns (and lazily generates) the per-execution identifier.
     *
     * @return string 32-character hex string.
     */
    public function execution_id(): string {
        if ($this->execution_id === null) {
            $this->execution_id = md5(uniqid((string) mt_rand(), true));
        }
        return $this->execution_id;
    }

    /**
     * Ensures the log table exists and is up to date, once per version.
     *
     * @return void
     */
    public function maybe_create_table(): void {
        if ((int) get_option(self::SCHEMA_OPTION) === self::SCHEMA_VERSION) {
            return;
        }
        $this->create_table();
        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
    }

    /**
     * Creates or migrates the log table via dbDelta.
     *
     * @return void
     */
    public function create_table(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table   = $this->table();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            logged_at DATETIME NOT NULL,
            duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
            method VARCHAR(10) NOT NULL DEFAULT 'GET',
            host VARCHAR(255) NOT NULL DEFAULT '',
            path VARCHAR(255) NOT NULL DEFAULT '',
            status_code SMALLINT UNSIGNED NULL DEFAULT NULL,
            outcome VARCHAR(20) NOT NULL DEFAULT 'other',
            blocking TINYINT(1) NOT NULL DEFAULT 1,
            context VARCHAR(20) NOT NULL DEFAULT '',
            request_uri VARCHAR(255) NOT NULL DEFAULT '',
            caller VARCHAR(191) NOT NULL DEFAULT 'unknown',
            execution_id CHAR(32) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY logged_at (logged_at),
            KEY duration_ms (duration_ms),
            KEY host (host),
            KEY execution_id (execution_id)
        ) {$charset};";
        dbDelta($sql);
    }

    /**
     * Inserts one record, stopping capture on storage failure.
     *
     * @param array<string,mixed> $record Sanitized record.
     * @return bool True on success.
     */
    private function insert(array $record): bool {
        global $wpdb;

        $this->maybe_create_table();

        $formats = array('%s', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s');
        if ($record['status_code'] === null) {
            unset($record['status_code']);
            $formats = array('%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s');
        }

        $hidden = $wpdb->hide_errors();
        $result = $wpdb->insert($this->table(), $record, $formats);
        if ($result === false) {
            // The table may have been dropped out from under us; recreate once
            // and retry a single time before giving up.
            $this->create_table();
            $result = $wpdb->insert($this->table(), $record, $formats);
        }
        $wpdb->show_errors($hidden);

        if ($result === false) {
            // Stop capture and require a manual restart; never retry in a loop.
            $this->update_option(self::ERROR_OPTION, 'Storage failure: capture stopped. Restart it after investigating database health.');
            $this->update_option(self::ENABLED_OPTION, false);
            return false;
        }

        $this->maybe_prune();
        return true;
    }

    /**
     * Prunes at most once per throttle window (default 300s) after inserts.
     *
     * @return void
     */
    private function maybe_prune(): void {
        $window = (int) apply_filters('templ_optimizer_http_log_prune_interval', 300);
        $last   = (int) get_option(self::PRUNE_OPTION);
        if ((time() - $last) < $window) {
            return;
        }
        update_option(self::PRUNE_OPTION, time(), false);
        $this->prune();
    }

    /**
     * Removes expired and overflow records. Safe to call directly (cron).
     *
     * @return int Number of rows removed.
     */
    public function prune(): int {
        global $wpdb;
        $table   = $this->table();
        $removed = 0;

        $cutoff  = gmdate('Y-m-d H:i:s', time() - ($this->max_age_days() * DAY_IN_SECONDS));
        $hidden  = $wpdb->hide_errors();
        $aged    = $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE logged_at < %s", $cutoff));
        if (is_int($aged)) {
            $removed += $aged;
        }

        // Enforce the row cap by dropping everything older than the newest N ids.
        $max   = $this->max_rows();
        $floor = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d",
            $max
        ));
        if ($floor !== null) {
            $over = $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id <= %d", (int) $floor));
            if (is_int($over)) {
                $removed += $over;
            }
        }
        $wpdb->show_errors($hidden);

        return $removed;
    }

    /**
     * Enables capture: creates the table, clears any error, schedules pruning.
     *
     * @return void
     */
    public function enable(): void {
        // Create unconditionally: the schema-version guard can be set while the
        // table itself is absent (e.g. after an external drop).
        $this->create_table();
        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
        $this->update_option(self::ERROR_OPTION, '');
        $this->update_option(self::ENABLED_OPTION, true);
        if (function_exists('wp_next_scheduled') && ! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    /**
     * Disables capture without deleting records; unschedules pruning.
     *
     * @return void
     */
    public function disable(): void {
        $this->update_option(self::ENABLED_OPTION, false);
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }
    }

    /**
     * Deletes all stored records for this site, preserving capture settings.
     *
     * @return void
     */
    public function clear(): void {
        global $wpdb;
        if (! $this->table_exists()) {
            return;
        }
        $wpdb->query('DELETE FROM ' . $this->table());
        update_option(self::PRUNE_OPTION, time(), false);
    }

    /**
     * Whether the log table currently exists.
     *
     * @return bool
     */
    public function table_exists(): bool {
        global $wpdb;
        $table = $this->table();
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    /**
     * Allowed columns for sorting, mapped to their real column names.
     *
     * @var array<string,string>
     */
    private $orderable = array(
        'duration' => 'duration_ms',
        'time'     => 'logged_at',
    );

    /**
     * Returns the capture status payload for the admin app and CLI.
     *
     * @return array<string,mixed>
     */
    public function get_status(): array {
        global $wpdb;

        $count  = 0;
        $oldest = null;
        $newest = null;
        if ($this->table_exists()) {
            $table  = $this->table();
            $count  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            $oldest = $wpdb->get_var("SELECT MIN(logged_at) FROM {$table}");
            $newest = $wpdb->get_var("SELECT MAX(logged_at) FROM {$table}");
        }

        return array(
            'enabled'       => $this->is_enabled(),
            'error'         => $this->last_error(),
            'count'         => $count,
            'oldest'        => $oldest,
            'newest'        => $newest,
            'max_age_days'  => $this->max_age_days(),
            'max_rows'      => $this->max_rows(),
        );
    }

    /**
     * Normalizes and clamps a filter set from untrusted input.
     *
     * @param array<string,mixed> $filters Raw filters.
     * @return array<string,mixed> Clean filters.
     */
    public function normalize_filters(array $filters): array {
        // Reused helpers keep the branch/path count low: one conditional each,
        // exercised repeatedly, instead of a dozen distinct ternaries.
        $raw  = static function (string $key) use ($filters) {
            return array_key_exists($key, $filters) ? $filters[$key] : null;
        };
        $text = static function (string $key, int $len) use ($raw) {
            $value = $raw($key);
            return $value === null ? '' : substr(sanitize_text_field((string) $value), 0, $len);
        };

        $orderby = isset($this->orderable[(string) $raw('orderby')]) ? (string) $raw('orderby') : 'duration';
        $order   = strtolower((string) $raw('order')) === 'asc' ? 'ASC' : 'DESC';
        $per     = max(1, min(500, (int) $raw('per_page') ?: 50));
        $page    = max(1, (int) $raw('page') ?: 1);

        return array(
            'orderby'      => $orderby,
            'order'        => $order,
            'per_page'     => $per,
            'page'         => $page,
            'min_duration' => max(0, (int) $raw('min_duration')),
            'host'         => $text('host', 255),
            'caller'       => $text('caller', 191),
            'context'      => substr(sanitize_key((string) $raw('context')), 0, 20),
            'outcome'      => substr(sanitize_key((string) $raw('outcome')), 0, 20),
            'execution'    => preg_replace('/[^a-f0-9]/', '', (string) $raw('execution')),
            'since'        => $this->clean_datetime((string) $raw('since')),
            'until'        => $this->clean_datetime((string) $raw('until')),
        );
    }

    /**
     * Validates a `Y-m-d H:i:s` UTC datetime string, or returns ''.
     *
     * @param string $value Candidate datetime.
     * @return string
     */
    private function clean_datetime(string $value): string {
        $value = trim($value);
        $dt    = DateTime::createFromFormat('Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if ($dt && $dt->format('Y-m-d H:i:s') === $value) {
            return $value;
        }
        return '';
    }

    /**
     * Builds the shared WHERE clause and bound parameters for a filter set.
     *
     * @param array<string,mixed> $f Normalized filters.
     * @return array{0:string,1:array<int,mixed>} SQL fragment and params.
     */
    private function where(array $f): array {
        $where  = array();
        $params = array();
        // Each row: [is-active, SQL fragment, bound value]. One loop with a
        // single conditional keeps the path count flat regardless of filter mix.
        $specs = array(
            array($f['min_duration'] > 0, 'duration_ms >= %d', $f['min_duration']),
            array($f['host'] !== '',      'host = %s',         $f['host']),
            array($f['caller'] !== '',    'caller = %s',       $f['caller']),
            array($f['context'] !== '',   'context = %s',      $f['context']),
            array($f['outcome'] !== '',   'outcome = %s',      $f['outcome']),
            array($f['execution'] !== '', 'execution_id = %s', $f['execution']),
            array($f['since'] !== '',     'logged_at >= %s',   $f['since']),
            array($f['until'] !== '',     'logged_at <= %s',   $f['until']),
        );
        foreach ($specs as $spec) {
            if ($spec[0]) {
                $where[]  = $spec[1];
                $params[] = $spec[2];
            }
        }
        $sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        return array($sql, $params);
    }

    /**
     * Runs a bounded, filtered, sorted query and returns rows plus totals.
     *
     * @param array<string,mixed> $filters Raw filters.
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,per_page:int}
     */
    public function query(array $filters): array {
        global $wpdb;

        $f = $this->normalize_filters($filters);
        if (! $this->table_exists()) {
            return array('rows' => array(), 'total' => 0, 'page' => $f['page'], 'per_page' => $f['per_page']);
        }

        $table              = $this->table();
        list($where, $args) = $this->where($f);

        $total = (int) $wpdb->get_var(
            $args ? $wpdb->prepare("SELECT COUNT(*) FROM {$table}{$where}", $args) : "SELECT COUNT(*) FROM {$table}"
        );

        $column = $this->orderable[$f['orderby']];
        $offset = ($f['page'] - 1) * $f['per_page'];
        // $column/$order come from server-side allowlists, never user text.
        $sql    = "SELECT * FROM {$table}{$where} ORDER BY {$column} {$f['order']}, id {$f['order']} LIMIT %d OFFSET %d";
        $rows   = $wpdb->get_results(
            $wpdb->prepare($sql, array_merge($args, array($f['per_page'], $offset))),
            ARRAY_A
        );

        return array(
            'rows'     => array_map(array($this, 'shape_row'), $rows ?: array()),
            'total'    => $total,
            'page'     => $f['page'],
            'per_page' => $f['per_page'],
        );
    }

    /**
     * Fetches one shaped record by id, or null when absent.
     *
     * @param int $id Record id.
     * @return array<string,mixed>|null
     */
    public function get_by_id(int $id): ?array {
        global $wpdb;
        if (! $this->table_exists()) {
            return null;
        }
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id),
            ARRAY_A
        );
        return $row ? $this->shape_row($row) : null;
    }

    /**
     * Casts a raw database row into typed, display-ready fields.
     *
     * @param array<string,mixed> $row Raw row.
     * @return array<string,mixed>
     */
    private function shape_row(array $row): array {
        return array(
            'id'           => (int) $row['id'],
            'logged_at'    => $row['logged_at'],
            'duration_ms'  => (int) $row['duration_ms'],
            'method'       => $row['method'],
            'host'         => $row['host'],
            'path'         => $row['path'],
            'status_code'  => $row['status_code'] === null ? null : (int) $row['status_code'],
            'outcome'      => $row['outcome'],
            'blocking'     => (int) $row['blocking'] === 1,
            'context'      => $row['context'],
            'request_uri'  => $row['request_uri'],
            'caller'       => $row['caller'],
            'execution_id' => $row['execution_id'],
        );
    }

    /**
     * Aggregates captured calls by host or caller for the summary view.
     *
     * Cumulative blocking wait counts blocking calls only, so non-blocking
     * dispatch timings never inflate the totals.
     *
     * @param array<string,mixed> $filters Raw filters (group_by plus any list filters).
     * @return array{group_by:string,rows:array<int,array<string,mixed>>}
     */
    public function summary(array $filters): array {
        global $wpdb;

        $group = isset($filters['group_by']) && $filters['group_by'] === 'caller' ? 'caller' : 'host';
        if (! $this->table_exists()) {
            return array('group_by' => $group, 'rows' => array());
        }

        $f                  = $this->normalize_filters($filters);
        $table              = $this->table();
        list($where, $args) = $this->where($f);

        // $group is an allowlisted column name.
        $sql = "SELECT {$group} AS name,
                    COUNT(*) AS calls,
                    SUM(CASE WHEN blocking = 1 THEN duration_ms ELSE 0 END) AS blocking_ms,
                    MAX(duration_ms) AS max_ms,
                    SUM(CASE WHEN outcome IN ('error','server_error','client_error') THEN 1 ELSE 0 END) AS failures
                FROM {$table}{$where}
                GROUP BY {$group}
                ORDER BY blocking_ms DESC, calls DESC
                LIMIT 50";

        $rows = $wpdb->get_results($args ? $wpdb->prepare($sql, $args) : $sql, ARRAY_A);

        $out = array();
        foreach ($rows ?: array() as $row) {
            $out[] = array(
                'name'        => $row['name'],
                'calls'       => (int) $row['calls'],
                'blocking_ms' => (int) $row['blocking_ms'],
                'max_ms'      => (int) $row['max_ms'],
                'failures'    => (int) $row['failures'],
            );
        }
        return array('group_by' => $group, 'rows' => $out);
    }

    /**
     * Drops the log table. Used by uninstall only.
     *
     * @return void
     */
    public function drop_table(): void {
        global $wpdb;
        $wpdb->query('DROP TABLE IF EXISTS ' . $this->table());
        delete_option(self::SCHEMA_OPTION);
        delete_option(self::PRUNE_OPTION);
    }

}
