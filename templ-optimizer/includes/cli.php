<?php
/**
 * WP-CLI integration.
 *
 * Exposes the plugin's database cleanup/optimization tools and wp-config tweaks
 * as documented WP-CLI subcommands under `wp templ-optimizer`.
 *
 * The command tree mirrors the admin app and REST surface:
 *
 *   wp templ-optimizer db status
 *   wp templ-optimizer db size
 *   wp templ-optimizer db list <target>
 *   wp templ-optimizer db clean [<target>...] [--all] [--dry-run] [--yes]
 *   wp templ-optimizer db convert-innodb [--dry-run] [--yes]
 *   wp templ-optimizer db optimize-tables [--yes]
 *   wp templ-optimizer db optimize-all [--dry-run] [--yes]
 *
 *   wp templ-optimizer tweak list
 *   wp templ-optimizer tweak get <key>
 *   wp templ-optimizer tweak set <key> <value> [--yes]
 *
 *   wp templ-optimizer indexes status
 *   wp templ-optimizer indexes enable [<table>...] [--all] [--dry-run] [--yes]
 *   wp templ-optimizer indexes disable [<table>...] [--all] [--dry-run] [--yes]
 *
 *   wp templ-optimizer http enable
 *   wp templ-optimizer http disable
 *   wp templ-optimizer http status
 *   wp templ-optimizer http list [--min-duration-ms=<ms>] [--host=<host>] ...
 *   wp templ-optimizer http show <id>
 *   wp templ-optimizer http summary [--group-by=<host|caller>]
 *   wp templ-optimizer http clear [--yes]
 *
 * Full per-subcommand help (OPTIONS + EXAMPLES) is available via
 * `wp help templ-optimizer db <subcommand>`.
 *
 * @package Templ_Optimizer
 */

declare(strict_types=1);

defined('ABSPATH') or die();

/**
 * Registers the plugin's WP-CLI command namespaces.
 *
 * Instantiates the database and tweak tool sets and injects them into the two
 * command classes below. Kept deliberately thin: it only wires objects to
 * `WP_CLI::add_command()`; all command logic lives in the command classes.
 *
 * @since 1.1.0
 */
class templOptimizerCli {

    /**
     * Builds the tool sets and registers the `db` and `tweak` command namespaces.
     */
    function __construct() {

        // Reuse the same tool classes the REST layer uses; their constructors
        // deliberately skip the plugin bootstrap so no hooks are re-registered.
        $db       = new templOptimizerDb();
        $tweaks   = new templOptimizerTweaks();
        $indexes  = new templOptimizerIndexes();
        $http_log = new templOptimizerHttpLog();

        WP_CLI::add_command( 'templ-optimizer db',      new Templ_Optimizer_DB_Command( $db ) );
        WP_CLI::add_command( 'templ-optimizer tweak',   new Templ_Optimizer_Tweak_Command( $tweaks ) );
        WP_CLI::add_command( 'templ-optimizer indexes', new Templ_Optimizer_Indexes_Command( $indexes ) );
        WP_CLI::add_command( 'templ-optimizer http',    new Templ_Optimizer_Http_Command( $http_log ) );

    }

}

/**
 * `wp templ-optimizer db` — database inspection, cleanup and optimization.
 *
 * @since 2.3.0
 */
class Templ_Optimizer_DB_Command {

    /**
     * Database tool set.
     *
     * @var templOptimizerDb
     */
    private $db;

    /**
     * Cleanup target registry.
     *
     * Each key is a hyphenated CLI target name mapping to the count method used
     * for previews, the mutation method that performs the removal, a human
     * label, and whether the operation drops tables (extra-destructive).
     *
     * @var array<string,array{count:string,run:string,label:string,destructive:bool}>
     */
    private $clean_targets = array(
        'trashed-posts'      => array( 'count' => 'count_trashed_posts',                 'run' => 'delete_trashed_posts',                 'label' => 'trashed posts',          'destructive' => false ),
        'revisions'          => array( 'count' => 'count_revisions',                     'run' => 'delete_revisions',                     'label' => 'post revisions',         'destructive' => false ),
        'auto-drafts'        => array( 'count' => 'count_auto_drafts',                   'run' => 'delete_auto_drafts',                   'label' => 'auto-draft posts',       'destructive' => false ),
        'orphaned-postmeta'  => array( 'count' => 'count_orphaned_postmeta',             'run' => 'delete_orphaned_postmeta',             'label' => 'orphaned postmeta rows', 'destructive' => false ),
        'expired-transients' => array( 'count' => 'count_expired_transients',            'run' => 'delete_expired_transients',            'label' => 'expired transients',     'destructive' => false ),
        'foreign-tables'     => array( 'count' => 'count_tables_with_different_prefix',  'run' => 'drop_tables_with_different_prefix',     'label' => 'foreign-prefixed tables','destructive' => true  ),
    );

    /**
     * @param templOptimizerDb $db Database tool set.
     */
    public function __construct( templOptimizerDb $db ) {
        $this->db = $db;
    }

