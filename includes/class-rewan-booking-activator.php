<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rewan_Booking_Activator {

    public static function activate() {
        self::create_services_table();
        self::create_employees_table();
        self::create_employee_hours_table();
        self::create_employee_breaks_table();
        self::create_employee_absences_table();
        self::create_bookings_table();

        self::insert_default_services();
        self::insert_default_employees();
        self::insert_default_employee_hours();
        self::insert_default_employee_breaks();

        flush_rewrite_rules();
    }

    private static function create_services_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rewan_booking_services';
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            description text NULL,
            image_url varchar(500) NOT NULL DEFAULT '',
            price decimal(10,2) NOT NULL DEFAULT 0.00,
            duration int NOT NULL DEFAULT 0,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        dbDelta($sql);
    }

    private static function create_employees_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'rewan_booking_employees';
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            email varchar(255) NOT NULL,
            image_url varchar(500) NOT NULL DEFAULT '',
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        dbDelta($sql);
    }

    private static function create_employee_hours_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'rewan_booking_employee_hours';
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            employee_id mediumint(9) NOT NULL,
            weekday tinyint(1) NOT NULL,
            is_working tinyint(1) NOT NULL DEFAULT 1,
            start_time time NOT NULL DEFAULT '09:00:00',
            end_time time NOT NULL DEFAULT '18:00:00',
            PRIMARY KEY (id),
            UNIQUE KEY employee_day (employee_id, weekday)
        ) $charset_collate;";

        dbDelta($sql);
    }

    private static function create_employee_breaks_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'rewan_booking_employee_breaks';
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            employee_id mediumint(9) NOT NULL,
            weekday tinyint(1) NOT NULL,
            is_enabled tinyint(1) NOT NULL DEFAULT 0,
            break_start time NOT NULL DEFAULT '12:00:00',
            break_end time NOT NULL DEFAULT '13:00:00',
            PRIMARY KEY (id),
            UNIQUE KEY employee_break_day (employee_id, weekday)
        ) $charset_collate;";

        dbDelta($sql);
    }

    private static function create_employee_absences_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'rewan_booking_employee_absences';
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            employee_id mediumint(9) NOT NULL,
            title varchar(255) NOT NULL DEFAULT '',
            absence_type varchar(50) NOT NULL DEFAULT 'absence',
            start_date date NOT NULL,
            end_date date NOT NULL,
            is_all_day tinyint(1) NOT NULL DEFAULT 1,
            start_time time NOT NULL DEFAULT '00:00:00',
            end_time time NOT NULL DEFAULT '23:59:59',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY employee_id (employee_id),
            KEY start_date (start_date),
            KEY end_date (end_date)
        ) $charset_collate;";

        dbDelta($sql);
    }

    private static function create_bookings_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'rewan_booking_bookings';
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            customer_name varchar(255) NOT NULL,
            customer_email varchar(255) NOT NULL,
            customer_phone varchar(100) NOT NULL,
            notes text NULL,
            services text NOT NULL,
            employee_id mediumint(9) NOT NULL,
            employee_name varchar(255) NOT NULL,
            booking_date date NOT NULL,
            start_time time NOT NULL,
            end_time time NOT NULL,
            total_price decimal(10,2) NOT NULL DEFAULT 0.00,
            total_duration int NOT NULL DEFAULT 0,
            status varchar(50) NOT NULL DEFAULT 'confirmed',
            payment_method varchar(50) NOT NULL DEFAULT 'vor_ort',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY employee_id (employee_id),
            KEY booking_date (booking_date),
            KEY start_time (start_time),
            KEY end_time (end_time)
        ) $charset_collate;";

        dbDelta($sql);
    }

    private static function insert_default_services() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'rewan_booking_services';
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name");

        if ($count > 0) {
            return;
        }

        $services = array(
            array('name' => 'Haarschnitt', 'price' => 35.00, 'duration' => 30, 'is_active' => 1),
            array('name' => 'Bartpflege', 'price' => 25.00, 'duration' => 20, 'is_active' => 1),
            array('name' => 'Augenbrauen', 'price' => 15.00, 'duration' => 10, 'is_active' => 1),
            array('name' => 'Haare & Bart', 'price' => 55.00, 'duration' => 50, 'is_active' => 1),
            array('name' => 'Luxuspaket', 'price' => 85.00, 'duration' => 75, 'is_active' => 1),
        );

        foreach ($services as $service) {
            $wpdb->insert(
                $table_name,
                $service,
                array('%s', '%f', '%d', '%d')
            );
        }
    }

    private static function insert_default_employees() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'rewan_booking_employees';
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name");

        if ($count > 0) {
            return;
        }

        $employees = array(
            array(
                'name' => 'Reber',
                'email' => get_option('admin_email'),
                'image_url' => '',
                'is_active' => 1
            ),
            array(
                'name' => 'Rewan',
                'email' => get_option('admin_email'),
                'image_url' => '',
                'is_active' => 1
            ),
            array(
                'name' => 'Achmet',
                'email' => get_option('admin_email'),
                'image_url' => '',
                'is_active' => 1
            ),
        );

        foreach ($employees as $employee) {
            $wpdb->insert(
                $table_name,
                $employee,
                array('%s', '%s', '%s', '%d')
            );
        }
    }

    private static function insert_default_employee_hours() {
        global $wpdb;

        $employees_table = $wpdb->prefix . 'rewan_booking_employees';
        $hours_table = $wpdb->prefix . 'rewan_booking_employee_hours';

        $employees = $wpdb->get_results("SELECT id FROM $employees_table", ARRAY_A);

        if (empty($employees)) {
            return;
        }

        foreach ($employees as $employee) {
            $employee_id = (int) $employee['id'];

            for ($weekday = 1; $weekday <= 7; $weekday++) {
                $exists = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM $hours_table WHERE employee_id = %d AND weekday = %d",
                        $employee_id,
                        $weekday
                    )
                );

                if ($exists > 0) {
                    continue;
                }

                $is_working = ($weekday >= 1 && $weekday <= 6) ? 1 : 0;

                $wpdb->insert(
                    $hours_table,
                    array(
                        'employee_id' => $employee_id,
                        'weekday'     => $weekday,
                        'is_working'  => $is_working,
                        'start_time'  => '09:00:00',
                        'end_time'    => '18:00:00',
                    ),
                    array('%d', '%d', '%d', '%s', '%s')
                );
            }
        }
    }

    private static function insert_default_employee_breaks() {
        global $wpdb;

        $employees_table = $wpdb->prefix . 'rewan_booking_employees';
        $breaks_table = $wpdb->prefix . 'rewan_booking_employee_breaks';

        $employees = $wpdb->get_results("SELECT id FROM $employees_table", ARRAY_A);

        if (empty($employees)) {
            return;
        }

        foreach ($employees as $employee) {
            $employee_id = (int) $employee['id'];

            for ($weekday = 1; $weekday <= 7; $weekday++) {
                $exists = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM $breaks_table WHERE employee_id = %d AND weekday = %d",
                        $employee_id,
                        $weekday
                    )
                );

                if ($exists > 0) {
                    continue;
                }

                $wpdb->insert(
                    $breaks_table,
                    array(
                        'employee_id' => $employee_id,
                        'weekday'     => $weekday,
                        'is_enabled'  => 0,
                        'break_start' => '12:00:00',
                        'break_end'   => '13:00:00',
                    ),
                    array('%d', '%d', '%d', '%s', '%s')
                );
            }
        }
    }
}