<?php
/**
 * Plugin Name: Rewan Booking
 * Plugin URI: https://barbershop-rewan.ch/
 * Description: Eigenes Buchungssystem für Barbershop Rewan.
 * Version: 1.0.0
 * Author: Oguz Bektas
 * Author URI: https://barbershop-rewan.ch/
 * License: GPL2
 * Text Domain: rewan-booking
 */

if (!defined('ABSPATH')) {
    exit;
}

define('REWAN_BOOKING_VERSION', '1.0.0');
define('REWAN_BOOKING_PATH', plugin_dir_path(__FILE__));
define('REWAN_BOOKING_URL', plugin_dir_url(__FILE__));

require_once REWAN_BOOKING_PATH . 'includes/class-rewan-booking-activator.php';
require_once REWAN_BOOKING_PATH . 'includes/class-rewan-booking-admin.php';
require_once REWAN_BOOKING_PATH . 'includes/class-rewan-booking-frontend.php';

function rewan_booking_activate() {
    Rewan_Booking_Activator::activate();
}
register_activation_hook(__FILE__, 'rewan_booking_activate');

function rewan_booking_init() {
    $admin = new Rewan_Booking_Admin();
    $frontend = new Rewan_Booking_Frontend();

    $admin->init();
    $frontend->init();
}
add_action('plugins_loaded', 'rewan_booking_init');