    /**
     * Shows a status overview of the database: size and every cleanable count.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Render output in a particular format.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     # Human-readable overview.
     *     $ wp templ-optimizer db status
     *
     *     # Machine-readable for scripting.
     *     $ wp templ-optimizer db status --format=json
     *
     * @when after_wp_load
     *
     * @param array $args       Positional arguments (unused).
     * @param array $assoc_args Flags: --format.
     * @return void
     */
    public function status( array $args, array $assoc_args ): void {

        $format = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

        $items = array(
            array( 'item' => 'database_size',      'value' => $this->db->get_database_size() ),
            array( 'item' => 'total_tables',       'value' => $this->db->count_tables() ),
            array( 'item' => 'trashed_posts',      'value' => $this->db->count_trashed_posts() ),
            array( 'item' => 'revisions',          'value' => $this->db->count_revisions() ),
            array( 'item' => 'auto_drafts',        'value' => $this->db->count_auto_drafts() ),
            array( 'item' => 'orphaned_postmeta',  'value' => $this->db->count_orphaned_postmeta() ),
            array( 'item' => 'expired_transients', 'value' => $this->db->count_expired_transients() ),
            array( 'item' => 'foreign_tables',     'value' => $this->db->count_tables_with_different_prefix() ),
            array( 'item' => 'myisam_tables',      'value' => $this->db->count_myisam_tables() ),
        );

        WP_CLI\Utils\format_items( $format, $items, array( 'item', 'value' ) );

    }

    /**
     * Prints the total on-disk size of the database.
     *
     * ## EXAMPLES
     *
     *     $ wp templ-optimizer db size
     *     42.7MB
     *
     * @when after_wp_load
     *
     * @param array $args       Positional arguments (unused).
     * @param array $assoc_args Flags (unused).
     * @return void
     */
    public function size( array $args, array $assoc_args ): void {
        WP_CLI::log( $this->db->get_database_size() );
    }

    /**
     * Lists the tables behind a countable target.
     *
     * ## OPTIONS
     *
     * <target>
     * : Which set of tables to list.
     * ---
     * options:
     *   - foreign-tables
     *   - myisam
     * ---
     *
     * [--format=<format>]
     * : Render output in a particular format.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     # Tables not matching the site's base table prefix.
     *     $ wp templ-optimizer db list foreign-tables
     *
     *     # Tables still on the MyISAM engine.
     *     $ wp templ-optimizer db list myisam --format=csv
     *
     * @subcommand list
     * @when after_wp_load
     *
     * @param array $args       Positional: <target>.
     * @param array $assoc_args Flags: --format.
     * @return void
     */
    public function list_( array $args, array $assoc_args ): void {

        $target = $args[0];
        $format = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

        switch ( $target ) {
            case 'foreign-tables':
                $csv = $this->db->list_tables_with_different_prefix();
                break;
            case 'myisam':
                $csv = $this->db->list_myisam_tables();
                break;
            default:
                WP_CLI::error( "Unknown target '{$target}'. Valid: foreign-tables, myisam." );
                return;
        }

        // The db tools return a comma-separated string; normalize to a list.
        $names = array_values( array_filter( array_map( 'trim', explode( ',', $csv ) ) ) );

        if ( empty( $names ) ) {
            WP_CLI::success( 'No matching tables found.' );
            return;
        }

        $items = array_map( fn( $name ) => array( 'table' => $name ), $names );
        WP_CLI\Utils\format_items( $format, $items, array( 'table' ) );

    }

    /**
     * Removes unneeded database content for one or more targets.
     *
     * Runs a count preview for each target first. Use --dry-run to preview only.
     * Destructive targets (dropping foreign-prefixed tables) require confirmation
     * unless --yes is passed.
     *
     * ## OPTIONS
     *
     * [<target>...]
     * : One or more content types to clean. Omit when using --all.
     * ---
     * options:
     *   - trashed-posts
     *   - revisions
     *   - auto-drafts
     *   - orphaned-postmeta
     *   - expired-transients
     *   - foreign-tables
     * ---
     *
     * [--all]
     * : Clean ordinary targets; foreign-tables must be selected explicitly.
     *
     * [--dry-run]
     * : Count what would be removed without making changes.
     *
     * [--yes]
     * : Answer yes to the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     # Preview what a full clean would remove.
     *     $ wp templ-optimizer db clean --all --dry-run
     *
     *     # Delete revisions and auto-drafts without prompting.
     *     $ wp templ-optimizer db clean revisions auto-drafts --yes
     *
     *     # Drop tables left behind by an old prefix (asks to confirm).
     *     $ wp templ-optimizer db clean foreign-tables
     *
     * @when after_wp_load
     *
     * @param array $args       Positional: zero or more <target> names.
     * @param array $assoc_args Flags: --all, --dry-run, --yes.
     * @return void
     */
    public function clean( array $args, array $assoc_args ): void {

        $all      = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'all', false );
        $dry_run  = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
        $valid    = implode( ', ', array_keys( $this->clean_targets ) );

        if ($all && $args) {
            WP_CLI::error('Use either explicit targets or --all, not both.');
        }
        // Foreign tables may belong to another application; never include implicitly.
        $targets = $all ? array_values(array_diff(array_keys($this->clean_targets), array('foreign-tables'))) : $args;

        if ( empty( $targets ) ) {
            WP_CLI::error( "Specify one or more targets, or use --all. Valid targets: {$valid}." );
        }

        // Validate before touching the database so a typo aborts up front.
        foreach ( $targets as $target ) {
            if ( ! isset( $this->clean_targets[ $target ] ) ) {
                WP_CLI::error( "Unknown target '{$target}'. Valid targets: {$valid}." );
            }
        }

