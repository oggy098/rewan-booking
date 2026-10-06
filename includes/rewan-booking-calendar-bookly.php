<?php
/**
 * Bookly-Kalender-UI für Rewan Booking: gleiche Assets/Markup wie Bookly, Daten aus Rewan-Tabellen.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Rewan_Booking_Calendar_Bookly {

    /**
     * @return string|false Absoluter Pfad zu Bookly main.php
     */
    private static function bookly_main_file() {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $paths = array(
            WP_PLUGIN_DIR . '/bookly-responsive-appointment-booking-tool/main.php',
            WP_PLUGIN_DIR . '/bookly/main.php',
        );
        $cached = false;
        foreach ($paths as $p) {
            if (is_readable($p)) {
                $cached = $p;
                break;
            }
        }
        return $cached;
    }

    /**
     * Registriert Bookly-Backend-Skripte und -Styles (Bootstrap, Globals-Bundle). Kein enqueue.
     *
     * @return string|false bookly main.php oder false
     */
    public static function register_bookly_backend_base() {
        $main = self::bookly_main_file();
        if (!$main) {
            return false;
        }
        $v = (string) @filemtime(dirname($main) . '/backend/resources/bootstrap/css/bootstrap.min.css');
        if ($v === '0' || $v === '') {
            $v = defined('REWAN_BOOKING_VERSION') ? REWAN_BOOKING_VERSION : '1';
        }

        if (!wp_script_is('bookly-backend-globals', 'registered')) {
            $b = static function ($handle, $rel, $deps = array()) use ($main, $v) {
                if (!wp_script_is($handle, 'registered')) {
                    wp_register_script($handle, plugins_url($rel, $main), $deps, $v, true);
                }
            };
            $b('bookly-moment.min.js', 'backend/resources/js/moment.min.js', array());
            $b('bookly-bootstrap.min.js', 'backend/resources/bootstrap/js/bootstrap.min.js', array('jquery'));
            $b('bookly-datatables.min.js', 'backend/resources/js/datatables.min.js', array('jquery'));
            $b('bookly-spin.min.js', 'frontend/resources/js/spin.min.js', array('jquery'));
            $b('bookly-ladda.min.js', 'frontend/resources/js/ladda.min.js', array('jquery'));
            wp_register_script('bookly-globals', false, array('bookly-spin.min.js'), $v, true);
            $b('bookly-daterangepicker.js', 'backend/resources/js/daterangepicker.js', array('bookly-moment.min.js', 'jquery'));
            $b('bookly-dropdown.js', 'backend/resources/js/dropdown.js', array('jquery'));
            $b('bookly-common.js', 'backend/resources/js/common.js', array('jquery'));
            $b('bookly-select2.min.js', 'backend/resources/js/select2.min.js', array('jquery'));
            wp_register_script(
                'bookly-backend-globals',
                false,
                array(
                    'bookly-globals',
                    'bookly-bootstrap.min.js',
                    'bookly-datatables.min.js',
                    'bookly-daterangepicker.js',
                    'bookly-dropdown.js',
                    'bookly-select2.min.js',
                    'bookly-common.js',
                    'bookly-spin.min.js',
                    'bookly-ladda.min.js',
                ),
                $v,
                true
            );
        }

        if (!wp_style_is('bookly-bootstrap.min.css', 'registered')) {
            wp_register_style('bookly-bootstrap.min.css', plugins_url('backend/resources/bootstrap/css/bootstrap.min.css', $main), array(), $v);
            wp_register_style('bookly-ladda.min.css', plugins_url('frontend/resources/css/ladda.min.css', $main), array('bookly-bootstrap.min.css'), $v);
            wp_register_style('bookly-backend-globals', false, array('bookly-bootstrap.min.css', 'bookly-ladda.min.css'), $v);
        }

        if (!wp_style_is('bookly-fontawesome-admin', 'registered')) {
            wp_register_style(
                'bookly-fontawesome-admin',
                plugins_url('backend/resources/css/fontawesome-all.min.css', $main),
                array('bookly-bootstrap.min.css'),
                $v
            );
        }

        return $main;
    }

    /**
     * Styles wie Bookly Appointments: Bootstrap + Tailwind (bookly-css-root), ohne appointments.js.
     */
    public static function enqueue_bookly_appointments_shell() {
        $main = self::register_bookly_backend_base();
        if (!$main) {
            return;
        }
        $v = (string) @filemtime(dirname($main) . '/backend/resources/bootstrap/css/bootstrap.min.css');
        if ($v === '0' || $v === '') {
            $v = defined('REWAN_BOOKING_VERSION') ? REWAN_BOOKING_VERSION : '1';
        }
        if (!wp_style_is('bookly-tailwind.css', 'registered')) {
            wp_register_style(
                'bookly-tailwind.css',
                plugins_url('backend/resources/tailwind/tailwind.css', $main),
                array('bookly-backend-globals'),
                $v
            );
        }
        wp_enqueue_style('bookly-backend-globals');
        wp_enqueue_style('bookly-fontawesome-admin');
        wp_enqueue_style('bookly-tailwind.css');
        wp_enqueue_style(
            'rewan-booking-dashboard-bookly',
            rewan_booking_plugin_url('assets/css/rewan-booking-dashboard-bookly.css'),
            array('bookly-backend-globals'),
            REWAN_BOOKING_VERSION
        );
    }

    /**
     * Assets für Bookly Days-Off-Ansicht (jCal + Popover) auf Rewan Ferien-Seite.
     *
     * @param array<int,array<int,array<string,int>>> $events_map employee_id => events
     * @param array<int,string> $employee_colors employee_id => hex color (für Legende / Auswahl)
     */
    public static function enqueue_days_off_assets(array $events_map, array $employee_colors = array()) {
        $ver = defined('REWAN_BOOKING_VERSION') ? REWAN_BOOKING_VERSION : '1';

        $main = false;

        $shim_deps = array('jquery');
        if ($main) {
            $shim_deps[] = 'bookly-backend-globals';
        }
        wp_register_script(
            'rewan-booking-jcal-popover-shim',
            rewan_booking_plugin_url('assets/js/rewan-booking-jcal-popover-shim.js'),
            $shim_deps,
            $ver,
            true
        );

        if ($main) {
            $jcal_deps = array('jquery', 'bookly-backend-globals', 'rewan-booking-jcal-popover-shim');
        } else {
            $jcal_deps = array('jquery', 'rewan-booking-jcal-popover-shim');
        }

        wp_register_script(
            'rewan-booking-jcal-days-off',
            rewan_booking_plugin_url('assets/js/rewan-booking-jcal-days-off.js'),
            $jcal_deps,
            $ver,
            true
        );

        if ($main) {
            $v = (string) @filemtime(dirname($main) . '/backend/resources/bootstrap/css/bootstrap.min.css');
            if ($v === '0' || $v === '') {
                $v = $ver;
            }
            wp_enqueue_style('bookly-backend-globals');
            wp_enqueue_style('bookly-fontawesome-admin');
            wp_enqueue_script('bookly-backend-globals');
            $dash_css_deps = array('bookly-backend-globals');
        } else {
            if (!wp_style_is('rewan-booking-bootstrap-standalone', 'registered')) {
                wp_register_style(
                    'rewan-booking-bootstrap-standalone',
                    rewan_booking_plugin_url('assets/css/rewan-booking-bootstrap-4.6.2.min.css'),
                    array(),
                    $ver
                );
            }
            wp_enqueue_style('rewan-booking-bootstrap-standalone');
            $dash_css_deps = array('rewan-booking-bootstrap-standalone');
        }

        wp_enqueue_script('rewan-booking-jcal-days-off');

        wp_enqueue_style(
            'rewan-booking-dashboard-bookly',
            rewan_booking_plugin_url('assets/css/rewan-booking-dashboard-bookly.css'),
            $dash_css_deps,
            $ver
        );

        $days_off_deps = array('jquery', 'rewan-booking-jcal-days-off');
        if ($main) {
            $days_off_deps[] = 'bookly-backend-globals';
        }

        wp_enqueue_script(
            'rewan-booking-days-off',
            rewan_booking_plugin_url('assets/js/rewan-booking-days-off.js'),
            $days_off_deps,
            $ver,
            true
        );
        wp_localize_script(
            'rewan-booking-days-off',
            'RewanDaysOffL10n',
            array(
                'eventsMap' => $events_map,
                'employeeColors' => $employee_colors,
                'standalone' => !$main,
                'firstDay' => (int) get_option('start_of_week', 1),
                'months' => array_values($GLOBALS['wp_locale']->month),
                'days' => array_values($GLOBALS['wp_locale']->weekday_abbrev),
                'weAreNotWorking' => __('Frei', 'rewan-booking'),
                'close' => __('Fertig', 'rewan-booking'),
                'popoverTitle' => __('Frei eintragen', 'rewan-booking'),
                'popoverIntro' => '',
                'sectionWholeTitle' => __('Ganztägig', 'rewan-booking'),
                'sectionWholeHint' => '',
                'btnWholeDayFree' => __('Ganze Markierung frei', 'rewan-booking'),
                'sectionTimes' => __('Mit Uhrzeit', 'rewan-booking'),
                'timesHint' => '',
                'btnSaveTimes' => __('Speichern', 'rewan-booking'),
                'btnNotFree' => __('Frei aufheben', 'rewan-booking'),
                'labelFromTime' => __('Von', 'rewan-booking'),
                'labelToTime' => __('Bis', 'rewan-booking'),
                'alertTimes' => __('Bitte Von- und Bis-Uhrzeit angeben.', 'rewan-booking'),
                'ajaxError' => __('Die Anfrage ist fehlgeschlagen. Bitte erneut versuchen.', 'rewan-booking'),
                'csrfToken' => class_exists('\Bookly\Lib\Utils\Common')
                    ? \Bookly\Lib\Utils\Common::getCsrfToken()
                    : wp_create_nonce('rewan_booking_cal'),
            )
        );
    }

    /**
     * Registriert und lädt Bookly-Backend-Bundle + Kalender-Skripte (wie Bookly\Backend\Modules\Calendar\Page).
     */
    public static function enqueue_assets() {
        $main = self::register_bookly_backend_base();
        if (!$main) {
            return;
        }
        $v = (string) @filemtime(dirname($main) . '/backend/resources/bootstrap/css/bootstrap.min.css');
        if ($v === '0' || $v === '') {
            $v = defined('REWAN_BOOKING_VERSION') ? REWAN_BOOKING_VERSION : '1';
        }

        $calendar_version = get_option('bookly_legacy_calendar') ? 'legacy' : 'latest';
        $ec_css = $calendar_version !== 'latest' ? 'event-calendar-4.min.css' : 'event-calendar.min.css';
        $ec_js = $calendar_version !== 'latest' ? 'event-calendar-4.min.js' : 'event-calendar.min.js';

        if (!wp_style_is('bookly-event-calendar.min.css', 'registered')) {
            wp_register_style(
                'bookly-event-calendar.min.css',
                plugins_url('backend/modules/calendar/resources/css/' . $ec_css, $main),
                array('bookly-backend-globals'),
                $v
            );
        }
        $ec_handle = $calendar_version !== 'latest' ? 'bookly-event-calendar-4.min.js' : 'bookly-event-calendar.min.js';
        if (!wp_script_is($ec_handle, 'registered')) {
            wp_register_script(
                $ec_handle,
                plugins_url('backend/modules/calendar/resources/js/' . $ec_js, $main),
                array('bookly-backend-globals'),
                $v,
                true
            );
        }
        if (!wp_script_is('bookly-calendar-common.js', 'registered')) {
            wp_register_script(
                'bookly-calendar-common.js',
                plugins_url('backend/modules/calendar/resources/js/calendar-common.js', $main),
                array($ec_handle),
                $v,
                true
            );
        }
        if (!wp_script_is('bookly-calendar.js', 'registered')) {
            wp_register_script(
                'bookly-calendar.js',
                plugins_url('backend/modules/calendar/resources/js/calendar.js', $main),
                array('bookly-calendar-common.js', 'bookly-dropdown.js'),
                $v,
                true
            );
        }
        if (!wp_script_is('bookly-nav-scrollable.js', 'registered')) {
            wp_register_script(
                'bookly-nav-scrollable.js',
                plugins_url('backend/resources/js/nav-scrollable.js', $main),
                array('bookly-backend-globals'),
                $v,
                true
            );
        }

        wp_enqueue_style('bookly-backend-globals');
        wp_enqueue_style('bookly-fontawesome-admin');
        wp_enqueue_style('bookly-event-calendar.min.css');
        wp_enqueue_script('bookly-backend-globals');
        wp_enqueue_script($ec_handle);
        wp_enqueue_script('bookly-calendar-common.js');
        wp_enqueue_script('bookly-calendar.js');
        wp_enqueue_script('bookly-nav-scrollable.js');

        self::localize_globals($main);
        self::localize_calendar($calendar_version);
        self::inline_ajax_patch();
    }

    private static function localize_globals($main) {
        if (class_exists('\Bookly\Lib\Utils\Common') && class_exists('\Bookly\Lib\Utils\DateTime') && class_exists('\Bookly\Lib\Proxy\Shared')) {
            global $sitepress;
            $ajax_url = admin_url('admin-ajax.php');
            wp_localize_script(
                'bookly-globals',
                'BooklyL10nGlobal',
                \Bookly\Lib\Proxy\Shared::prepareL10nGlobal(
                    array(
                        'csrf_token' => \Bookly\Lib\Utils\Common::getCsrfToken(),
                        'ajax_url_backend' => $ajax_url,
                        'ajax_url_frontend' => $sitepress instanceof \SitePress ? add_query_arg(array('lang' => $sitepress->get_current_language()), $ajax_url) : admin_url('admin-ajax.php'),
                        'mjsTimeFormat' => \Bookly\Lib\Utils\DateTime::convertFormat('time', \Bookly\Lib\Utils\DateTime::FORMAT_MOMENT_JS),
                        'datePicker' => \Bookly\Lib\Utils\DateTime::datePickerOptions(),
                        'dateRange' => \Bookly\Lib\Utils\DateTime::dateRangeOptions(),
                        'l10n' => array(
                            'apply' => __('Apply', 'bookly'),
                            'cancel' => __('Cancel', 'bookly'),
                            'areYouSure' => __('Are you sure?', 'bookly'),
                        ),
                        'addons' => array(),
                        'cloud_products' => get_option('bookly_cloud_account_products', array()),
                        'data' => (object) array(),
                    )
                )
            );
            return;
        }
        wp_localize_script(
            'bookly-globals',
            'BooklyL10nGlobal',
            array(
                'csrf_token' => wp_create_nonce('rewan_booking_cal'),
                'ajax_url_backend' => admin_url('admin-ajax.php'),
                'ajax_url_frontend' => admin_url('admin-ajax.php'),
                'mjsTimeFormat' => 'HH:mm',
                'datePicker' => array(),
                'dateRange' => array(),
                'l10n' => array(
                    'apply' => __('Apply', 'rewan-booking'),
                    'cancel' => __('Cancel', 'rewan-booking'),
                    'areYouSure' => __('Are you sure?', 'rewan-booking'),
                ),
                'addons' => array(),
                'cloud_products' => array(),
                'data' => (object) array(),
            )
        );
    }

    private static function localize_calendar($calendar_version) {
        if (class_exists('\Bookly\Lib\Utils\Common') && class_exists('\Bookly\Lib\Config')) {
            wp_localize_script(
                'bookly-calendar.js',
                'BooklyL10n',
                array_merge(
                    \Bookly\Lib\Utils\Common::getCalendarSettings(),
                    array(
                        'calendar_version' => $calendar_version,
                        'clmn_min_width' => get_option('bookly_bc_clmn_min_width', '120'),
                        'delete' => __('Delete', 'bookly'),
                        'are_you_sure' => __('Are you sure?', 'bookly'),
                        'filterResourcesWithEvents' => \Bookly\Lib\Config::showOnlyStaffWithAppointmentsInCalendarDayView(),
                        'scrollable_calendar' => (int) get_option('bookly_cal_scrollable_calendar', '1'),
                        'recurring_appointments' => array(
                            'active' => (int) \Bookly\Lib\Config::recurringAppointmentsActive(),
                            'title' => __('Recurring appointments', 'bookly'),
                        ),
                        'waiting_list' => array(
                            'active' => (int) \Bookly\Lib\Config::waitingListActive(),
                            'title' => __('On waiting list', 'bookly'),
                        ),
                        'packages' => array(
                            'active' => (int) \Bookly\Lib\Config::packagesActive(),
                            'title' => __('Package', 'bookly'),
                        ),
                        'events' => array(
                            'attendees' => __('Attendees', 'bookly'),
                        ),
                    )
                )
            );
            return;
        }
        wp_localize_script(
            'bookly-calendar.js',
            'BooklyL10n',
            array(
                'locale' => str_replace('_', '-', get_locale()),
                'today' => __('Today', 'rewan-booking'),
                'month' => __('Month', 'rewan-booking'),
                'week' => __('Week', 'rewan-booking'),
                'day' => __('Day', 'rewan-booking'),
                'list' => __('List', 'rewan-booking'),
                'timeline' => __('Timeline', 'rewan-booking'),
                'slotMinTime' => '00:00:00',
                'slotMaxTime' => '24:00:00',
                'scrollTime' => '08:00:00',
                'mjsTimeFormat' => 'HH:mm',
                'datePicker' => array(
                    'dayNames' => array_values($GLOBALS['wp_locale']->weekday),
                    'dayNamesShort' => array_values($GLOBALS['wp_locale']->weekday_abbrev),
                    'monthNames' => array_values($GLOBALS['wp_locale']->month),
                    'monthNamesShort' => array_values($GLOBALS['wp_locale']->month_abbrev),
                    'firstDay' => (int) get_option('start_of_week', 1),
                ),
                'calendar_version' => $calendar_version,
                'clmn_min_width' => '120',
                'delete' => __('Delete', 'rewan-booking'),
                'are_you_sure' => __('Are you sure?', 'rewan-booking'),
                'filterResourcesWithEvents' => false,
                'scrollable_calendar' => 1,
                'recurring_appointments' => array('active' => 0, 'title' => ''),
                'waiting_list' => array('active' => 0, 'title' => ''),
                'packages' => array('active' => 0, 'title' => ''),
                'events' => array('attendees' => __('Attendees', 'rewan-booking')),
                'more' => __('+%d more', 'rewan-booking'),
                'noEvents' => __('No appointments', 'rewan-booking'),
                'allDay' => __('Ganztägig', 'rewan-booking'),
            )
        );
    }

    private static function inline_ajax_patch() {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $js = <<<'JS'
jQuery(function ($) {
    $.ajaxPrefilter(function (options) {
        if (!options.data) {
            return;
        }
        if (typeof options.data === 'string') {
            if (options.data.indexOf('action=bookly_get_staff_appointments') !== -1) {
                options.data = options.data.replace(/action=bookly_get_staff_appointments/g, 'action=rewan_booking_get_staff_appointments');
            }
            return;
        }
        if (typeof options.data === 'object' && options.data.action === 'bookly_get_staff_appointments') {
            options.data = $.extend({}, options.data, { action: 'rewan_booking_get_staff_appointments' });
        }
    });
});
JS;
        wp_add_inline_script('bookly-calendar.js', $js, 'before');
    }

    /**
     * AJAX: Event-Daten für Bookly Event Calendar (Rewan-Buchungen).
     */
    public static function ajax_get_staff_appointments() {
        if (!is_user_logged_in() || !current_user_can(defined('REWAN_BOOKING_CAP_SALON') ? REWAN_BOOKING_CAP_SALON : 'read')) {
            wp_send_json(array());
        }
        $csrf = isset($_REQUEST['csrf_token']) ? sanitize_text_field(wp_unslash($_REQUEST['csrf_token'])) : '';
        $csrf_ok = class_exists('\Bookly\Lib\Utils\Common')
            ? wp_verify_nonce($csrf, 'bookly')
            : wp_verify_nonce($csrf, 'rewan_booking_cal');
        if (!$csrf_ok) {
            wp_send_json(array());
        }
        if (!isset($_REQUEST['start'], $_REQUEST['end'])) {
            wp_send_json(array());
        }
        $start = sanitize_text_field(wp_unslash($_REQUEST['start']));
        $end = sanitize_text_field(wp_unslash($_REQUEST['end']));
        if (!preg_match('/^[\d\-T:+Z.]+$/', $start) || !preg_match('/^[\d\-T:+Z.]+$/', $end)) {
            wp_send_json(array());
        }

        $staff_ids_raw = isset($_REQUEST['staff_ids']) ? sanitize_text_field(wp_unslash($_REQUEST['staff_ids'])) : '';
        $service_ids_raw = isset($_REQUEST['service_ids']) ? sanitize_text_field(wp_unslash($_REQUEST['service_ids'])) : '';

        $staff_ids = array_filter(array_map('intval', array_filter(explode(',', $staff_ids_raw))));
        $service_ids = array_filter(explode(',', $service_ids_raw));

        global $wpdb;
        $bookings_table = $wpdb->prefix . 'rewan_booking_bookings';
        $services_table = $wpdb->prefix . 'rewan_booking_services';

        $service_id_to_name = array();
        $rows = $wpdb->get_results("SELECT id, name FROM {$services_table} WHERE is_active = 1", ARRAY_A);
        foreach ((array) $rows as $r) {
            $service_id_to_name[ (int) $r['id'] ] = (string) $r['name'];
        }

        $allowed_names = array();
        if (empty($service_ids) || in_array('all', $service_ids, true)) {
            $allowed_names = null;
        } else {
            foreach ($service_ids as $sid) {
                if ($sid === 'custom') {
                    continue;
                }
                $id = (int) $sid;
                if ($id > 0 && isset($service_id_to_name[ $id ])) {
                    $allowed_names[] = $service_id_to_name[ $id ];
                }
            }
            if (empty($allowed_names)) {
                $allowed_names = null;
            }
        }

        if (current_user_can(defined('REWAN_BOOKING_CAP_MANAGE') ? REWAN_BOOKING_CAP_MANAGE : 'manage_options')) {
            if (empty($staff_ids)) {
                $staff_ids = array_map('intval', wp_list_pluck($wpdb->get_results("SELECT id FROM {$wpdb->prefix}rewan_booking_employees WHERE is_active = 1", ARRAY_A), 'id'));
            }
        } else {
            $linked = (int) get_user_meta(get_current_user_id(), 'rewan_booking_employee_id', true);
            if ($linked > 0) {
                $staff_ids = array($linked);
            } elseif (empty($staff_ids)) {
                $staff_ids = array_map('intval', wp_list_pluck($wpdb->get_results("SELECT id FROM {$wpdb->prefix}rewan_booking_employees WHERE is_active = 1", ARRAY_A), 'id'));
            }
        }

        $start_dt = new DateTimeImmutable($start);
        $end_dt = new DateTimeImmutable($end);
        $start_dt = $start_dt->modify('-1 day');
        $end_dt = $end_dt->modify('+1 day');
        $from = $start_dt->format('Y-m-d');
        $to = $end_dt->format('Y-m-d');

        if (empty($staff_ids)) {
            wp_send_json(array());
        }

        $placeholders = implode(',', array_fill(0, count($staff_ids), '%d'));
        $sql = "SELECT * FROM {$bookings_table} WHERE booking_date BETWEEN %s AND %s AND employee_id IN ($placeholders) ORDER BY booking_date ASC, start_time ASC";
        $params = array_merge(array($from, $to), $staff_ids);
        $bookings = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        $events = array();
        foreach ((array) $bookings as $b) {
            if ($allowed_names !== null) {
                $names = json_decode((string) $b['services'], true);
                if (!is_array($names)) {
                    $names = array();
                }
                $intersect = array_intersect($allowed_names, $names);
                if (empty($intersect)) {
                    continue;
                }
            }
            $start_ts = trim($b['booking_date'] . ' ' . $b['start_time']);
            $end_ts = trim($b['booking_date'] . ' ' . $b['end_time']);
            $color = $b['status'] === 'cancelled' ? '#b32d2e' : '#6aab20';
            $title = $b['customer_name'];
            $svc = is_array(json_decode((string) $b['services'], true)) ? implode(', ', array_map('strval', json_decode((string) $b['services'], true))) : '';
            $tip = '<div class="d-block text-muted mb-2">' . esc_html($svc) . '</div>';
            $tip .= '<div><strong>' . esc_html($b['customer_name']) . '</strong><br>' . esc_html($b['customer_phone']) . '<br>' . esc_html($b['customer_email']) . '</div>';
            $desc = $title . ' — ' . $svc;
            $events[] = array(
                'id' => (int) $b['id'],
                'start' => $start_ts,
                'end' => $end_ts,
                'title' => ' ',
                'color' => $color,
                'resourceId' => (int) $b['employee_id'],
                'allDay' => false,
                'extendedProps' => array(
                    'tooltip' => $tip,
                    'desc' => esc_html($desc),
                    'staffId' => (int) $b['employee_id'],
                    'series_id' => null,
                    'package_id' => null,
                    'waitlisted' => null,
                    'staff_any' => 0,
                    'overall_status' => $b['status'],
                    'participants' => 'one',
                ),
            );
        }

        wp_send_json($events);
    }

    /**
     * Markup wie Bookly calendar.php (ohne Outlook/Google-Sync).
     *
     * @param array<int,array<string,mixed>> $employees Staff-Dropdown wie Bookly: [ cat_id => [ 'name' => '', 'items' => [ ['id','full_name','image_url'] ] ] ]
     * @param array<int,array<string,mixed>> $services_dropdown [ cat_id => [ 'name' => '', 'items' => [ ['id','title'] ] ] ]
     * @param int $refresh_rate Sekunden (0 = aus), wie Bookly user meta bookly_calendar_refresh_rate
     */
    public static function render_calendar_shell(array $employees, array $services_dropdown, $refresh_rate) {
        $refresh_rate = (int) $refresh_rate;
        $flat_staff = array();
        foreach ($employees as $cat) {
            foreach ($cat['items'] as $st) {
                $flat_staff[] = $st;
            }
        }
        $staff_count = count($flat_staff);
        $btn_class = $refresh_rate > 0 ? 'btn-success' : 'btn-default';
        $is_manage = current_user_can(defined('REWAN_BOOKING_CAP_MANAGE') ? REWAN_BOOKING_CAP_MANAGE : 'manage_options');
        ?>
        <div id="bookly-tbs" class="wrap">
            <div class="form-row align-items-center mb-3">
                <h4 class="col m-0"><?php esc_html_e('Kalender', 'rewan-booking'); ?></h4>
            </div>
            <div class="card">
                <div class="card-body">
                    <?php if ($staff_count > 0) : ?>
                        <div class="form-row justify-content-xl-end justify-content-center">
                            <div class="col-sm-auto mb-2">
                                <ul id="bookly-js-services-filter"
                                    data-icon-class="far fa-dot-circle"
                                    data-align="right"
                                    data-txt-select-all="<?php echo esc_attr__('Alle Dienstleistungen', 'rewan-booking'); ?>"
                                    data-txt-all-selected="<?php echo esc_attr__('Alle Dienstleistungen', 'rewan-booking'); ?>"
                                    data-txt-nothing-selected="<?php echo esc_attr__('Keine Dienstleistung ausgewählt', 'rewan-booking'); ?>"
                                >
                                    <?php foreach ($services_dropdown as $category_id => $category) : ?>
                                        <li<?php echo ! $category_id ? ' data-flatten-if-single' : ''; ?>><?php echo esc_html($category['name']); ?>
                                            <ul>
                                                <?php foreach ($category['items'] as $service) : ?>
                                                    <li data-value="<?php echo esc_attr((string) $service['id']); ?>">
                                                        <?php echo esc_html($service['title']); ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php if ($is_manage) : ?>
                                <div class="col-sm-auto mb-2">
                                    <ul id="bookly-js-staff-filter"
                                        data-align="right"
                                        data-txt-select-all="<?php echo esc_attr__('Alle Mitarbeiter', 'rewan-booking'); ?>"
                                        data-txt-all-selected="<?php echo esc_attr__('Alle Mitarbeiter', 'rewan-booking'); ?>"
                                        data-txt-nothing-selected="<?php echo esc_attr__('Kein Mitarbeiter ausgewählt', 'rewan-booking'); ?>"
                                    >
                                        <?php foreach ($employees as $category_id => $category) : ?>
                                            <li<?php echo ! $category_id ? ' data-flatten-if-single' : ''; ?>><?php echo esc_html($category['name']); ?>
                                                <ul>
                                                    <?php foreach ($category['items'] as $staff) : ?>
                                                        <li data-value="<?php echo esc_attr((string) $staff['id']); ?>">
                                                            <?php echo esc_html($staff['full_name']); ?>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                            <div class="col-sm-auto mb-2 text-center">
                                <div class="btn-group">
                                    <button type="button" class="btn <?php echo esc_attr($btn_class); ?>" id="bookly-calendar-refresh"><i class="fas fa-sync-alt"></i></button>
                                    <button type="button" class="btn <?php echo esc_attr($btn_class); ?> bookly-dropdown-toggle bookly-dropdown-toggle-split" data-toggle="bookly-dropdown" aria-haspopup="true" aria-expanded="false"></button>
                                    <div class="bookly-dropdown-menu pb-0 bookly-dropdown-menu-right overflow-hidden">
                                        <h6 class="bookly-dropdown-header"><?php esc_html_e('Kalender automatisch aktualisieren', 'rewan-booking'); ?></h6>
                                        <div class="bookly-dropdown-divider"></div>
                                        <?php
                                        $intervals = array(
                                            '60' => __('Alle 1 Minute', 'rewan-booking'),
                                            '300' => __('Alle 5 Minuten', 'rewan-booking'),
                                            '900' => __('Alle 15 Minuten', 'rewan-booking'),
                                            '0' => __('Deaktivieren', 'rewan-booking'),
                                        );
                                        foreach ($intervals as $val => $label) :
                                            ?>
                                            <label class="bookly-dropdown-item mx-3 w-100 custom-control custom-radio">
                                                <input type="radio" class="custom-control-input" name="bookly_calendar_refresh_rate" value="<?php echo esc_attr((string) $val); ?>" <?php checked($refresh_rate, (int) $val); ?> />
                                                <span class="custom-control-label"><?php echo esc_html($label); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-auto mb-2 text-center">
                                <button class="btn btn-default" id="bookly-calendar-fullscreen" type="button"><i class="fas fa-expand"></i></button>
                            </div>
                        </div>
                        <ul id="bookly-js-locations-filter" class="d-none" data-align="right"
                            data-txt-select-all="<?php echo esc_attr__('Alle Standorte', 'rewan-booking'); ?>"
                            data-txt-all-selected="<?php echo esc_attr__('Alle Standorte', 'rewan-booking'); ?>"
                            data-txt-nothing-selected="<?php echo esc_attr__('Kein Standort ausgewählt', 'rewan-booking'); ?>"
                        >
                            <li data-flatten-if-single><?php esc_html_e('Locations', 'rewan-booking'); ?>
                                <ul>
                                    <li data-value="0" data-selected="1"><?php esc_html_e('Default', 'rewan-booking'); ?></li>
                                </ul>
                            </li>
                        </ul>
                        <div class="nav-scrollable nav-scrollable-pills justify-content-center justify-content-xl-start bookly-js-staff-pills <?php echo $staff_count <= 1 ? 'd-none' : ''; ?>">
                            <ul class="col-auto nav nav-pills flex-nowrap p-0">
                                <?php if ($is_manage) : ?>
                                    <li class="nav-item mr-2">
                                        <a class="nav-link d-block text-center p-2" href="#" data-staff_id="0">
                                            <span class="d-block"><i class="fas fa-users fa-2x" style="width: 40px; height: 40px;"></i></span>
                                            <span class="small align-self-center"><?php esc_html_e('Alle', 'rewan-booking'); ?></span>
                                        </a>
                                    </li>
                                <?php endif; ?>
                                <?php foreach ($flat_staff as $staff) : ?>
                                    <li class="nav-item mr-2 text-nowrap" style="<?php echo $is_manage ? '' : 'display:none;'; ?>">
                                        <a class="nav-link d-block p-2 text-center" href="#" data-staff_id="<?php echo esc_attr((string) $staff['id']); ?>">
                                            <?php if (!empty($staff['image_url'])) : ?>
                                                <span class="rounded-circle d-flex overflow-hidden m-auto" style="height: 40px; width: 40px;">
                                                    <img src="<?php echo esc_url($staff['image_url']); ?>" alt="" class="d-block mx-auto" style="max-width: 40px; max-height: 40px; align-self: center;" />
                                                </span>
                                            <?php else : ?>
                                                <i class="far fa-user-circle fa-2x d-block mx-auto font-weight-bold" style="width: 40px; height: 40px;"></i>
                                            <?php endif; ?>
                                            <span class="small align-self-center"><?php echo esc_html($staff['full_name']); ?></span>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <div class="mt-3 position-relative">
                            <?php $first = $flat_staff[0]; ?>
                            <input type="hidden" value="<?php echo esc_attr((string) $first['id']); ?>" id="bookly-js-staff-id" data-name="<?php echo esc_attr($first['full_name']); ?>" />
                            <div class="bookly-ec-loading" style="display: none">
                                <div class="bookly-ec-loading-icon"></div>
                            </div>
                            <div class="bookly-js-calendar"></div>
                        </div>
                    <?php else : ?>
                        <div class="m-3">
                            <p><?php esc_html_e('Füge aktive Mitarbeiter hinzu, um den Kalender zu nutzen.', 'rewan-booking'); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
}
