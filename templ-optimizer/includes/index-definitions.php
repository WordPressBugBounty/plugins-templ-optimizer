<?php
/**
 * High-performance index catalogue.
 *
 * Pure data consumed by {@see templOptimizerIndexes}. For each supported table
 * it declares two key sets:
 *
 *   - `target`  — Templ Optimizer's high-performance keys.
 *   - `default` — WordPress's standard keys, used to revert.
 *
 * Each set is `[ 'primary' => array|null, 'keys' => [ name => spec ] ]` where a
 * key spec is `[ 'unique' => bool, 'cols' => string[] ]`. A column entry is a
 * bare column name, or `name(length)` for a prefix index (e.g. `meta_value(32)`).
 * `primary` is `null` when the table's PRIMARY KEY is deliberately left
 * unchanged (only secondary keys are managed).
 *
 * Definitions target the Barracuda / DYNAMIC row format and are derived from
 * the published index-wp-mysql-for-speed tables-and-keys reference (GPLv2+,
 * re-implemented, not copied). Only the eight core WordPress tables are
 * covered; WooCommerce tables are deferred (see docs/plan-mysql-indexing.md).
 *
 * Table keys map to `$wpdb` properties, so nonstandard prefixes and multisite
 * global/per-site tables resolve correctly.
 *
 * @package Templ_Optimizer
 */

declare(strict_types=1);

defined('ABSPATH') or die();

/**
 * Returns the high-performance index catalogue.
 *
 * @return array<string, array<string, mixed>> Catalogue keyed by table slug
 *              (matching the `$wpdb` property of the same name).
 */
function templ_optimizer_index_catalogue(): array {

    // Shared meta-table pattern: WordPress stores meta as (id, object_id,
    // meta_key, meta_value). The high-performance layout clusters rows by the
    // object id (without making nullable meta_key part of a PRIMARY KEY) and keeps the
    // auto-increment id reachable through a UNIQUE key.
    $meta = static function ( string $object_id, string $meta_id ): array {
        return array(
            'target' => array(
                'primary' => array( $object_id, $meta_id ),
                'keys'    => array(
                    $object_id   => array( 'unique' => false, 'cols' => array( $object_id, 'meta_key', $meta_id ) ),
                    $meta_id     => array( 'unique' => true, 'cols' => array( $meta_id ) ),
                    'meta_key'   => array( 'unique' => false, 'cols' => array( 'meta_key', 'meta_value(32)', $object_id, $meta_id ) ),
                    'meta_value' => array( 'unique' => false, 'cols' => array( 'meta_value(32)', $meta_id ) ),
                ),
            ),
            'default' => array(
                'primary' => array( $meta_id ),
                'keys'    => array(
                    $object_id => array( 'unique' => false, 'cols' => array( $object_id ) ),
                    'meta_key' => array( 'unique' => false, 'cols' => array( 'meta_key(191)' ) ),
                ),
            ),
        );
    };

    return array(

        'posts' => array(
            'label'   => 'Posts',
            'target'  => array(
                'primary' => null, // ID, unchanged.
                'keys'    => array(
                    'post_name'        => array( 'unique' => false, 'cols' => array( 'post_name' ) ),
                    'post_parent'      => array( 'unique' => false, 'cols' => array( 'post_parent', 'post_type', 'post_status' ) ),
                    'type_status_date' => array( 'unique' => false, 'cols' => array( 'post_type', 'post_status', 'post_date', 'post_author' ) ),
                    'post_author'      => array( 'unique' => false, 'cols' => array( 'post_author', 'post_type', 'post_status', 'post_date' ) ),
                ),
            ),
            'default' => array(
                'primary' => null,
                'keys'    => array(
                    'post_name'        => array( 'unique' => false, 'cols' => array( 'post_name(191)' ) ),
                    'post_parent'      => array( 'unique' => false, 'cols' => array( 'post_parent' ) ),
                    'type_status_date' => array( 'unique' => false, 'cols' => array( 'post_type', 'post_status', 'post_date', 'ID' ) ),
                    'post_author'      => array( 'unique' => false, 'cols' => array( 'post_author' ) ),
                ),
            ),
        ),

        'postmeta'    => array( 'label' => 'Post meta' ) + $meta( 'post_id', 'meta_id' ),
        'commentmeta' => array( 'label' => 'Comment meta' ) + $meta( 'comment_id', 'meta_id' ),
        'termmeta'    => array( 'label' => 'Term meta' ) + $meta( 'term_id', 'meta_id' ),
        'usermeta'    => array( 'label' => 'User meta' ) + $meta( 'user_id', 'umeta_id' ),

        'comments' => array(
            'label'   => 'Comments',
            'target'  => array(
                'primary' => array( 'comment_post_ID', 'comment_ID' ),
                'keys'    => array(
                    'comment_ID'                   => array( 'unique' => true, 'cols' => array( 'comment_ID' ) ),
                    'comment_approved_date_gmt'    => array( 'unique' => false, 'cols' => array( 'comment_approved', 'comment_date_gmt' ) ),
                    'comment_date_gmt'             => array( 'unique' => false, 'cols' => array( 'comment_date_gmt' ) ),
                    'comment_parent'               => array( 'unique' => false, 'cols' => array( 'comment_parent', 'comment_ID' ) ),
                    'comment_author_email'         => array( 'unique' => false, 'cols' => array( 'comment_author_email', 'comment_post_ID', 'comment_ID' ) ),
                    'comment_post_parent_approved' => array( 'unique' => false, 'cols' => array( 'comment_post_ID', 'comment_parent', 'comment_approved', 'comment_type', 'user_id', 'comment_date_gmt', 'comment_ID' ) ),
                ),
            ),
            'default' => array(
                'primary' => array( 'comment_ID' ),
                'keys'    => array(
                    'comment_post_ID'           => array( 'unique' => false, 'cols' => array( 'comment_post_ID' ) ),
                    'comment_approved_date_gmt' => array( 'unique' => false, 'cols' => array( 'comment_approved', 'comment_date_gmt' ) ),
                    'comment_date_gmt'          => array( 'unique' => false, 'cols' => array( 'comment_date_gmt' ) ),
                    'comment_parent'            => array( 'unique' => false, 'cols' => array( 'comment_parent' ) ),
                    'comment_author_email'      => array( 'unique' => false, 'cols' => array( 'comment_author_email(10)' ) ),
                ),
            ),
        ),

        'users' => array(
            'label'   => 'Users',
            'target'  => array(
                'primary' => null, // ID, unchanged.
                'keys'    => array(
                    'display_name' => array( 'unique' => false, 'cols' => array( 'display_name' ) ),
                ),
            ),
            'default' => array(
                'primary' => null,
                'keys'    => array(),
            ),
        ),

        'options' => array(
            'label'   => 'Options',
            'target'  => array(
                'primary' => array( 'option_name' ),
                'keys'    => array(
                    'option_id' => array( 'unique' => true, 'cols' => array( 'option_id' ) ),
                    'autoload'  => array( 'unique' => false, 'cols' => array( 'autoload' ) ),
                ),
            ),
            'default' => array(
                'primary' => array( 'option_id' ),
                'keys'    => array(
                    'option_name' => array( 'unique' => true, 'cols' => array( 'option_name' ) ),
                    'autoload'    => array( 'unique' => false, 'cols' => array( 'autoload' ) ),
                ),
            ),
        ),

    );
}