        // Preview: count each target and note whether any target drops tables.
        $total       = 0;
        $destructive = false;
        foreach ( $targets as $target ) {
            $spec  = $this->clean_targets[ $target ];
            $count = (int) call_user_func( array( $this->db, $spec['count'] ) );
            $total += $count;
            $destructive = $destructive || $spec['destructive'];
            WP_CLI::log( sprintf( '%-20s %6d %s', $target, $count, $spec['label'] ) );
        }

        if ( $dry_run ) {
            WP_CLI::success( "Dry run: {$total} item(s) would be removed. No changes made." );
            return;
        }

        if ( 0 === $total ) {
            WP_CLI::success( 'Nothing to clean.' );
            return;
        }

        WP_CLI::confirm(
            sprintf(
                'Remove %d item(s) across %d target(s)%s?',
                $total,
                count( $targets ),
                $destructive ? ' — this includes DROPPING tables' : ''
            ),
            $assoc_args
        );

        $removed = 0;
        foreach ( $targets as $target ) {
            $spec = $this->clean_targets[ $target ];
            $rows = (int) call_user_func( array( $this->db, $spec['run'] ) );
            $removed += $rows;
            WP_CLI::log( sprintf( 'Cleaned %s: %d removed.', $target, $rows ) );
        }

        WP_CLI::success( "Removed {$removed} item(s)." );

    }

    /**
     * Converts every MyISAM table to the InnoDB storage engine.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : List the tables that would be converted without changing them.
     *
     * [--yes]
     * : Answer yes to the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     $ wp templ-optimizer db convert-innodb --dry-run
     *     $ wp templ-optimizer db convert-innodb --yes
     *
     * @subcommand convert-innodb
     * @when after_wp_load
     *
     * @param array $args       Positional arguments (unused).
     * @param array $assoc_args Flags: --dry-run, --yes.
     * @return void
     */
    public function convert_innodb( array $args, array $assoc_args ): void {

        $dry_run = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
        $count   = $this->db->count_myisam_tables();

        if ( 0 === $count ) {
            WP_CLI::success( 'No MyISAM tables found.' );
            return;
        }

        WP_CLI::log( sprintf( '%d MyISAM table(s): %s', $count, $this->db->list_myisam_tables() ) );

        if ( $dry_run ) {
            WP_CLI::success( "Dry run: {$count} table(s) would be converted to InnoDB." );
            return;
        }

        WP_CLI::confirm( sprintf( 'Convert %d table(s) to InnoDB?', $count ), $assoc_args );

        $converted = $this->db->convert_to_innodb();
        WP_CLI::success( "Converted {$converted} table(s) to InnoDB." );

    }

    /**
     * Runs OPTIMIZE TABLE against every table in the database.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Answer yes to the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     $ wp templ-optimizer db optimize-tables --yes
     *
     * @subcommand optimize-tables
     * @when after_wp_load
     *
     * @param array $args       Positional arguments (unused).
     * @param array $assoc_args Flags: --yes.
     * @return void
     */
    public function optimize_tables( array $args, array $assoc_args ): void {

        $count = $this->db->count_tables();

        WP_CLI::confirm( sprintf( 'Run OPTIMIZE TABLE on %d table(s)?', $count ), $assoc_args );

        $optimized = $this->db->optimize_tables();
        WP_CLI::success( "Optimized {$optimized} table(s)." );

    }

    /**
     * Runs the full cleanup and optimization sequence.
     *
     * Equivalent to the admin app's one-click optimize: deletes trashed posts,
     * revisions, auto-drafts, orphaned postmeta and expired transients; drops
     * no foreign-prefixed tables; converts MyISAM to InnoDB; and optimizes tables.
     * Reports database size before and after.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Preview every count and the current size without making changes.
     *
     * [--yes]
     * : Answer yes to the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     $ wp templ-optimizer db optimize-all --dry-run
     *     $ wp templ-optimizer db optimize-all --yes
     *
     * @subcommand optimize-all
     * @when after_wp_load
     *
     * @param array $args       Positional arguments (unused).
     * @param array $assoc_args Flags: --dry-run, --yes.
     * @return void
     */
    public function optimize_all( array $args, array $assoc_args ): void {

        $dry_run = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
        $before  = $this->db->get_database_size();

        // Preview every cleanable target plus the MyISAM conversion.
        foreach ( $this->clean_targets as $target => $spec ) {
            if ($target === 'foreign-tables') {
                continue;
            }
            $count = (int) call_user_func( array( $this->db, $spec['count'] ) );
            WP_CLI::log( sprintf( '%-20s %6d %s', $target, $count, $spec['label'] ) );
        }
        WP_CLI::log( sprintf( '%-20s %6d %s', 'myisam', $this->db->count_myisam_tables(), 'tables to convert to InnoDB' ) );
        WP_CLI::log( "Database size: {$before}" );

        if ( $dry_run ) {
            WP_CLI::success( 'Dry run: no changes made.' );
            return;
        }

        WP_CLI::confirm( 'Run the full cleanup and optimization sequence?', $assoc_args );

        $this->db->optimize_all();
        $after = $this->db->get_database_size();

        WP_CLI::success( "Optimized. Before: {$before}, after: {$after}." );

    }

    /**
     * Deprecated alias for `db optimize-all`.
     *
     * Retained so existing scripts using the underscore form keep working.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Preview only.
     *
     * [--yes]
     * : Answer yes to the confirmation prompt.
     *
     * @subcommand optimize_all
     * @when after_wp_load
     *
     * @param array $args       Positional arguments (unused).
     * @param array $assoc_args Flags: --dry-run, --yes.
     * @return void
     */
    public function optimize_all_legacy( array $args, array $assoc_args ): void {
        WP_CLI::warning( '`db optimize_all` is deprecated; use `db optimize-all`.' );
        $this->optimize_all( $args, $assoc_args );
    }

}

