<?php
defined('ABSPATH') or die();

class templOptimizerDb extends templOptimizer {

    function __construct() {}

    function get_database_size() {
        global $wpdb;
        $size = $wpdb->get_var("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 1) FROM information_schema.tables WHERE TABLE_SCHEMA = '{$wpdb->dbname}' GROUP BY table_schema");
        return $size . 'MB';
    }

    function count_trashed_posts() {
        global $wpdb;
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}posts WHERE post_status = 'trash'");
        return $count;
    }

    function delete_trashed_posts() {
        global $wpdb;
        $count = $wpdb->query("DELETE FROM {$wpdb->prefix}posts WHERE post_status = 'trash'");
        return $count;
    }

    function count_revisions() {
        global $wpdb;
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}posts WHERE post_type = 'revision'");
        return $count;
    }

    function delete_revisions() {
        global $wpdb;
        $count = $wpdb->query("DELETE FROM {$wpdb->prefix}posts WHERE post_type = 'revision'");
        return $count;
    }

    function count_auto_drafts() {
        global $wpdb;
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}posts WHERE post_status = 'auto-draft'");
        return $count;
    }

    function delete_auto_drafts() {
        global $wpdb;
        $count = $wpdb->query("DELETE FROM {$wpdb->prefix}posts WHERE post_status = 'auto-draft'");
        return $count;
    }

    function count_orphaned_postmeta() {
        global $wpdb;
        $count = $wpdb->get_var("SELECT COUNT(*) pm FROM {$wpdb->prefix}postmeta pm LEFT JOIN {$wpdb->prefix}posts wp ON wp.ID = pm.post_id WHERE wp.ID IS NULL");
        return $count;
    }

    function delete_orphaned_postmeta() {
        global $wpdb;
        $count = $wpdb->query("DELETE pm FROM {$wpdb->prefix}postmeta pm LEFT JOIN {$wpdb->prefix}posts wp ON wp.ID = pm.post_id WHERE wp.ID IS NULL");
        return $count;
    }

    function count_tables_with_different_prefix() {
        global $wpdb;
        $count = $wpdb->get_var("SELECT COUNT(TABLE_NAME) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$wpdb->dbname}' AND TABLE_NAME NOT LIKE '{$wpdb->base_prefix}%'");
        return $count;
    }

    function list_tables_with_different_prefix() {

        global $wpdb;

        $query = $wpdb->get_results(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$wpdb->dbname}' AND TABLE_NAME NOT LIKE '{$wpdb->base_prefix}%'",
            $output = 'ARRAY_A'
        );

        $tables = array();

        foreach( $query as $table ) {
            $tables []= $table['TABLE_NAME'];
        }

        $list = implode( ', ', $tables );

        return $list;

    }

    function drop_tables_with_different_prefix() {

        global $wpdb;

        $query = $wpdb->get_results(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$wpdb->dbname}' AND TABLE_NAME NOT LIKE '{$wpdb->base_prefix}%'",
            $output = 'ARRAY_A'
        );

        $count = 0;

        foreach( $query as $table ) {
            $table_name = $table['TABLE_NAME'];
            $wpdb->query("DROP TABLE `{$table_name}`");
            $count++;
        }
        
        return $count;

    }

    function count_expired_transients() {

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

    function delete_expired_transients() {

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
            $wpdb->delete( $wpdb->options, array( 'option_name' => '_transient_timeout_' . $transient_name ) );
            $wpdb->delete( $wpdb->options, array( 'option_name' => '_transient_' . $transient_name ) );

            $count++;

        }

        return $count;

    }

    function count_myisam_tables() {

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

    function list_myisam_tables() {

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

    function convert_to_innodb() {

        global $wpdb;

        $query = $wpdb->get_results(
            "SHOW TABLE STATUS WHERE Engine = 'MyISAM'",
            $output = 'ARRAY_A'
        );

        $count = 0;

        foreach( $query as $table ) {
            $table_name = $table['Name'];
            $wpdb->query("ALTER TABLE {$table_name} ENGINE=InnoDB");
            $count++;
        }
        
        return $count;

    }

    function optimize_tables() {

        global $wpdb;

        $query = $wpdb->get_results(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$wpdb->dbname}'",
            $output = 'ARRAY_A'
        );

        $count = 0;

        foreach( $query as $table ) {
            $table_name = $table['TABLE_NAME'];
            $wpdb->query("OPTIMIZE TABLE {$table_name}");
            $count++;
        }
        
        return $count;

    }

    function count_tables() {
        global $wpdb;
        $count = $wpdb->get_var("SELECT COUNT(TABLE_NAME) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$wpdb->dbname}'");
        return $count;
    }
    
    function optimize_all() {
        $this->delete_trashed_posts();
        $this->delete_revisions();
        $this->delete_auto_drafts();
        $this->delete_orphaned_postmeta();
        $this->drop_tables_with_different_prefix();
        $this->delete_expired_transients();
        $this->convert_to_innodb();
        $this->optimize_tables();
    }

}
