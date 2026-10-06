<?php
/**
 * Öffnungszeiten und wirksame Arbeitszeit.
 * Bestehende Mitarbeiterzeiten bleiben gespeichert. Die Öffnung ist nur der Rahmen.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Rewan_Booking_Schedule {

    /**
     * Wirksames Fenster für Slots: Öffnung schneidet die Arbeitszeit, oder die Person folgt dem Laden.
     *
     * @return array<string,mixed>|null
     */
    public static function effective_for_employee($employee_id, $booking_date) {
        $raw = self::raw_employee_hours((int) $employee_id, (string) $booking_date);
        return self::apply_opening((int) $employee_id, (string) $booking_date, $raw);
    }

    /**
     * @param array<string,mixed>|null $raw
     * @return array<string,mixed>|null
     */
    public static function apply_opening($employee_id, $booking_date, $raw) {
        $ts = strtotime((string) $booking_date . ' 12:00:00');
        if (!$ts) {
            return $raw;
        }
        $iso = (int) date('N', $ts);
        $open = self::opening_row($iso);
        if ($open === null) {
            return $raw;
        }
        if ((int) $open['is_open'] !== 1) {
            return array(
                'is_working' => 0,
                'start_time' => '00:00:00',
                'end_time' => '00:00:00',
            );
        }

        $open_start = self::as_hms($open['start_time']);
        $open_end = self::as_hms($open['end_time']);
        if ($open_end <= $open_start) {
            return array(
                'is_working' => 0,
                'start_time' => $open_start,
                'end_time' => $open_end,
            );
        }

        if (self::follows_opening((int) $employee_id)) {
            return array(
                'is_working' => 1,
                'start_time' => $open_start,
                'end_time' => $open_end,
            );
        }

        if (!is_array($raw) || (int) $raw['is_working'] !== 1) {
            return $raw;
        }

        $start = self::as_hms($raw['start_time']);
        $end = self::as_hms($raw['end_time']);
        if (strcmp($start, $open_start) < 0) {
            $start = $open_start;
        }
        if (strcmp($end, $open_end) > 0) {
            $end = $open_end;
        }
        if (strcmp($end, $start) <= 0) {
            $raw['is_working'] = 0;
            $raw['start_time'] = $start;
            $raw['end_time'] = $end;
            return $raw;
        }

        $raw['is_working'] = 1;
        $raw['start_time'] = $start;
        $raw['end_time'] = $end;
        return $raw;
    }

    /**
     * @return array<string,mixed>|null null = Tabelle fehlt, alte Slot-Logik behalten
     */
    public static function opening_row($iso_weekday) {
        global $wpdb;
        $iso_weekday = (int) $iso_weekday;
        if ($iso_weekday < 1 || $iso_weekday > 7) {
            return null;
        }
        $table = $wpdb->prefix . 'rewan_booking_opening_hours';
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ($found !== $table) {
            return null;
        }
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE weekday = %d", $iso_weekday),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    public static function follows_opening($employee_id) {
        global $wpdb;
        $employee_id = (int) $employee_id;
        if ($employee_id <= 0 || !self::employees_have_follows_column()) {
            return false;
        }
        $table = $wpdb->prefix . 'rewan_booking_employees';
        $value = $wpdb->get_var(
            $wpdb->prepare("SELECT follows_opening FROM {$table} WHERE id = %d", $employee_id)
        );
        return (int) $value === 1;
    }

    public static function employees_have_follows_column() {
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'rewan_booking_employees';
        $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'follows_opening'));
        $has = !empty($col);
        return $has;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function raw_employee_hours($employee_id, $booking_date) {
        global $wpdb;
        $employee_id = (int) $employee_id;
        $hours_table = $wpdb->prefix . 'rewan_booking_employee_hours';
        $ts = strtotime((string) $booking_date . ' 12:00:00');
        if (!$ts || $employee_id <= 0) {
            return null;
        }
        $weekday_iso = (int) date('N', $ts);
        $weekday_legacy = $weekday_iso - 1;
        $use_legacy = self::employee_weekday_mode($hours_table, $employee_id) === 'legacy';
        $primary = $use_legacy ? $weekday_legacy : $weekday_iso;
        $fallback = $use_legacy ? $weekday_iso : $weekday_legacy;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$hours_table}
                 WHERE employee_id = %d
                 AND weekday IN (%d, %d)
                 ORDER BY CASE WHEN weekday = %d THEN 0 ELSE 1 END
                 LIMIT 1",
                $employee_id,
                $primary,
                $fallback,
                $primary
            ),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    private static function employee_weekday_mode($table_name, $employee_id) {
        global $wpdb;
        $employee_id = (int) $employee_id;
        $has_zero = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE employee_id = %d AND weekday = 0", $employee_id)
        );
        $has_seven = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE employee_id = %d AND weekday = 7", $employee_id)
        );
        $max_weekday = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COALESCE(MAX(weekday), -1) FROM {$table_name} WHERE employee_id = %d", $employee_id)
        );
        if ($has_zero > 0 && $has_seven === 0) {
            return 'legacy';
        }
        if ($has_seven > 0 && $has_zero === 0) {
            return 'iso';
        }
        if ($has_zero === 0 && $has_seven === 0 && $max_weekday >= 0 && $max_weekday <= 6) {
            return 'legacy';
        }
        return 'mixed';
    }

    private static function as_hms($value) {
        $value = (string) $value;
        if (preg_match('/^\d{2}:\d{2}$/', $value)) {
            return $value . ':00';
        }
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)) {
            return $value;
        }
        return '00:00:00';
    }
}