/**
 * `wp templ-optimizer tweak` — read and write the plugin's tunable settings.
 *
 * Settings backed by wp-config.php constants (wp-memory-limit, wp-post-revisions,
 * disable-wp-cron) rewrite wp-config.php via WPConfigTransformer. The remaining
 * keys are stored in the plugin's option row.
 *
 * @since 2.3.0
 */
class Templ_Optimizer_Tweak_Command {

    /**
     * Tweak tool set.
     *
     * @var templOptimizerTweaks
     */
    private $tweaks;

    /**
     * Tweak registry.
     *
     * Maps each hyphenated CLI key to its setter method, the allowed value set
     * (null = free-form value), and whether applying it rewrites wp-config.php.
     *
     * @var array<string,array{setter:string,options:?array<string>,config:bool}>
     */
    private $keys = array(
        'heartbeat-interval'         => array( 'setter' => 'set_heartbeat_interval',         'options' => array( 'default', 'slow' ),     'config' => false ),
        'wp-rocket-preload-interval' => array( 'setter' => 'set_wp_rocket_preload_interval', 'options' => array( 'default', 'slow' ),     'config' => false ),
        'wp-memory-limit'            => array( 'setter' => 'set_wp_memory_limit',            'options' => null,                           'config' => true  ),
        'wp-post-revisions'          => array( 'setter' => 'set_wp_post_revisions',          'options' => null,                           'config' => true  ),
        'disable-wp-cron'            => array( 'setter' => 'set_disable_wp_cron',            'options' => array( 'enabled', 'disabled' ), 'config' => true  ),
    );

    /**
     * @param templOptimizerTweaks $tweaks Tweak tool set.
     */
    public function __construct( templOptimizerTweaks $tweaks ) {
        $this->tweaks = $tweaks;
    }

    /**
     * Returns the current effective value of every tweak key.
     *
     * Config-backed keys read the live constant; option-backed keys read the
     * stored setting. Absent values report as 'default'.
     *
     * @return array<string,string>
     */
    private function current_values(): array {
        return array(
            'heartbeat-interval'         => (string) ( $this->tweaks->get_option( 'heartbeat_interval' ) ?: 'default' ),
            'wp-rocket-preload-interval' => (string) ( $this->tweaks->get_option( 'wp_rocket_preload_interval' ) ?: 'default' ),
            'wp-memory-limit'            => defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : 'default',
            'wp-post-revisions'          => ( defined( 'WP_POST_REVISIONS' ) && WP_POST_REVISIONS !== true ) ? var_export( WP_POST_REVISIONS, true ) : 'default',
            'disable-wp-cron'            => ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ? 'disabled' : 'enabled',
        );
    }

    /**
     * Lists all tweak keys with their current value and storage source.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Render output in a particular format.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     $ wp templ-optimizer tweak list
     *
     * @subcommand list
     * @when after_wp_load
     *
     * @param array $args       Positional arguments (unused).
     * @param array $assoc_args Flags: --format.
     * @return void
     */
    public function list_( array $args, array $assoc_args ): void {

        $format  = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
        $values  = $this->current_values();

        $items = array();
        foreach ( $this->keys as $key => $spec ) {
            $items[] = array(
                'key'     => $key,
                'value'   => $values[ $key ],
                'source'  => $spec['config'] ? 'wp-config.php' : 'option',
                'options' => is_array( $spec['options'] ) ? implode( '|', $spec['options'] ) : '(free-form)',
            );
        }

        WP_CLI\Utils\format_items( $format, $items, array( 'key', 'value', 'source', 'options' ) );

    }

    /**
     * Prints the current value of a single tweak key.
     *
     * ## OPTIONS
     *
     * <key>
     * : The tweak key to read.
     * ---
     * options:
     *   - heartbeat-interval
     *   - wp-rocket-preload-interval
     *   - wp-memory-limit
     *   - wp-post-revisions
     *   - disable-wp-cron
     * ---
     *
     * ## EXAMPLES
     *
     *     $ wp templ-optimizer tweak get disable-wp-cron
     *     enabled
     *
     * @when after_wp_load
     *
     * @param array $args       Positional: <key>.
     * @param array $assoc_args Flags (unused).
     * @return void
     */
    public function get( array $args, array $assoc_args ): void {

        $key = $args[0];

        if ( ! isset( $this->keys[ $key ] ) ) {
            WP_CLI::error( "Unknown key '{$key}'. Valid keys: " . implode( ', ', array_keys( $this->keys ) ) . '.' );
        }

        $values = $this->current_values();
        WP_CLI::log( $values[ $key ] );

    }

