<?php
/**
 * High-performance database indexing.
 *
 * Adds Templ Optimizer's high-performance keys to the core WordPress tables and
 * reverts them to WordPress defaults. Backs the admin app (via REST) and the
 * WP-CLI command. See docs/plan-mysql-indexing.md and includes/index-definitions.php.
 *
 * @package Templ_Optimizer
 */

declare(strict_types=1);

defined('ABSPATH') or die();

require_once( TEMPL_OPTIMIZER_PATH . 'includes/index-definitions.php' );

/**
 * Detects, applies and reverts high-performance table keys.
 *
 * Extends {@see templOptimizer} to reuse its option helpers; the empty
 * constructor deliberately skips the parent bootstrap so instantiating this
 * class does not register the plugin's hooks a second time.
 *
 * @since 2.3.0
 */
class templOptimizerIndexes extends templOptimizer {

    /**
     * Index prefix that marks user/third-party keys we must never touch.
     *
     * Matches the index-wp-mysql-for-speed convention so the two plugins do not
     * fight over custom keys.
     *
     * @var string
     */
    const PROTECT_PREFIX = 'index_wp_mysql_protect_';

    /**
     * Cached catalogue from {@see templ_optimizer_index_catalogue()}.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private $catalogue = null;

    /**
     * Instantiates the tool set without running the parent bootstrap.
     */
    function __construct() {}

    /**
     * Returns the index catalogue, loading it once.
     *
     * @return array<string, array<string, mixed>>
     */
    private function catalogue(): array {
        if( $this->catalogue === null ) {
            $this->catalogue = templ_optimizer_index_catalogue();
        }
        return $this->catalogue;
    }

    /**
     * Resolves a catalogue slug to its real, prefixed table name.
     *
     * Uses the matching `$wpdb` property so nonstandard prefixes and multisite
     * global/per-site tables resolve correctly.
     *
     * @param string $slug Catalogue slug (e.g. `postmeta`).
     * @return string|null Table name, or null when `$wpdb` has no such property.
     */
    private function table_name( string $slug ): ?string {
        global $wpdb;
        return isset( $wpdb->{$slug} ) ? (string) $wpdb->{$slug} : null;
    }

    /**
     * Row-inline ceiling above which REST refuses to run and defers to WP-CLI.
     *
     * @return int Approximate row count threshold.
     */
    public function inline_max_rows(): int {
        return (int) apply_filters( 'templ_optimizer_index_inline_max_rows', 500000 );
    }

