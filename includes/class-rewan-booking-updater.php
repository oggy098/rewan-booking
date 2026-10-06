<?php
/**
 * Update aus GitHub (oggy098/rewan-booking), ohne Datenbankeinträge anzufassen.
 * Öffentliche Repos brauchen keinen Token. Private Repos nutzen die Option rewan_booking_github_token.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Rewan_Booking_Updater {

    const REPO = 'oggy098/rewan-booking';
    const BRANCH = 'main';

    public static function init() {
        add_filter('pre_set_site_transient_update_plugins', array(__CLASS__, 'inject_update'));
        add_filter('plugins_api', array(__CLASS__, 'plugins_api'), 20, 3);
        add_filter('upgrader_source_selection', array(__CLASS__, 'rename_source'), 10, 4);
        add_filter('auto_update_plugin', array(__CLASS__, 'enable_auto_update'), 10, 2);
        add_filter('http_request_args', array(__CLASS__, 'authorize_github'), 10, 2);
        add_filter('upgrader_post_install', array(__CLASS__, 'clear_remote_cache'), 10, 3);
    }

    public static function plugin_file() {
        return plugin_basename(REWAN_BOOKING_FILE);
    }

    public static function inject_update($transient) {
        if (!is_object($transient)) {
            $transient = new stdClass();
        }
        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = array();
        }

        $remote = self::remote_release();
        if (!$remote || empty($remote['version'])) {
            return $transient;
        }
        if (version_compare((string) $remote['version'], REWAN_BOOKING_VERSION, '<=')) {
            unset($transient->response[ self::plugin_file() ]);
            return $transient;
        }

        $transient->response[ self::plugin_file() ] = (object) array(
            'slug' => 'rewan-booking',
            'plugin' => self::plugin_file(),
            'new_version' => (string) $remote['version'],
            'url' => 'https://github.com/' . self::REPO,
            'package' => self::package_url(),
            'tested' => get_bloginfo('version'),
            'requires_php' => '7.4',
        );
        return $transient;
    }

    public static function plugins_api($result, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== 'rewan-booking') {
            return $result;
        }
        $remote = self::remote_release();
        $version = $remote && !empty($remote['version']) ? (string) $remote['version'] : REWAN_BOOKING_VERSION;
        return (object) array(
            'name' => 'Rewan Booking',
            'slug' => 'rewan-booking',
            'version' => $version,
            'author' => '<a href="https://barbershop-rewan.ch/">Oguz Bektas</a>',
            'homepage' => 'https://github.com/' . self::REPO,
            'download_link' => self::package_url(),
            'requires_php' => '7.4',
            'sections' => array(
                'description' => 'Buchungssystem für den Barbershop. Das Update ersetzt nur Plugin-Dateien. Buchungen, Dienstleistungen und Mitarbeiter bleiben in der Datenbank.',
            ),
        );
    }

    /**
     * GitHub-Zip entpackt in einen Ordner mit Branch oder Commit. WordPress erwartet rewan-booking/.
     *
     * @param string $source
     * @param string $remote_source
     * @param WP_Upgrader $upgrader
     * @param array $hook_extra
     * @return string
     */
    public static function rename_source($source, $remote_source, $upgrader, $hook_extra) {
        unset($remote_source, $upgrader);
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== self::plugin_file()) {
            return $source;
        }
        $source = untrailingslashit((string) $source);
        if (!is_dir($source) || !file_exists($source . '/rewan-booking.php')) {
            return $source;
        }
        $target = trailingslashit(dirname($source)) . 'rewan-booking';
        if (wp_normalize_path($source) === wp_normalize_path($target)) {
            return $source;
        }
        if (is_dir($target)) {
            return $source;
        }
        if (@rename($source, $target)) {
            return $target;
        }
        return $source;
    }

    public static function enable_auto_update($update, $item) {
        $plugin = '';
        if (is_object($item) && isset($item->plugin)) {
            $plugin = (string) $item->plugin;
        } elseif (is_string($item)) {
            $plugin = $item;
        }
        if ($plugin === self::plugin_file()) {
            return true;
        }
        return $update;
    }

    public static function authorize_github($args, $url) {
        $url = (string) $url;
        if (strpos($url, 'github.com/' . self::REPO) === false && strpos($url, 'api.github.com/repos/' . self::REPO) === false && strpos($url, 'raw.githubusercontent.com/' . self::REPO) === false) {
            return $args;
        }
        $token = self::token();
        if ($token === '') {
            return $args;
        }
        if (!isset($args['headers']) || !is_array($args['headers'])) {
            $args['headers'] = array();
        }
        $args['headers']['Authorization'] = 'Bearer ' . $token;
        $args['headers']['Accept'] = 'application/vnd.github+json';
        return $args;
    }

    public static function clear_remote_cache($response, $hook_extra, $result) {
        unset($hook_extra, $result);
        delete_transient('rewan_booking_remote_version');
        return $response;
    }

    private static function package_url() {
        if (self::token() !== '') {
            return 'https://api.github.com/repos/' . self::REPO . '/zipball/' . self::BRANCH;
        }
        return 'https://github.com/' . self::REPO . '/archive/refs/heads/' . self::BRANCH . '.zip';
    }

    private static function token() {
        $token = get_option('rewan_booking_github_token', '');
        return is_string($token) ? trim($token) : '';
    }

    /**
     * @return array{version:string}|null
     */
    private static function remote_release() {
        $cached = get_transient('rewan_booking_remote_version');
        if (is_array($cached) && isset($cached['version'])) {
            return $cached;
        }
        if ($cached === 'missing') {
            return null;
        }

        $version = self::version_from_raw();
        if ($version === '') {
            set_transient('rewan_booking_remote_version', 'missing', 3 * HOUR_IN_SECONDS);
            return null;
        }
        $data = array('version' => $version);
        set_transient('rewan_booking_remote_version', $data, 6 * HOUR_IN_SECONDS);
        return $data;
    }

    private static function version_from_raw() {
        $urls = array(
            'https://raw.githubusercontent.com/' . self::REPO . '/' . self::BRANCH . '/rewan-booking.php',
            'https://api.github.com/repos/' . self::REPO . '/contents/rewan-booking.php?ref=' . self::BRANCH,
        );
        foreach ($urls as $url) {
            $response = wp_remote_get(
                $url,
                array(
                    'timeout' => 8,
                    'headers' => array(
                        'Accept' => 'application/vnd.github+json',
                    ),
                )
            );
            if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
                continue;
            }
            $body = (string) wp_remote_retrieve_body($response);
            $json = json_decode($body, true);
            if (is_array($json) && !empty($json['content'])) {
                $decoded = base64_decode(str_replace("\n", '', (string) $json['content']), true);
                if (is_string($decoded)) {
                    $body = $decoded;
                }
            }
            if (preg_match('/^\s*\*\s*Version:\s*([0-9.]+)/mi', $body, $match)) {
                return $match[1];
            }
        }
        return '';
    }
}