    /**
     * Sets a tweak key to a new value.
     *
     * Keys backed by wp-config.php constants rewrite wp-config.php. Use the
     * value 'default' to remove a wp-config constant and restore WordPress
     * defaults.
     *
     * ## OPTIONS
     *
     * <key>
     * : The tweak key to write.
     * ---
     * options:
     *   - heartbeat-interval
     *   - wp-rocket-preload-interval
     *   - wp-memory-limit
     *   - wp-post-revisions
     *   - disable-wp-cron
     * ---
     *
     * <value>
     * : The new value. Enumerated keys accept only their listed values; use
     * 'default' to reset memory/revisions. Revisions accept a non-negative
     * integer. Memory accepts positive bytes, K/M/G quantities or -1 (unlimited).
     * Cron accepts enabled/disabled; Heartbeat and Rocket accept default/slow.
     *
     * [--yes]
     * : Answer yes to the confirmation prompt (shown for wp-config changes).
     *   Does not replace --external-cron-confirmed.
     *
     * [--external-cron-confirmed]
     * : Confirm a recurring external scheduler is configured and tested for this
     *   site. Required when disabling the automatic WP-Cron trigger. Prefer a
     *   server cron job running `wp cron event run --due-now`: PHP CLI does not
     *   occupy a web PHP worker. Scheduled HTTPS calls to wp-cron.php are a
     *   fallback and still use a web PHP worker. Neither option is set up here.
     *
     * ## EXAMPLES
     *
     *     # Slow the Heartbeat API (option-backed).
     *     $ wp templ-optimizer tweak set heartbeat-interval slow
     *
     *     # Only after configuring and testing a recurring external scheduler:
     *     $ wp templ-optimizer tweak set disable-wp-cron disabled --external-cron-confirmed --yes
     *
     *     # Cap stored revisions at 5.
     *     $ wp templ-optimizer tweak set wp-post-revisions 5 --yes
     *
     *     # Remove the WP_MEMORY_LIMIT constant.
     *     $ wp templ-optimizer tweak set wp-memory-limit default --yes
     *
     * @when after_wp_load
     *
     * @param array $args       Positional: <key> <value>.
     * @param array $assoc_args Flags: --yes.
     * @return void
     */
    public function set( array $args, array $assoc_args ): void {

        list( $key, $value ) = $args;

        if ( ! isset( $this->keys[ $key ] ) ) {
            WP_CLI::error( "Unknown key '{$key}'. Valid keys: " . implode( ', ', array_keys( $this->keys ) ) . '.' );
        }

        $spec = $this->keys[ $key ];

        if (!templOptimizerTweaks::valid_setting(str_replace('-', '_', $key), $value)) {
            WP_CLI::error("Invalid value for {$key}.");
        }

        $external_cron_confirmed = ($assoc_args['external-cron-confirmed'] ?? false) === true;
        if ($key === 'disable-wp-cron' && $value === 'disabled' && !$external_cron_confirmed) {
            WP_CLI::error('Scheduled posts, update checks and plugin tasks may stop running without a replacement scheduler. Configure and test a recurring server cron job running wp cron event run --due-now (preferred; no web PHP worker), or scheduled HTTPS calls to wp-cron.php, then pass --external-cron-confirmed. --yes alone is insufficient.');
        }

        // Config-backed keys rewrite wp-config.php; guard and confirm. The
        // tweaks setter only builds its transformer when this file exists.
        if ( $spec['config'] ) {
            if ( ! file_exists( ABSPATH . 'wp-config.php' ) ) {
                WP_CLI::error( 'wp-config.php not found; cannot change this setting.' );
            }
            WP_CLI::confirm( "This rewrites wp-config.php ({$key} => {$value}). Continue?", $assoc_args );
        }

        // Dispatch to the matching tweak setter.
        try {
            if ($key === 'disable-wp-cron') {
                $this->tweaks->set_disable_wp_cron($value, $external_cron_confirmed);
            } else {
                call_user_func( array( $this->tweaks, $spec['setter'] ), $value );
            }
        } catch (Throwable $error) {
            WP_CLI::error('Unable to save this setting.');
        }

        WP_CLI::success( "Set {$key} to {$value}." );

    }

}

/**
 * `wp templ-optimizer indexes` — high-performance table keys.
 *
 * The timeout-safe way to add high-performance keys: unlike the admin app it
 * never times out, so it is the recommended path for large tables.
 *
 * @since 2.3.0
 */
class Templ_Optimizer_Indexes_Command {

    /**
     * Indexing tool set.
     *
     * @var templOptimizerIndexes
     */
    private $indexes;

    /**
     * @param templOptimizerIndexes $indexes Indexing tool set.
     */
    public function __construct( templOptimizerIndexes $indexes ) {
        $this->indexes = $indexes;
    }

    /**
     * Resolves the target slugs from positional args and --all.
     *
     * @param array $args       Positional slugs.
     * @param array $assoc_args Flags: --all.
     * @return string[] Validated catalogue slugs.
     */
    private function resolve_targets( array $args, array $assoc_args ): array {
        $all   = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'all', false );
        $valid = $this->indexes->slugs();