    /**
     * Reads engine, row format and approximate row count for a table.
     *
     * @param string $table Real table name.
     * @return array{exists:bool,engine:string,row_format:string,rows:int}
     */
    private function table_meta( string $table ): array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT ENGINE, ROW_FORMAT, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                $wpdb->dbname,
                $table
            ),
            ARRAY_A
        );
        if( ! $row ) {
            return array( 'exists' => false, 'engine' => '', 'row_format' => '', 'rows' => 0 );
        }
        return array(
            'exists'     => true,
            'engine'     => (string) $row['ENGINE'],
            'row_format' => (string) $row['ROW_FORMAT'],
            'rows'       => (int) $row['TABLE_ROWS'],
        );
    }

    /**
     * Reads the current keys of a table via SHOW INDEX.
     *
     * @param string $table Real table name.
     * @return array<string, array{unique:bool,cols:string[]}> Keyed by key name
     *              (`PRIMARY` included). Column entries are `col` or `col(len)`.
     */
    private function current_indexes( string $table ): array {
        global $wpdb;
        // Table name comes from a trusted $wpdb property; not user input.
        $rows = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A );
        $keys = array();
        if( ! $rows ) {
            return $keys;
        }
        foreach( $rows as $r ) {
            $name = (string) $r['Key_name'];
            $seq  = (int) $r['Seq_in_index'];
            $col  = (string) $r['Column_name'];
            if( isset( $r['Sub_part'] ) && $r['Sub_part'] !== null && $r['Sub_part'] !== '' ) {
                $col .= '(' . (int) $r['Sub_part'] . ')';
            }
            if( ! isset( $keys[ $name ] ) ) {
                $keys[ $name ] = array( 'unique' => ( (int) $r['Non_unique'] === 0 ), 'parts' => array() );
            }
            $keys[ $name ]['parts'][ $seq ] = $col;
        }
        $out = array();
        foreach( $keys as $name => $data ) {
            ksort( $data['parts'] );
            $out[ $name ] = array( 'unique' => $data['unique'], 'cols' => array_values( $data['parts'] ) );
        }
        return $out;
    }

    /**
     * Tests whether the live table matches a target key set.
     *
     * A managed PRIMARY (non-null in the set) must match; every secondary key in
     * the set must be present with identical columns and uniqueness. Extra keys
     * on the table (e.g. protected custom keys) are ignored.
     *
     * @param array<string, array{unique:bool,cols:string[]}> $current Live keys.
     * @param array{primary:?string[],keys:array<string,array{unique:bool,cols:string[]}>} $set Target set.
     * @return bool
     */
    private function matches_set( array $current, array $set ): bool {
        if( $set['primary'] !== null ) {
            if( ! isset( $current['PRIMARY'] ) || $current['PRIMARY']['cols'] !== $set['primary'] ) {
                return false;
            }
        }
        foreach( $set['keys'] as $name => $spec ) {
            if( ! isset( $current[ $name ] ) ) {
                return false;
            }
            if( $current[ $name ]['cols'] !== $spec['cols'] || $current[ $name ]['unique'] !== $spec['unique'] ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Renders a column spec list into a SQL column list.
     *
     * @param string[] $cols Column specs (`col` or `col(len)`).
     * @return string e.g. "`meta_key`, `meta_value`(32)".
     */
    private function render_cols( array $cols ): string {
        $parts = array();
        foreach( $cols as $col ) {
            if( preg_match( '/^(\w+)\((\d+)\)$/', $col, $m ) ) {
                $parts[] = "`{$m[1]}`({$m[2]})";
            } else {
                $parts[] = "`{$col}`";
            }
        }
        return implode( ', ', $parts );
    }

    /**
     * Builds the single ALTER TABLE that transforms a table to a target set.
     *
     * Drops only the keys this catalogue manages (never protected custom keys),
     * then adds the target primary and keys, all in one statement so an
     * AUTO_INCREMENT column is never left without a key. Returns null when the
     * table already matches (nothing to do).
     *
     * @param string $table       Real table name.
     * @param array  $set         Target key set (`primary`, `keys`).
     * @param array  $managed     Managed secondary key names (union of target+default).
     * @param array  $current     Live keys from {@see current_indexes()}.
     * @param bool   $force_dynamic Prepend ROW_FORMAT=DYNAMIC (enable path only).
     * @return string|null ALTER statement, or null when no change is needed.
     */
    private function build_alter( string $table, array $set, array $managed, array $current, bool $force_dynamic ): ?string {

        $drops = array();
        $adds  = array();

        // Primary.
        if( $set['primary'] !== null ) {
            $cur_primary = isset( $current['PRIMARY'] ) ? $current['PRIMARY']['cols'] : null;
            if( $cur_primary !== $set['primary'] ) {
                if( $cur_primary !== null ) {
                    $drops[] = 'DROP PRIMARY KEY';
                }
                $adds[] = 'ADD PRIMARY KEY (' . $this->render_cols( $set['primary'] ) . ')';
            }
        }

        // Secondary keys.
        foreach( $managed as $name ) {
            $want = isset( $set['keys'][ $name ] ) ? $set['keys'][ $name ] : null;
            $have = isset( $current[ $name ] ) ? $current[ $name ] : null;

            if( $want === null ) {
                // Not wanted: drop if present (never touch protected keys).
                if( $have !== null && strpos( $name, self::PROTECT_PREFIX ) !== 0 ) {
                    $drops[] = "DROP KEY `{$name}`";
                }
                continue;
            }

            $matches = $have !== null && $have['cols'] === $want['cols'] && $have['unique'] === $want['unique'];
            if( $matches ) {
                continue;
            }
            if( $have !== null ) {
                $drops[] = "DROP KEY `{$name}`";
            }
            $unique = $want['unique'] ? 'UNIQUE ' : '';
            $adds[] = "ADD {$unique}KEY `{$name}` (" . $this->render_cols( $want['cols'] ) . ')';
        }

        if( ! $drops && ! $adds ) {
            return null;
        }

        $clauses = array_merge( $drops, $adds );
        $prefix  = '';
        if( $force_dynamic ) {
            $prefix = 'ROW_FORMAT=DYNAMIC, ';
        }

        return "ALTER TABLE `{$table}` " . $prefix . implode( ', ', $clauses );
    }

    /**
     * Refuses implicit column changes when adding a primary key.
     *
     * MySQL can silently coerce NULL data and change nullable columns to NOT
     * NULL when building a PRIMARY KEY. Never normalize user data here.
     *
     * @param string $table Real table name.
     * @param array $set Requested key set.
     * @return string|null Failure reason, or null when safe.
     */
    private function primary_key_error( string $table, array $set ): ?string {
        global $wpdb;
        if( $set['primary'] === null ) {
            return null;
        }
        $columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );
        if( ! $columns ) {
            return 'Cannot inspect table columns; no keys were changed.';
        }
        foreach( $columns as $column ) {
            if( in_array( $column['Field'], $set['primary'], true ) && $column['Null'] === 'YES' ) {
                return 'Primary key column ' . $column['Field'] . ' is nullable; refusing to change column definitions or data.';
            }
        }
        return null;
    }

    /**
     * Union of target + default secondary key names for a table.
     *
     * @param array $entry Catalogue entry.
     * @return string[] Managed secondary key names.
     */
    private function managed_keys( array $entry ): array {
        $names = array_merge(
            array_keys( $entry['target']['keys'] ),
            array_keys( $entry['default']['keys'] )
        );
        return array_values( array_unique( $names ) );
    }

    /**
     * Returns the persisted per-table desired state.
     *
     * @return array<string, string> slug => `high_performance`|`default`.
     */
    private function desired_state(): array {
        $state = $this->get_option( 'index_state' );
        return is_array( $state ) ? $state : array();
    }

    /**
     * Persists the desired state for a single table.
     *
     * @param string $slug  Catalogue slug.
     * @param string $value `high_performance` or `default`.
     * @return void
     */
    private function set_desired( string $slug, string $value ): void {
        $state = $this->desired_state();
        $state[ $slug ] = $value;
        $this->update_option( 'index_state', $state );
    }

    /**
     * Builds the full per-table index status payload for the admin app and CLI.
     *
     * @return array{threshold:int,tables:array<string,array<string,mixed>>,summary:array<string,mixed>}
     */
    public function get_index_status(): array {

        $desired   = $this->desired_state();
        $threshold = $this->inline_max_rows();
        $tables    = array();

        $counts = array(
            'supported'        => 0,
            'high_performance' => 0,
            'default'          => 0,
            'drifted'          => 0,
            'needs_innodb'     => 0,
        );

        foreach( $this->catalogue() as $slug => $entry ) {

            $table = $this->table_name( $slug );
            if( $table === null ) {
                continue;
            }
            $meta = $this->table_meta( $table );
            if( ! $meta['exists'] ) {
                continue;
            }

            $counts['supported']++;
            $want = isset( $desired[ $slug ] ) ? $desired[ $slug ] : null;

            if( strtolower( $meta['engine'] ) !== 'innodb' ) {
                $state = 'needs_innodb';
                $counts['needs_innodb']++;
            } else {
                $current = $this->current_indexes( $table );
                $hp      = $this->matches_set( $current, $entry['target'] );
                if( $hp ) {
                    $state = 'high_performance';
                    $counts['high_performance']++;
                } elseif( $want === 'high_performance' ) {
                    $state = 'drifted';
                    $counts['drifted']++;
                } else {
                    $state = 'default';
                    $counts['default']++;
                }
            }

            $tables[ $slug ] = array(
                'label'      => $entry['label'],
                'table'      => $table,
                'engine'     => $meta['engine'],
                'row_format' => $meta['row_format'],
                'rows'       => $meta['rows'],
                'state'      => $state,
                'desired'    => $want,
                'too_large'  => $meta['rows'] > $threshold,
                'cli'        => "wp templ-optimizer indexes enable {$slug}",
            );
        }

        // Aggregate for the hero toggle.
        if( $counts['drifted'] > 0 ) {
            $aggregate = 'attention';
        } elseif( $counts['supported'] > 0 && $counts['high_performance'] === $counts['supported'] ) {
            $aggregate = 'active';
        } elseif( $counts['high_performance'] === 0 ) {
            $aggregate = 'inactive';
        } else {
            $aggregate = 'partial';
        }

        return array(
            'threshold' => $threshold,
            'tables'    => $tables,
            'summary'   => array_merge( $counts, array( 'aggregate' => $aggregate ) ),
        );
    }

    /**
     * Returns slugs of tables whose keys have drifted from the desired state.
     *
     * Used for the dashboard admin notice.
     *
     * @return string[] Catalogue slugs in the `drifted` state.
     */
    public function drifted_tables(): array {
        $drifted = array();
        foreach( $this->get_index_status()['tables'] as $slug => $t ) {
            if( $t['state'] === 'drifted' ) {
                $drifted[] = $slug;
            }
        }
        return $drifted;
    }

    /**
     * Plans (without running) the ALTER for one table toward a target set.
     *
     * @param string $slug Catalogue slug.
     * @param string $set  `target` or `default`.
     * @return string|null ALTER statement, null when nothing to do, or a
     *                      skip reason string prefixed with `-- `.
     */
    public function plan_sql( string $slug, string $set = 'target' ): ?string {
        $catalogue = $this->catalogue();
        if( ! isset( $catalogue[ $slug ] ) ) {
            return '-- unknown table';
        }
        $table = $this->table_name( $slug );
        if( $table === null ) {
            return '-- table not present';
        }
        $meta = $this->table_meta( $table );
        if( ! $meta['exists'] ) {
            return '-- table not present';
        }
        if( strtolower( $meta['engine'] ) !== 'innodb' ) {
            return '-- table is not InnoDB (convert to InnoDB first)';
        }
        $entry   = $catalogue[ $slug ];
        $error   = $this->primary_key_error( $table, $entry[ $set ] );
        if( $error !== null ) {
            return '-- ' . $error;
        }
        $current = $this->current_indexes( $table );
        $dynamic = $set === 'target' && in_array( strtolower( $meta['row_format'] ), array( 'compact', 'redundant' ), true );
        return $this->build_alter( $table, $entry[ $set ], $this->managed_keys( $entry ), $current, $dynamic );
    }

    /**
     * Applies a target key set to one table and records the intent.
     *
     * @param string $slug   Catalogue slug.
     * @param string $set    `target` (enable) or `default` (revert).
     * @param string $intent Desired state to persist on success.
     * @return array{slug:string,ok:bool,changed:bool,sql:?string,error:?string,skipped:?string}
     */
    private function apply( string $slug, string $set, string $intent ): array {
        global $wpdb;

        $result = array( 'slug' => $slug, 'ok' => false, 'changed' => false, 'sql' => null, 'error' => null, 'skipped' => null );

        $catalogue = $this->catalogue();
        if( ! isset( $catalogue[ $slug ] ) ) {
            $result['skipped'] = 'unknown';
            return $result;
        }
        $table = $this->table_name( $slug );
        if( $table === null ) {
            $result['skipped'] = 'absent';
            return $result;
        }
        $meta = $this->table_meta( $table );
        if( ! $meta['exists'] ) {
            $result['skipped'] = 'absent';
            return $result;
        }
        if( strtolower( $meta['engine'] ) !== 'innodb' ) {
            $result['skipped'] = 'needs_innodb';
            return $result;
        }

        $entry   = $catalogue[ $slug ];
        $error   = $this->primary_key_error( $table, $entry[ $set ] );
        if( $error !== null ) {
            $result['error'] = $error;
            return $result;
        }
        $current = $this->current_indexes( $table );
        $dynamic = $set === 'target' && in_array( strtolower( $meta['row_format'] ), array( 'compact', 'redundant' ), true );
        $sql     = $this->build_alter( $table, $entry[ $set ], $this->managed_keys( $entry ), $current, $dynamic );

        // Persist intent even when the schema already matches, so drift is
        // measured against what the user asked for.
        if( $sql === null ) {
            $this->set_desired( $slug, $intent );
            $result['ok'] = true;
            return $result;
        }

        if( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }

        $result['sql'] = $sql;
        $hidden = $wpdb->hide_errors();
        $ran    = $wpdb->query( $sql );
        $wpdb->show_errors( $hidden );

        if( $ran === false ) {
            $result['error'] = $wpdb->last_error ? $wpdb->last_error : 'query failed';
            return $result;
        }

        $this->set_desired( $slug, $intent );
        $result['ok']      = true;
        $result['changed'] = true;
        return $result;
    }

    /**
     * Adds the high-performance keys to one table.
     *
     * @param string $slug Catalogue slug.
     * @return array Result from {@see apply()}.
     */
    public function enable_keys( string $slug ): array {
        return $this->apply( $slug, 'target', 'high_performance' );
    }

    /**
     * Reverts one table to WordPress's standard keys.
     *
     * @param string $slug Catalogue slug.
     * @return array Result from {@see apply()}.
     */
    public function disable_keys( string $slug ): array {
        return $this->apply( $slug, 'default', 'default' );
    }

    /**
     * Enables high-performance keys on every supported InnoDB table.
     *
     * @return array<int, array> Per-table results.
     */
    public function enable_all(): array {
        $results = array();
        foreach( array_keys( $this->catalogue() ) as $slug ) {
            $results[] = $this->enable_keys( $slug );
        }
        return $results;
    }

    /**
     * Reverts every supported table to WordPress's standard keys.
     *
     * @return array<int, array> Per-table results.
     */
    public function disable_all(): array {
        $results = array();
        foreach( array_keys( $this->catalogue() ) as $slug ) {
            $results[] = $this->disable_keys( $slug );
        }
        return $results;
    }

    /**
     * All catalogue slugs (whether or not the table is present).
     *
     * @return string[]
     */
    public function slugs(): array {
        return array_keys( $this->catalogue() );
    }

    /**
     * Whether a slug names a supported, present table.
     *
     * @param string $slug Catalogue slug.
     * @return bool
     */
    public function is_supported( string $slug ): bool {
        $catalogue = $this->catalogue();
        if( ! isset( $catalogue[ $slug ] ) ) {
            return false;
        }
        $table = $this->table_name( $slug );
        return $table !== null && $this->table_meta( $table )['exists'];
    }

}
