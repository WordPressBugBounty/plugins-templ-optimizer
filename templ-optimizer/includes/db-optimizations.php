<?php
/**
 * Database optimization tools.
 *
 * Provides count/list/cleanup operations over the WordPress database used by
 * the admin app (via REST) and the WP-CLI command.
 *
 * @package Templ_Optimizer
 */

declare(strict_types=1);

defined('ABSPATH') or die();

/**
 * Counts, lists and cleans up database content.
 *
 * Extends {@see templOptimizer} to reuse its option helpers; the empty
 * constructor deliberately skips the parent bootstrap so instantiating this
 * class does not register the plugin's hooks a second time.
 *
 * @since 2.0.0
 */
class templOptimizerDb extends templOptimizer {

    /**
     * Instantiates the tool set without running the parent bootstrap.
     */
    function __construct() {}

    /** Execute a mutation without converting SQL failure into zero/success. */
    private function mutate(string $sql): int {
        global $wpdb;
        $hidden = $wpdb->hide_errors();
        try {
            $result = $wpdb->query($sql);
            if ($result === false) {
                throw new RuntimeException('Database operation failed.');
            }
            return (int) $result;
        } finally {
            $wpdb->show_errors($hidden);
        }
    }

    /** Foreign tables are outside the network base prefix, matched literally. */
    private function foreign_tables(): array {
        global $wpdb;
        $tables = $wpdb->get_col($wpdb->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME NOT LIKE %s',
            $wpdb->dbname, $wpdb->esc_like($wpdb->base_prefix) . '%'
        ));
        // CUSTOM_USER_TABLE/CUSTOM_USER_META_TABLE can live outside base_prefix.
        // They are still live WordPress tables, shared by every network site.
        return array_values(array_diff($tables, array($wpdb->users, $wpdb->usermeta)));
    }

    /** Quote a discovered identifier, including embedded backticks. */
    private function identifier(string $name): string {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * Returns the total on-disk size of the current database.
     *
     * @return string Human-readable size, e.g. "12.3MB".
     */
    function get_database_size(): string {
        global $wpdb;
        $size = $wpdb->get_var("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 1) FROM information_schema.tables WHERE TABLE_SCHEMA = '{$wpdb->dbname}' GROUP BY table_schema");
        return $size . 'MB';
    }

    /**
     * Counts posts in the trash.
     *
     * @return int Number of trashed posts.
     */
    function count_trashed_posts(): int {
        global $wpdb;
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}posts WHERE post_status = 'trash'");
        return (int) $count;
    }

    /**
     * Permanently deletes all trashed posts.
     *
     * @return int Number of rows deleted.
     */
    function delete_trashed_posts(): int {
        global $wpdb;
        $count = $this->mutate("DELETE FROM {$wpdb->prefix}posts WHERE post_status = 'trash'");
        return (int) $count;
    }

    /**
     * Counts stored post revisions.
     *
     * @return int Number of revision rows.
     */
    function count_revisions(): int {
        global $wpdb;
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}posts WHERE post_type = 'revision'");
        return (int) $count;
    }

    /**
     * Deletes all post revisions.
     *
     * @return int Number of rows deleted.
     */
    function delete_revisions(): int {
        global $wpdb;
        $count = $this->mutate("DELETE FROM {$wpdb->prefix}posts WHERE post_type = 'revision'");
        return (int) $count;
    }

    /**
     * Counts auto-draft posts.
     *
     * @return int Number of auto-draft rows.
     */
    function count_auto_drafts(): int {
        global $wpdb;
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}posts WHERE post_status = 'auto-draft'");
        return (int) $count;
    }

    /**
     * Deletes all auto-draft posts.
     *
     * @return int Number of rows deleted.
     */
    function delete_auto_drafts(): int {
        global $wpdb;
        $count = $this->mutate("DELETE FROM {$wpdb->prefix}posts WHERE post_status = 'auto-draft'");
        return (int) $count;
    }

    /**
     * Counts postmeta rows whose parent post no longer exists.
     *
     * @return int Number of orphaned postmeta rows.
     */
    function count_orphaned_postmeta(): int {
        global $wpdb;
        $count = $wpdb->get_var("SELECT COUNT(*) pm FROM {$wpdb->prefix}postmeta pm LEFT JOIN {$wpdb->prefix}posts wp ON wp.ID = pm.post_id WHERE wp.ID IS NULL");
        return (int) $count;
    }

    /**
     * Deletes postmeta rows whose parent post no longer exists.
     *
     * @return int Number of rows deleted.
     */
    function delete_orphaned_postmeta(): int {
        global $wpdb;
        $count = $this->mutate("DELETE pm FROM {$wpdb->prefix}postmeta pm LEFT JOIN {$wpdb->prefix}posts wp ON wp.ID = pm.post_id WHERE wp.ID IS NULL");
        return (int) $count;
    }

    /**
     * Counts tables that do not match the site's base table prefix.
     *
     * @return int Number of foreign-prefixed tables.
     */
    function count_tables_with_different_prefix(): int {
        return count($this->foreign_tables());
    }

    /**
     * Lists tables that do not match the site's base table prefix.
     *
     * @return string Comma-separated list of table names (empty string if none).
     */
    function list_tables_with_different_prefix(): string {
        return implode(', ', $this->foreign_tables());
    }

    /**
     * Drops every table that does not match the site's base table prefix.
     *
     * @return int Number of tables dropped.
     */
    function drop_tables_with_different_prefix(): int {
        $count = 0;
        foreach ($this->foreign_tables() as $table) {
            $this->mutate('DROP TABLE ' . $this->identifier($table));
            $count++;
        }
        return $count;
    }

    /**
     * Counts expired transients by their stored expiration timestamp.
     *
     * @return string Number of expired transients as a string.
     */
    function count_expired_transients(): string {

        global $wpdb;

        // Count timeout rows whose stored expiration timestamp is in the past.
        // Column names must not be quoted; option_value holds the unix expiry.
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
                $wpdb->esc_like( '_transient_timeout_' ) . '%',
                time()
            )
        );

        return strval( (int) $count );

    }

    /**
     * Deletes every expired transient and its paired timeout row.
     *
     * @return int Number of transients removed.
     */
    function delete_expired_transients(): int {

        global $wpdb;

        // Fetch the timeout keys of every expired transient.
        $expired_timeouts = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
                $wpdb->esc_like( '_transient_timeout_' ) . '%',
                time()
            )
        );

        $count = 0;

        foreach( $expired_timeouts as $timeout_name ) {

            $transient_name = substr( $timeout_name, strlen( '_transient_timeout_' ) );

            // Exact-match deletes ($wpdb->delete escapes and avoids LIKE wildcards).
            $this->mutate($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name IN (%s, %s)",
                '_transient_timeout_' . $transient_name, '_transient_' . $transient_name
            ));

            $count++;

        }

        return $count;

    }

    /**
     * Counts tables still using the MyISAM storage engine.
     *
     * @return int Number of MyISAM tables.
     */
    function count_myisam_tables(): int {

        global $wpdb;

        $query = $wpdb->get_results(
            "SHOW TABLE STATUS WHERE Engine = 'MyISAM'",
            $output = 'ARRAY_A'
        );

        $count = 0;

        foreach( $query as $table ) {
            $count++;
        }

        return $count;

    }

    /**
     * Lists tables still using the MyISAM storage engine.
     *
     * @return string Comma-separated list of table names (empty string if none).
     */
    function list_myisam_tables(): string {

        global $wpdb;

        $query = $wpdb->get_results(
            "SHOW TABLE STATUS WHERE Engine = 'MyISAM'",
            $output = 'ARRAY_A'
        );

        $my_isam_tables = array();

        foreach( $query as $table ) {
            $my_isam_tables []= $table['Name'];
        }

        $list = implode( ', ', $my_isam_tables );

        return $list;

    }

    /**
     * Converts every MyISAM table to the InnoDB storage engine.
     *
     * @return int Number of tables converted.
     */
    function convert_to_innodb(): int {

        global $wpdb;

        $query = $wpdb->get_results(
            "SHOW TABLE STATUS WHERE Engine = 'MyISAM'",
            $output = 'ARRAY_A'
        );

        $count = 0;

        foreach( $query as $table ) {
            $table_name = $table['Name'];
            $this->mutate('ALTER TABLE ' . $this->identifier($table_name) . ' ENGINE=InnoDB');
            $count++;
        }

        return $count;

    }

    /**
     * Runs OPTIMIZE TABLE against every table in the database.
     *
     * @return int Number of tables optimized.
     */
    function optimize_tables(): int {

        global $wpdb;

        $query = $wpdb->get_results(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$wpdb->dbname}'",
            $output = 'ARRAY_A'
        );

        $count = 0;

        foreach( $query as $table ) {
            $table_name = $table['TABLE_NAME'];
            $rows = $wpdb->get_results('OPTIMIZE TABLE ' . $this->identifier($table_name), ARRAY_A);
            if (!$rows || $wpdb->last_error) {
                throw new RuntimeException('Table optimization failed.');
            }
            foreach ($rows as $row) {
                if (strtolower($row['Msg_type']) === 'error') {
                    throw new RuntimeException('Table optimization failed.');
                }
            }
            $count++;
        }

        return $count;

    }

    /**
     * Counts all tables in the current database.
     *
     * @return int Total number of tables.
     */
    function count_tables(): int {
        global $wpdb;
        $count = $wpdb->get_var("SELECT COUNT(TABLE_NAME) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$wpdb->dbname}'");
        return (int) $count;
    }

    /**
     * Runs the full cleanup and optimization sequence in order.
     *
     * @return void
     */
    function optimize_all(): void {
        $this->delete_trashed_posts();
        $this->delete_revisions();
        $this->delete_auto_drafts();
        $this->delete_orphaned_postmeta();
        $this->delete_expired_transients();
        $this->convert_to_innodb();
        $this->optimize_tables();
    }

}