        if ($all && $args) {
            WP_CLI::error('Use either explicit tables or --all, not both.');
        }
        $targets = $all ? $valid : $args;
        if ( empty( $targets ) ) {
            WP_CLI::error( 'Specify one or more tables, or use --all. Valid: ' . implode( ', ', $valid ) . '.' );
        }
        foreach ( $targets as $slug ) {
            if ( ! in_array( $slug, $valid, true ) ) {
                WP_CLI::error( "Unknown table '{$slug}'. Valid: " . implode( ', ', $valid ) . '.' );
            }
        }
        return $targets;
    }

    /**
     * Shows the high-performance key status of every supported table.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Render output in a particular format.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     $ wp templ-optimizer indexes status
     *
     * @when after_wp_load
     *
     * @param array $args       Positional arguments (unused).
     * @param array $assoc_args Flags: --format.
     * @return void
     */
    public function status( array $args, array $assoc_args ): void {

        $format = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
        $status = $this->indexes->get_index_status();

        $items = array();
        foreach ( $status['tables'] as $slug => $t ) {
            $items[] = array(
                'table'     => $slug,
                'state'     => $t['state'],
                'engine'    => $t['engine'],
                'rows'      => $t['rows'],
                'too_large' => $t['too_large'] ? 'yes' : 'no',
            );
        }

        WP_CLI\Utils\format_items( $format, $items, array( 'table', 'state', 'engine', 'rows', 'too_large' ) );
        if ($format === 'table') {
            WP_CLI::log( 'Overall: ' . $status['summary']['aggregate'] );
        }

    }

    /**
     * Adds Templ Optimizer's high-performance keys to one or more tables.
     *
     * ## OPTIONS
     *
     * [<table>...]
     * : One or more tables. Omit when using --all.
     *
     * [--all]
     * : Apply to every supported table.
     *
     * [--dry-run]
     * : Print the ALTER statements without running them.
     *
     * [--yes]
     * : Answer yes to the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     # Preview the SQL for every table.
     *     $ wp templ-optimizer indexes enable --all --dry-run
     *
     *     # Add keys to the postmeta table.
     *     $ wp templ-optimizer indexes enable postmeta --yes
     *
     * @when after_wp_load
     *
     * @param array $args       Positional: zero or more table slugs.
     * @param array $assoc_args Flags: --all, --dry-run, --yes.
     * @return void
     */
    public function enable( array $args, array $assoc_args ): void {
        $this->run( 'enable', $args, $assoc_args );
    }

    /**
     * Reverts one or more tables to WordPress's standard keys.
     *
     * ## OPTIONS
     *
     * [<table>...]
     * : One or more tables. Omit when using --all.
     *
     * [--all]
     * : Revert every supported table.
     *
     * [--dry-run]
     * : Print the ALTER statements without running them.
     *
     * [--yes]
     * : Answer yes to the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     $ wp templ-optimizer indexes disable --all --yes
     *     $ wp templ-optimizer indexes disable postmeta --dry-run
     *
     * @when after_wp_load
     *
     * @param array $args       Positional: zero or more table slugs.
     * @param array $assoc_args Flags: --all, --dry-run, --yes.
     * @return void
     */
    public function disable( array $args, array $assoc_args ): void {
        $this->run( 'disable', $args, $assoc_args );
    }

    /**
     * Shared enable/disable implementation.
     *
     * @param string $action     `enable` or `disable`.
     * @param array  $args       Positional slugs.
     * @param array  $assoc_args Flags: --all, --dry-run, --yes.
     * @return void
     */
    private function run( string $action, array $args, array $assoc_args ): void {

        $targets = $this->resolve_targets( $args, $assoc_args );
        $dry_run = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
        $set     = $action === 'enable' ? 'target' : 'default';

        if ( $dry_run ) {
            foreach ( $targets as $slug ) {
                $sql = $this->indexes->plan_sql( $slug, $set );
                WP_CLI::log( $sql === null ? "-- {$slug}: no change needed" : $sql . ';' );
            }
            WP_CLI::success( 'Dry run: no changes made.' );
            return;
        }

        $verb = $action === 'enable' ? 'add high-performance keys to' : 'revert to WordPress keys on';
        WP_CLI::confirm( sprintf( 'This will %s %d table(s). Back up your database first. Continue?', $verb, count( $targets ) ), $assoc_args );

        $changed = 0;
        $failed  = 0;
        foreach ( $targets as $slug ) {
            $res = $action === 'enable' ? $this->indexes->enable_keys( $slug ) : $this->indexes->disable_keys( $slug );
            if ( $res['error'] !== null ) {
                $failed++;
                WP_CLI::warning( "{$slug}: {$res['error']}" );
            } elseif ( $res['skipped'] !== null ) {
                $failed++;
                WP_CLI::warning( "{$slug}: skipped ({$res['skipped']})" );
            } elseif ( $res['changed'] ) {
                $changed++;
                WP_CLI::log( "{$slug}: done" );
            } else {
                WP_CLI::log( "{$slug}: already up to date" );
            }
        }

        if ( $failed > 0 ) {
            WP_CLI::error( "{$changed} table(s) changed, {$failed} failed." );
        }
        WP_CLI::success( "{$changed} table(s) changed." );

    }

}

/**
 * `wp templ-optimizer http` — outbound HTTP request diagnostics.
 *
 * Enable per-site capture, then find outbound WordPress HTTP API calls that
 * slow PHP requests down. Records are stored in a dedicated per-site table with
 * bounded retention; URLs are redacted (host + path, no query values) and no
 * headers, cookies or bodies are ever captured. This is a trusted host-side
 * administrative interface: output carries the same sanitized fields as the UI.
 *
 * Use --url=<site-url> to target a specific Multisite site.
 *
 * @since 2.4.0
 */
