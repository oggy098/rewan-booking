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
        self::create_booking_global_blocks_table();
        self::create_global_week_schedule_table();
        self::create_opening_hours_table();
        self::ensure_follows_opening_column();
        self::create_bookings_table();

        self::insert_default_services();
        self::insert_default_employees();
        self::insert_default_employee_hours();
        self::insert_default_employee_breaks();

        self::install_roles_and_caps();

        flush_rewrite_rules();
    }

    /**
     * Rollen und Berechtigungen nachziehen (z. B. nach Plugin-Update).
     */
    public static function maybe_upgrade() {
        $stored = get_option('rewan_booking_plugin_version', '0');
        if (version_compare((string) $stored, REWAN_BOOKING_VERSION, '<')) {
            self::install_roles_and_caps();
            self::create_booking_global_blocks_table();
            self::create_global_week_schedule_table();
            self::create_opening_hours_table();
            self::ensure_follows_opening_column();
            update_option('rewan_booking_plugin_version', REWAN_BOOKING_VERSION);
        }
    }

    /**
     * Administrator: voller Zugriff. Rolle „Salon · Rewan Buchung“: nur Salon-Bereich.
     */
    public static function install_roles_and_caps() {
        if (!defined('REWAN_BOOKING_CAP_SALON') || !defined('REWAN_BOOKING_CAP_MANAGE')) {
            return;
        }

        $cap_salon = REWAN_BOOKING_CAP_SALON;
        $cap_manage = REWAN_BOOKING_CAP_MANAGE;

        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap($cap_salon);
            $admin->add_cap($cap_manage);
        }

        $role = get_role('rewan_booking_salon');
        if (!$role) {
            add_role(
                'rewan_booking_salon',
                'Salon · Rewan Buchung',
                array(
                    'read' => true,
                    $cap_salon => true,
                )
            );
        }
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

    private static function create_booking_global_blocks_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'rewan_booking_global_blocks';
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL DEFAULT '',
            start_date date NOT NULL,
            end_date date NOT NULL,
            is_all_day tinyint(1) NOT NULL DEFAULT 1,
            start_time time NOT NULL DEFAULT '00:00:00',
            end_time time NOT NULL DEFAULT '23:59:59',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY start_date (start_date),
            KEY end_date (end_date)
        ) $charset_collate;";

        dbDelta($sql);
    }

    private static function create_global_week_schedule_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'rewan_booking_global_week_schedule';
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE $table_name (
            weekday tinyint(1) NOT NULL,
            block_mode varchar(20) NOT NULL DEFAULT 'none',
            start_time time NOT NULL DEFAULT '12:00:00',
            end_time time NOT NULL DEFAULT '13:00:00',
            PRIMARY KEY (weekday)
        ) $charset_collate;";

        dbDelta($sql);
        self::ensure_global_week_schedule_seeded();
    }

    /**
     * Genau 7 Zeilen (Wochentag 1–7), Standard: keine Sperre.
     */
    private static function ensure_global_week_schedule_seeded() {
        global $wpdb;
        $t = $wpdb->prefix . 'rewan_booking_global_week_schedule';
        $existing = $wpdb->get_col("SELECT weekday FROM {$t}");
        $have = array_map('intval', is_array($existing) ? $existing : array());
        for ($w = 1; $w <= 7; $w++) {
            if (in_array($w, $have, true)) {
                continue;
            }
            $wpdb->insert(
                $t,
                array(
                    'weekday' => $w,
                    'block_mode' => 'none',
                    'start_time' => '12:00:00',
                    'end_time' => '13:00:00',
                ),
                array('%d', '%s', '%s', '%s')
            );
        }
    }

    /**
     * Laden-Öffnungszeiten. Bestehende Buchungen und Mitarbeiterzeiten werden nicht gelöscht.
     * Die erste Befüllung umschliesst die schon gespeicherten Arbeitszeiten, damit Slots gleich bleiben.
     */
    private static function create_opening_hours_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'rewan_booking_opening_hours';
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE $table_name (
            weekday tinyint(1) NOT NULL,
            is_open tinyint(1) NOT NULL DEFAULT 1,
            start_time time NOT NULL DEFAULT '09:00:00',
            end_time time NOT NULL DEFAULT '18:00:00',
            PRIMARY KEY (weekday)
        ) $charset_collate;";

        dbDelta($sql);
        self::seed_opening_hours_once();
    }

    private static function ensure_follows_opening_column() {
        global $wpdb;
        $table = $wpdb->prefix . 'rewan_booking_employees';
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ($found !== $table) {
            return;
        }
        $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'follows_opening'));
        if (!empty($col)) {
            return;
        }
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN follows_opening tinyint(1) NOT NULL DEFAULT 0");
    }

    /**
     * Einmalig. Danach bleiben manuelle Öffnungszeiten unangetastet.
     */
    private static function seed_opening_hours_once() {
        if (get_option('rewan_booking_opening_seeded') === '1') {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'rewan_booking_opening_hours';
        $hours = $wpdb->prefix . 'rewan_booking_employee_hours';
        $rows = array();
        $hours_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($hours)));
        if ($hours_exists === $hours) {
            $rows = $wpdb->get_results(
                "SELECT weekday, start_time, end_time FROM {$hours} WHERE is_working = 1",
                ARRAY_A
            );
            if (!is_array($rows)) {
                $rows = array();
            }
        }

        $weekdays = array();
        foreach ($rows as $row) {
            $weekdays[] = (int) $row['weekday'];
        }
        $has_zero = in_array(0, $weekdays, true);
        $has_seven = in_array(7, $weekdays, true);
        $mixed = $has_zero && $has_seven;
        $legacy = $has_zero && !$has_seven;

        $wide_start = '09:00:00';
        $wide_end = '18:00:00';
        if ($mixed && !empty($rows)) {
            $starts = array();
            $ends = array();
            foreach ($rows as $row) {
                $starts[] = self::normalize_seed_time($row['start_time']);
                $ends[] = self::normalize_seed_time($row['end_time']);
            }
            sort($starts);
            rsort($ends);
            $wide_start = $starts[0];
            $wide_end = $ends[0];
        }

        for ($iso = 1; $iso <= 7; $iso++) {
            $is_open = ($iso === 7) ? 0 : 1;
            $start = '09:00:00';
            $end = '18:00:00';

            if ($mixed) {
                $is_open = 1;
                $start = $wide_start;
                $end = $wide_end;
            } elseif (!empty($rows)) {
                $key = $legacy ? ($iso - 1) : $iso;
                $starts = array();
                $ends = array();
                foreach ($rows as $row) {
                    if ((int) $row['weekday'] !== $key) {
                        continue;
                    }
                    $starts[] = self::normalize_seed_time($row['start_time']);
                    $ends[] = self::normalize_seed_time($row['end_time']);
                }
                if (empty($starts)) {
                    $is_open = 0;
                } else {
                    sort($starts);
                    rsort($ends);
                    $is_open = 1;
                    $start = $starts[0];
                    $end = $ends[0];
                }
            }

            if ($end <= $start) {
                $is_open = 0;
                $start = '09:00:00';
                $end = '18:00:00';
            }

            $wpdb->replace(
                $table,
                array(
                    'weekday' => $iso,
                    'is_open' => $is_open,
                    'start_time' => $start,
                    'end_time' => $end,
                ),
                array('%d', '%d', '%s', '%s')
            );
        }

        update_option('rewan_booking_opening_seeded', '1', false);
    }

    private static function normalize_seed_time($value) {
        $value = substr((string) $value, 0, 8);
        if (preg_match('/^\d{2}:\d{2}$/', $value)) {
            return $value . ':00';
        }
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)) {
            return $value;
        }
        return '09:00:00';
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
                'name' => 'Max Muster',
                'email' => 'max.muster@example.com',
                'image_url' => '',
                'is_active' => 1
            ),
            array(
                'name' => 'Lea Muster',
                'email' => 'lea.muster@example.com',
                'image_url' => '',
                'is_active' => 1
            ),
            array(
                'name' => 'Sam Muster',
                'email' => 'sam.muster@example.com',
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