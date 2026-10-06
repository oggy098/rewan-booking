<?php
/**
 * Plugin Name: Rewan Booking
 * Plugin URI: https://barbershop-rewan.ch/
 * Description: Eigenes Buchungssystem für Barbershop Rewan.
 * Version: 1.3.0
 * Author: Oguz Bektas
 * Author URI: https://barbershop-rewan.ch/
 * License: GPL2
 * Text Domain: rewan-booking
 */

if (!defined('ABSPATH')) {
    exit;
}

define('REWAN_BOOKING_VERSION', '1.3.0');
define('REWAN_BOOKING_FILE', __FILE__);
define('REWAN_BOOKING_PATH', plugin_dir_path(__FILE__));
define('REWAN_BOOKING_URL', plugin_dir_url(__FILE__));

/**
 * Öffentliche URL zu einer Datei im Plugin (z. B. assets/css/…).
 * Nutzt plugins_url() — stabiler bei Unterverzeichnissen, Symlinks und WP_CONTENT_URL.
 *
 * @param string $relative Relativ zum Plugin-Stamm, z. B. 'assets/js/foo.js'.
 */
function rewan_booking_plugin_url($relative) {
    return plugins_url(ltrim((string) $relative, '/'), REWAN_BOOKING_FILE);
}
/** Salon-Personal: Buchungen, Kalender, Mein Tag */
define('REWAN_BOOKING_CAP_SALON', 'rewan_booking');
/** Inhaber / Verwaltung: Dienstleistungen, Team, Abwesenheiten, Cockpit */
define('REWAN_BOOKING_CAP_MANAGE', 'rewan_booking_manage');

require_once REWAN_BOOKING_PATH . 'includes/class-rewan-booking-activator.php';
require_once REWAN_BOOKING_PATH . 'includes/class-rewan-booking-schedule.php';
require_once REWAN_BOOKING_PATH . 'includes/class-rewan-booking-updater.php';
require_once REWAN_BOOKING_PATH . 'includes/rewan-booking-calendar-bookly.php';
require_once REWAN_BOOKING_PATH . 'includes/class-rewan-booking-admin.php';
require_once REWAN_BOOKING_PATH . 'includes/class-rewan-booking-frontend.php';

Rewan_Booking_Updater::init();

function rewan_booking_activate() {
    Rewan_Booking_Activator::activate();
}
register_activation_hook(__FILE__, 'rewan_booking_activate');

function rewan_booking_init() {
    Rewan_Booking_Activator::maybe_upgrade();

    $admin = new Rewan_Booking_Admin();
    $frontend = new Rewan_Booking_Frontend();

    $admin->init();
    $frontend->init();
}
add_action('plugins_loaded', 'rewan_booking_init');

/**
 * Neu registrierte Benutzer dürfen Rewan-Salon-Bereich nutzen.
 */
function rewan_booking_grant_caps_to_new_user($user_id) {
    $user = get_user_by('id', (int) $user_id);
    if (!$user instanceof WP_User) {
        return;
    }
    if (defined('REWAN_BOOKING_CAP_SALON')) {
        $user->add_cap(REWAN_BOOKING_CAP_SALON);
    }
}
add_action('user_register', 'rewan_booking_grant_caps_to_new_user');

/**
 * Einmalig: vorhandenen Benutzern Rewan-Salon-Cap geben.
 */
function rewan_booking_backfill_caps_for_existing_users() {
    if (!defined('REWAN_BOOKING_CAP_SALON')) {
        return;
    }
    if (get_option('rewan_booking_caps_backfilled') === '1') {
        return;
    }

    $users = get_users(
        array(
            'fields' => array('ID'),
            'number' => -1,
        )
    );
    foreach ((array) $users as $u) {
        $user = get_user_by('id', (int) $u->ID);
        if ($user instanceof WP_User) {
            $user->add_cap(REWAN_BOOKING_CAP_SALON);
        }
    }
    update_option('rewan_booking_caps_backfilled', '1');
}
add_action('plugins_loaded', 'rewan_booking_backfill_caps_for_existing_users', 20);