class Templ_Optimizer_Http_Command {

    /**
     * HTTP diagnostics tool set.
     *
     * @var templOptimizerHttpLog
     */
    private $http_log;

    /**
     * @param templOptimizerHttpLog $http_log HTTP diagnostics tool set.
     */
    public function __construct( templOptimizerHttpLog $http_log ) {
        $this->http_log = $http_log;
    }

    /**
     * Enables outbound HTTP request capture for this site.
     *
     * Capture stays on across subsequent web, cron and CLI executions until it
     * is disabled or a storage failure stops it. Reports failure with a nonzero
     * exit code if the log table cannot be created.
     *
     * ## EXAMPLES
     *
     *     $ wp templ-optimizer http enable
     *
     * @when after_wp_load
     *
     * @param array $args       Positional arguments (unused).
     * @param array $assoc_args Flags (unused).
     * @return void
     */
    public function enable( array $args, array $assoc_args ): void {
        $this->http_log->enable();
        if ( ! $this->http_log->is_enabled() || ! $this->http_log->table_exists() ) {
            WP_CLI::error( 'Could not enable capture: log storage is unavailable.' );
        }
        WP_CLI::success( 'HTTP request capture enabled.' );
    }

    /**
     * Disables capture without deleting stored records.
     *
     * ## EXAMPLES
     *
     *     $ wp templ-optimizer http disable
     *
     * @when after_wp_load
     *
     * @param array $args       Positional arguments (unused).
     * @param array $assoc_args Flags (unused).
     * @return void
     */
    public function disable( array $args, array $assoc_args ): void {
        $this->http_log->disable();
        WP_CLI::success( 'HTTP request capture disabled. Stored records are preserved.' );
    }

    /**
     * Shows capture state, retention limits and stored record coverage.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Render output in a particular format.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     $ wp templ-optimizer http status
     *     $ wp templ-optimizer http status --format=json
     *
     * @when after_wp_load
     *
     * @param array $args       Positional arguments (unused).
     * @param array $assoc_args Flags: --format.
     * @return void
     */
    public function status( array $args, array $assoc_args ): void {
        $format = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
        $s      = $this->http_log->get_status();
        $items  = array(
            array( 'item' => 'enabled',      'value' => $s['enabled'] ? 'yes' : 'no' ),
            array( 'item' => 'error',        'value' => $s['error'] !== '' ? $s['error'] : '-' ),
            array( 'item' => 'records',      'value' => $s['count'] ),
            array( 'item' => 'oldest',       'value' => $s['oldest'] ?: '-' ),
            array( 'item' => 'newest',       'value' => $s['newest'] ?: '-' ),
            array( 'item' => 'max_age_days', 'value' => $s['max_age_days'] ),
            array( 'item' => 'max_rows',     'value' => $s['max_rows'] ),
        );
        WP_CLI\Utils\format_items( $format, $items, array( 'item', 'value' ) );
    }

    /**
     * Lists captured calls, slowest first by default.
     *
     * ## OPTIONS
     *
     * [--min-duration-ms=<ms>]
     * : Only calls lasting at least this many milliseconds.
     *
     * [--host=<host>]
     * : Filter by destination host (exact match).
     *
     * [--caller=<caller>]
     * : Filter by attributed caller (e.g. plugins/woocommerce).
     *
     * [--request-context=<context>]
     * : Filter by execution context (frontend, admin, rest, ajax, cron, cli, xmlrpc).
     *   Named differently from WP-CLI's reserved global --context parameter.
     *
     * [--outcome=<outcome>]
     * : Filter by outcome (success, redirect, client_error, server_error, error, other).
     *
     * [--execution=<id>]
     * : Only calls from one PHP execution.
     *
     * [--since=<datetime>]
     * : Only calls at or after this UTC 'Y-m-d H:i:s' time.
     *
     * [--until=<datetime>]
     * : Only calls at or before this UTC 'Y-m-d H:i:s' time.
     *
     * [--orderby=<field>]
     * : Sort field.
     * ---
     * default: duration
     * options:
     *   - duration
     *   - time
     * ---
     *
     * [--order=<order>]
     * : Sort direction.
     * ---
     * default: desc
     * options:
     *   - asc
     *   - desc
     * ---
     *
     * [--page=<page>]
     * : 1-based page number.
     *
     * [--per-page=<n>]
     * : Results per page (1-500).
     *
     * [--format=<format>]
     * : Render output in a particular format.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     # The 50 slowest calls lasting at least one second.
     *     $ wp templ-optimizer http list --min-duration-ms=1000
     *
     *     # Machine-readable output for host-side analysis.
     *     $ wp templ-optimizer http list --min-duration-ms=1000 --format=json
     *
     * @subcommand list
     * @when after_wp_load
     *
     * @param array $args       Positional arguments (unused).
     * @param array $assoc_args Filter and format flags.
     * @return void
     */
    public function list_( array $args, array $assoc_args ): void {
        $format = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
        $result = $this->http_log->query( $this->filters( $assoc_args ) );
        $items  = array_map( array( $this, 'row_item' ), $result['rows'] );
        WP_CLI\Utils\format_items(
            $format,
            $items,
            array( 'id', 'logged_at', 'duration_ms', 'method', 'status', 'host', 'path', 'outcome', 'blocking', 'context', 'caller', 'execution_id' )
        );
    }

