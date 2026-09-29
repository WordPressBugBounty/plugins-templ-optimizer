<?php
/**
 * WordPress and plugin tweaks.
 *
 * Applies runtime filters (heartbeat, WP Rocket) and persists tunable settings,
 * writing wp-config.php constants through {@see templOptimizerConfigTransformer}
 * where required.
 *
 * @package Templ_Optimizer
 */

declare(strict_types=1);

defined('ABSPATH') or die();

/**
 * Registers tweak filters and exposes setters for each tunable setting.
 *
 * Extends {@see templOptimizer} to reuse its option helpers. Unlike the parent,
 * this constructor only wires the tweak filters and does not re-run the plugin
 * bootstrap.
 *
 * @since 2.0.0
 */
class templOptimizerTweaks extends templOptimizer {

    /**
     * Wires the tweak filters and prepares the wp-config transformer.
     */
    function __construct() {

        add_filter( 'heartbeat_settings', array( $this, 'custom_heartbeat_interval' ) );

        if( $this->get_option('wp_rocket_preload_interval') && $this->get_option('wp_rocket_preload_interval') !== 'default' ) {
            add_filter( 'pre_get_rocket_option_sitemap_preload_url_crawl', array( $this, 'wp_rocket_preload_interval' ) );
        }

    }

    /** Build the transformer only for a write; read-only sites can still boot. */
    private function config_transformer(): templOptimizerConfigTransformer {
        $file = ABSPATH . 'wp-config.php';
        clearstatcache(true, $file);
        if (!is_file($file) || !is_writable($file)) {
            throw new RuntimeException('wp-config.php is missing or not writable.');
        }
        return new templOptimizerConfigTransformer($file);
    }

    /** Validates the shared REST/CLI setting contract before any write. */
    public static function valid_setting(string $setting, $value): bool {
        if (!is_string($value) && !is_int($value)) {
            return false;
        }
        $value = (string) $value;
        switch ($setting) {
            case 'heartbeat_interval':
            case 'wp_rocket_preload_interval':
                return in_array($value, array('default', 'slow'), true);
            case 'disable_wp_cron':
                return in_array($value, array('enabled', 'disabled'), true);
            case 'wp_memory_limit':
                return $value === 'default' || (bool) preg_match('/^(?:-1|[1-9][0-9]*[KMG]?)$/D', $value);
            case 'wp_post_revisions':
                return $value === 'default' || ((bool) preg_match('/^(0|[1-9][0-9]*)$/D', $value)
                    && filter_var($value, FILTER_VALIDATE_INT, array('options' => array('min_range' => 0))) !== false);
            default:
                return false;
        }
    }

    /** WP Rocket's preload delay, in microseconds. */
    public function wp_rocket_preload_interval($value) {
        return $this->get_option('wp_rocket_preload_interval') === 'slow' ? 1000000 : $value;
    }

    /**
     * Adjusts the Heartbeat API interval based on the stored setting.
     *
     * Hooked to the `heartbeat_settings` filter.
     *
     * @param array $settings Current heartbeat settings.
     * @return array Modified heartbeat settings.
     */
    function custom_heartbeat_interval( array $settings ): array {

        if( $this->get_option('heartbeat_interval') === 'default' ) {
            $settings['interval'] = 15;
        }

        if( $this->get_option('heartbeat_interval') === 'slow' ) {
            $settings['interval'] = 60;
        }

        return $settings;

    }

    /**
     * Sets or removes the WP_MEMORY_LIMIT wp-config constant.
     *
     * @param mixed $value New limit, or 'default' to remove the constant.
     * @return mixed Result of the wp-config transformer operation.
     */
    function set_wp_memory_limit( $value ) {
        if( $value == 'default' ) {
            return $this->config_transformer()->remove('constant', 'WP_MEMORY_LIMIT');
        }
        return $this->config_transformer()->update('constant', 'WP_MEMORY_LIMIT', $value);
    }

    /**
     * Stores the desired Heartbeat interval setting.
     *
     * @param mixed $value 'default', 'slow' or a custom interval marker.
     * @return void
     */
    function set_heartbeat_interval( $value ): void {
        $this->update_option( 'heartbeat_interval', $value );
    }

    /**
     * Stores the desired WP Rocket preload interval setting.
     *
     * @param mixed $value Preload interval marker.
     * @return void
     */
    function set_wp_rocket_preload_interval( $value ): void {
        $this->update_option( 'wp_rocket_preload_interval', $value );
    }

    /**
     * Sets or removes the WP_POST_REVISIONS wp-config constant.
     *
     * @param mixed $value Revision limit, or 'default' to remove the constant.
     * @return mixed Result of the wp-config transformer operation.
     */
    function set_wp_post_revisions( $value ) {
        if( $value == 'default' ) {
            return $this->config_transformer()->remove('constant', 'WP_POST_REVISIONS');
        }
        return $this->config_transformer()->update('constant', 'WP_POST_REVISIONS', (string) $value, array('raw' => true));
    }

    /**
     * Enables or disables WP-Cron via the DISABLE_WP_CRON wp-config constant.
     *
     * @param mixed $value 'disabled' to set the constant, 'enabled' to remove it.
     * @param bool $external_cron_confirmed Operator acknowledgement, not scheduler verification.
     * @return mixed Result of the wp-config transformer operation, or null for
     *               an unrecognized value.
     */
    function set_disable_wp_cron( $value, $external_cron_confirmed = false ) {
        if( $value == 'disabled' ) {
            if ($external_cron_confirmed !== true) {
                throw new InvalidArgumentException('Configure and test a recurring external scheduler before disabling the automatic WP-Cron trigger.');
            }
            return $this->config_transformer()->update('constant', 'DISABLE_WP_CRON', 'true', array('raw' => true));
        }
        if( $value == 'enabled' ) {
            return $this->config_transformer()->remove('constant', 'DISABLE_WP_CRON');
        }
    }

}