    /**
     * Shows one captured call in full.
     *
     * ## OPTIONS
     *
     * <id>
     * : Record id from `http list`.
     *
     * [--format=<format>]
     * : Render output in a particular format.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     $ wp templ-optimizer http show 123
     *
     * @when after_wp_load
     *
     * @param array $args       Positional: <id>.
     * @param array $assoc_args Flags: --format.
     * @return void
     */
    public function show( array $args, array $assoc_args ): void {
        $format = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
        $match  = $this->http_log->get_by_id( (int) $args[0] );
        if ( $match === null ) {
            WP_CLI::error( 'No captured call with id ' . (int) $args[0] . '.' );
        }
        $item  = $this->row_item( $match );
        $item['request_uri'] = $match['request_uri'];
        $pairs = array();
        foreach ( $item as $key => $value ) {
            $pairs[] = array( 'field' => $key, 'value' => $value );
        }
        WP_CLI\Utils\format_items( $format, $pairs, array( 'field', 'value' ) );
    }

    /**
     * Aggregates captured calls by destination host or attributed caller.
     *
     * Cumulative blocking wait counts blocking calls only, exposing repeated
     * smaller delays that a single-call view can hide.
     *
     * ## OPTIONS
     *
     * [--group-by=<field>]
     * : Aggregation dimension.
     * ---
     * default: host
     * options:
     *   - host
     *   - caller
     * ---
     *
     * [--min-duration-ms=<ms>]
     * : Only include calls lasting at least this many milliseconds.
     *
     * [--since=<datetime>]
     * : Only calls at or after this UTC 'Y-m-d H:i:s' time.
     *
     * [--until=<datetime>]
     * : Only calls at or before this UTC 'Y-m-d H:i:s' time.
     *
     * [--format=<format>]
     * : Render output in a particular format.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     $ wp templ-optimizer http summary --group-by=host
     *
     * @when after_wp_load
     *
     * @param array $args       Positional arguments (unused).
     * @param array $assoc_args Flags: --group-by, filters, --format.
     * @return void
     */
    public function summary( array $args, array $assoc_args ): void {
        $format   = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
        $filters  = $this->filters( $assoc_args );
        $filters['group_by'] = WP_CLI\Utils\get_flag_value( $assoc_args, 'group-by', 'host' );
        $result   = $this->http_log->summary( $filters );
        WP_CLI\Utils\format_items(
            $format,
            $result['rows'],
            array( 'name', 'calls', 'blocking_ms', 'max_ms', 'failures' )
        );
    }

    /**
     * Deletes this site's captured records; preserves the capture setting.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Answer yes to the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     $ wp templ-optimizer http clear --yes
     *
     * @when after_wp_load
     *
     * @param array $args       Positional arguments (unused).
     * @param array $assoc_args Flags: --yes.
     * @return void
     */
    public function clear( array $args, array $assoc_args ): void {
        WP_CLI::confirm( 'Delete all captured HTTP request records for this site?', $assoc_args );
        $this->http_log->clear();
        WP_CLI::success( 'Captured HTTP request records deleted.' );
    }

    /**
     * Maps CLI flags to the query filter array.
     *
     * @param array $assoc_args Command flags.
     * @return array<string,mixed>
     */
    private function filters( array $assoc_args ): array {
        return array(
            'min_duration' => WP_CLI\Utils\get_flag_value( $assoc_args, 'min-duration-ms', 0 ),
            'host'         => WP_CLI\Utils\get_flag_value( $assoc_args, 'host', '' ),
            'caller'       => WP_CLI\Utils\get_flag_value( $assoc_args, 'caller', '' ),
            'context'      => WP_CLI\Utils\get_flag_value( $assoc_args, 'request-context', '' ),
            'outcome'      => WP_CLI\Utils\get_flag_value( $assoc_args, 'outcome', '' ),
            'execution'    => WP_CLI\Utils\get_flag_value( $assoc_args, 'execution', '' ),
            'since'        => WP_CLI\Utils\get_flag_value( $assoc_args, 'since', '' ),
            'until'        => WP_CLI\Utils\get_flag_value( $assoc_args, 'until', '' ),
            'orderby'      => WP_CLI\Utils\get_flag_value( $assoc_args, 'orderby', 'duration' ),
            'order'        => WP_CLI\Utils\get_flag_value( $assoc_args, 'order', 'desc' ),
            'page'         => WP_CLI\Utils\get_flag_value( $assoc_args, 'page', 1 ),
            'per_page'     => WP_CLI\Utils\get_flag_value( $assoc_args, 'per-page', 50 ),
        );
    }

    /**
     * Flattens a shaped row into scalar CLI columns.
     *
     * @param array<string,mixed> $row Shaped record row.
     * @return array<string,mixed>
     */
    private function row_item( array $row ): array {
        return array(
            'id'           => $row['id'],
            'logged_at'    => $row['logged_at'],
            'duration_ms'  => $row['duration_ms'],
            'method'       => $row['method'],
            'status'       => $row['status_code'] === null ? '-' : $row['status_code'],
            'host'         => $row['host'],
            'path'         => $row['path'],
            'outcome'      => $row['outcome'],
            'blocking'     => $row['blocking'] ? 'yes' : 'no',
            'context'      => $row['context'],
            'caller'       => $row['caller'],
            'execution_id' => $row['execution_id'],
        );
    }

}
