<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rewan_Booking_Admin {

    /**
     * @var array|null Konfiguration für Chart.js im Admin-Footer (nur Dashboard).
     */
    private $dashboard_chart_footer = null;

    public function init() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_menu', array($this, 'strip_default_menus_for_salon'), 9999);
        add_action('admin_init', array($this, 'maybe_redirect_salon_users'), 1);
        add_filter('login_redirect', array($this, 'login_redirect_salon'), 10, 3);

        add_action('show_user_profile', array($this, 'user_profile_employee_link'));
        add_action('edit_user_profile', array($this, 'user_profile_employee_link'));
        add_action('personal_options_update', array($this, 'save_user_profile_employee_link'));
        add_action('edit_user_profile_update', array($this, 'save_user_profile_employee_link'));

        add_action('admin_post_rewan_booking_save_settings', array($this, 'handle_save_settings'));
        add_action('admin_post_rewan_booking_save_emails', array($this, 'handle_save_emails'));
        add_action('wp_ajax_rewan_booking_email_preview', array($this, 'ajax_email_preview'));

        add_action('admin_post_rewan_booking_add_service', array($this, 'handle_add_service'));
        add_action('admin_post_rewan_booking_save_service', array($this, 'handle_save_service'));
        add_action('admin_post_rewan_booking_delete_service', array($this, 'handle_delete_service'));

        add_action('admin_post_rewan_booking_save_employee', array($this, 'handle_save_employee'));
        add_action('admin_post_rewan_booking_delete_employee', array($this, 'handle_delete_employee'));

        add_action('admin_post_rewan_booking_add_absence', array($this, 'handle_add_absence'));
        add_action('admin_post_rewan_booking_save_absence', array($this, 'handle_save_absence'));
        add_action('admin_post_rewan_booking_quick_day_off', array($this, 'handle_quick_day_off'));
        add_action('admin_post_rewan_booking_delete_absence', array($this, 'handle_delete_absence'));
        add_action('wp_ajax_rewan_booking_update_holidays', array($this, 'ajax_update_holidays'));
        add_action('wp_ajax_rewan_booking_save_ferien_range', array($this, 'ajax_save_ferien_range'));

        add_action('admin_post_rewan_booking_save_week_schedule', array($this, 'handle_save_week_schedule'));
        add_action('admin_post_rewan_booking_save_opening_hours', array($this, 'handle_save_opening_hours'));
        add_action('admin_post_rewan_booking_save_booking', array($this, 'handle_save_booking'));
        add_action('admin_post_rewan_booking_delete_booking', array($this, 'handle_delete_booking'));

        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('admin_print_footer_scripts', array($this, 'output_dashboard_chart_footer'), 99);
        add_action('wp_ajax_rewan_booking_get_staff_appointments', array('Rewan_Booking_Calendar_Bookly', 'ajax_get_staff_appointments'));
        add_filter('admin_body_class', array($this, 'admin_body_class'));
    }

    /**
     * True auf allen admin.php-Seiten mit ?page=rewan-booking…
     */
    private function is_rewan_booking_screen() {
        if (!is_admin()) {
            return false;
        }
        if (!isset($_GET['page']) || !is_string($_GET['page'])) {
            return false;
        }
        $page = sanitize_text_field(wp_unslash($_GET['page']));
        return strpos($page, 'rewan-booking') === 0;
    }

    /**
     * @param string $classes Space-getrennte Klassen.
     * @return string
     */
    public function admin_body_class($classes) {
        if (!$this->is_rewan_booking_screen()) {
            return $classes;
        }
        return trim($classes . ' rewan-booking-admin');
    }

    /**
     * Warnt, wenn Kalender-/CSS-Dateien auf dem Server fehlen (häufige Ursache für 404 in der Konsole).
     */
    private function render_rewan_assets_missing_notice_for_absences() {
        $required = array(
            'assets/css/rewan-booking-admin.css',
            'assets/css/rewan-booking-theme.css',
            'assets/js/rewan-booking-jcal-popover-shim.js',
            'assets/js/rewan-booking-jcal-days-off.js',
            'assets/js/rewan-booking-days-off.js',
        );
        $required[] = 'assets/css/rewan-booking-bootstrap-4.6.2.min.css';
        $missing = array();
        foreach ($required as $rel) {
            $path = REWAN_BOOKING_PATH . $rel;
            if (!is_file($path) || !is_readable($path)) {
                $missing[] = $rel;
            }
        }
        if (empty($missing)) {
            return;
        }
        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php esc_html_e('Rewan Booking', 'rewan-booking'); ?>:</strong>
                <?php esc_html_e('Auf dem Webspace fehlen Plugin-Dateien unter „assets/“ (oder sie sind für PHP nicht lesbar). Dann liefert der Browser HTTP 404 und Kalender sowie Styles funktionieren nicht. Bitte per SFTP/FTP prüfen und den kompletten Ordner rewan-booking inkl. assets/css und assets/js erneut hochladen.', 'rewan-booking'); ?>
            </p>
            <ul style="list-style: disc; margin: 0 0 0 1.25em;">
                <?php foreach ($missing as $rel) : ?>
                    <li><code><?php echo esc_html($rel); ?></code></li>
                <?php endforeach; ?>
            </ul>
            <p>
                <strong><?php esc_html_e('Erwarteter Pfad auf dem Server:', 'rewan-booking'); ?></strong>
                <code><?php echo esc_html(REWAN_BOOKING_PATH); ?></code>
            </p>
        </div>
        <?php
    }

    public function enqueue_admin_assets() {
        if (!$this->is_rewan_booking_screen()) {
            return;
        }
        wp_enqueue_style(
            'rewan-booking-admin',
            rewan_booking_plugin_url('assets/css/rewan-booking-admin.css'),
            array(),
            REWAN_BOOKING_VERSION
        );
        wp_enqueue_style(
            'rewan-booking-theme',
            rewan_booking_plugin_url('assets/css/rewan-booking-theme.css'),
            array('rewan-booking-admin'),
            REWAN_BOOKING_VERSION
        );
    }

    /**
     * Pfad zur main.php des offiziellen Bookly-Plugins (WordPress.org), falls installiert.
     *
     * @return string|false Absoluter Pfad oder false
     */
    private function get_bookly_core_main_file() {
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
     * Bookly-Backend-Assets (Bootstrap, Font Awesome, Chart) nur auf dem Rewan-Verwaltungs-Dashboard.
     */
    private function enqueue_bookly_tbs_shell() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'rewan-booking' || !current_user_can(REWAN_BOOKING_CAP_MANAGE)) {
            return;
        }
        $main = $this->get_bookly_core_main_file();
        $dash_css_deps = array('rewan-booking-admin');
        if ($main) {
            $bootstrap_css = dirname($main) . '/backend/resources/bootstrap/css/bootstrap.min.css';
            $v = is_readable($bootstrap_css) ? (string) filemtime($bootstrap_css) : REWAN_BOOKING_VERSION;
            wp_enqueue_style(
                'rewan-booking-bookly-bootstrap',
                plugins_url('backend/resources/bootstrap/css/bootstrap.min.css', $main),
                array(),
                $v
            );
            wp_enqueue_style(
                'rewan-booking-bookly-ladda',
                plugins_url('frontend/resources/css/ladda.min.css', $main),
                array('rewan-booking-bookly-bootstrap'),
                $v
            );
            wp_enqueue_style(
                'rewan-booking-bookly-fa',
                plugins_url('backend/resources/css/fontawesome-all.min.css', $main),
                array('rewan-booking-bookly-bootstrap'),
                $v
            );
            wp_enqueue_script(
                'rewan-booking-bookly-bootstrap-js',
                plugins_url('backend/resources/bootstrap/js/bootstrap.min.js', $main),
                array('jquery'),
                $v,
                true
            );
            wp_enqueue_script(
                'rewan-booking-dash-chart',
                plugins_url('backend/components/dashboard/appointments/resources/js/chart.min.js', $main),
                array(),
                $v,
                true
            );
            $dash_css_deps = array('rewan-booking-bookly-bootstrap', 'rewan-booking-bookly-fa', 'rewan-booking-bookly-ladda');
        }
        wp_enqueue_style(
            'rewan-booking-dashboard-bookly',
            rewan_booking_plugin_url('assets/css/rewan-booking-dashboard-bookly.css'),
            $dash_css_deps,
            REWAN_BOOKING_VERSION
        );
    }

    /**
     * Chart.js für Canvas #canvas (wie Bookly), nach Footer-Ladung von chart.min.js.
     */
    public function output_dashboard_chart_footer() {
        if (!is_array($this->dashboard_chart_footer)) {
            return;
        }
        if (empty($this->dashboard_chart_footer['run'])) {
            $this->dashboard_chart_footer = null;
            return;
        }
        $cfg = $this->dashboard_chart_footer;
        $this->dashboard_chart_footer = null;
        $labels = isset($cfg['labels']) ? $cfg['labels'] : array();
        $values = isset($cfg['values']) ? $cfg['values'] : array();
        if (empty($labels) || !wp_script_is('rewan-booking-dash-chart', 'enqueued')) {
            return;
        }
        if (count($labels) !== count($values)) {
            return;
        }
        ?>
        <script>
        (function () {
            var el = document.getElementById('canvas');
            if (!el || typeof Chart === 'undefined') {
                return;
            }
            var labels = <?php echo wp_json_encode($labels); ?>;
            var values = <?php echo wp_json_encode($values); ?>;
            new Chart(el, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: <?php echo wp_json_encode(__('Termine', 'rewan-booking')); ?>,
                        data: values,
                        borderColor: '#6aab20',
                        backgroundColor: 'rgba(106, 171, 32, 0.12)',
                        tension: 0.25,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: true } },
                    scales: {
                        y: { beginAtZero: true, ticks: { precision: 0 } }
                    }
                }
            });
        })();
        </script>
        <?php
    }

    /**
     * @return bool True wenn nur Salon-Rechte (kein Verwaltungs-Cockpit).
     */
    private function user_is_salon_only() {
        return current_user_can(REWAN_BOOKING_CAP_SALON) && !current_user_can(REWAN_BOOKING_CAP_MANAGE);
    }

    /**
     * Verknüpfter Buchungs-Mitarbeiter (WP-Benutzer ↔ Barber), 0 = nicht gesetzt (z. B. Empfang).
     *
     * @param int $user_id 0 = aktueller Benutzer
     */
    private function get_user_linked_employee_id($user_id = 0) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            $user_id = get_current_user_id();
        }
        if ($user_id <= 0) {
            return 0;
        }
        $v = get_user_meta($user_id, 'rewan_booking_employee_id', true);
        return $v !== '' && $v !== null ? (int) $v : 0;
    }

    /**
     * Schnell „frei“ nur für erlaubte Mitarbeiter-IDs.
     */
    private function salon_user_can_quick_off_for_employee($employee_id) {
        $employee_id = (int) $employee_id;
        if (current_user_can(REWAN_BOOKING_CAP_MANAGE)) {
            return true;
        }
        if (!current_user_can(REWAN_BOOKING_CAP_SALON)) {
            return false;
        }
        $linked = $this->get_user_linked_employee_id();
        if ($linked <= 0) {
            return true;
        }
        return $employee_id === $linked;
    }

    /**
     * Einstieg Rewan Booking: Salon sieht unter dieser URL die Tagesansicht (Landing), Verwaltung das Cockpit.
     */
    private function admin_url_salon_today() {
        return admin_url('admin.php?page=rewan-booking');
    }

    public function maybe_redirect_salon_users() {
        if (!is_user_logged_in() || wp_doing_ajax()) {
            return;
        }
        if (!defined('REWAN_BOOKING_CAP_SALON') || !defined('REWAN_BOOKING_CAP_MANAGE')) {
            return;
        }
        if (!current_user_can(REWAN_BOOKING_CAP_SALON) || current_user_can(REWAN_BOOKING_CAP_MANAGE)) {
            return;
        }

        global $pagenow;
        $allowed_pages = array('profile.php', 'user-edit.php');
        if (in_array($pagenow, $allowed_pages, true)) {
            return;
        }

        if ($pagenow === 'admin.php' && isset($_GET['page'])) {
            $page = sanitize_text_field(wp_unslash($_GET['page']));
            if (strpos($page, 'rewan-booking') === 0) {
                return;
            }
        }

        wp_safe_redirect($this->admin_url_salon_today());
        exit;
    }

    public function strip_default_menus_for_salon() {
        if (!defined('REWAN_BOOKING_CAP_MANAGE') || !defined('REWAN_BOOKING_CAP_SALON')) {
            return;
        }
        if (!current_user_can(REWAN_BOOKING_CAP_SALON) || current_user_can(REWAN_BOOKING_CAP_MANAGE)) {
            return;
        }

        remove_menu_page('index.php');
        remove_menu_page('edit.php');
        remove_menu_page('upload.php');
        remove_menu_page('edit.php?post_type=page');
        remove_menu_page('themes.php');
        remove_menu_page('plugins.php');
        remove_menu_page('users.php');
        remove_menu_page('tools.php');
        remove_menu_page('options-general.php');
        remove_menu_page('edit-comments.php');
    }

    /**
     * @param string           $redirect_to
     * @param string           $requested_redirect_to
     * @param WP_User|WP_Error $user
     * @return string
     */
    public function login_redirect_salon($redirect_to, $requested_redirect_to, $user) {
        if ($user instanceof WP_User && in_array('rewan_booking_salon', (array) $user->roles, true)) {
            return $this->admin_url_salon_today();
        }
        return $redirect_to;
    }

    public function user_profile_employee_link($user) {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (!isset($user->ID)) {
            return;
        }
        global $wpdb;
        $employees_table = $wpdb->prefix . 'rewan_booking_employees';
        $employees = $wpdb->get_results("SELECT id, name FROM $employees_table WHERE is_active = 1 ORDER BY name ASC", ARRAY_A);
        $current = $this->get_user_linked_employee_id((int) $user->ID);
        ?>
        <h2><?php esc_html_e('Rewan Buchung', 'rewan-booking'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="rewan_booking_employee_id"><?php esc_html_e('Verknüpfter Barber im Buchungssystem', 'rewan-booking'); ?></label></th>
                <td>
                    <select name="rewan_booking_employee_id" id="rewan_booking_employee_id">
                        <option value="0"><?php esc_html_e('— Keine Verknüpfung (z. B. Empfang: alle Termine)', 'rewan-booking'); ?></option>
                        <?php foreach ($employees as $emp) : ?>
                            <option value="<?php echo esc_attr((string) $emp['id']); ?>" <?php selected($current, (int) $emp['id']); ?>><?php echo esc_html($emp['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e('Wenn gesetzt, sieht dieses Konto auf „Mein Tag“ nur die eigenen Termine und kann „Frei“ nur für diese Person eintragen.', 'rewan-booking'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function save_user_profile_employee_link($user_id) {
        if (!isset($_POST['rewan_booking_employee_id']) || !current_user_can('manage_options') || !current_user_can('edit_user', $user_id)) {
            return;
        }
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return;
        }
        if (defined('IS_PROFILE_PAGE') && IS_PROFILE_PAGE) {
            check_admin_referer('personal-options-update');
        } else {
            check_admin_referer('update-user_' . $user_id);
        }
        $eid = (int) wp_unslash($_POST['rewan_booking_employee_id']);
        if ($eid < 0) {
            $eid = 0;
        }
        update_user_meta($user_id, 'rewan_booking_employee_id', $eid);
    }

    /**
     * @param mixed $raw Wert aus Spalte services (JSON-Liste oder Text)
     */
    private function format_booking_services_cell($raw) {
        if ($raw === null || $raw === '') {
            return '';
        }
        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) {
            return implode(', ', array_map('strval', $decoded));
        }
        return (string) $raw;
    }

    public function enqueue_styles() {
        wp_enqueue_style(
            'rewan-booking-frontend-css',
            rewan_booking_plugin_url('assets/css/rewan-booking-frontend.css'),
            array(),
            REWAN_BOOKING_VERSION
        );
    }

    /**
     * Top-Level bei page=rewan-booking: derselbe Hook wie das Untermenü „Cockpit“ (gleiche Slug).
     * Verwaltung: Cockpit rendert nur der Submenu-Callback — hier nichts ausgeben, sonst doppeltes Cockpit.
     * Nur-Salon (ohne manage): Submenu „Cockpit“ fehlt → hier „Mein Tag“.
     */
    public function render_rewan_booking_landing() {
        if (current_user_can(REWAN_BOOKING_CAP_MANAGE)) {
            return;
        }
        if (current_user_can(REWAN_BOOKING_CAP_SALON)) {
            $this->render_salon_today_page();
            return;
        }
        wp_die(esc_html__('Keine Berechtigung.', 'rewan-booking'));
    }

    public function add_admin_menu() {
        $cap_salon = REWAN_BOOKING_CAP_SALON;
        $cap_manage = REWAN_BOOKING_CAP_MANAGE;

        add_menu_page(
            __('Rewan Booking', 'rewan-booking'),
            __('Rewan Booking', 'rewan-booking'),
            $cap_salon,
            'rewan-booking',
            array($this, 'render_rewan_booking_landing'),
            'dashicons-calendar-alt',
            26
        );

        add_submenu_page(
            'rewan-booking',
            __('Dashboard', 'rewan-booking'),
            __('Dashboard', 'rewan-booking'),
            $cap_manage,
            'rewan-booking',
            array($this, 'render_dashboard_page')
        );

        add_submenu_page(
            'rewan-booking',
            __('Kalender', 'rewan-booking'),
            __('Kalender', 'rewan-booking'),
            $cap_salon,
            'rewan-booking-calendar',
            array($this, 'render_calendar_page')
        );

        add_submenu_page(
            'rewan-booking',
            __('Buchungen', 'rewan-booking'),
            __('Buchungen', 'rewan-booking'),
            $cap_salon,
            'rewan-booking-bookings',
            array($this, 'render_bookings_page')
        );

        add_submenu_page(
            'rewan-booking',
            __('Öffnungszeiten', 'rewan-booking'),
            __('Öffnungszeiten', 'rewan-booking'),
            $cap_manage,
            'rewan-booking-hours',
            array($this, 'render_opening_hours_page')
        );

        add_submenu_page(
            'rewan-booking',
            __('Sperrzeit', 'rewan-booking'),
            __('Sperrzeit', 'rewan-booking'),
            $cap_manage,
            'rewan-booking-blocks',
            array($this, 'render_global_blocks_page')
        );

        add_submenu_page(
            'rewan-booking',
            __('Ferien', 'rewan-booking'),
            __('Ferien', 'rewan-booking'),
            $cap_salon,
            'rewan-booking-absences',
            array($this, 'render_absences_page')
        );

        add_submenu_page(
            'rewan-booking',
            __('Dienstleistungen', 'rewan-booking'),
            __('Dienstleistungen', 'rewan-booking'),
            $cap_manage,
            'rewan-booking-services',
            array($this, 'render_services_page')
        );

        add_submenu_page(
            'rewan-booking',
            __('Mitarbeiter', 'rewan-booking'),
            __('Mitarbeiter', 'rewan-booking'),
            $cap_manage,
            'rewan-booking-employees',
            array($this, 'render_employees_page')
        );

        add_submenu_page(
            'rewan-booking',
            __('E-Mails', 'rewan-booking'),
            __('E-Mails', 'rewan-booking'),
            $cap_manage,
            'rewan-booking-emails',
            array($this, 'render_emails_page')
        );

        add_submenu_page(
            'rewan-booking',
            __('Einstellungen', 'rewan-booking'),
            __('Einstellungen', 'rewan-booking'),
            $cap_manage,
            'rewan-booking-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * @param float|int $current
     * @param float|int $previous
     */
    private function dashboard_trend_label($current, $previous) {
        $current = (float) $current;
        $previous = (float) $previous;
        if ($previous <= 0 && $current <= 0) {
            return '— wie Vormonat (gleiche Tage)';
        }
        if ($previous <= 0) {
            return '+Neu · Vormonat 0';
        }
        $pct = round((($current - $previous) / $previous) * 100);
        $sign = $pct > 0 ? '+' : '';
        return $sign . (int) $pct . '% vs. Vormonat';
    }

    /**
     * @param float|int $current
     * @param float|int $previous
     */
    private function dashboard_trend_label_week($current, $previous) {
        $current = (float) $current;
        $previous = (float) $previous;
        if ($previous <= 0 && $current <= 0) {
            return '— wie Vorwoche';
        }
        if ($previous <= 0) {
            return '+Neu · Vorwoche 0';
        }
        $pct = round((($current - $previous) / $previous) * 100);
        $sign = $pct > 0 ? '+' : '';
        return $sign . (int) $pct . '% vs. Vorwoche';
    }

    /**
     * @param float|int $current
     * @param float|int $previous
     */
    private function dashboard_trend_label_day($current, $previous) {
        $current = (float) $current;
        $previous = (float) $previous;
        if ($previous <= 0 && $current <= 0) {
            return '— wie gestern';
        }
        if ($previous <= 0) {
            return '+Neu · gestern 0';
        }
        $pct = round((($current - $previous) / $previous) * 100);
        $sign = $pct > 0 ? '+' : '';
        return $sign . (int) $pct . '% vs. gestern';
    }

    /**
     * Mobile-first Tagesansicht für Salon-Personal (grosse Buttons, klare Sprache).
     */
    public function render_salon_today_page() {
        if (!current_user_can(REWAN_BOOKING_CAP_SALON)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rewan-booking'));
        }

        global $wpdb;
        $bookings_table = $wpdb->prefix . 'rewan_booking_bookings';
        $employees_table = $wpdb->prefix . 'rewan_booking_employees';

        $today = current_time('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));
        $message = isset($_GET['message']) ? sanitize_text_field(wp_unslash($_GET['message'])) : '';
        $linked = $this->get_user_linked_employee_id();

        $emp_sql = '';
        $emp_args = array();
        if ($linked > 0) {
            $emp_sql = ' AND employee_id = %d';
            $emp_args[] = $linked;
        }

        $q_today = "SELECT * FROM {$bookings_table} WHERE booking_date = %s AND status = 'confirmed'{$emp_sql} ORDER BY start_time ASC";
        $bookings_today = $emp_args
            ? $wpdb->get_results($wpdb->prepare($q_today, array_merge(array($today), $emp_args)), ARRAY_A)
            : $wpdb->get_results($wpdb->prepare($q_today, $today), ARRAY_A);

        $q_tom = "SELECT * FROM {$bookings_table} WHERE booking_date = %s AND status = 'confirmed'{$emp_sql} ORDER BY start_time ASC";
        $bookings_tomorrow = $emp_args
            ? $wpdb->get_results($wpdb->prepare($q_tom, array_merge(array($tomorrow), $emp_args)), ARRAY_A)
            : $wpdb->get_results($wpdb->prepare($q_tom, $tomorrow), ARRAY_A);

        $employees = $wpdb->get_results("SELECT * FROM {$employees_table} WHERE is_active = 1 ORDER BY name ASC", ARRAY_A);
        if ($linked > 0) {
            $employees = array_values(
                array_filter(
                    $employees,
                    static function ($row) use ($linked) {
                        return (int) $row['id'] === $linked;
                    }
                )
            );
        }

        $cal_week_url = admin_url('admin.php?page=rewan-booking-calendar&view=week&date=' . rawurlencode($today));
        $bookings_url = admin_url('admin.php?page=rewan-booking-bookings&filter=today');
        $cockpit_url = admin_url('admin.php?page=rewan-booking');
        $bookmark_url = $this->admin_url_salon_today();
        ?>
        <div class="wrap rb-salon">
            <h1 class="rb-salon__h1"><?php esc_html_e('Mein Tag', 'rewan-booking'); ?></h1>
            <p class="rb-salon__lead"><?php echo esc_html(date_i18n('l, j. F Y', strtotime($today))); ?></p>
            <?php $this->render_admin_notice($message); ?>
            <style>
                .rb-salon { max-width:720px; margin:0 auto 32px; padding:0 4px; box-sizing:border-box; }
                .rb-salon__h1 { font-size:clamp(1.5rem, 5vw, 1.85rem); font-weight:700; margin:0 0 6px; letter-spacing:-0.02em; color:#0f172a; }
                .rb-salon__lead { margin:0 0 18px; font-size:clamp(1rem, 3.5vw, 1.1rem); color:#475569; }
                .rb-salon__nav { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:22px; }
                .rb-salon__nav a {
                    display:flex; align-items:center; justify-content:center; min-height:52px; padding:12px 14px;
                    text-align:center; text-decoration:none; font-weight:700; font-size:15px; border-radius:12px;
                    background:#1e293b; color:#fff !important; border:1px solid #334155; box-shadow:0 2px 8px rgba(15,23,42,.12);
                }
                .rb-salon__nav a:hover, .rb-salon__nav a:focus { filter:brightness(1.08); color:#fff !important; }
                .rb-salon__nav a.is-secondary { background:#fff; color:#1e293b !important; border-color:#cbd5e1; }
                .rb-salon__section { margin-top:26px; }
                .rb-salon__section h2 { font-size:1.05rem; font-weight:700; margin:0 0 12px; color:#0f172a; }
                .rb-salon__cards { display:flex; flex-direction:column; gap:12px; }
                .rb-salon__card {
                    background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:16px 18px;
                    box-shadow:0 2px 10px rgba(15,23,42,.05);
                }
                .rb-salon__card--time { font-size:1.35rem; font-weight:800; color:#0f172a; letter-spacing:-0.02em; }
                .rb-salon__card--name { font-size:1.1rem; font-weight:700; margin:6px 0 4px; color:#1e293b; }
                .rb-salon__card--meta { font-size:14px; color:#64748b; line-height:1.45; }
                .rb-salon__card--actions { margin-top:14px; display:flex; flex-wrap:wrap; gap:10px; }
                .rb-salon__card--actions a, .rb-salon__card--actions .button {
                    min-height:48px; padding:0 18px; display:inline-flex; align-items:center; justify-content:center;
                    border-radius:10px; font-size:15px; font-weight:600;
                }
                .rb-salon__empty {
                    padding:22px 16px; text-align:center; color:#64748b; background:#f8fafc; border:2px dashed #cbd5e1;
                    border-radius:14px; font-size:15px; line-height:1.5;
                }
                .rb-salon__team { margin-top:28px; }
                .rb-salon__team-card {
                    background:linear-gradient(180deg,#fff 0%,#f8fafc 100%); border:1px solid #e2e8f0; border-radius:14px;
                    padding:16px 18px; margin-bottom:14px;
                }
                .rb-salon__team-card h3 { margin:0 0 12px; font-size:1.05rem; font-weight:700; color:#0f172a; }
                .rb-salon__quick { display:flex; flex-wrap:wrap; gap:10px; }
                .rb-salon__quick form { margin:0; flex:1 1 calc(50% - 5px); min-width:140px; }
                .rb-salon__quick .button {
                    width:100%; min-height:50px !important; font-size:15px !important; font-weight:600 !important;
                    border-radius:10px !important; white-space:normal; line-height:1.25; padding:10px 12px !important; height:auto !important;
                }
                .rb-salon__help {
                    margin-top:28px; padding:16px 18px; background:#fffbeb; border:1px solid #fde68a; border-radius:14px; color:#78350f;
                    font-size:14px; line-height:1.55;
                }
                .rb-salon__help strong { display:block; margin-bottom:8px; font-size:15px; color:#92400e; }
                .rb-salon__help code { font-size:12px; word-break:break-all; background:#fff7ed; padding:4px 8px; border-radius:6px; display:block; margin-top:8px; }
                .rb-salon__pill { display:inline-block; margin-top:8px; padding:4px 10px; border-radius:999px; background:#e0e7ff; color:#3730a3; font-size:12px; font-weight:600; }
            </style>

            <div class="rb-salon__nav">
                <a class="is-secondary" href="<?php echo esc_url($cal_week_url); ?>"><?php esc_html_e('Kalender (Woche)', 'rewan-booking'); ?></a>
                <a href="<?php echo esc_url($bookings_url); ?>"><?php esc_html_e('Alle Buchungen', 'rewan-booking'); ?></a>
            </div>

            <?php if (current_user_can(REWAN_BOOKING_CAP_MANAGE)) : ?>
                <p style="margin:0 0 18px;">
                    <a class="button button-secondary" style="min-height:44px;" href="<?php echo esc_url($cockpit_url); ?>"><?php esc_html_e('Zum Dashboard (Zahlen & Einstellungen)', 'rewan-booking'); ?></a>
                </p>
            <?php endif; ?>

            <?php if ($linked > 0) : ?>
                <span class="rb-salon__pill"><?php esc_html_e('Nur deine Termine (Profil-Verknüpfung)', 'rewan-booking'); ?></span>
            <?php endif; ?>

            <div class="rb-salon__section">
                <h2><?php esc_html_e('Heute — Kunden', 'rewan-booking'); ?></h2>
                <?php if (!empty($bookings_today)) : ?>
                    <div class="rb-salon__cards">
                        <?php foreach ($bookings_today as $b) : ?>
                            <div class="rb-salon__card">
                                <div class="rb-salon__card--time"><?php echo esc_html(substr((string) $b['start_time'], 0, 5)); ?></div>
                                <div class="rb-salon__card--name"><?php echo esc_html($b['customer_name']); ?></div>
                                <div class="rb-salon__card--meta">
                                    <?php echo esc_html($b['employee_name']); ?> · <?php echo esc_html($this->format_booking_services_cell($b['services'] ?? '')); ?>
                                    <?php if (!empty($b['customer_phone'])) : ?>
                                        <br><a href="tel:<?php echo esc_attr(preg_replace('/\s+/', '', (string) $b['customer_phone'])); ?>"><?php echo esc_html($b['customer_phone']); ?></a>
                                    <?php endif; ?>
                                </div>
                                <div class="rb-salon__card--actions">
                                    <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=rewan-booking-bookings&edit_booking=' . (int) $b['id'])); ?>"><?php esc_html_e('Termin öffnen', 'rewan-booking'); ?></a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else : ?>
                    <div class="rb-salon__empty"><?php esc_html_e('Für heute sind keine bestätigten Termine eingetragen.', 'rewan-booking'); ?></div>
                <?php endif; ?>
            </div>

            <div class="rb-salon__section">
                <h2><?php esc_html_e('Morgen — Vorschau', 'rewan-booking'); ?></h2>
                <?php if (!empty($bookings_tomorrow)) : ?>
                    <div class="rb-salon__cards">
                        <?php foreach ($bookings_tomorrow as $b) : ?>
                            <div class="rb-salon__card">
                                <div class="rb-salon__card--time"><?php echo esc_html(substr((string) $b['start_time'], 0, 5)); ?></div>
                                <div class="rb-salon__card--name"><?php echo esc_html($b['customer_name']); ?></div>
                                <div class="rb-salon__card--meta"><?php echo esc_html($b['employee_name']); ?> · <?php echo esc_html($this->format_booking_services_cell($b['services'] ?? '')); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else : ?>
                    <div class="rb-salon__empty"><?php esc_html_e('Für morgen noch keine bestätigten Termine.', 'rewan-booking'); ?></div>
                <?php endif; ?>
            </div>

            <div class="rb-salon__team">
                <h2 style="font-size:1.15rem; margin-bottom:14px;"><?php esc_html_e('Heute / morgen nicht im Shop (ein Tippen)', 'rewan-booking'); ?></h2>
                <p style="margin:-6px 0 16px; color:#64748b; font-size:14px; line-height:1.5;">
                    <?php esc_html_e('Trägt einen freien Tag ein, damit online keine neuen Buchungen entstehen.', 'rewan-booking'); ?>
                </p>
                <?php if (!empty($employees)) : ?>
                    <?php foreach ($employees as $emp) : ?>
                        <?php
                        $eid = (int) $emp['id'];
                        if (!$this->salon_user_can_quick_off_for_employee($eid)) {
                            continue;
                        }
                        ?>
                        <div class="rb-salon__team-card">
                            <h3><?php echo esc_html($emp['name']); ?></h3>
                            <div class="rb-salon__quick">
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="rewan_booking_quick_day_off">
                                    <input type="hidden" name="employee_id" value="<?php echo esc_attr((string) $eid); ?>">
                                    <input type="hidden" name="start_date" value="<?php echo esc_attr($today); ?>">
                                    <input type="hidden" name="end_date" value="<?php echo esc_attr($today); ?>">
                                    <input type="hidden" name="title" value="<?php echo esc_attr(__('Nicht im Shop (heute)', 'rewan-booking')); ?>">
                                    <input type="hidden" name="rewan_booking_redirect" value="salon">
                                    <?php wp_nonce_field('rewan_booking_quick_day_off_nonce', 'rewan_booking_quick_day_off_nonce'); ?>
                                    <button type="submit" class="button"><?php esc_html_e('Heute frei', 'rewan-booking'); ?></button>
                                </form>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="rewan_booking_quick_day_off">
                                    <input type="hidden" name="employee_id" value="<?php echo esc_attr((string) $eid); ?>">
                                    <input type="hidden" name="start_date" value="<?php echo esc_attr($tomorrow); ?>">
                                    <input type="hidden" name="end_date" value="<?php echo esc_attr($tomorrow); ?>">
                                    <input type="hidden" name="title" value="<?php echo esc_attr(__('Nicht im Shop (morgen)', 'rewan-booking')); ?>">
                                    <input type="hidden" name="rewan_booking_redirect" value="salon">
                                    <?php wp_nonce_field('rewan_booking_quick_day_off_nonce', 'rewan_booking_quick_day_off_nonce'); ?>
                                    <button type="submit" class="button"><?php esc_html_e('Morgen frei', 'rewan-booking'); ?></button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <div class="rb-salon__empty"><?php esc_html_e('Keine aktiven Mitarbeitenden.', 'rewan-booking'); ?></div>
                <?php endif; ?>
            </div>

            <div class="rb-salon__help">
                <strong><?php esc_html_e('Tipp fürs Handy', 'rewan-booking'); ?></strong>
                <?php esc_html_e('Diese Seite als Lesezeichen speichern — dann seid ihr mit einem Fingertipp direkt bei „Mein Tag“.', 'rewan-booking'); ?>
                <code><?php echo esc_html($bookmark_url); ?></code>
                <?php if (current_user_can(REWAN_BOOKING_CAP_MANAGE)) : ?>
                    <p style="margin:12px 0 0;"><?php esc_html_e('Neue Login-Rolle „Salon · Rewan Buchung“: unter Benutzer anlegen und Rolle zuweisen — ohne volles WordPress-Menü.', 'rewan-booking'); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function render_dashboard_page() {
        if (!current_user_can(REWAN_BOOKING_CAP_MANAGE)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rewan-booking'));
        }
        global $wpdb;
        $table = $wpdb->prefix . 'rewan_booking_bookings';
        $message = isset($_GET['message']) ? sanitize_text_field(wp_unslash($_GET['message'])) : '';

        $today = current_time('Y-m-d');
        $now_time = current_time('H:i:s');
        $default_from = $today;
        $rb_from = isset($_GET['rb_from']) ? sanitize_text_field(wp_unslash($_GET['rb_from'])) : $default_from;
        $rb_to = isset($_GET['rb_to']) ? sanitize_text_field(wp_unslash($_GET['rb_to'])) : $today;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rb_from)) {
            $rb_from = $default_from;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rb_to)) {
            $rb_to = $today;
        }
        if ($rb_from > $rb_to) {
            $swap = $rb_from;
            $rb_from = $rb_to;
            $rb_to = $swap;
        }
        $rb_based_on = isset($_GET['rb_based_on']) && $_GET['rb_based_on'] === 'created_at' ? 'created_at' : 'start_date';

        $date_filter_attr = sprintf('%s - %s', $rb_from, $rb_to);
        $date_filter_human = sprintf(
            '%s - %s',
            date_i18n(get_option('date_format'), strtotime($rb_from)),
            date_i18n(get_option('date_format'), strtotime($rb_to))
        );

        $dash_url = function ($from, $to) use ($rb_based_on) {
            return add_query_arg(
                array(
                    'page' => 'rewan-booking',
                    'rb_from' => $from,
                    'rb_to' => $to,
                    'rb_based_on' => $rb_based_on,
                ),
                admin_url('admin.php')
            );
        };

        $preset_7_from = date('Y-m-d', strtotime('-7 days', strtotime($today . ' 12:00:00')));
        $preset_30_from = date('Y-m-d', strtotime('-30 days', strtotime($today . ' 12:00:00')));
        $this_month_from = date('Y-m-01', strtotime($today));
        $this_month_to = date('Y-m-t', strtotime($today));
        $prev_month_from = date('Y-m-01', strtotime('first day of previous month', strtotime($today)));
        $prev_month_to = date('Y-m-t', strtotime('last day of previous month', strtotime($today)));

        if ($rb_based_on === 'created_at') {
            $n_approved = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM $table WHERE status = 'confirmed' AND DATE(created_at) BETWEEN %s AND %s",
                    $rb_from,
                    $rb_to
                )
            );
            $n_total = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM $table WHERE status IN ('confirmed','cancelled') AND DATE(created_at) BETWEEN %s AND %s",
                    $rb_from,
                    $rb_to
                )
            );
            $revenue = (float) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COALESCE(SUM(total_price),0) FROM $table WHERE status = 'confirmed' AND DATE(created_at) BETWEEN %s AND %s",
                    $rb_from,
                    $rb_to
                )
            );
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT DATE(created_at) AS d, COUNT(*) AS c FROM $table WHERE status = 'confirmed' AND DATE(created_at) BETWEEN %s AND %s GROUP BY DATE(created_at)",
                    $rb_from,
                    $rb_to
                ),
                ARRAY_A
            );
        } else {
            $n_approved = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM $table WHERE status = 'confirmed' AND booking_date BETWEEN %s AND %s",
                    $rb_from,
                    $rb_to
                )
            );
            $n_total = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM $table WHERE status IN ('confirmed','cancelled') AND booking_date BETWEEN %s AND %s",
                    $rb_from,
                    $rb_to
                )
            );
            $revenue = (float) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COALESCE(SUM(total_price),0) FROM $table WHERE status = 'confirmed' AND booking_date BETWEEN %s AND %s",
                    $rb_from,
                    $rb_to
                )
            );
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT booking_date AS d, COUNT(*) AS c FROM $table WHERE status = 'confirmed' AND booking_date BETWEEN %s AND %s GROUP BY booking_date",
                    $rb_from,
                    $rb_to
                ),
                ARRAY_A
            );
        }
        $n_pending = 0;

        $by_day = array();
        foreach ((array) $rows as $r) {
            $by_day[ (string) $r['d'] ] = (int) $r['c'];
        }

        $tz = wp_timezone();
        $start_d = new DateTimeImmutable($rb_from, $tz);
        $end_excl = (new DateTimeImmutable($rb_to, $tz))->modify('+1 day');
        $period = new DatePeriod($start_d, new DateInterval('P1D'), $end_excl);
        $chart_labels = array();
        $chart_values = array();
        $max_pts = 45;
        $pt = 0;
        foreach ($period as $day) {
            if ($pt++ >= $max_pts) {
                break;
            }
            $ds = $day->format('Y-m-d');
            $chart_labels[] = $day->format('d.m.');
            $chart_values[] = isset($by_day[ $ds ]) ? $by_day[ $ds ] : 0;
        }

        $this->dashboard_chart_footer = null;
        $n_cancelled = max(0, $n_total - $n_approved);

        $href_bookings_range = add_query_arg(
            array(
                'page' => 'rewan-booking-bookings',
                'filter' => 'all',
                'from_date' => $rb_from,
                'to_date' => $rb_to,
            ),
            admin_url('admin.php')
        );
        $href_cancelled_range = add_query_arg(
            array(
                'page' => 'rewan-booking-bookings',
                'filter' => 'cancelled',
                'from_date' => $rb_from,
                'to_date' => $rb_to,
            ),
            admin_url('admin.php')
        );

        $next_bookings = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, customer_name, employee_name, services, booking_date, start_time, end_time
                 FROM $table
                 WHERE status = %s
                 AND (booking_date > %s OR (booking_date = %s AND start_time >= %s))
                 ORDER BY booking_date ASC, start_time ASC
                 LIMIT 8",
                'confirmed',
                $today,
                $today,
                $now_time
            ),
            ARRAY_A
        );

        ?>
        <div class="wrap rb-dash">
            <?php $this->render_admin_notice($message); ?>
            <header class="rb-dash__head">
                <div>
                    <p class="rb-dash__kicker"><?php esc_html_e('Salon', 'rewan-booking'); ?></p>
                    <h1><?php esc_html_e('Dashboard', 'rewan-booking'); ?></h1>
                    <p class="rb-dash__lead"><?php esc_html_e('Was heute und im gewählten Zeitraum ansteht.', 'rewan-booking'); ?></p>
                </div>
            </header>

            <div class="rb-dash__presets" role="navigation" aria-label="<?php esc_attr_e('Zeitraum', 'rewan-booking'); ?>">
                <a class="rb-dash__preset<?php echo ($rb_from === $today && $rb_to === $today) ? ' is-active' : ''; ?>" href="<?php echo esc_url($dash_url($today, $today)); ?>"><?php esc_html_e('Heute', 'rewan-booking'); ?></a>
                <a class="rb-dash__preset<?php echo ($rb_from === $preset_7_from && $rb_to === $today) ? ' is-active' : ''; ?>" href="<?php echo esc_url($dash_url($preset_7_from, $today)); ?>"><?php esc_html_e('7 Tage', 'rewan-booking'); ?></a>
                <a class="rb-dash__preset<?php echo ($rb_from === $this_month_from && $rb_to === $this_month_to) ? ' is-active' : ''; ?>" href="<?php echo esc_url($dash_url($this_month_from, $this_month_to)); ?>"><?php esc_html_e('Dieser Monat', 'rewan-booking'); ?></a>
                <a class="rb-dash__preset<?php echo ($rb_from === $prev_month_from && $rb_to === $prev_month_to) ? ' is-active' : ''; ?>" href="<?php echo esc_url($dash_url($prev_month_from, $prev_month_to)); ?>"><?php esc_html_e('Letzter Monat', 'rewan-booking'); ?></a>
                <span class="rb-dash__range"><?php echo esc_html($date_filter_human); ?></span>
            </div>

            <div class="rb-dash__stats">
                <a class="rb-dash__stat" href="<?php echo esc_url($href_bookings_range); ?>">
                    <span class="rb-dash__stat-value"><?php echo esc_html((string) $n_approved); ?></span>
                    <span class="rb-dash__stat-label"><?php esc_html_e('Bestätigt', 'rewan-booking'); ?></span>
                </a>
                <a class="rb-dash__stat" href="<?php echo esc_url($href_cancelled_range); ?>">
                    <span class="rb-dash__stat-value"><?php echo esc_html((string) $n_cancelled); ?></span>
                    <span class="rb-dash__stat-label"><?php esc_html_e('Storniert', 'rewan-booking'); ?></span>
                </a>
                <a class="rb-dash__stat" href="<?php echo esc_url($href_bookings_range); ?>">
                    <span class="rb-dash__stat-value"><?php echo esc_html((string) $n_total); ?></span>
                    <span class="rb-dash__stat-label"><?php esc_html_e('Termine gesamt', 'rewan-booking'); ?></span>
                </a>
                <a class="rb-dash__stat" href="<?php echo esc_url($href_bookings_range); ?>">
                    <span class="rb-dash__stat-value"><?php echo esc_html(number_format_i18n($revenue, 2)); ?></span>
                    <span class="rb-dash__stat-label"><?php esc_html_e('Umsatz CHF', 'rewan-booking'); ?></span>
                </a>
            </div>

            <section class="rb-dash__panel rb-dash__upcoming">
                <h2><?php esc_html_e('Nächste Termine', 'rewan-booking'); ?></h2>
                <?php if (!empty($next_bookings)) : ?>
                    <?php
                    $tomorrow_obj = date_create_immutable($today . ' 12:00:00', wp_timezone());
                    $tomorrow = $tomorrow_obj instanceof DateTimeImmutable ? $tomorrow_obj->modify('+1 day')->format('Y-m-d') : '';
                    $groups = array();
                    foreach ($next_bookings as $booking) {
                        $groups[(string) $booking['booking_date']][] = $booking;
                    }
                    ?>
                    <div class="rb-next">
                        <?php foreach ($groups as $day => $day_bookings) : ?>
                            <?php
                            if ($day === $today) {
                                $day_label = __('Heute', 'rewan-booking');
                            } elseif ($tomorrow !== '' && $day === $tomorrow) {
                                $day_label = __('Morgen', 'rewan-booking');
                            } else {
                                $day_label = date_i18n('l, d.m.Y', strtotime($day . ' 12:00:00'));
                            }
                            ?>
                            <h3 class="rb-next__day"><?php echo esc_html($day_label); ?></h3>
                            <div class="rb-next__list">
                                <?php foreach ($day_bookings as $booking) : ?>
                                    <?php
                                    $service_names = array();
                                    $decoded = json_decode((string) ($booking['services'] ?? ''), true);
                                    if (is_array($decoded)) {
                                        foreach ($decoded as $item) {
                                            if (is_string($item) && $item !== '') {
                                                $service_names[] = $item;
                                            }
                                        }
                                    }
                                    $meta_parts = array();
                                    if (!empty($booking['employee_name'])) {
                                        $meta_parts[] = (string) $booking['employee_name'];
                                    }
                                    if (!empty($service_names)) {
                                        $meta_parts[] = implode(', ', $service_names);
                                    }
                                    $start = substr((string) $booking['start_time'], 0, 5);
                                    ?>
                                    <a class="rb-next__card" href="<?php echo esc_url(admin_url('admin.php?page=rewan-booking-bookings&edit_booking=' . (int) $booking['id'])); ?>">
                                        <span class="rb-next__clock"><?php echo esc_html($start); ?></span>
                                        <span class="rb-next__main">
                                            <strong><?php echo esc_html($booking['customer_name']); ?></strong>
                                            <?php if (!empty($meta_parts)) : ?>
                                                <span><?php echo esc_html(implode(' · ', $meta_parts)); ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="rb-next__go" aria-hidden="true">›</span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else : ?>
                    <p class="rb-dash__empty"><?php esc_html_e('Keine kommenden Termine.', 'rewan-booking'); ?></p>
                <?php endif; ?>
            </section>
        </div>
        <?php
    }

    public function render_services_page() {
        if (!current_user_can(REWAN_BOOKING_CAP_MANAGE)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rewan-booking'));
        }
        global $wpdb;

        $table_name = $wpdb->prefix . 'rewan_booking_services';
        $services = $wpdb->get_results("SELECT * FROM $table_name ORDER BY name ASC", ARRAY_A);

        $new_service = array(
            'name' => '',
            'description' => '',
            'image_url' => '',
            'price' => '',
            'duration' => '',
            'is_active' => 1,
        );

        $message = isset($_GET['message']) ? sanitize_text_field(wp_unslash($_GET['message'])) : '';
        ?>
        <div class="wrap rb-srv-page">
            <h1 class="rb-srv-page__h1"><?php esc_html_e('Dienstleistungen', 'rewan-booking'); ?></h1>

            <?php $this->render_admin_notice($message); ?>
            <style>
                .rb-srv-page { max-width:1280px; }
                .rb-srv-page__h1 { margin-bottom:10px; }
                .rb-srv-intro { margin:0 0 14px; color:#64748b; font-size:14px; line-height:1.55; max-width:70ch; }
                .rb-srv-panel {
                    background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:16px; margin:14px 0;
                    box-shadow:0 1px 4px rgba(15,23,42,.05);
                }
                .rb-srv-panel h2 { margin:0 0 6px; font-size:18px; }
                .rb-srv-panel p { margin:0 0 12px; color:#64748b; }
                .rb-srv-form-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; }
                .rb-srv-form-grid > div { min-width:0; }
                .rb-srv-form-grid input, .rb-srv-form-grid textarea { width:100%; min-height:40px; }
                .rb-srv-form-grid textarea { min-height:92px; }
                .rb-srv-form-grid label { display:block; font-weight:600; margin-bottom:6px; }
                .rb-srv-form-grid .full { grid-column:1 / -1; }
                .rb-srv-toggle { margin:10px 0 14px; }
                .rb-srv-table-wrap {
                    margin-top:12px; overflow:auto; border:1px solid #e2e8f0; border-radius:12px; background:#fff;
                }
                .rb-srv-table { width:100%; border-collapse:separate; border-spacing:0; min-width:880px; }
                .rb-srv-table th {
                    background:#f8fafc; text-transform:uppercase; letter-spacing:.05em; font-size:12px;
                    font-weight:700; color:#475569; text-align:left; padding:12px 14px; border-bottom:1px solid #e2e8f0;
                }
                .rb-srv-table td {
                    padding:12px 14px; border-bottom:1px solid #eef2f7; vertical-align:top; font-size:14px;
                }
                .rb-srv-table tbody tr:last-child td { border-bottom:none; }
                .rb-srv-table tbody tr:hover td { background:#f8fbff; }
                .rb-srv-name { font-weight:700; color:#0f172a; }
                .rb-srv-muted { color:#64748b; font-size:13px; }
                .rb-srv-pill { display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:700; }
                .rb-srv-pill--active { background:#dcfce7; color:#166534; border:1px solid #86efac; }
                .rb-srv-pill--inactive { background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; }
                .rb-srv-actions { white-space:nowrap; display:flex; gap:8px; }
                .rb-srv-edit-row details { margin:0; }
                .rb-srv-edit-row summary { cursor:pointer; color:#2271b1; font-weight:600; }
                .rb-srv-edit-form { margin-top:10px; padding:12px; border:1px solid #e2e8f0; border-radius:10px; background:#fcfdff; }
                .rb-srv-edit-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; }
                .rb-srv-edit-grid .full { grid-column:1 / -1; }
                .rb-srv-edit-grid input, .rb-srv-edit-grid textarea { width:100%; }
                .rb-srv-empty { padding:22px; text-align:center; color:#64748b; }
                @media (max-width:960px) {
                    .rb-srv-form-grid { grid-template-columns:1fr 1fr; }
                }
                @media (max-width:680px) {
                    .rb-srv-form-grid { grid-template-columns:1fr; }
                    .rb-srv-panel { padding:12px; }
                    .rb-srv-edit-grid { grid-template-columns:1fr; }
                    .rb-srv-actions { flex-wrap:wrap; }
                    .rb-srv-actions .button { width:100%; text-align:center; }
                }
            </style>

            <p class="rb-srv-intro"><?php esc_html_e('Lege neue Services an und verwalte bestehende Leistungen zentral. Die Tabelle ist kompakt auf Desktop und bleibt auf Mobile gut bedienbar.', 'rewan-booking'); ?></p>

            <div class="rb-srv-panel">
                <h2><?php esc_html_e('Neue Dienstleistung', 'rewan-booking'); ?></h2>
                <p><?php esc_html_e('Name, Preis und Dauer reichen. Beschreibung und Bild kannst du leer lassen.', 'rewan-booking'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="rb-add-form">
                    <input type="hidden" name="action" value="rewan_booking_save_service">
                    <input type="hidden" name="service_id" value="0">
                    <?php wp_nonce_field('rewan_booking_save_service_nonce', 'rewan_booking_save_service_nonce'); ?>

                    <div class="rb-add-fields">
                        <label><span><?php esc_html_e('Name', 'rewan-booking'); ?></span><input type="text" name="service_name" value="<?php echo esc_attr($new_service['name']); ?>" required></label>
                        <label><span><?php esc_html_e('Preis (CHF)', 'rewan-booking'); ?></span><input type="number" name="service_price" min="0" step="0.01" inputmode="decimal" placeholder="35.00" value="<?php echo esc_attr($new_service['price']); ?>" required></label>
                        <label><span><?php esc_html_e('Dauer (Minuten)', 'rewan-booking'); ?></span><input type="number" name="service_duration" min="1" step="1" inputmode="numeric" placeholder="30" value="<?php echo esc_attr($new_service['duration']); ?>" required></label>
                        <label class="is-full"><span><?php esc_html_e('Beschreibung', 'rewan-booking'); ?></span><textarea name="service_description" rows="3" placeholder="<?php esc_attr_e('Kurz, was dazugehört', 'rewan-booking'); ?>"><?php echo esc_textarea($new_service['description']); ?></textarea></label>
                        <label class="is-full"><span><?php esc_html_e('Bild-URL', 'rewan-booking'); ?></span><input type="url" name="service_image_url" placeholder="https://" value="<?php echo esc_attr($new_service['image_url']); ?>"></label>
                    </div>
                    <label class="rb-emp-switch">
                        <input type="checkbox" name="service_is_active" value="1" checked>
                        <span><?php esc_html_e('Aktiv', 'rewan-booking'); ?><small><?php esc_html_e('Kunden können das buchen', 'rewan-booking'); ?></small></span>
                    </label>
                    <div class="rb-add-actions">
                        <?php submit_button(__('Dienstleistung speichern', 'rewan-booking'), 'primary', 'submit', false); ?>
                    </div>
                </form>
            </div>

            <div class="rb-srv-panel">
                <h2><?php esc_html_e('Bestehende Dienstleistungen', 'rewan-booking'); ?></h2>
                <p><?php esc_html_e('Per Klick auf „Bearbeiten“ die Eingabefelder direkt in der Zeile öffnen.', 'rewan-booking'); ?></p>
                <?php if (!empty($services)) : ?>
                    <div class="rb-srv-table-wrap">
                        <table class="rb-srv-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Name', 'rewan-booking'); ?></th>
                                    <th><?php esc_html_e('Preis', 'rewan-booking'); ?></th>
                                    <th><?php esc_html_e('Dauer', 'rewan-booking'); ?></th>
                                    <th><?php esc_html_e('Status', 'rewan-booking'); ?></th>
                                    <th><?php esc_html_e('Aktion', 'rewan-booking'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($services as $row) : ?>
                                    <tr>
                                        <td>
                                            <div class="rb-srv-name"><?php echo esc_html($row['name']); ?></div>
                                            <?php if (!empty($row['description'])) : ?>
                                                <div class="rb-srv-muted"><?php echo esc_html(wp_trim_words((string) $row['description'], 18)); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html(number_format((float) $row['price'], 2, '.', '\'')); ?> CHF</td>
                                        <td><?php echo esc_html((string) $row['duration']); ?> Min</td>
                                        <td>
                                            <?php if ((int) $row['is_active'] === 1) : ?>
                                                <span class="rb-srv-pill rb-srv-pill--active"><?php esc_html_e('Aktiv', 'rewan-booking'); ?></span>
                                            <?php else : ?>
                                                <span class="rb-srv-pill rb-srv-pill--inactive"><?php esc_html_e('Inaktiv', 'rewan-booking'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="rb-srv-edit-row">
                                            <details>
                                                <summary><?php esc_html_e('Bearbeiten', 'rewan-booking'); ?></summary>
                                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="rb-srv-edit-form">
                                                    <input type="hidden" name="action" value="rewan_booking_save_service">
                                                    <input type="hidden" name="service_id" value="<?php echo esc_attr($row['id']); ?>">
                                                    <?php wp_nonce_field('rewan_booking_save_service_nonce', 'rewan_booking_save_service_nonce'); ?>

                                                    <div class="rb-srv-edit-grid">
                                                        <div><label><?php esc_html_e('Name', 'rewan-booking'); ?><br><input type="text" name="service_name" value="<?php echo esc_attr($row['name']); ?>" required></label></div>
                                                        <div><label><?php esc_html_e('Preis (CHF)', 'rewan-booking'); ?><br><input type="number" name="service_price" min="0" step="0.01" value="<?php echo esc_attr($row['price']); ?>" required></label></div>
                                                        <div><label><?php esc_html_e('Dauer (Minuten)', 'rewan-booking'); ?><br><input type="number" name="service_duration" min="1" step="1" value="<?php echo esc_attr($row['duration']); ?>" required></label></div>
                                                        <div class="full"><label><?php esc_html_e('Beschreibung', 'rewan-booking'); ?><br><textarea name="service_description" rows="3"><?php echo esc_textarea(isset($row['description']) ? $row['description'] : ''); ?></textarea></label></div>
                                                        <div class="full"><label><?php esc_html_e('Bild-URL', 'rewan-booking'); ?><br><input type="url" name="service_image_url" value="<?php echo esc_attr(isset($row['image_url']) ? $row['image_url'] : ''); ?>"></label></div>
                                                        <div class="full"><label><input type="checkbox" name="service_is_active" value="1" <?php checked((int) $row['is_active'], 1); ?>> <?php esc_html_e('Aktiv anzeigen', 'rewan-booking'); ?></label></div>
                                                    </div>
                                                    <p class="rb-srv-actions">
                                                        <?php submit_button(__('Änderung speichern', 'rewan-booking'), 'primary', 'submit', false); ?>
                                                        <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=rewan_booking_delete_service&service_id=' . (int) $row['id']), 'rewan_booking_delete_service_' . (int) $row['id'])); ?>" onclick="return confirm('<?php echo esc_js(__('Dienstleistung wirklich löschen?', 'rewan-booking')); ?>');"><?php esc_html_e('Löschen', 'rewan-booking'); ?></a>
                                                    </p>
                                                </form>
                                            </details>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else : ?>
                    <p class="rb-srv-empty"><?php esc_html_e('Keine Dienstleistungen gefunden.', 'rewan-booking'); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function render_employees_page() {
        if (!current_user_can(REWAN_BOOKING_CAP_MANAGE)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rewan-booking'));
        }
        global $wpdb;

        $employees_table = $wpdb->prefix . 'rewan_booking_employees';
        $hours_table = $wpdb->prefix . 'rewan_booking_employee_hours';
        $breaks_table = $wpdb->prefix . 'rewan_booking_employee_breaks';

        $employees = $wpdb->get_results("SELECT * FROM $employees_table ORDER BY name ASC", ARRAY_A);
        $hours_rows = $wpdb->get_results("SELECT employee_id, weekday, is_working, start_time, end_time FROM $hours_table", ARRAY_A);
        $break_rows = $wpdb->get_results("SELECT employee_id, weekday, is_enabled, break_start, break_end FROM $breaks_table", ARRAY_A);
        $weekday_labels = $this->get_global_weekday_labels();
        $hours_by_employee = array();
        $breaks_by_employee = array();
        if (is_array($hours_rows)) {
            foreach ($hours_rows as $h) {
                $eid = isset($h['employee_id']) ? (int) $h['employee_id'] : 0;
                $w = isset($h['weekday']) ? (int) $h['weekday'] : 0;
                if ($eid > 0 && $w >= 1 && $w <= 7) {
                    $hours_by_employee[$eid][$w] = $h;
                }
            }
        }
        if (is_array($break_rows)) {
            foreach ($break_rows as $b) {
                $eid = isset($b['employee_id']) ? (int) $b['employee_id'] : 0;
                $w = isset($b['weekday']) ? (int) $b['weekday'] : 0;
                if ($eid > 0 && $w >= 1 && $w <= 7) {
                    $breaks_by_employee[$eid][$w] = $b;
                }
            }
        }

        $message = isset($_GET['message']) ? sanitize_text_field(wp_unslash($_GET['message'])) : '';
        ?>
        <div class="wrap rb-emp-page">
            <h1 class="rb-emp-page__h1"><?php esc_html_e('Mitarbeiter', 'rewan-booking'); ?></h1>

            <?php $this->render_admin_notice($message); ?>

            <div class="rb-emp-panel">
                <h2><?php esc_html_e('Neuen Mitarbeiter hinzufügen', 'rewan-booking'); ?></h2>
                <p><?php esc_html_e('Er arbeitet zuerst wie der Laden. Andere Zeiten stellst du beim Bearbeiten ein.', 'rewan-booking'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="rb-add-form">
                    <input type="hidden" name="action" value="rewan_booking_save_employee">
                    <input type="hidden" name="employee_id" value="0">
                    <?php wp_nonce_field('rewan_booking_save_employee_nonce', 'rewan_booking_save_employee_nonce'); ?>

                    <div class="rb-add-fields is-pair">
                        <label><span><?php esc_html_e('Name', 'rewan-booking'); ?></span><input type="text" name="employee_name" required></label>
                        <label><span><?php esc_html_e('E-Mail', 'rewan-booking'); ?></span><input type="email" name="employee_email" required></label>
                        <label class="is-full"><span><?php esc_html_e('Bild-URL', 'rewan-booking'); ?></span><input type="url" name="employee_image_url" placeholder="https://"></label>
                    </div>
                    <label class="rb-emp-switch">
                        <input type="checkbox" name="employee_is_active" value="1" checked>
                        <span><?php esc_html_e('Aktiv', 'rewan-booking'); ?><small><?php esc_html_e('Sichtbar für Kunden. Zeiten sind zuerst wie der Laden.', 'rewan-booking'); ?></small></span>
                    </label>
                    <input type="hidden" name="follows_opening" value="1">
                    <div class="rb-add-actions">
                        <?php submit_button(__('Mitarbeiter speichern', 'rewan-booking'), 'primary', 'submit', false); ?>
                    </div>
                </form>
            </div>

            <div class="rb-emp-panel">
            <h2><?php esc_html_e('Alle Mitarbeiter', 'rewan-booking'); ?></h2>
            <div class="rb-emp-list">
                <?php if (!empty($employees)) : ?>
                    <?php foreach ($employees as $row) : ?>
                        <?php $employee_id = (int) $row['id']; ?>
                        <?php $follows_opening = !empty($row['follows_opening']); ?>
                        <article class="rb-emp-card">
                            <div class="rb-emp-card-head">
                                <div>
                                    <div class="rb-emp-name"><?php echo esc_html($row['name']); ?></div>
                                    <div class="rb-emp-muted"><?php echo esc_html($row['email']); ?></div>
                                </div>
                                <?php if ((int) $row['is_active'] === 1) : ?>
                                    <span class="rb-emp-pill rb-emp-pill--active"><?php esc_html_e('Aktiv', 'rewan-booking'); ?></span>
                                <?php else : ?>
                                    <span class="rb-emp-pill rb-emp-pill--inactive"><?php esc_html_e('Inaktiv', 'rewan-booking'); ?></span>
                                <?php endif; ?>
                            </div>
                            <details class="rb-emp-details">
                                <summary><?php esc_html_e('Bearbeiten', 'rewan-booking'); ?></summary>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="rb-emp-edit-form">
                                    <input type="hidden" name="action" value="rewan_booking_save_employee">
                                    <input type="hidden" name="employee_id" value="<?php echo esc_attr($employee_id); ?>">
                                    <?php wp_nonce_field('rewan_booking_save_employee_nonce', 'rewan_booking_save_employee_nonce'); ?>

                                    <h3><?php esc_html_e('Person', 'rewan-booking'); ?></h3>
                                    <div class="rb-emp-fields">
                                        <label><span><?php esc_html_e('Name', 'rewan-booking'); ?></span><input type="text" name="employee_name" value="<?php echo esc_attr($row['name']); ?>" required></label>
                                        <label><span><?php esc_html_e('E-Mail', 'rewan-booking'); ?></span><input type="email" name="employee_email" value="<?php echo esc_attr($row['email']); ?>" required></label>
                                        <label class="is-full"><span><?php esc_html_e('Bild-URL', 'rewan-booking'); ?></span><input type="url" name="employee_image_url" value="<?php echo esc_attr($row['image_url']); ?>" placeholder="https://"></label>
                                    </div>
                                    <label class="rb-emp-switch">
                                        <input type="checkbox" name="employee_is_active" value="1" <?php checked((int) $row['is_active'], 1); ?>>
                                        <span><?php esc_html_e('Aktiv', 'rewan-booking'); ?><small><?php esc_html_e('Sichtbar für Kunden', 'rewan-booking'); ?></small></span>
                                    </label>

                                    <h3><?php esc_html_e('Arbeitszeiten', 'rewan-booking'); ?></h3>
                                    <label class="rb-emp-switch">
                                        <input type="checkbox" name="follows_opening" value="1" class="rb-emp-follows" <?php checked($follows_opening); ?>>
                                        <span><?php esc_html_e('Wie der Laden', 'rewan-booking'); ?><small><?php esc_html_e('Haken an: es gelten die Öffnungszeiten. Haken weg: eigene Tage unten.', 'rewan-booking'); ?></small></span>
                                    </label>
                                    <div class="rb-emp-schedule-wrap<?php echo $follows_opening ? ' is-muted' : ''; ?>">
                                        <div class="rb-emp-schedule-toolbar">
                                            <button type="button" class="button rb-emp-preset-monsa"><?php esc_html_e('Mo–Sa 09:00–18:00, Sonntag frei', 'rewan-booking'); ?></button>
                                            <span class="rb-emp-schedule-note"><?php esc_html_e('Eigene Zeiten gelten nur, solange der Laden offen ist.', 'rewan-booking'); ?></span>
                                        </div>
                                        <div class="rb-emp-days">
                                            <?php for ($weekday = 1; $weekday <= 7; $weekday++) : ?>
                                                <?php
                                                $h = isset($hours_by_employee[$employee_id][$weekday]) ? $hours_by_employee[$employee_id][$weekday] : array();
                                                $b = isset($breaks_by_employee[$employee_id][$weekday]) ? $breaks_by_employee[$employee_id][$weekday] : array();
                                                $is_working = isset($h['is_working']) ? (int) $h['is_working'] : (($weekday <= 6) ? 1 : 0);
                                                $start_time = isset($h['start_time']) ? substr((string) $h['start_time'], 0, 5) : '09:00';
                                                $end_time = isset($h['end_time']) ? substr((string) $h['end_time'], 0, 5) : '18:00';
                                                $is_enabled = isset($b['is_enabled']) ? (int) $b['is_enabled'] : 0;
                                                $break_start = isset($b['break_start']) ? substr((string) $b['break_start'], 0, 5) : '12:00';
                                                $break_end = isset($b['break_end']) ? substr((string) $b['break_end'], 0, 5) : '13:00';
                                                ?>
                                                <div class="rb-emp-day<?php echo $is_working ? '' : ' is-off'; ?>">
                                                    <div class="rb-emp-day-name"><?php echo esc_html($weekday_labels[$weekday]); ?></div>
                                                    <label class="rb-emp-switch rb-emp-switch--inline rb-emp-work">
                                                        <input type="checkbox" class="rb-emp-working" name="hours[<?php echo esc_attr((string) $weekday); ?>][is_working]" value="1" <?php checked($is_working, 1); ?>>
                                                        <span><?php esc_html_e('Arbeitet', 'rewan-booking'); ?></span>
                                                    </label>
                                                    <label class="rb-emp-time">
                                                        <span><?php esc_html_e('Von', 'rewan-booking'); ?></span>
                                                        <?php $this->render_time_24('hours[' . $weekday . '][start_time]', $start_time); ?>
                                                    </label>
                                                    <label class="rb-emp-time">
                                                        <span><?php esc_html_e('Bis', 'rewan-booking'); ?></span>
                                                        <?php $this->render_time_24('hours[' . $weekday . '][end_time]', $end_time); ?>
                                                    </label>
                                                    <label class="rb-emp-switch rb-emp-switch--inline rb-emp-pause">
                                                        <input type="checkbox" class="rb-emp-break" name="breaks[<?php echo esc_attr((string) $weekday); ?>][is_enabled]" value="1" <?php checked($is_enabled, 1); ?>>
                                                        <span><?php esc_html_e('Pause', 'rewan-booking'); ?></span>
                                                    </label>
                                                    <label class="rb-emp-time rb-emp-break-time<?php echo $is_enabled ? '' : ' is-muted'; ?>">
                                                        <span><?php esc_html_e('Von', 'rewan-booking'); ?></span>
                                                        <?php $this->render_time_24('breaks[' . $weekday . '][break_start]', $break_start); ?>
                                                    </label>
                                                    <label class="rb-emp-time rb-emp-break-time<?php echo $is_enabled ? '' : ' is-muted'; ?>">
                                                        <span><?php esc_html_e('Bis', 'rewan-booking'); ?></span>
                                                        <?php $this->render_time_24('breaks[' . $weekday . '][break_end]', $break_end); ?>
                                                    </label>
                                                </div>
                                            <?php endfor; ?>
                                        </div>
                                    </div>

                                    <div class="rb-emp-actions">
                                        <?php submit_button(__('Änderungen speichern', 'rewan-booking'), 'primary', 'submit', false); ?>
                                        <a class="rb-emp-delete" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=rewan_booking_delete_employee&employee_id=' . $employee_id), 'rewan_booking_delete_employee_' . $employee_id)); ?>" onclick="return confirm('<?php echo esc_js(__('Mitarbeiter wirklich löschen? Das geht nur, wenn noch keine Buchungen zugewiesen sind.', 'rewan-booking')); ?>');"><?php esc_html_e('Löschen', 'rewan-booking'); ?></a>
                                    </div>
                                </form>
                            </details>
                        </article>
                    <?php endforeach; ?>
                    <script>
                    (function () {
                        function setTime(form, inputName, value) {
                            var hidden = form.querySelector('input[name="' + inputName + '"]');
                            if (!hidden) return;
                            var parts = String(value).split(':');
                            var wrap = hidden.closest('.rb-time24');
                            var hour = wrap ? wrap.querySelector('.rb-time24-h') : null;
                            var minute = wrap ? wrap.querySelector('.rb-time24-m') : null;
                            if (hour) hour.value = parts[0];
                            if (minute) minute.value = parts[1];
                            hidden.value = parts[0] + ':' + parts[1];
                        }

                        function syncDay(day) {
                            var work = day.querySelector('.rb-emp-working');
                            var pause = day.querySelector('.rb-emp-break');
                            day.classList.toggle('is-off', !(work && work.checked));
                            day.querySelectorAll('.rb-emp-break-time').forEach(function (el) {
                                el.classList.toggle('is-muted', !(pause && pause.checked) || !(work && work.checked));
                            });
                        }

                        document.querySelectorAll('.rb-emp-edit-form').forEach(function (form) {
                            var follow = form.querySelector('.rb-emp-follows');
                            var schedule = form.querySelector('.rb-emp-schedule-wrap');
                            function syncFollow() {
                                if (!follow || !schedule) return;
                                schedule.classList.toggle('is-muted', follow.checked);
                            }
                            if (follow) {
                                follow.addEventListener('change', syncFollow);
                                syncFollow();
                            }
                            form.querySelectorAll('.rb-emp-day').forEach(function (day) {
                                syncDay(day);
                                day.addEventListener('change', function () { syncDay(day); });
                            });
                            var preset = form.querySelector('.rb-emp-preset-monsa');
                            if (!preset) return;
                            function setDay(day, workOn, st, et, pauseOn, pst, pet) {
                                var w = String(day);
                                var work = form.querySelector('input[name="hours[' + w + '][is_working]"]');
                                var pOn = form.querySelector('input[name="breaks[' + w + '][is_enabled]"]');
                                if (work) work.checked = !!workOn;
                                if (pOn) pOn.checked = !!pauseOn;
                                setTime(form, 'hours[' + w + '][start_time]', st);
                                setTime(form, 'hours[' + w + '][end_time]', et);
                                setTime(form, 'breaks[' + w + '][break_start]', pst);
                                setTime(form, 'breaks[' + w + '][break_end]', pet);
                            }
                            preset.addEventListener('click', function () {
                                for (var day = 1; day <= 6; day++) {
                                    setDay(day, true, '09:00', '18:00', false, '12:00', '13:00');
                                }
                                setDay(7, false, '09:00', '18:00', false, '12:00', '13:00');
                                form.querySelectorAll('.rb-emp-day').forEach(syncDay);
                            });
                        });
                    })();
                    </script>
                <?php else : ?>
                    <p class="rb-emp-empty"><?php esc_html_e('Keine Mitarbeiter gefunden.', 'rewan-booking'); ?></p>
                <?php endif; ?>
            </div>
            </div>
        </div>
        <?php
    }

    /**
     * Konvertiert Y-m-d (DB) in lesbare Anzeige mit Wochentag (site-lokalisiert).
     */
    private function format_absence_date_display($ymd) {
        if (!is_string($ymd) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
            return $ymd;
        }
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $ymd, wp_timezone());
        if (!$dt instanceof DateTimeImmutable) {
            return $ymd;
        }
        return date_i18n('l, d.m.Y', $dt->getTimestamp());
    }

    /**
     * Kopfzeile Karte: Zeitraum mit Wochentag(en).
     *
     * @param array<string,mixed> $row
     */
    private function format_absence_card_period_header($row) {
        $start_d = isset($row['start_date']) ? (string) $row['start_date'] : '';
        $end_d = isset($row['end_date']) ? (string) $row['end_date'] : '';
        if ($start_d === '') {
            return '';
        }
        if ($start_d === $end_d) {
            return $this->format_absence_date_display($start_d);
        }
        return $this->format_absence_date_display($start_d) . ' – ' . $this->format_absence_date_display($end_d);
    }

    /**
     * Karten-Body: Kurzinfo (ganztägig / Uhrzeit), ohne erneutes Datum.
     *
     * @param array<string,mixed> $row
     */
    private function format_absence_card_detail($row) {
        $all_day = isset($row['is_all_day']) ? (int) $row['is_all_day'] === 1 : true;
        if ($all_day) {
            $sd = isset($row['start_date']) ? (string) $row['start_date'] : '';
            $ed = isset($row['end_date']) ? (string) $row['end_date'] : '';
            return ($sd !== '' && $sd === $ed) ? 'Ganztägig' : 'Ganztägig · mehrere Tage';
        }
        $sd = isset($row['start_date']) ? (string) $row['start_date'] : '';
        $ed = isset($row['end_date']) ? (string) $row['end_date'] : '';
        $st = isset($row['start_time']) ? substr((string) $row['start_time'], 0, 5) : '';
        $et = isset($row['end_time']) ? substr((string) $row['end_time'], 0, 5) : '';
        if ($st === '' || $et === '') {
            return '';
        }
        if ($sd !== '' && $ed !== '' && $sd !== $ed) {
            return sprintf(
                /* translators: 1: start time (HH:MM), 2: end time (HH:MM) */
                __('Mehrere Tage · ab %1$s · bis %2$s Uhr', 'rewan-booking'),
                $st,
                $et
            );
        }
        return $st . ' – ' . $et . ' Uhr';
    }

    /**
     * Wiederkehrende Ferien-Tage (monat-tag) je Mitarbeiter.
     *
     * @return array<int,array<string,int>>
     */
    private function get_recurring_holidays_map() {
        $raw = get_option('rewan_booking_recurring_holidays', array());
        if (!is_array($raw)) {
            return array();
        }
        $map = array();
        foreach ($raw as $employee_id => $days) {
            $eid = (int) $employee_id;
            if ($eid <= 0 || !is_array($days)) {
                continue;
            }
            $clean = array();
            foreach ($days as $md => $on) {
                if (!is_string($md) || !preg_match('/^\d{1,2}-\d{1,2}$/', $md)) {
                    continue;
                }
                $clean[$md] = 1;
            }
            if (!empty($clean)) {
                $map[$eid] = $clean;
            }
        }
        return $map;
    }

    /**
     * @param array<int,array<string,int>> $map
     */
    private function set_recurring_holidays_map(array $map) {
        update_option('rewan_booking_recurring_holidays', $map, false);
    }

    /**
     * Unterscheidbare Farben für Mitarbeiter-Legende (Freie Tage).
     *
     * @return list<string>
     */
    private function get_days_off_legend_color_palette() {
        return array(
            '#2563eb',
            '#dc2626',
            '#059669',
            '#d97706',
            '#7c3aed',
            '#db2777',
            '#0891b2',
            '#4d7c0f',
            '#c2410c',
            '#4338ca',
            '#0f766e',
            '#b45309',
        );
    }

    /**
     * Eventformat für Bookly jCal.
     *
     * @return array<int,array<string,int>>
     */
    private function build_days_off_events_for_employee($employee_id) {
        $employee_id = (int) $employee_id;
        if ($employee_id <= 0) {
            return array();
        }
        global $wpdb;
        $absences_table = $wpdb->prefix . 'rewan_booking_employee_absences';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT start_date, end_date FROM {$absences_table}
                 WHERE employee_id = %d AND absence_type IN ('holiday','vacation')
                 ORDER BY start_date ASC",
                $employee_id
            ),
            ARRAY_A
        );

        $events = array();
        foreach ((array) $rows as $row) {
            $from = isset($row['start_date']) ? (string) $row['start_date'] : '';
            $to = isset($row['end_date']) ? (string) $row['end_date'] : '';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                continue;
            }
            if ($to < $from) {
                $tmp = $from;
                $from = $to;
                $to = $tmp;
            }
            $d = $from;
            while ($d <= $to) {
                $events[] = array(
                    'm' => (int) date('n', strtotime($d)),
                    'd' => (int) date('j', strtotime($d)),
                    'y' => (int) date('Y', strtotime($d)),
                );
                $d = date('Y-m-d', strtotime($d . ' +1 day'));
            }
        }

        $recurring = $this->get_recurring_holidays_map();
        if (isset($recurring[$employee_id])) {
            foreach ($recurring[$employee_id] as $md => $on) {
                list($m, $d) = array_map('intval', explode('-', $md, 2));
                if ($m >= 1 && $m <= 12 && $d >= 1 && $d <= 31) {
                    $events[] = array('m' => $m, 'd' => $d);
                }
            }
        }

        return $events;
    }

    /**
     * @return string H:i:s
     */
    private function normalize_day_off_time($time) {
        $time = trim((string) $time);
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $time, $m)) {
            $h = max(0, min(23, (int) $m[1]));
            $i = max(0, min(59, (int) $m[2]));
            $s = isset($m[3]) ? max(0, min(59, (int) $m[3])) : 0;

            return sprintf('%02d:%02d:%02d', $h, $i, $s);
        }

        return '09:00:00';
    }

    public function ajax_update_holidays() {
        if (!current_user_can(REWAN_BOOKING_CAP_SALON)) {
            wp_send_json_error(array('message' => 'forbidden'), 403);
        }
        $csrf = isset($_POST['csrf_token']) ? sanitize_text_field(wp_unslash($_POST['csrf_token'])) : '';
        $csrf_ok = wp_verify_nonce($csrf, 'bookly') || wp_verify_nonce($csrf, 'rewan_booking_cal');
        if (!$csrf_ok) {
            wp_send_json_error(array('message' => 'csrf'), 403);
        }

        $employee_id = isset($_POST['staff_id']) ? (int) $_POST['staff_id'] : 0;
        if ($employee_id <= 0) {
            wp_send_json_error(array('message' => 'employee'), 400);
        }

        $holiday = isset($_POST['holiday']) && wp_unslash((string) $_POST['holiday']) === 'true';
        $range = isset($_POST['range']) ? (array) $_POST['range'] : array();
        if (count($range) < 2) {
            wp_send_json_error(array('message' => 'range'), 400);
        }
        $from = sanitize_text_field(wp_unslash((string) $range[0]));
        $to = sanitize_text_field(wp_unslash((string) $range[1]));
        if (!preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $from) || !preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $to)) {
            wp_send_json_error(array('message' => 'range_format'), 400);
        }
        $from = date('Y-m-d', strtotime($from));
        $to = date('Y-m-d', strtotime($to));
        if ($to < $from) {
            $tmp = $from;
            $from = $to;
            $to = $tmp;
        }

        $all_day = !isset($_POST['all_day']) || wp_unslash((string) $_POST['all_day']) !== 'false';
        $start_time = $this->normalize_day_off_time(
            isset($_POST['start_time']) ? (string) wp_unslash($_POST['start_time']) : '09:00'
        );
        $end_time = $this->normalize_day_off_time(
            isset($_POST['end_time']) ? (string) wp_unslash($_POST['end_time']) : '18:00'
        );

        if ($holiday && !$all_day && strcmp($end_time, $start_time) <= 0) {
            wp_send_json_error(
                array('message' => __('„Bis“ muss nach „Von“ liegen.', 'rewan-booking')),
                400
            );
        }

        global $wpdb;
        $absences_table = $wpdb->prefix . 'rewan_booking_employee_absences';

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$absences_table}
                 WHERE employee_id = %d
                   AND absence_type = 'holiday'
                   AND start_date <= %s
                   AND end_date >= %s",
                $employee_id,
                $to,
                $from
            )
        );

        if ($holiday) {
            $d = $from;
            while ($d <= $to) {
                if ($all_day) {
                    $wpdb->insert(
                        $absences_table,
                        array(
                            'employee_id' => $employee_id,
                            'start_date' => $d,
                            'end_date' => $d,
                            'start_time' => '00:00:00',
                            'end_time' => '23:59:59',
                            'is_all_day' => 1,
                            'absence_type' => 'holiday',
                            'title' => '',
                        ),
                        array('%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
                    );
                } else {
                    $wpdb->insert(
                        $absences_table,
                        array(
                            'employee_id' => $employee_id,
                            'start_date' => $d,
                            'end_date' => $d,
                            'start_time' => $start_time,
                            'end_time' => $end_time,
                            'is_all_day' => 0,
                            'absence_type' => 'holiday',
                            'title' => '',
                        ),
                        array('%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
                    );
                }
                $d = date('Y-m-d', strtotime($d . ' +1 day'));
            }
        }

        wp_send_json_success($this->build_days_off_events_for_employee($employee_id));
    }

    /**
     * Aufeinanderfolgende Ganztags-Einträge derselben Person als ein Zeitraum.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function group_absence_rows($rows) {
        $rows = array_values((array) $rows);
        usort($rows, function ($a, $b) {
            $by_employee = (int) $a['employee_id'] <=> (int) $b['employee_id'];
            if ($by_employee !== 0) {
                return $by_employee;
            }
            $by_date = strcmp((string) $a['start_date'], (string) $b['start_date']);
            if ($by_date !== 0) {
                return $by_date;
            }
            return (int) $a['id'] <=> (int) $b['id'];
        });

        $groups = array();
        foreach ($rows as $row) {
            $all_day = isset($row['is_all_day']) && (int) $row['is_all_day'] === 1;
            $title = trim((string) ($row['title'] ?? ''));
            $index = count($groups) - 1;
            $last = $index >= 0 ? $groups[$index] : null;
            $follows = false;
            if ($last && $all_day && (int) $last['is_all_day'] === 1 && (int) $last['employee_id'] === (int) $row['employee_id'] && trim((string) $last['title']) === $title && (string) $last['absence_type'] === (string) $row['absence_type']) {
                $next_day = date('Y-m-d', strtotime($last['end_date'] . ' +1 day'));
                $follows = (string) $row['start_date'] <= $next_day;
            }
            if ($follows) {
                if ((string) $row['end_date'] > (string) $groups[$index]['end_date']) {
                    $groups[$index]['end_date'] = (string) $row['end_date'];
                }
                $groups[$index]['ids'][] = (int) $row['id'];
                continue;
            }
            $groups[] = array(
                'ids' => array((int) $row['id']),
                'employee_id' => (int) $row['employee_id'],
                'employee_name' => (string) ($row['employee_name'] ?? ''),
                'title' => $title,
                'absence_type' => (string) ($row['absence_type'] ?? ''),
                'start_date' => (string) $row['start_date'],
                'end_date' => (string) $row['end_date'],
                'is_all_day' => $all_day ? 1 : 0,
                'start_time' => isset($row['start_time']) ? substr((string) $row['start_time'], 0, 5) : '',
                'end_time' => isset($row['end_time']) ? substr((string) $row['end_time'], 0, 5) : '',
            );
        }

        usort($groups, function ($a, $b) {
            return strcmp((string) $b['start_date'], (string) $a['start_date']);
        });

        return $groups;
    }

    public function ajax_save_ferien_range() {
        if (!current_user_can(REWAN_BOOKING_CAP_SALON)) {
            wp_send_json_error(array('message' => 'Keine Berechtigung.'), 403);
        }
        check_ajax_referer('rewan_booking_ferien', 'nonce');

        $employee_id = isset($_POST['employee_id']) ? (int) $_POST['employee_id'] : 0;
        $start = isset($_POST['start']) ? sanitize_text_field(wp_unslash($_POST['start'])) : '';
        $end = isset($_POST['end']) ? sanitize_text_field(wp_unslash($_POST['end'])) : '';
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        if ($title === '') {
            $title = 'Ferien';
        }
        if ($employee_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            wp_send_json_error(array('message' => 'Bitte einen gültigen Zeitraum wählen.'), 400);
        }
        if ($end < $start) {
            $swap = $start;
            $start = $end;
            $end = $swap;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'rewan_booking_employee_absences';
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table}
                 WHERE employee_id = %d
                   AND is_all_day = 1
                   AND absence_type IN ('holiday','vacation')
                   AND start_date <= %s
                   AND end_date >= %s",
                $employee_id,
                $end,
                $start
            )
        );
        $ok = $wpdb->insert(
            $table,
            array(
                'employee_id' => $employee_id,
                'title' => $title,
                'absence_type' => 'holiday',
                'start_date' => $start,
                'end_date' => $end,
                'is_all_day' => 1,
                'start_time' => '00:00:00',
                'end_time' => '23:59:59',
            ),
            array('%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );
        if (!$ok) {
            wp_send_json_error(array('message' => 'Konnte nicht gespeichert werden.'), 500);
        }
        wp_send_json_success(array('id' => (int) $wpdb->insert_id));
    }

    public function render_absences_page() {
        if (!current_user_can(REWAN_BOOKING_CAP_SALON)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rewan-booking'));
        }
        global $wpdb;

        $employees_table = $wpdb->prefix . 'rewan_booking_employees';
        $absences_table = $wpdb->prefix . 'rewan_booking_employee_absences';

        $employees = $wpdb->get_results("SELECT * FROM $employees_table WHERE is_active = 1 ORDER BY name ASC", ARRAY_A);
        $absences = $wpdb->get_results(
            "SELECT a.*, e.name AS employee_name
             FROM {$absences_table} a
             LEFT JOIN {$employees_table} e ON e.id = a.employee_id
             ORDER BY a.start_date DESC, a.id DESC",
            ARRAY_A
        );
        $prefill_employee_id = isset($_GET['employee_id']) ? (int) $_GET['employee_id'] : 0;
        $days_off_employee_id = $prefill_employee_id > 0
            ? $prefill_employee_id
            : (!empty($employees) ? (int) $employees[0]['id'] : 0);

        $groups = $this->group_absence_rows($absences);
        $ranges_by_employee = array();
        foreach ($groups as $group) {
            $eid = (int) $group['employee_id'];
            if (!isset($ranges_by_employee[$eid])) {
                $ranges_by_employee[$eid] = array();
            }
            $ranges_by_employee[$eid][] = array(
                'ids' => $group['ids'],
                'start' => $group['start_date'],
                'end' => $group['end_date'],
                'title' => $group['title'] !== '' ? $group['title'] : 'Ferien',
            );
        }

        wp_enqueue_script(
            'rewan-booking-ferien',
            rewan_booking_plugin_url('assets/js/rewan-booking-ferien.js'),
            array(),
            REWAN_BOOKING_VERSION,
            true
        );
        wp_localize_script(
            'rewan-booking-ferien',
            'RewanFerien',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('rewan_booking_ferien'),
                'employeeId' => $days_off_employee_id,
                'ranges' => $ranges_by_employee,
                'reload' => admin_url('admin.php?page=rewan-booking-absences'),
            )
        );

        $message = isset($_GET['message']) ? sanitize_text_field(wp_unslash($_GET['message'])) : '';
        ?>
        <div class="wrap rb-abs-dashboard rb-ferien">
            <h1><?php esc_html_e('Ferien', 'rewan-booking'); ?></h1>
            <?php $this->render_admin_notice($message); ?>

            <p class="rb-ferien-lead"><?php esc_html_e('Person antippen. Dann den ersten Tag, danach den letzten. Die Tage dazwischen werden gelb und als ein Eintrag gespeichert.', 'rewan-booking'); ?></p>

            <div class="rb-ferien-panel">
                <p class="rb-ferien-label"><?php esc_html_e('Mitarbeiter', 'rewan-booking'); ?></p>
                <div class="rb-ferien-chips" role="listbox">
                    <?php foreach ($employees as $employee) : ?>
                        <?php $eid = (int) $employee['id']; ?>
                        <button type="button" class="rb-ferien-chip<?php echo $days_off_employee_id === $eid ? ' is-selected' : ''; ?>" data-employee-id="<?php echo esc_attr((string) $eid); ?>">
                            <?php echo esc_html($employee['name']); ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="rb-ferien-nav">
                    <button type="button" class="rb-ferien-nav-btn" id="rb-ferien-prev" aria-label="<?php esc_attr_e('Vorheriger Monat', 'rewan-booking'); ?>">‹</button>
                    <strong id="rb-ferien-month"></strong>
                    <button type="button" class="rb-ferien-nav-btn" id="rb-ferien-next" aria-label="<?php esc_attr_e('Nächster Monat', 'rewan-booking'); ?>">›</button>
                </div>
                <div class="rb-ferien-dow" aria-hidden="true">
                    <span>Mo</span><span>Di</span><span>Mi</span><span>Do</span><span>Fr</span><span>Sa</span><span>So</span>
                </div>
                <div class="rb-ferien-grid" id="rb-ferien-grid"></div>
                <p class="rb-ferien-hint" id="rb-ferien-hint"><?php esc_html_e('Ersten Tag antippen.', 'rewan-booking'); ?></p>
            </div>

            <div class="rb-ferien-sheet" id="rb-ferien-sheet" hidden>
                <p class="rb-ferien-sheet-title" id="rb-ferien-sheet-title"></p>
                <label class="rb-ferien-note" id="rb-ferien-note-wrap">
                    <?php esc_html_e('Was war das?', 'rewan-booking'); ?>
                    <input type="text" id="rb-ferien-title" value="Ferien" maxlength="80" autocomplete="off">
                </label>
                <button type="button" class="rb-ferien-save" id="rb-ferien-save"><?php esc_html_e('Speichern', 'rewan-booking'); ?></button>
                <a class="rb-ferien-delete" id="rb-ferien-delete" hidden href="#"><?php esc_html_e('Diese Ferien löschen', 'rewan-booking'); ?></a>
                <button type="button" class="rb-ferien-cancel" id="rb-ferien-cancel"><?php esc_html_e('Abbrechen', 'rewan-booking'); ?></button>
            </div>

            <div class="rb-ferien-list">
                <div class="rb-ferien-list-head">
                    <h2><?php esc_html_e('Einträge', 'rewan-booking'); ?> <span id="rb-ferien-count"><?php echo esc_html((string) count(array_filter($groups, function ($group) use ($days_off_employee_id) {
                        return (int) $group['employee_id'] === (int) $days_off_employee_id;
                    }))); ?></span></h2>
                    <button type="button" class="rb-ferien-all" id="rb-ferien-all"><?php esc_html_e('Alle anzeigen', 'rewan-booking'); ?></button>
                </div>
                <?php if (empty($groups)) : ?>
                    <p class="rb-ferien-empty"><?php esc_html_e('Noch keine Ferien.', 'rewan-booking'); ?></p>
                <?php else : ?>
                    <div class="rb-ferien-cards">
                        <?php foreach ($groups as $group) : ?>
                            <?php
                            $ids = array_map('intval', $group['ids']);
                            sort($ids);
                            $id_key = implode(',', $ids);
                            $delete_url = wp_nonce_url(
                                admin_url('admin-post.php?action=rewan_booking_delete_absence&absence_ids=' . rawurlencode($id_key) . '&employee_id=' . (int) $group['employee_id']),
                                'rewan_booking_delete_absence_group_' . $id_key
                            );
                            $start_label = date_i18n('d.m.Y', strtotime($group['start_date']));
                            $end_label = date_i18n('d.m.Y', strtotime($group['end_date']));
                            $start_dt = date_create($group['start_date']);
                            $end_dt = date_create($group['end_date']);
                            $day_count = ($start_dt && $end_dt) ? ((int) $start_dt->diff($end_dt)->days + 1) : 1;
                            $period = $group['start_date'] === $group['end_date']
                                ? $start_label
                                : $start_label . ' – ' . $end_label;
                            $note = $group['title'] !== '' ? $group['title'] : 'Ferien';
                            if ((int) $group['is_all_day'] !== 1 && $group['start_time'] !== '') {
                                $note .= ' · ' . $group['start_time'] . '–' . $group['end_time'];
                            }
                            ?>
                            <article class="rb-ferien-card" data-employee="<?php echo esc_attr((string) $group['employee_id']); ?>" data-start="<?php echo esc_attr($group['start_date']); ?>" data-end="<?php echo esc_attr($group['end_date']); ?>"<?php echo (int) $group['employee_id'] === (int) $days_off_employee_id ? '' : ' hidden'; ?>>
                                <div>
                                    <strong><?php echo esc_html($group['employee_name']); ?></strong>
                                    <span><?php echo esc_html($period); ?></span>
                                    <em><?php echo esc_html($day_count === 1 ? '1 Tag · ' . $note : $day_count . ' Tage · ' . $note); ?></em>
                                </div>
                                <a class="rb-ferien-card-del" href="<?php echo esc_url($delete_url); ?>"><?php esc_html_e('Löschen', 'rewan-booking'); ?></a>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Admin-Link für Buchungsliste inkl. optionalem Datumsbereich.
     */
    private function bookings_admin_filter_url($filter, $from_date = '', $to_date = '') {
        $args = array(
            'page' => 'rewan-booking-bookings',
            'filter' => $filter,
        );
        if ($from_date !== '') {
            $args['from_date'] = $from_date;
        }
        if ($to_date !== '') {
            $args['to_date'] = $to_date;
        }
        return add_query_arg($args, admin_url('admin.php'));
    }

    /**
     * Telefonnummer für tel:-Links bereinigen.
     */
    private function bookings_phone_tel_href($phone) {
        $phone = is_string($phone) ? trim($phone) : '';
        if ($phone === '') {
            return '';
        }
        $stripped = preg_replace('/[^\d+]/', '', $phone);
        return $stripped !== '' ? 'tel:' . $stripped : '';
    }

    public function render_bookings_page() {
        if (!current_user_can(REWAN_BOOKING_CAP_SALON)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rewan-booking'));
        }
        global $wpdb;
        $table_name = $wpdb->prefix . 'rewan_booking_bookings';
        $employees_table = $wpdb->prefix . 'rewan_booking_employees';

        $edit_id = isset($_GET['edit_booking']) ? (int) $_GET['edit_booking'] : 0;

        // --- BEARBEITEN ANSICHT ---
        if ($edit_id > 0) {
            $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $edit_id), ARRAY_A);
            if (!$booking) {
                echo '<div class="wrap"><h1>Buchung nicht gefunden.</h1></div>';
                return;
            }
            $linked = $this->get_user_linked_employee_id();
            if ($this->user_is_salon_only() && $linked > 0 && (int) $booking['employee_id'] !== $linked) {
                wp_die(esc_html__('Keine Berechtigung für diese Buchung.', 'rewan-booking'));
            }

            $employees = $wpdb->get_results("SELECT id, name FROM $employees_table WHERE is_active = 1", ARRAY_A);
            $full_edit = current_user_can(REWAN_BOOKING_CAP_MANAGE);
            ?>
            <div class="wrap">
                <h1>Buchung bearbeiten (#<?php echo esc_html($booking['id']); ?>)</h1>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="rewan_booking_save_booking">
                    <input type="hidden" name="booking_id" value="<?php echo esc_attr($booking['id']); ?>">
                    <?php wp_nonce_field('rewan_booking_save_booking_nonce', 'rewan_booking_save_booking_nonce'); ?>

                    <table class="form-table">
                        <tr>
                            <th>Status</th>
                            <td>
                                <select name="status">
                                    <option value="confirmed" <?php selected($booking['status'], 'confirmed'); ?>>Bestätigt</option>
                                    <option value="cancelled" <?php selected($booking['status'], 'cancelled'); ?>>Storniert</option>
                                </select>
                            </td>
                        </tr>
                        <?php if ($full_edit) : ?>
                        <tr>
                            <th>Datum (Verschieben)</th>
                            <td><input type="date" name="booking_date" value="<?php echo esc_attr($booking['booking_date']); ?>" required></td>
                        </tr>
                        <tr>
                            <th>Zeit (Verschieben)</th>
                            <td><?php $this->render_time_24('start_time', substr($booking['start_time'], 0, 5)); ?></td>
                        </tr>
                        <tr>
                            <th>Mitarbeiter</th>
                            <td>
                                <select name="employee_id">
                                    <?php foreach ($employees as $emp) : ?>
                                        <option value="<?php echo esc_attr($emp['id']); ?>" <?php selected($booking['employee_id'], $emp['id']); ?>><?php echo esc_html($emp['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <?php else : ?>
                            <input type="hidden" name="booking_date" value="<?php echo esc_attr($booking['booking_date']); ?>">
                            <input type="hidden" name="start_time" value="<?php echo esc_attr(substr($booking['start_time'], 0, 5)); ?>">
                            <input type="hidden" name="employee_id" value="<?php echo esc_attr((string) $booking['employee_id']); ?>">
                            <tr>
                                <th colspan="2">
                                    <p class="description" style="max-width:520px;">
                                        <?php esc_html_e('Datum, Uhrzeit oder Barber ändern geht im vollen Verwaltungsbereich (Cockpit). Hier nur Status: bestätigt oder storniert.', 'rewan-booking'); ?>
                                    </p>
                                </th>
                            </tr>
                        <?php endif; ?>
                    </table>
                    <p>
                        <?php submit_button('Änderungen speichern', 'primary', 'submit', false); ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=rewan-booking-bookings')); ?>" class="button" style="margin-left:10px;">Abbrechen</a>
                    </p>
                </form>
            </div>
            <?php
            return;
        }

        // --- LISTEN ANSICHT ---
        $today = current_time('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));
        $filter = isset($_GET['filter']) ? sanitize_text_field(wp_unslash($_GET['filter'])) : 'today';
        $allowed_filters = array('today', 'tomorrow', 'upcoming', 'all', 'cancelled');
        if (!in_array($filter, $allowed_filters, true)) {
            $filter = 'today';
        }

        $from_date = isset($_GET['from_date']) ? sanitize_text_field(wp_unslash($_GET['from_date'])) : '';
        $to_date = isset($_GET['to_date']) ? sanitize_text_field(wp_unslash($_GET['to_date'])) : '';

        if (!empty($from_date) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date)) {
            $from_date = '';
        }
        if (!empty($to_date) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date)) {
            $to_date = '';
        }
        if (!empty($from_date) && !empty($to_date) && $to_date < $from_date) {
            $tmp = $from_date;
            $from_date = $to_date;
            $to_date = $tmp;
        }

        $where = array();
        $params = array();

        if ($filter === 'today') {
            $where[] = 'booking_date = %s';
            $params[] = $today;
        } elseif ($filter === 'tomorrow') {
            $where[] = 'booking_date = %s';
            $params[] = $tomorrow;
        } elseif ($filter === 'upcoming') {
            $where[] = 'booking_date >= %s';
            $params[] = $today;
            $where[] = 'status = %s';
            $params[] = 'confirmed';
        } elseif ($filter === 'cancelled') {
            $where[] = 'status = %s';
            $params[] = 'cancelled';
        }

        if (!empty($from_date)) {
            $where[] = 'booking_date >= %s';
            $params[] = $from_date;
        }
        if (!empty($to_date)) {
            $where[] = 'booking_date <= %s';
            $params[] = $to_date;
        }

        if ($this->user_is_salon_only()) {
            $linked_list = $this->get_user_linked_employee_id();
            if ($linked_list > 0) {
                $where[] = 'employee_id = %d';
                $params[] = $linked_list;
            }
        }

        $sql = "SELECT * FROM $table_name";
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY booking_date DESC, start_time DESC';

        $bookings = !empty($params)
            ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A)
            : $wpdb->get_results($sql, ARRAY_A);

        $bookings_empty = empty($bookings);
        $empty_detail = $bookings_empty
            ? (
                ($filter === 'today')
                    ? __('Heute sind keine Buchungen eingetragen. Schnellfilter oder Datumsbereich anpassen.', 'rewan-booking')
                    : __('Passe die Schnellfilter oder den Zeitraum an, um andere Buchungen zu sehen.', 'rewan-booking')
            )
            : '';

        $use_bookly_shell = false;

        ?>
        <?php if ($use_bookly_shell) : ?>
        <div id="bookly-tbs" class="wrap bookly-css-root rb-bk-page">
            <div class="form-row align-items-center mb-3">
                <h4 class="col m-0"><?php esc_html_e('Buchungen', 'rewan-booking'); ?></h4>
            </div>
            <div class="card">
                <div class="card-body">
                    <div class="bookly:grid bookly:grid-cols-1 bookly:gap-2 bookly:relative mb-3 rewan-bk-bookings-filters">
                        <div class="rb-bk-segments-wrap">
                            <nav class="rb-bk-segments" aria-label="<?php esc_attr_e('Buchungsfilter', 'rewan-booking'); ?>">
                                <a class="rb-bk-seg <?php echo $filter === 'today' ? 'is-active' : ''; ?>" href="<?php echo esc_url($this->bookings_admin_filter_url('today', $from_date, $to_date)); ?>"><?php esc_html_e('Heute', 'rewan-booking'); ?></a>
                                <a class="rb-bk-seg <?php echo $filter === 'tomorrow' ? 'is-active' : ''; ?>" href="<?php echo esc_url($this->bookings_admin_filter_url('tomorrow', $from_date, $to_date)); ?>"><?php esc_html_e('Morgen', 'rewan-booking'); ?></a>
                                <a class="rb-bk-seg <?php echo $filter === 'upcoming' ? 'is-active' : ''; ?>" href="<?php echo esc_url($this->bookings_admin_filter_url('upcoming', $from_date, $to_date)); ?>"><?php esc_html_e('Kommend', 'rewan-booking'); ?></a>
                                <a class="rb-bk-seg <?php echo $filter === 'all' ? 'is-active' : ''; ?>" href="<?php echo esc_url($this->bookings_admin_filter_url('all', $from_date, $to_date)); ?>"><?php esc_html_e('Alle', 'rewan-booking'); ?></a>
                                <a class="rb-bk-seg <?php echo $filter === 'cancelled' ? 'is-active' : ''; ?>" href="<?php echo esc_url($this->bookings_admin_filter_url('cancelled', $from_date, $to_date)); ?>"><?php esc_html_e('Storniert', 'rewan-booking'); ?></a>
                            </nav>
                        </div>
                        <form method="get" class="bookly:grid bookly:grid-cols-1 bookly:gap-2" aria-label="<?php esc_attr_e('Datumsfilter', 'rewan-booking'); ?>">
                            <input type="hidden" name="page" value="rewan-booking-bookings">
                            <input type="hidden" name="filter" value="<?php echo esc_attr($filter); ?>">
                            <div>
                                <label class="d-block" for="rb_from_date"><?php esc_html_e('Von', 'rewan-booking'); ?></label>
                                <input type="date" class="form-control" id="rb_from_date" name="from_date" value="<?php echo esc_attr($from_date); ?>">
                            </div>
                            <div>
                                <label class="d-block" for="rb_to_date"><?php esc_html_e('Bis', 'rewan-booking'); ?></label>
                                <input type="date" class="form-control" id="rb_to_date" name="to_date" value="<?php echo esc_attr($to_date); ?>">
                            </div>
                            <div class="d-flex flex-wrap align-items-center" style="gap:8px;">
                                <button type="submit" class="btn btn-primary"><?php esc_html_e('Filter anwenden', 'rewan-booking'); ?></button>
                                <a class="btn btn-default" href="<?php echo esc_url($this->bookings_admin_filter_url($filter, '', '')); ?>"><?php esc_html_e('Zurücksetzen', 'rewan-booking'); ?></a>
                            </div>
                        </form>
                    </div>
        <?php else : ?>
        <div class="wrap rb-bk-page">
            <header class="rb-bk-page__header">
                <h1 class="rb-bk-page__h1"><?php esc_html_e('Buchungen', 'rewan-booking'); ?></h1>
                <p class="rb-bk-page__lead"><?php esc_html_e('Übersicht nach Zeitraum, Schnellfilter und optional Von–Bis. Klick auf eine Zeile-Aktion zum Bearbeiten.', 'rewan-booking'); ?></p>
            </header>

            <div class="rb-bk-toolbar">
                <div class="rb-bk-segments-wrap">
                    <nav class="rb-bk-segments" aria-label="<?php esc_attr_e('Buchungsfilter', 'rewan-booking'); ?>">
                        <a class="rb-bk-seg <?php echo $filter === 'today' ? 'is-active' : ''; ?>" href="<?php echo esc_url($this->bookings_admin_filter_url('today', $from_date, $to_date)); ?>"><?php esc_html_e('Heute', 'rewan-booking'); ?></a>
                        <a class="rb-bk-seg <?php echo $filter === 'tomorrow' ? 'is-active' : ''; ?>" href="<?php echo esc_url($this->bookings_admin_filter_url('tomorrow', $from_date, $to_date)); ?>"><?php esc_html_e('Morgen', 'rewan-booking'); ?></a>
                        <a class="rb-bk-seg <?php echo $filter === 'upcoming' ? 'is-active' : ''; ?>" href="<?php echo esc_url($this->bookings_admin_filter_url('upcoming', $from_date, $to_date)); ?>"><?php esc_html_e('Kommend', 'rewan-booking'); ?></a>
                        <a class="rb-bk-seg <?php echo $filter === 'all' ? 'is-active' : ''; ?>" href="<?php echo esc_url($this->bookings_admin_filter_url('all', $from_date, $to_date)); ?>"><?php esc_html_e('Alle', 'rewan-booking'); ?></a>
                        <a class="rb-bk-seg <?php echo $filter === 'cancelled' ? 'is-active' : ''; ?>" href="<?php echo esc_url($this->bookings_admin_filter_url('cancelled', $from_date, $to_date)); ?>"><?php esc_html_e('Storniert', 'rewan-booking'); ?></a>
                    </nav>
                </div>

                <form method="get" class="rb-bk-date-filter" aria-label="<?php esc_attr_e('Datumsfilter', 'rewan-booking'); ?>">
                    <input type="hidden" name="page" value="rewan-booking-bookings">
                    <input type="hidden" name="filter" value="<?php echo esc_attr($filter); ?>">
                    <div>
                        <label for="rb_from_date"><?php esc_html_e('Von', 'rewan-booking'); ?></label>
                        <input type="date" id="rb_from_date" name="from_date" value="<?php echo esc_attr($from_date); ?>">
                    </div>
                    <div>
                        <label for="rb_to_date"><?php esc_html_e('Bis', 'rewan-booking'); ?></label>
                        <input type="date" id="rb_to_date" name="to_date" value="<?php echo esc_attr($to_date); ?>">
                    </div>
                    <button type="submit" class="button button-primary"><?php esc_html_e('Filter anwenden', 'rewan-booking'); ?></button>
                    <a class="button" href="<?php echo esc_url($this->bookings_admin_filter_url($filter, '', '')); ?>"><?php esc_html_e('Zurücksetzen', 'rewan-booking'); ?></a>
                </form>
            </div>

        <?php endif; ?>

            <div class="rb-bk-list rb-bookings-table<?php echo $bookings_empty ? ' rb-bk-list--empty' : ''; ?>" role="table" aria-label="<?php esc_attr_e('Buchungen', 'rewan-booking'); ?>">
                <div class="rb-bk-head" role="row">
                    <span role="columnheader"><?php esc_html_e('ID', 'rewan-booking'); ?></span>
                    <span role="columnheader"><?php esc_html_e('Kunde', 'rewan-booking'); ?></span>
                    <span role="columnheader"><?php esc_html_e('Mitarbeiter', 'rewan-booking'); ?></span>
                    <span role="columnheader"><?php esc_html_e('Datum', 'rewan-booking'); ?></span>
                    <span role="columnheader"><?php esc_html_e('Zeit', 'rewan-booking'); ?></span>
                    <span role="columnheader"><?php esc_html_e('Service', 'rewan-booking'); ?></span>
                    <span role="columnheader"><?php esc_html_e('Status', 'rewan-booking'); ?></span>
                    <span role="columnheader" class="rb-bk-head__action"><?php esc_html_e('Aktion', 'rewan-booking'); ?></span>
                </div>
                <?php if (!empty($bookings)) : ?>
                    <?php foreach ($bookings as $booking) : ?>
                        <?php
                        $service_text = $booking['services'];
                        $service_decoded = json_decode($booking['services'], true);
                        if (is_array($service_decoded)) {
                            $service_text = implode(', ', $service_decoded);
                        }
                        $is_today_row = ($booking['booking_date'] === $today);
                        $tel_href = $this->bookings_phone_tel_href(isset($booking['customer_phone']) ? $booking['customer_phone'] : '');
                        $del_url = '';
                        if (current_user_can(REWAN_BOOKING_CAP_MANAGE)) {
                            $del_url = wp_nonce_url(
                                admin_url('admin-post.php?action=rewan_booking_delete_booking&booking_id=' . (int) $booking['id']),
                                'rewan_booking_delete_booking_' . (int) $booking['id']
                            );
                        }
                        ?>
                        <div class="rb-bk-row<?php echo $is_today_row ? ' rb-bk-row--today' : ''; ?>" role="row">
                            <span class="rb-bk-id" role="cell">#<?php echo esc_html((string) $booking['id']); ?></span>
                            <div role="cell">
                                <p class="rb-bk-customer-name"><?php echo esc_html($booking['customer_name']); ?></p>
                                <?php if (!empty($booking['customer_phone'])) : ?>
                                    <p class="rb-bk-customer-tel">
                                        <?php if ($tel_href !== '') : ?>
                                            <a href="<?php echo esc_url($tel_href); ?>"><?php echo esc_html($booking['customer_phone']); ?></a>
                                        <?php else : ?>
                                            <?php echo esc_html($booking['customer_phone']); ?>
                                        <?php endif; ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                            <span class="rb-bk-emp" role="cell"><?php echo esc_html($booking['employee_name']); ?></span>
                            <span class="rb-bk-date" role="cell"><?php echo esc_html(date_i18n('l, d.m.Y', strtotime($booking['booking_date']))); ?></span>
                            <span class="rb-bk-time" role="cell"><?php echo esc_html(substr($booking['start_time'], 0, 5)); ?></span>
                            <span class="rb-bk-service" role="cell"><?php echo esc_html($service_text); ?></span>
                            <span role="cell">
                                <?php if ($booking['status'] === 'cancelled') : ?>
                                    <span class="rb-bk-badge rb-bk-badge--cancelled"><?php esc_html_e('Storniert', 'rewan-booking'); ?></span>
                                <?php else : ?>
                                    <span class="rb-bk-badge rb-bk-badge--confirmed"><?php esc_html_e('Bestätigt', 'rewan-booking'); ?></span>
                                <?php endif; ?>
                            </span>
                            <div class="rb-bk-actions" role="cell">
                                <a class="rb-bk-btn rb-bk-btn--primary" href="<?php echo esc_url(admin_url('admin.php?page=rewan-booking-bookings&edit_booking=' . (int) $booking['id'])); ?>">
                                    <span class="dashicons dashicons-edit" aria-hidden="true"></span> <?php esc_html_e('Bearbeiten', 'rewan-booking'); ?>
                                </a>
                                <?php if ($del_url !== '') : ?>
                                <a class="rb-bk-btn rb-bk-btn--danger" href="<?php echo esc_url($del_url); ?>" onclick="return confirm('<?php echo esc_js(__('Buchung wirklich löschen?', 'rewan-booking')); ?>');">
                                    <span class="dashicons dashicons-trash" aria-hidden="true"></span> <?php esc_html_e('Löschen', 'rewan-booking'); ?>
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <div class="rb-bk-empty" role="status">
                        <div class="rb-bk-empty-state">
                            <span class="rb-bk-empty-state__icon dashicons dashicons-calendar-alt" aria-hidden="true"></span>
                            <strong class="rb-bk-empty-state__title"><?php esc_html_e('Keine Buchungen für diese Auswahl', 'rewan-booking'); ?></strong>
                            <span class="rb-bk-empty-state__text"><?php echo esc_html($empty_detail); ?></span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="rb-bk-cards rb-bookings-mobile">
                <?php if (!empty($bookings)) : ?>
                    <?php foreach ($bookings as $booking) : ?>
                        <?php
                        $service_text = $booking['services'];
                        $service_decoded = json_decode($booking['services'], true);
                        if (is_array($service_decoded)) {
                            $service_text = implode(', ', $service_decoded);
                        }
                        $is_today_card = ($booking['booking_date'] === $today);
                        $tel_href_m = $this->bookings_phone_tel_href(isset($booking['customer_phone']) ? $booking['customer_phone'] : '');
                        $del_url_m = '';
                        if (current_user_can(REWAN_BOOKING_CAP_MANAGE)) {
                            $del_url_m = wp_nonce_url(
                                admin_url('admin-post.php?action=rewan_booking_delete_booking&booking_id=' . (int) $booking['id']),
                                'rewan_booking_delete_booking_' . (int) $booking['id']
                            );
                        }
                        ?>
                        <article class="rb-bk-card<?php echo $is_today_card ? ' rb-bk-card--today' : ''; ?>">
                            <div class="rb-bk-card__top">
                                <div>
                                    <p class="rb-bk-customer-name"><?php echo esc_html($booking['customer_name']); ?></p>
                                    <?php if (!empty($booking['customer_phone'])) : ?>
                                        <p class="rb-bk-customer-tel">
                                            <?php if ($tel_href_m !== '') : ?>
                                                <a href="<?php echo esc_url($tel_href_m); ?>"><?php echo esc_html($booking['customer_phone']); ?></a>
                                            <?php else : ?>
                                                <?php echo esc_html($booking['customer_phone']); ?>
                                            <?php endif; ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                                <span class="rb-bk-id">#<?php echo esc_html((string) $booking['id']); ?></span>
                            </div>
                            <div class="rb-bk-date rb-bk-card__meta"><?php echo esc_html(date_i18n('l, d.m.Y', strtotime($booking['booking_date']))); ?></div>
                            <div class="rb-bk-time rb-bk-card__time"><?php echo esc_html(substr($booking['start_time'], 0, 5)); ?> <?php esc_html_e('Uhr', 'rewan-booking'); ?></div>
                            <div class="rb-bk-emp rb-bk-card__meta"><?php echo esc_html($booking['employee_name']); ?></div>
                            <div class="rb-bk-service"><?php echo esc_html($service_text); ?></div>
                            <div class="rb-bk-card__status">
                                <?php if ($booking['status'] === 'cancelled') : ?>
                                    <span class="rb-bk-badge rb-bk-badge--cancelled"><?php esc_html_e('Storniert', 'rewan-booking'); ?></span>
                                <?php else : ?>
                                    <span class="rb-bk-badge rb-bk-badge--confirmed"><?php esc_html_e('Bestätigt', 'rewan-booking'); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="rb-bk-card__actions">
                                <a class="rb-bk-btn rb-bk-btn--primary" href="<?php echo esc_url(admin_url('admin.php?page=rewan-booking-bookings&edit_booking=' . (int) $booking['id'])); ?>">
                                    <span class="dashicons dashicons-edit" aria-hidden="true"></span> <?php esc_html_e('Bearbeiten', 'rewan-booking'); ?>
                                </a>
                                <?php if ($del_url_m !== '') : ?>
                                <a class="rb-bk-btn rb-bk-btn--danger" href="<?php echo esc_url($del_url_m); ?>" onclick="return confirm('<?php echo esc_js(__('Buchung wirklich löschen?', 'rewan-booking')); ?>');">
                                    <span class="dashicons dashicons-trash" aria-hidden="true"></span> <?php esc_html_e('Löschen', 'rewan-booking'); ?>
                                </a>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php else : ?>
                    <div class="rb-bk-empty" role="status">
                        <div class="rb-bk-empty-state">
                            <span class="rb-bk-empty-state__icon dashicons dashicons-calendar-alt" aria-hidden="true"></span>
                            <strong class="rb-bk-empty-state__title"><?php esc_html_e('Keine Buchungen für diese Auswahl', 'rewan-booking'); ?></strong>
                            <span class="rb-bk-empty-state__text"><?php echo esc_html($empty_detail); ?></span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php if ($use_bookly_shell) : ?>
                </div>
            </div>
        </div>
        <?php else : ?>
        </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Kalender-Farblogik: urlaub | krank | block
     */
    private function get_calendar_absence_kind($absence_type) {
        $t = is_string($absence_type) ? $absence_type : '';
        if (in_array($t, array('vacation', 'holiday'), true)) {
            return 'urlaub';
        }
        if (in_array($t, array('sick', 'absence'), true)) {
            return 'krank';
        }
        return 'block';
    }

    /**
     * @param array<int,array<string,mixed>> $absences
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function expand_calendar_absences_by_date(array $absences, $range_start, $range_end) {
        $by_date = array();
        foreach ($absences as $a) {
            $from = max((string) $a['start_date'], $range_start);
            $to = min((string) $a['end_date'], $range_end);
            if ($from > $to) {
                continue;
            }
            if ((int) $a['is_all_day'] === 1) {
                $d = $from;
                while ($d <= $to) {
                    if (!isset($by_date[$d])) {
                        $by_date[$d] = array();
                    }
                    $by_date[$d][] = $a;
                    $d = date('Y-m-d', strtotime($d . ' +1 day'));
                }
            } else {
                $sd = (string) $a['start_date'];
                $ed = (string) $a['end_date'];
                $from = max($sd, $range_start);
                $to = min($ed, $range_end);
                if ($from > $to) {
                    continue;
                }
                $d = $from;
                while ($d <= $to) {
                    if (!isset($by_date[$d])) {
                        $by_date[$d] = array();
                    }
                    $by_date[$d][] = $a;
                    $d = date('Y-m-d', strtotime($d . ' +1 day'));
                }
            }
        }
        return $by_date;
    }

    /**
     * Tooltip-Text für Abwesenheit im Kalender.
     *
     * @param array<string,mixed> $a
     */
    /**
     * @param array<string,mixed> $a
     * @param string|null         $on_date Y-m-d der Kalenderzelle (für mehrtägige Teilzeit)
     */
    private function calendar_absence_tooltip(array $a, $on_date = null) {
        $parts = array();
        if (!empty($a['title'])) {
            $parts[] = $a['title'];
        }
        if (!empty($a['employee_name'])) {
            $parts[] = $a['employee_name'];
        }
        if ((int) $a['is_all_day'] === 1) {
            $parts[] = 'Ganztägig';
        } else {
            $st = isset($a['start_time']) ? substr((string) $a['start_time'], 0, 5) : '';
            $et = isset($a['end_time']) ? substr((string) $a['end_time'], 0, 5) : '';
            $sd = isset($a['start_date']) ? (string) $a['start_date'] : '';
            $ed = isset($a['end_date']) ? (string) $a['end_date'] : '';
            if ($st !== '' && $et !== '') {
                if (
                    is_string($on_date) && $on_date !== ''
                    && $sd !== '' && $ed !== '' && $sd !== $ed
                ) {
                    if ($on_date === $sd && $on_date !== $ed) {
                        $parts[] = sprintf(
                            /* translators: %s: time (HH:MM) */
                            __('Ab %s Uhr (erster Tag)', 'rewan-booking'),
                            $st
                        );
                    } elseif ($on_date === $ed && $on_date !== $sd) {
                        $parts[] = sprintf(
                            /* translators: %s: time (HH:MM) */
                            __('Bis %s Uhr (letzter Tag)', 'rewan-booking'),
                            $et
                        );
                    } elseif ($on_date > $sd && $on_date < $ed) {
                        $parts[] = __('Ganztägig (Zwischentag)', 'rewan-booking');
                    } else {
                        $parts[] = $st . '–' . $et . ' Uhr';
                    }
                } else {
                    $parts[] = $st . '–' . $et . ' Uhr';
                }
            }
        }
        return implode(' · ', $parts);
    }

    /**
     * @param array<string,mixed> $a
     * @param string|null         $on_date Y-m-d der Kalenderzelle
     */
    private function calendar_absence_short_label(array $a, $on_date = null) {
        if ((int) $a['is_all_day'] === 1) {
            return 'Abwesend';
        }
        $st = isset($a['start_time']) ? substr((string) $a['start_time'], 0, 5) : '';
        $et = isset($a['end_time']) ? substr((string) $a['end_time'], 0, 5) : '';
        if ($st === '' || $et === '') {
            return 'ABWESEND';
        }
        $sd = isset($a['start_date']) ? (string) $a['start_date'] : '';
        $ed = isset($a['end_date']) ? (string) $a['end_date'] : '';
        if (
            is_string($on_date) && $on_date !== ''
            && $sd !== '' && $ed !== '' && $sd !== $ed
        ) {
            if ($on_date === $sd && $on_date !== $ed) {
                return 'ABW · ab ' . $st;
            }
            if ($on_date === $ed && $on_date !== $sd) {
                return 'ABW · bis ' . $et;
            }
            if ($on_date > $sd && $on_date < $ed) {
                return 'ABW · Tag';
            }
        }
        return 'ABW · ' . $st . '–' . $et;
    }

    /**
     * Zeitspalte in der Kalender-Listenansicht (pro Kalendertag).
     *
     * @param array<string,mixed> $a
     * @param string              $day_ymd Y-m-d
     */
    private function calendar_absence_list_time_cell(array $a, $day_ymd) {
        if ((int) $a['is_all_day'] === 1) {
            return __('Ganztägig', 'rewan-booking');
        }
        $st = isset($a['start_time']) ? substr((string) $a['start_time'], 0, 5) : '';
        $et = isset($a['end_time']) ? substr((string) $a['end_time'], 0, 5) : '';
        $sd = isset($a['start_date']) ? (string) $a['start_date'] : '';
        $ed = isset($a['end_date']) ? (string) $a['end_date'] : '';
        if ($st === '' || $et === '') {
            return '—';
        }
        if ($sd !== '' && $ed !== '' && $sd !== $ed) {
            if ($day_ymd === $sd && $day_ymd !== $ed) {
                return sprintf(
                    /* translators: %s: time */
                    __('ab %s', 'rewan-booking'),
                    $st
                );
            }
            if ($day_ymd === $ed && $day_ymd !== $sd) {
                return sprintf(
                    /* translators: %s: time */
                    __('bis %s', 'rewan-booking'),
                    $et
                );
            }
            if ($day_ymd > $sd && $day_ymd < $ed) {
                return __('Ganztägig', 'rewan-booking');
            }
        }
        return $st . '–' . $et;
    }

    public function render_calendar_page() {
        if (!current_user_can(REWAN_BOOKING_CAP_SALON)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rewan-booking'));
        }
        $this->render_calendar_page_standalone();
    }

    /**
     * Eigenständige Monatsansicht, wenn Bookly nicht installiert ist.
     */
    private function render_calendar_page_standalone() {
        global $wpdb;

        $bookings_table = $wpdb->prefix . 'rewan_booking_bookings';
        $employees_table = $wpdb->prefix . 'rewan_booking_employees';
        $services_table = $wpdb->prefix . 'rewan_booking_services';

        $month = isset($_GET['month']) ? sanitize_text_field(wp_unslash((string) $_GET['month'])) : '';
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = current_time('Y-m');
        }
        $month_start = $month . '-01';
        $month_end = date('Y-m-t', strtotime($month_start));

        $employee_filter = isset($_GET['employee_id']) ? (int) $_GET['employee_id'] : 0;
        $service_filter = isset($_GET['service_id']) ? (int) $_GET['service_id'] : 0;

        $employees = $wpdb->get_results(
            "SELECT id, name FROM {$employees_table} WHERE is_active = 1 ORDER BY name ASC",
            ARRAY_A
        );
        $services = $wpdb->get_results(
            "SELECT id, name FROM {$services_table} WHERE is_active = 1 ORDER BY name ASC",
            ARRAY_A
        );

        $where = array('b.booking_date BETWEEN %s AND %s');
        $args = array($month_start, $month_end);
        if ($employee_filter > 0) {
            $where[] = 'b.employee_id = %d';
            $args[] = $employee_filter;
        }
        if ($service_filter > 0) {
            $where[] = '(b.services LIKE %s OR b.services = %s)';
            $needle = '"' . $service_filter . '"';
            $args[] = '%' . $wpdb->esc_like($needle) . '%';
            $args[] = (string) $service_filter;
        }
        $where_sql = implode(' AND ', $where);

        $bookings = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT b.id, b.booking_date, b.start_time, b.end_time, b.customer_name, b.customer_phone, b.customer_email, b.notes, b.employee_name, b.services, b.status,
                        e.name AS employee_name_join
                 FROM {$bookings_table} b
                 LEFT JOIN {$employees_table} e ON e.id = b.employee_id
                 WHERE {$where_sql}
                 ORDER BY b.booking_date ASC, b.start_time ASC",
                $args
            ),
            ARRAY_A
        );

        $bookings_by_day = array();
        foreach ((array) $bookings as $b) {
            $d = isset($b['booking_date']) ? (string) $b['booking_date'] : '';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                continue;
            }
            if (!isset($bookings_by_day[$d])) {
                $bookings_by_day[$d] = array();
            }
            $bookings_by_day[$d][] = $b;
        }

        $days_in_month = (int) date('t', strtotime($month_start));
        $first_weekday_n = (int) date('N', strtotime($month_start)); // 1=Mo ... 7=So
        $leading_blanks = max(0, $first_weekday_n - 1);
        $prev_month = date('Y-m', strtotime($month_start . ' -1 month'));
        $next_month = date('Y-m', strtotime($month_start . ' +1 month'));

        $base_args = array(
            'page' => 'rewan-booking-calendar',
            'employee_id' => $employee_filter > 0 ? $employee_filter : null,
            'service_id' => $service_filter > 0 ? $service_filter : null,
        );
        $base_args = array_filter(
            $base_args,
            static function ($v) {
                return $v !== null;
            }
        );
        ?>
        <div class="wrap rb-cal-lite">
            <h1><?php esc_html_e('Kalender', 'rewan-booking'); ?></h1>
            <p class="description"><?php esc_html_e('Monatsansicht der Termine. Farben unterscheiden die Mitarbeiter.', 'rewan-booking'); ?></p>

            <form method="get" class="rb-cal-lite__filters">
                <input type="hidden" name="page" value="rewan-booking-calendar">
                <input type="hidden" name="month" value="<?php echo esc_attr($month); ?>">
                <label>
                    <?php esc_html_e('Mitarbeiter', 'rewan-booking'); ?>
                    <select name="employee_id">
                        <option value="0"><?php esc_html_e('Alle', 'rewan-booking'); ?></option>
                        <?php foreach ($employees as $e) : ?>
                            <option value="<?php echo esc_attr((string) $e['id']); ?>" <?php selected($employee_filter, (int) $e['id']); ?>>
                                <?php echo esc_html((string) $e['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <?php esc_html_e('Dienstleistung', 'rewan-booking'); ?>
                    <select name="service_id">
                        <option value="0"><?php esc_html_e('Alle', 'rewan-booking'); ?></option>
                        <?php foreach ($services as $s) : ?>
                            <option value="<?php echo esc_attr((string) $s['id']); ?>" <?php selected($service_filter, (int) $s['id']); ?>>
                                <?php echo esc_html((string) $s['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="button button-primary"><?php esc_html_e('Anwenden', 'rewan-booking'); ?></button>
            </form>

            <div class="rb-cal-lite__head">
                <a class="button" href="<?php echo esc_url(add_query_arg(array_merge($base_args, array('month' => $prev_month)), admin_url('admin.php'))); ?>">&#8592;</a>
                <strong><?php echo esc_html(date_i18n('F Y', strtotime($month_start))); ?></strong>
                <a class="button" href="<?php echo esc_url(add_query_arg(array_merge($base_args, array('month' => $next_month)), admin_url('admin.php'))); ?>">&#8594;</a>
            </div>

            <div class="rb-cal-lite__grid">
                <?php
                $dow = array(__('Mo', 'rewan-booking'), __('Di', 'rewan-booking'), __('Mi', 'rewan-booking'), __('Do', 'rewan-booking'), __('Fr', 'rewan-booking'), __('Sa', 'rewan-booking'), __('So', 'rewan-booking'));
                foreach ($dow as $d) :
                    ?>
                    <div class="rb-cal-lite__dow"><?php echo esc_html($d); ?></div>
                <?php endforeach; ?>

                <?php for ($i = 0; $i < $leading_blanks; $i++) : ?>
                    <div class="rb-cal-lite__cell rb-cal-lite__cell--blank"></div>
                <?php endfor; ?>

                <?php for ($day = 1; $day <= $days_in_month; $day++) : ?>
                    <?php
                    $ymd = sprintf('%s-%02d', $month, $day);
                    $day_items = isset($bookings_by_day[$ymd]) ? $bookings_by_day[$ymd] : array();
                    ?>
                    <div class="rb-cal-lite__cell">
                        <div class="rb-cal-lite__date"><?php echo esc_html((string) $day); ?></div>
                        <?php if (!empty($day_items)) : ?>
                            <ul class="rb-cal-lite__items">
                                <?php
                                $max = 4;
                                $count = 0;
                                foreach ($day_items as $item) :
                                    if ($count >= $max) {
                                        break;
                                    }
                                    $count++;
                                    $emp = !empty($item['employee_name']) ? (string) $item['employee_name'] : (string) $item['employee_name_join'];
                                    $time = substr((string) $item['start_time'], 0, 5);
                                    $end_time = isset($item['end_time']) ? substr((string) $item['end_time'], 0, 5) : '';
                                    $service_text = (string) $item['services'];
                                    $service_decoded = json_decode((string) $item['services'], true);
                                    if (is_array($service_decoded) && !empty($service_decoded)) {
                                        $service_text = implode(', ', array_map('strval', $service_decoded));
                                    }
                                    $detail_payload = array(
                                        'id' => (int) $item['id'],
                                        'customer' => (string) $item['customer_name'],
                                        'phone' => isset($item['customer_phone']) ? (string) $item['customer_phone'] : '',
                                        'email' => isset($item['customer_email']) ? (string) $item['customer_email'] : '',
                                        'employee' => $emp,
                                        'date' => (string) $item['booking_date'],
                                        'start' => $time,
                                        'end' => $end_time,
                                        'service' => $service_text,
                                        'status' => isset($item['status']) ? (string) $item['status'] : '',
                                        'notes' => isset($item['notes']) ? (string) $item['notes'] : '',
                                        'editUrl' => admin_url('admin.php?page=rewan-booking-bookings&edit_booking=' . (int) $item['id']),
                                    );
                                    ?>
                                    <li>
                                        <?php $emp_tone = (int) (abs(crc32(strtolower(trim($emp)))) % 6); ?>
                                        <button type="button" class="rb-cal-lite__item-btn rb-cal-emp-<?php echo esc_attr((string) $emp_tone); ?>" data-booking="<?php echo esc_attr(wp_json_encode($detail_payload)); ?>" title="<?php echo esc_attr((string) $item['customer_name']); ?>">
                                            <span class="rb-cal-lite__time"><?php echo esc_html($time); ?></span>
                                            <span class="rb-cal-lite__emp"><?php echo esc_html($emp); ?></span>
                                        </button>
                                    </li>
                                <?php endforeach; ?>
                                <?php if (count($day_items) > $max) : ?>
                                    <li class="rb-cal-lite__more">+<?php echo esc_html((string) (count($day_items) - $max)); ?></li>
                                <?php endif; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>

            <div id="rb-cal-lite-modal" class="rb-cal-lite-modal" hidden>
                <div class="rb-cal-lite-modal__backdrop"></div>
                <div class="rb-cal-lite-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="rb-cal-lite-modal-title">
                    <div class="rb-cal-lite-modal__head">
                        <h2 id="rb-cal-lite-modal-title"><?php esc_html_e('Buchungsdetails', 'rewan-booking'); ?></h2>
                        <button type="button" class="rb-cal-lite-modal__close" aria-label="<?php esc_attr_e('Schließen', 'rewan-booking'); ?>">&times;</button>
                    </div>
                    <div class="rb-cal-lite-modal__body" id="rb-cal-lite-modal-body"></div>
                    <div class="rb-cal-lite-modal__foot">
                        <a href="#" class="button button-primary" id="rb-cal-lite-modal-edit"><?php esc_html_e('Buchung öffnen', 'rewan-booking'); ?></a>
                    </div>
                </div>
            </div>
        </div>
        <style>
            .rb-cal-lite { max-width: 1200px; }
            .rb-cal-lite__filters { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; margin:12px 0 14px; }
            .rb-cal-lite__filters label { display:flex; flex-direction:column; gap:6px; font-weight:600; }
            .rb-cal-lite__filters select { min-width:180px; }
            .rb-cal-lite__head { display:flex; align-items:center; justify-content:center; gap:12px; margin:10px 0 14px; }
            .rb-cal-lite__head strong { font-size:20px; min-width:220px; text-align:center; }
            .rb-cal-lite__grid { display:grid; grid-template-columns:repeat(7,minmax(0,1fr)); gap:8px; }
            .rb-cal-lite__dow { text-align:center; font-weight:700; color:#334155; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:8px 4px; }
            .rb-cal-lite__cell { min-height:110px; border:1px solid #e2e8f0; border-radius:10px; background:#fff; padding:8px; }
            .rb-cal-lite__cell--blank { background:#fafafa; border-style:dashed; }
            .rb-cal-lite__date { font-weight:700; color:#0f172a; margin-bottom:6px; }
            .rb-cal-lite__items { margin:0; padding:0; list-style:none; display:flex; flex-direction:column; gap:4px; }
            .rb-cal-lite__items li { margin:0; }
            .rb-cal-lite__item-btn {
                width:100%; text-align:left; cursor:pointer; border:1px solid #e2e8f0; border-radius:6px;
                padding:3px 5px; background:#f8fbff; display:flex; gap:6px; font-size:12px; line-height:1.35;
            }
            .rb-cal-lite__item-btn:hover { border-color:#cbd5e1; background:#f1f5f9; }
            .rb-cal-lite__time { font-weight:700; color:#0f172a; min-width:38px; }
            .rb-cal-lite__emp { color:#334155; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
            .rb-cal-lite__more { justify-content:center; color:#64748b; }
            .rb-cal-lite-modal[hidden] { display:none !important; }
            .rb-cal-lite-modal {
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                right: 0 !important;
                bottom: 0 !important;
                width: 100vw !important;
                height: 100vh !important;
                z-index: 100000;
                display: grid;
                place-items: center;
                padding: 20px;
                isolation: isolate;
                box-sizing: border-box;
            }
            .rb-cal-lite-modal__backdrop {
                position: fixed;
                inset: 0;
                background: rgba(15, 23, 42, 0.32);
                -webkit-backdrop-filter: blur(2px);
                backdrop-filter: blur(2px);
            }
            .rb-cal-lite-modal__dialog {
                position: relative;
                width: min(560px, 100%);
                max-height: calc(100vh - 40px);
                overflow: auto;
                margin: 0 !important;
                background: #fff;
                border-radius: 14px;
                border: 1px solid #dbe3ef;
                box-shadow: 0 24px 70px rgba(15, 23, 42, .28), 0 2px 10px rgba(15, 23, 42, .12);
            }
            .rb-cal-lite-modal__head {
                display:flex;
                align-items:center;
                justify-content:space-between;
                padding:14px 16px;
                border-bottom:1px solid #e2e8f0;
                background:#f8fafc;
            }
            .rb-cal-lite-modal__head h2 { margin:0; font-size:19px; }
            .rb-cal-lite-modal__close {
                border: 1px solid #d0d7e2;
                background: #fff;
                width: 34px;
                height: 34px;
                border-radius: 10px;
                font-size: 22px;
                line-height: 1;
                cursor: pointer;
                color: #475569;
            }
            .rb-cal-lite-modal__close:hover { background:#f1f5f9; border-color:#c3cedb; }
            .rb-cal-lite-modal__body { padding:14px 16px; display:grid; grid-template-columns:160px 1fr; gap:10px 12px; }
            .rb-cal-lite-modal__label { color:#64748b; font-weight:600; }
            .rb-cal-lite-modal__value { color:#0f172a; }
            .rb-cal-lite-modal__notes { grid-column:1 / -1; margin-top:6px; padding-top:8px; border-top:1px dashed #cbd5e1; }
            .rb-cal-lite-modal__foot { padding:12px 16px 14px; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; background:#fff; }
            @media (max-width: 980px) {
                .rb-cal-lite__grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
                .rb-cal-lite__dow { display:none; }
            }
            @media (max-width: 640px) {
                .rb-cal-lite__grid { grid-template-columns:1fr; }
                .rb-cal-lite-modal__body { grid-template-columns:1fr; }
            }
        </style>
        <script>
            (function () {
                var modal = document.getElementById('rb-cal-lite-modal');
                if (!modal) { return; }
                var body = document.getElementById('rb-cal-lite-modal-body');
                var editLink = document.getElementById('rb-cal-lite-modal-edit');
                var closeBtn = modal.querySelector('.rb-cal-lite-modal__close');
                var backdrop = modal.querySelector('.rb-cal-lite-modal__backdrop');

                function esc(text) {
                    var d = document.createElement('div');
                    d.textContent = String(text || '');
                    return d.innerHTML;
                }

                function fmtStatus(status) {
                    var s = String(status || '').toLowerCase();
                    if (s === 'confirmed') return 'Bestätigt';
                    if (s === 'cancelled') return 'Storniert';
                    return status || '-';
                }

                function openModal(data) {
                    if (!data) { return; }
                    var time = data.start ? data.start + (data.end ? ' - ' + data.end : '') : '-';
                    body.innerHTML =
                        '<div class="rb-cal-lite-modal__label">Kunde</div><div class="rb-cal-lite-modal__value">' + esc(data.customer) + '</div>' +
                        '<div class="rb-cal-lite-modal__label">Datum</div><div class="rb-cal-lite-modal__value">' + esc(data.date) + '</div>' +
                        '<div class="rb-cal-lite-modal__label">Zeit</div><div class="rb-cal-lite-modal__value">' + esc(time) + '</div>' +
                        '<div class="rb-cal-lite-modal__label">Mitarbeiter</div><div class="rb-cal-lite-modal__value">' + esc(data.employee) + '</div>' +
                        '<div class="rb-cal-lite-modal__label">Dienstleistung</div><div class="rb-cal-lite-modal__value">' + esc(data.service) + '</div>' +
                        '<div class="rb-cal-lite-modal__label">Status</div><div class="rb-cal-lite-modal__value">' + esc(fmtStatus(data.status)) + '</div>' +
                        '<div class="rb-cal-lite-modal__label">Telefon</div><div class="rb-cal-lite-modal__value">' + esc(data.phone || '-') + '</div>' +
                        '<div class="rb-cal-lite-modal__label">E-Mail</div><div class="rb-cal-lite-modal__value">' + esc(data.email || '-') + '</div>';
                    if (data.notes) {
                        body.innerHTML += '<div class="rb-cal-lite-modal__notes"><div class="rb-cal-lite-modal__label">Notiz</div><div class="rb-cal-lite-modal__value">' + esc(data.notes) + '</div></div>';
                    }
                    editLink.setAttribute('href', data.editUrl || '#');
                    modal.hidden = false;
                }

                function closeModal() {
                    modal.hidden = true;
                }

                document.addEventListener('click', function (e) {
                    var btn = e.target.closest('.rb-cal-lite__item-btn');
                    if (!btn) { return; }
                    e.preventDefault();
                    var raw = btn.getAttribute('data-booking');
                    if (!raw) { return; }
                    try {
                        openModal(JSON.parse(raw));
                    } catch (_err) {}
                });

                closeBtn.addEventListener('click', closeModal);
                backdrop.addEventListener('click', closeModal);
                document.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape' && !modal.hidden) {
                        closeModal();
                    }
                });
            })();
        </script>
        <?php
    }

    private function render_admin_notice($message) {
        if (empty($message)) {
            return;
        }

        $success_messages = array(
            'service_saved' => 'Dienstleistung gespeichert.',
            'service_deleted' => 'Dienstleistung gelöscht.',
            'employee_saved' => 'Mitarbeiter gespeichert.',
            'employee_deleted' => 'Mitarbeiter gelöscht.',
            'absence_saved' => 'Abwesenheit gespeichert.',
            'absence_updated' => 'Abwesenheit aktualisiert.',
            'absence_deleted' => 'Abwesenheit gelöscht.',
            'week_schedule_saved' => 'Wochen-Sperrzeiten gespeichert.',
            'opening_hours_saved' => 'Öffnungszeiten gespeichert.',
            'quick_day_off_saved' => 'Freier Tag wurde gesetzt.',
            'settings_saved' => 'Einstellungen gespeichert.',
            'smtp_test_sent' => 'Testmail ist raus. Schau in den Posteingang, nicht nur in den Spam.',
            'emails_saved' => 'E-Mail-Texte gespeichert.',
            'emails_test_sent' => 'Testmails wurden an die Benachrichtigungsadresse geschickt.',
        );

        $error_messages = array(
            'employee_has_bookings' => 'Mitarbeiter kann nicht gelöscht werden, weil bereits Buchungen vorhanden sind.',
            'service_not_found' => 'Dienstleistung nicht gefunden.',
            'employee_not_found' => 'Mitarbeiter nicht gefunden.',
            'absence_not_found' => 'Abwesenheit nicht gefunden.',
            'quick_day_off_exists' => 'Für diesen Zeitraum gibt es bereits eine Abwesenheit.',
            'invalid_notification_email' => 'Bitte eine gültige E-Mail-Adresse eingeben.',
            'smtp_missing' => 'Für Hostpoint brauchst du das Postfach und das Passwort.',
            'smtp_test_failed' => 'Die Einstellungen sind gespeichert. Die Testmail kam nicht an. Prüfe Postfach und Passwort.',
            'emails_test_failed' => 'Die Texte sind gespeichert. Die Testmail konnte nicht gesendet werden. Prüfe die Benachrichtigungsadresse unter Einstellungen.',
            'emails_missing_shop' => 'Bitte einen Shop-Namen eintragen.',
        );

        if (isset($success_messages[$message])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($success_messages[$message]) . '</p></div>';
        } elseif (isset($error_messages[$message])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($error_messages[$message]) . '</p></div>';
        }
    }

    public function render_emails_page() {
        if (!current_user_can(REWAN_BOOKING_CAP_MANAGE)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rewan-booking'));
        }

        $shop = Rewan_Booking_Mail::shop();
        $copy = Rewan_Booking_Mail::stored_copy();
        $message = isset($_GET['message']) ? sanitize_text_field(wp_unslash($_GET['message'])) : '';
        $preview = Rewan_Booking_Mail::compose('customer', Rewan_Booking_Mail::sample(), $copy);
        $notify = get_option('rewan_booking_notification_email', '');
        ?>
        <div class="wrap rb-mail-page">
            <h1><?php esc_html_e('E-Mails', 'rewan-booking'); ?></h1>
            <?php $this->render_admin_notice($message); ?>
            <p class="rb-mail-lead"><?php esc_html_e('So sieht die Mail mit Musterdaten aus. Texte und Shop-Angaben kannst du ändern, ohne eine Buchung auszulösen.', 'rewan-booking'); ?></p>

            <div class="rb-mail-layout">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="rb-mail-form">
                    <input type="hidden" name="action" value="rewan_booking_save_emails">
                    <?php wp_nonce_field('rewan_booking_save_emails_nonce', 'rewan_booking_save_emails_nonce'); ?>

                    <section class="rb-mail-card">
                        <h2><?php esc_html_e('Shop', 'rewan-booking'); ?></h2>
                        <p class="rb-mail-hint"><?php esc_html_e('Name, Adresse und Telefon stehen im Formular-Kopf und in jeder Mail.', 'rewan-booking'); ?></p>
                        <label for="rb-shop-name"><?php esc_html_e('Name', 'rewan-booking'); ?></label>
                        <input type="text" id="rb-shop-name" name="shop_name" value="<?php echo esc_attr($shop['name']); ?>" required>
                        <label for="rb-shop-address"><?php esc_html_e('Adresse', 'rewan-booking'); ?></label>
                        <textarea id="rb-shop-address" name="shop_address" rows="2"><?php echo esc_textarea($shop['address']); ?></textarea>
                        <label for="rb-shop-phone"><?php esc_html_e('Telefon', 'rewan-booking'); ?></label>
                        <input type="text" id="rb-shop-phone" name="shop_phone" value="<?php echo esc_attr($shop['phone']); ?>">
                        <label for="rb-shop-web"><?php esc_html_e('Web', 'rewan-booking'); ?></label>
                        <input type="text" id="rb-shop-web" name="shop_web" value="<?php echo esc_attr($shop['web']); ?>">
                    </section>

                    <?php
                    $blocks = array(
                        'customer' => __('Mail an den Kunden', 'rewan-booking'),
                        'staff' => __('Mail an den Mitarbeiter', 'rewan-booking'),
                        'owner' => __('Mail an den Salon', 'rewan-booking'),
                    );
                    foreach ($blocks as $key => $title) :
                        ?>
                        <section class="rb-mail-card">
                            <h2><?php echo esc_html($title); ?></h2>
                            <label for="rb-<?php echo esc_attr($key); ?>-subject"><?php esc_html_e('Betreff', 'rewan-booking'); ?></label>
                            <input type="text" id="rb-<?php echo esc_attr($key); ?>-subject" name="<?php echo esc_attr($key); ?>_subject" value="<?php echo esc_attr($copy[$key . '_subject']); ?>">
                            <label for="rb-<?php echo esc_attr($key); ?>-intro"><?php esc_html_e('Text', 'rewan-booking'); ?></label>
                            <textarea id="rb-<?php echo esc_attr($key); ?>-intro" name="<?php echo esc_attr($key); ?>_intro" rows="3"><?php echo esc_textarea($copy[$key . '_intro']); ?></textarea>
                            <label for="rb-<?php echo esc_attr($key); ?>-note"><?php esc_html_e('Hinweis unten', 'rewan-booking'); ?></label>
                            <textarea id="rb-<?php echo esc_attr($key); ?>-note" name="<?php echo esc_attr($key); ?>_note" rows="2"><?php echo esc_textarea($copy[$key . '_note']); ?></textarea>
                        </section>
                    <?php endforeach; ?>

                    <p class="rb-mail-hint"><?php esc_html_e('Platzhalter: {name} {service} {date} {time} {barber} {price} {phone} {notes} {shop} {address} {shop_phone} {web}', 'rewan-booking'); ?></p>
                    <p class="rb-mail-actions">
                        <button type="submit" class="button button-primary" name="save_emails" value="1"><?php esc_html_e('Texte speichern', 'rewan-booking'); ?></button>
                        <button type="submit" class="button" name="send_test" value="1"><?php esc_html_e('Testmails schicken', 'rewan-booking'); ?></button>
                    </p>
                    <p class="rb-mail-hint"><?php echo esc_html(sprintf(__('Die drei Testmails gehen an %s, nicht an einen Kunden.', 'rewan-booking'), $notify !== '' ? $notify : __('die Benachrichtigungsadresse im Dashboard', 'rewan-booking'))); ?></p>
                </form>

                <aside class="rb-mail-preview">
                    <div class="rb-mail-preview-bar">
                        <span><?php esc_html_e('Beispiel', 'rewan-booking'); ?></span>
                        <button type="button" class="rb-mail-tab is-active" data-mail-type="customer"><?php esc_html_e('Kunde', 'rewan-booking'); ?></button>
                        <button type="button" class="rb-mail-tab" data-mail-type="staff"><?php esc_html_e('Mitarbeiter', 'rewan-booking'); ?></button>
                        <button type="button" class="rb-mail-tab" data-mail-type="owner"><?php esc_html_e('Salon', 'rewan-booking'); ?></button>
                    </div>
                    <p class="rb-mail-subject" id="rb-mail-subject"><?php echo esc_html($preview['subject']); ?></p>
                    <iframe id="rb-mail-frame" title="<?php esc_attr_e('Beispielmail', 'rewan-booking'); ?>"></iframe>
                </aside>
            </div>
        </div>
        <script>
        (function () {
            var form = document.getElementById('rb-mail-form');
            var frame = document.getElementById('rb-mail-frame');
            var subjectEl = document.getElementById('rb-mail-subject');
            var type = 'customer';
            var timer = null;
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce = <?php echo wp_json_encode(wp_create_nonce('rewan_booking_email_preview')); ?>;
            var initialHtml = <?php echo wp_json_encode($preview['html']); ?>;

            function show(html, subject) {
                if (frame) {
                    frame.srcdoc = html;
                }
                if (subjectEl) {
                    subjectEl.textContent = subject || '';
                }
            }

            function refresh() {
                if (!form) return;
                var data = new URLSearchParams(new FormData(form));
                data.set('action', 'rewan_booking_email_preview');
                data.set('nonce', nonce);
                data.set('preview_type', type);
                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: data.toString()
                }).then(function (response) { return response.json(); }).then(function (payload) {
                    if (payload && payload.success && payload.data) {
                        show(payload.data.html, payload.data.subject);
                    }
                }).catch(function () {});
            }

            show(initialHtml, subjectEl ? subjectEl.textContent : '');

            document.querySelectorAll('.rb-mail-tab').forEach(function (button) {
                button.addEventListener('click', function () {
                    type = button.getAttribute('data-mail-type') || 'customer';
                    document.querySelectorAll('.rb-mail-tab').forEach(function (el) {
                        el.classList.toggle('is-active', el === button);
                    });
                    refresh();
                });
            });

            if (form) {
                form.addEventListener('input', function () {
                    window.clearTimeout(timer);
                    timer = window.setTimeout(refresh, 280);
                });
            }
        })();
        </script>
        <?php
    }

    public function ajax_email_preview() {
        if (!current_user_can(REWAN_BOOKING_CAP_MANAGE)) {
            wp_send_json_error(array('message' => 'Keine Berechtigung.'));
        }
        check_ajax_referer('rewan_booking_email_preview', 'nonce');

        $type = isset($_POST['preview_type']) ? sanitize_key(wp_unslash($_POST['preview_type'])) : 'customer';
        $shop = array(
            'name' => isset($_POST['shop_name']) ? sanitize_text_field(wp_unslash($_POST['shop_name'])) : '',
            'address' => isset($_POST['shop_address']) ? sanitize_textarea_field(wp_unslash($_POST['shop_address'])) : '',
            'phone' => isset($_POST['shop_phone']) ? sanitize_text_field(wp_unslash($_POST['shop_phone'])) : '',
            'web' => isset($_POST['shop_web']) ? sanitize_text_field(wp_unslash($_POST['shop_web'])) : '',
        );
        $overrides = array();
        foreach (array_keys(Rewan_Booking_Mail::default_copy()) as $key) {
            if (!isset($_POST[$key])) {
                continue;
            }
            $raw = wp_unslash($_POST[$key]);
            $overrides[$key] = (strpos($key, 'subject') !== false)
                ? sanitize_text_field($raw)
                : sanitize_textarea_field($raw);
        }
        $mail = Rewan_Booking_Mail::compose($type, Rewan_Booking_Mail::sample($shop), Rewan_Booking_Mail::copy_with_overrides($overrides));
        wp_send_json_success($mail);
    }

    public function handle_save_emails() {
        if (!current_user_can(REWAN_BOOKING_CAP_MANAGE)) {
            wp_die('Keine Berechtigung.');
        }
        if (
            !isset($_POST['rewan_booking_save_emails_nonce']) ||
            !wp_verify_nonce($_POST['rewan_booking_save_emails_nonce'], 'rewan_booking_save_emails_nonce')
        ) {
            wp_die('Sicherheitsprüfung fehlgeschlagen.');
        }

        $shop_name = isset($_POST['shop_name']) ? sanitize_text_field(wp_unslash($_POST['shop_name'])) : '';
        if ($shop_name === '') {
            wp_redirect(admin_url('admin.php?page=rewan-booking-emails&message=emails_missing_shop'));
            exit;
        }

        update_option('rewan_booking_shop', array(
            'name' => $shop_name,
            'address' => isset($_POST['shop_address']) ? sanitize_textarea_field(wp_unslash($_POST['shop_address'])) : '',
            'phone' => isset($_POST['shop_phone']) ? sanitize_text_field(wp_unslash($_POST['shop_phone'])) : '',
            'web' => isset($_POST['shop_web']) ? sanitize_text_field(wp_unslash($_POST['shop_web'])) : '',
        ), false);

        $copy = array();
        foreach (array_keys(Rewan_Booking_Mail::default_copy()) as $key) {
            $raw = isset($_POST[$key]) ? wp_unslash($_POST[$key]) : '';
            $copy[$key] = (strpos($key, 'subject') !== false)
                ? sanitize_text_field($raw)
                : sanitize_textarea_field($raw);
        }
        update_option('rewan_booking_email_copy', $copy, false);

        $redirect = 'emails_saved';
        if (isset($_POST['send_test'])) {
            $to = (string) get_option('rewan_booking_notification_email', '');
            $redirect = Rewan_Booking_Mail::send_samples($to) ? 'emails_test_sent' : 'emails_test_failed';
        }
        wp_redirect(admin_url('admin.php?page=rewan-booking-emails&message=' . $redirect));
        exit;
    }

    public function handle_add_service() {
        $this->handle_save_service();
    }

    public function render_settings_page() {
        if (!current_user_can(REWAN_BOOKING_CAP_MANAGE)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rewan-booking'));
        }

        $message = isset($_GET['message']) ? sanitize_text_field(wp_unslash($_GET['message'])) : '';
        $delivery = Rewan_Booking_Mail::delivery();
        $notify = (string) get_option('rewan_booking_notification_email', '');
        $has_password = Rewan_Booking_Mail::smtp_password() !== '';
        $has_token = (string) get_option('rewan_booking_github_token', '') !== '';
        $smtp_error = get_transient('rewan_booking_smtp_error');
        if (is_string($smtp_error) && $smtp_error !== '') {
            delete_transient('rewan_booking_smtp_error');
        } else {
            $smtp_error = '';
        }
        $shop_name = Rewan_Booking_Mail::shop()['name'];
        ?>
        <div class="wrap rb-set-page">
            <h1><?php esc_html_e('Einstellungen', 'rewan-booking'); ?></h1>
            <?php $this->render_admin_notice($message); ?>
            <?php if ($smtp_error !== '') : ?>
                <p class="rb-set-error"><?php echo esc_html($smtp_error); ?></p>
            <?php endif; ?>
            <p class="rb-set-lead"><?php esc_html_e('Benachrichtigung, Versand über Hostpoint und Updates. Die Mailtexte selbst stehen unter E-Mails.', 'rewan-booking'); ?></p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="rewan_booking_save_settings">
                <?php wp_nonce_field('rewan_booking_save_settings_nonce', 'rewan_booking_save_settings_nonce'); ?>

                <section class="rb-set-card">
                    <h2><?php esc_html_e('Buchungen', 'rewan-booking'); ?></h2>
                    <p class="rb-set-hint"><?php esc_html_e('Shortcode für die Seite', 'rewan-booking'); ?> <code>[rewan_booking_form]</code></p>
                    <label for="rewan-notify-email">
                        <span><?php esc_html_e('E-Mail für neue Buchungen', 'rewan-booking'); ?></span>
                        <input type="email" id="rewan-notify-email" name="notification_email" value="<?php echo esc_attr($notify); ?>" required>
                    </label>
                    <p class="rb-set-hint"><?php esc_html_e('Hierhin geht die Meldung an den Salon. Das kann dieselbe Adresse sein wie das Postfach unten.', 'rewan-booking'); ?></p>
                </section>

                <section class="rb-set-card">
                    <h2><?php esc_html_e('Versand über Hostpoint', 'rewan-booking'); ?></h2>
                    <p class="rb-set-hint"><?php esc_html_e('Die Mail meldet sich am Postfach an. Dann liegt sie eher im Posteingang als im Spam.', 'rewan-booking'); ?></p>
                    <label class="rb-emp-switch">
                        <input type="checkbox" name="smtp_enabled" value="1" <?php checked($delivery['smtp_enabled'], 1); ?>>
                        <span><?php esc_html_e('Über Hostpoint senden', 'rewan-booking'); ?><small><?php esc_html_e('Gilt für die Terminmails dieser Website.', 'rewan-booking'); ?></small></span>
                    </label>
                    <dl class="rb-set-facts">
                        <div><dt><?php esc_html_e('Server', 'rewan-booking'); ?></dt><dd>asmtp.mail.hostpoint.ch</dd></div>
                        <div><dt><?php esc_html_e('Port', 'rewan-booking'); ?></dt><dd>587</dd></div>
                        <div><dt><?php esc_html_e('Verschlüsselung', 'rewan-booking'); ?></dt><dd>STARTTLS</dd></div>
                    </dl>
                    <div class="rb-add-fields is-pair">
                        <label for="rewan-from-name">
                            <span><?php esc_html_e('Absender-Name', 'rewan-booking'); ?></span>
                            <input type="text" id="rewan-from-name" name="from_name" value="<?php echo esc_attr($delivery['from_name']); ?>" placeholder="<?php echo esc_attr($shop_name); ?>">
                        </label>
                        <label for="rewan-from-email">
                            <span><?php esc_html_e('Postfach', 'rewan-booking'); ?></span>
                            <input type="email" id="rewan-from-email" name="from_email" value="<?php echo esc_attr($delivery['from_email']); ?>" placeholder="info@deinedomain.ch" autocomplete="off">
                        </label>
                        <label class="is-full" for="rewan-smtp-password">
                            <span><?php esc_html_e('Passwort des Postfachs', 'rewan-booking'); ?></span>
                            <input type="password" id="rewan-smtp-password" name="smtp_password" value="" autocomplete="new-password" placeholder="<?php echo esc_attr($has_password ? __('Passwort ist gespeichert', 'rewan-booking') : ''); ?>">
                        </label>
                    </div>
                    <p class="rb-set-hint"><?php esc_html_e('Absender und Anmeldung sind dieselbe Adresse. Ein leeres Passwortfeld lässt das gespeicherte Passwort stehen.', 'rewan-booking'); ?></p>
                    <details class="rb-set-more">
                        <summary><?php esc_html_e('Server ändern', 'rewan-booking'); ?></summary>
                        <label for="rewan-smtp-host">
                            <span><?php esc_html_e('SMTP-Server', 'rewan-booking'); ?></span>
                            <input type="text" id="rewan-smtp-host" name="smtp_host" value="<?php echo esc_attr($delivery['smtp_host']); ?>">
                        </label>
                    </details>
                    <ol class="rb-set-steps">
                        <li><?php esc_html_e('Im Hostpoint Control Panel ein Postfach anlegen.', 'rewan-booking'); ?></li>
                        <li><?php esc_html_e('Adresse und Passwort hier eintragen und den Haken setzen.', 'rewan-booking'); ?></li>
                        <li><?php esc_html_e('Testmail senden und im Posteingang nachsehen.', 'rewan-booking'); ?></li>
                        <li><?php esc_html_e('Bei der Domain DKIM einschalten. SPF setzt Hostpoint für eigene Domains meist selbst.', 'rewan-booking'); ?></li>
                    </ol>
                    <p class="rb-set-hint"><a href="<?php echo esc_url(admin_url('admin.php?page=rewan-booking-emails')); ?>"><?php esc_html_e('Texte der Bestätigung bearbeiten', 'rewan-booking'); ?></a></p>
                </section>

                <section class="rb-set-card">
                    <h2><?php esc_html_e('Updates', 'rewan-booking'); ?></h2>
                    <label for="rewan-github-token">
                        <span><?php esc_html_e('GitHub-Token', 'rewan-booking'); ?></span>
                        <input type="password" id="rewan-github-token" name="github_token" value="" autocomplete="new-password" placeholder="<?php echo esc_attr($has_token ? __('Token ist gespeichert', 'rewan-booking') : ''); ?>">
                    </label>
                    <p class="rb-set-hint"><?php esc_html_e('Leer lassen, um den Token zu behalten. Nur nötig, wenn das Repo privat ist. Buchungen bleiben beim Update erhalten.', 'rewan-booking'); ?></p>
                </section>

                <div class="rb-set-actions">
                    <?php submit_button(__('Speichern', 'rewan-booking'), 'primary', 'submit', false); ?>
                    <button type="submit" class="button" name="send_test" value="1"><?php esc_html_e('Speichern und Testmail', 'rewan-booking'); ?></button>
                </div>
            </form>
        </div>
        <?php
    }

    public function handle_save_settings() {
        if (!current_user_can(REWAN_BOOKING_CAP_MANAGE)) {
            wp_die('Keine Berechtigung.');
        }

        if (
            !isset($_POST['rewan_booking_save_settings_nonce']) ||
            !wp_verify_nonce($_POST['rewan_booking_save_settings_nonce'], 'rewan_booking_save_settings_nonce')
        ) {
            wp_die('Sicherheitsprüfung fehlgeschlagen.');
        }

        $notification_email = isset($_POST['notification_email']) ? sanitize_email(wp_unslash($_POST['notification_email'])) : '';

        if (empty($notification_email) || !is_email($notification_email)) {
            wp_redirect(admin_url('admin.php?page=rewan-booking-settings&message=invalid_notification_email'));
            exit;
        }

        $from_email = isset($_POST['from_email']) ? sanitize_email(wp_unslash($_POST['from_email'])) : '';
        $from_name = isset($_POST['from_name']) ? sanitize_text_field(wp_unslash($_POST['from_name'])) : '';
        $smtp_enabled = isset($_POST['smtp_enabled']) ? 1 : 0;
        $smtp_host = isset($_POST['smtp_host']) ? strtolower(sanitize_text_field(wp_unslash($_POST['smtp_host']))) : '';
        if (!preg_match('/^[a-z0-9.-]+$/', $smtp_host)) {
            $smtp_host = 'asmtp.mail.hostpoint.ch';
        }
        $smtp_password = isset($_POST['smtp_password']) ? (string) wp_unslash($_POST['smtp_password']) : '';
        $smtp_password = str_replace(array("\r", "\n", "\0"), '', $smtp_password);
        if ($smtp_password !== '') {
            update_option('rewan_booking_smtp_password', $smtp_password, false);
        }
        $stored_password = (string) get_option('rewan_booking_smtp_password', '');
        $smtp_ready = is_email($from_email) && $stored_password !== '';
        $smtp_blocked = $smtp_enabled && !$smtp_ready;
        if ($smtp_blocked) {
            $smtp_enabled = 0;
        }

        update_option('rewan_booking_notification_email', $notification_email);
        update_option('rewan_booking_mail_delivery', array(
            'from_name' => $from_name,
            'from_email' => is_email($from_email) ? $from_email : '',
            'smtp_enabled' => $smtp_enabled,
            'smtp_host' => $smtp_host,
        ), false);

        if (isset($_POST['github_token'])) {
            $github_token = trim((string) wp_unslash($_POST['github_token']));
            if ($github_token !== '') {
                update_option('rewan_booking_github_token', sanitize_text_field($github_token), false);
                delete_transient('rewan_booking_remote_version');
            }
        }

        $redirect = 'settings_saved';
        if ($smtp_blocked) {
            $redirect = 'smtp_missing';
        } elseif (isset($_POST['send_test'])) {
            $redirect = Rewan_Booking_Mail::send_delivery_test($notification_email) ? 'smtp_test_sent' : 'smtp_test_failed';
        }
        wp_redirect(admin_url('admin.php?page=rewan-booking-settings&message=' . $redirect));
        exit;
    }

    public function handle_save_service() {
        if (!current_user_can(REWAN_BOOKING_CAP_MANAGE)) {
            wp_die('Keine Berechtigung.');
        }
        if (!isset($_POST['rewan_booking_save_service_nonce']) || !wp_verify_nonce($_POST['rewan_booking_save_service_nonce'], 'rewan_booking_save_service_nonce')) { wp_die('Sicherheitsprüfung fehlgeschlagen.'); }

        global $wpdb;
        $table_name = $wpdb->prefix . 'rewan_booking_services';

        $service_id = isset($_POST['service_id']) ? (int) $_POST['service_id'] : 0;
        $name = isset($_POST['service_name']) ? sanitize_text_field(wp_unslash($_POST['service_name'])) : '';
        $price = isset($_POST['service_price']) ? (float) wp_unslash($_POST['service_price']) : 0;
        $duration = isset($_POST['service_duration']) ? (int) wp_unslash($_POST['service_duration']) : 0;
        $is_active = isset($_POST['service_is_active']) ? 1 : 0;
        
        // NEU: Beschreibung und Bild
        $description = isset($_POST['service_description']) ? sanitize_textarea_field(wp_unslash($_POST['service_description'])) : '';
        $image_url = isset($_POST['service_image_url']) ? esc_url_raw(wp_unslash($_POST['service_image_url'])) : '';

        if (empty($name) || $price < 0 || $duration < 1) { wp_die('Bitte gültige Werte eingeben.'); }

        $data = array(
            'name' => $name, 'price' => $price, 'duration' => $duration, 'is_active' => $is_active,
            'description' => $description, 'image_url' => $image_url
        );
        $format = array('%s', '%f', '%d', '%d', '%s', '%s');

        if ($service_id > 0) {
            $wpdb->update($table_name, $data, array('id' => $service_id), $format, array('%d'));
        } else {
            $wpdb->insert($table_name, $data, $format);
        }
        wp_redirect(admin_url('admin.php?page=rewan-booking-services&message=service_saved'));
        exit;
    }

    public function handle_delete_service() {
        if (!current_user_can(REWAN_BOOKING_CAP_MANAGE)) {
            wp_die('Keine Berechtigung.');
        }

        $service_id = isset($_GET['service_id']) ? (int) $_GET['service_id'] : 0;
        if ($service_id <= 0) {
            wp_redirect(admin_url('admin.php?page=rewan-booking-services&message=service_not_found'));
            exit;
        }

        if (!wp_verify_nonce(isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '', 'rewan_booking_delete_service_' . $service_id)) {
            wp_die('Sicherheitsprüfung fehlgeschlagen.');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'rewan_booking_services';

        $wpdb->delete($table_name, array('id' => $service_id), array('%d'));

        wp_redirect(admin_url('admin.php?page=rewan-booking-services&message=service_deleted'));
        exit;
    }

    public function handle_save_employee() {
        if (!current_user_can(REWAN_BOOKING_CAP_MANAGE)) {
            wp_die('Keine Berechtigung.');
        }

        if (
            !isset($_POST['rewan_booking_save_employee_nonce']) ||
            !wp_verify_nonce($_POST['rewan_booking_save_employee_nonce'], 'rewan_booking_save_employee_nonce')
        ) {
            wp_die('Sicherheitsprüfung fehlgeschlagen.');
        }

        global $wpdb;

        $employees_table = $wpdb->prefix . 'rewan_booking_employees';
        $hours_table = $wpdb->prefix . 'rewan_booking_employee_hours';
        $breaks_table = $wpdb->prefix . 'rewan_booking_employee_breaks';

        $employee_id = isset($_POST['employee_id']) ? (int) $_POST['employee_id'] : 0;
        $name = isset($_POST['employee_name']) ? sanitize_text_field(wp_unslash($_POST['employee_name'])) : '';
        $email = isset($_POST['employee_email']) ? sanitize_email(wp_unslash($_POST['employee_email'])) : '';
        $image_url = isset($_POST['employee_image_url']) ? esc_url_raw(wp_unslash($_POST['employee_image_url'])) : '';
        $is_active = isset($_POST['employee_is_active']) ? 1 : 0;
        $follows_opening = isset($_POST['follows_opening']) ? 1 : 0;
        $hours_input = isset($_POST['hours']) ? (array) $_POST['hours'] : array();
        $breaks_input = isset($_POST['breaks']) ? (array) $_POST['breaks'] : array();

        if (empty($name) || empty($email)) {
            wp_die('Bitte Name und E-Mail korrekt eingeben.');
        }

        if ($employee_id > 0) {
            $employee_data = array(
                'name' => $name,
                'email' => $email,
                'image_url' => $image_url,
                'is_active' => $is_active,
            );
            $employee_format = array('%s', '%s', '%s', '%d');
            if (class_exists('Rewan_Booking_Schedule') && Rewan_Booking_Schedule::employees_have_follows_column()) {
                $employee_data['follows_opening'] = $follows_opening;
                $employee_format[] = '%d';
            }
            $wpdb->update(
                $employees_table,
                $employee_data,
                array('id' => $employee_id),
                $employee_format,
                array('%d')
            );
        } else {
            $employee_data = array(
                'name' => $name,
                'email' => $email,
                'image_url' => $image_url,
                'is_active' => $is_active,
            );
            $employee_format = array('%s', '%s', '%s', '%d');
            if (class_exists('Rewan_Booking_Schedule') && Rewan_Booking_Schedule::employees_have_follows_column()) {
                $employee_data['follows_opening'] = $follows_opening;
                $employee_format[] = '%d';
            }
            $wpdb->insert(
                $employees_table,
                $employee_data,
                $employee_format
            );

            $employee_id = (int) $wpdb->insert_id;
        }

        if ($employee_id <= 0) {
            wp_die('Mitarbeiter konnte nicht gespeichert werden.');
        }

        $has_hours_input = !empty($hours_input);
        $has_breaks_input = !empty($breaks_input);

        for ($weekday = 1; $weekday <= 7; $weekday++) {
            $default_is_working = ($weekday <= 6) ? 1 : 0;
            $is_working = $default_is_working;
            $start_time = '09:00';
            $end_time = '18:00';

            if ($has_hours_input) {
                $row = isset($hours_input[$weekday]) ? (array) $hours_input[$weekday] : array();
                $is_working = isset($row['is_working']) ? 1 : 0;
                $start_time = isset($row['start_time']) ? sanitize_text_field(wp_unslash($row['start_time'])) : '09:00';
                $end_time = isset($row['end_time']) ? sanitize_text_field(wp_unslash($row['end_time'])) : '18:00';

                if (!preg_match('/^\d{2}:\d{2}$/', $start_time)) {
                    $start_time = '09:00';
                }
                if (!preg_match('/^\d{2}:\d{2}$/', $end_time)) {
                    $end_time = '18:00';
                }
                if ($end_time <= $start_time) {
                    $is_working = 0;
                    $start_time = '09:00';
                    $end_time = '18:00';
                }
            } elseif ($employee_id > 0) {
                $existing_hours = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT is_working, start_time, end_time FROM $hours_table WHERE employee_id = %d AND weekday = %d",
                        $employee_id,
                        $weekday
                    ),
                    ARRAY_A
                );
                if ($existing_hours) {
                    $is_working = (int) $existing_hours['is_working'];
                    $start_time = substr($existing_hours['start_time'], 0, 5);
                    $end_time = substr($existing_hours['end_time'], 0, 5);
                }
            }

            $exists = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM $hours_table WHERE employee_id = %d AND weekday = %d",
                    $employee_id,
                    $weekday
                )
            );

            if ($exists > 0) {
                $wpdb->update(
                    $hours_table,
                    array(
                        'is_working' => $is_working,
                        'start_time' => $start_time . ':00',
                        'end_time' => $end_time . ':00',
                    ),
                    array(
                        'employee_id' => $employee_id,
                        'weekday' => $weekday,
                    ),
                    array('%d', '%s', '%s'),
                    array('%d', '%d')
                );
            } else {
                $wpdb->insert(
                    $hours_table,
                    array(
                        'employee_id' => $employee_id,
                        'weekday' => $weekday,
                        'is_working' => $is_working,
                        'start_time' => $start_time . ':00',
                        'end_time' => $end_time . ':00',
                    ),
                    array('%d', '%d', '%d', '%s', '%s')
                );
            }

            $is_enabled = 0;
            $break_start = '12:00';
            $break_end = '13:00';

            if ($has_breaks_input) {
                $break_row = isset($breaks_input[$weekday]) ? (array) $breaks_input[$weekday] : array();
                $is_enabled = isset($break_row['is_enabled']) ? 1 : 0;
                $break_start = isset($break_row['break_start']) ? sanitize_text_field(wp_unslash($break_row['break_start'])) : '12:00';
                $break_end = isset($break_row['break_end']) ? sanitize_text_field(wp_unslash($break_row['break_end'])) : '13:00';

                if (!preg_match('/^\d{2}:\d{2}$/', $break_start)) {
                    $break_start = '12:00';
                }
                if (!preg_match('/^\d{2}:\d{2}$/', $break_end)) {
                    $break_end = '13:00';
                }
                if ($break_end <= $break_start) {
                    $is_enabled = 0;
                    $break_start = '12:00';
                    $break_end = '13:00';
                }
            } elseif ($employee_id > 0) {
                $existing_breaks = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT is_enabled, break_start, break_end FROM $breaks_table WHERE employee_id = %d AND weekday = %d",
                        $employee_id,
                        $weekday
                    ),
                    ARRAY_A
                );
                if ($existing_breaks) {
                    $is_enabled = (int) $existing_breaks['is_enabled'];
                    $break_start = substr($existing_breaks['break_start'], 0, 5);
                    $break_end = substr($existing_breaks['break_end'], 0, 5);
                }
            }

            $break_exists = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM $breaks_table WHERE employee_id = %d AND weekday = %d",
                    $employee_id,
                    $weekday
                )
            );

            if ($break_exists > 0) {
                $wpdb->update(
                    $breaks_table,
                    array(
                        'is_enabled' => $is_enabled,
                        'break_start' => $break_start . ':00',
                        'break_end' => $break_end . ':00',
                    ),
                    array(
                        'employee_id' => $employee_id,
                        'weekday' => $weekday,
                    ),
                    array('%d', '%s', '%s'),
                    array('%d', '%d')
                );
            } else {
                $wpdb->insert(
                    $breaks_table,
                    array(
                        'employee_id' => $employee_id,
                        'weekday' => $weekday,
                        'is_enabled' => $is_enabled,
                        'break_start' => $break_start . ':00',
                        'break_end' => $break_end . ':00',
                    ),
                    array('%d', '%d', '%d', '%s', '%s')
                );
            }
        }

        wp_redirect(admin_url('admin.php?page=rewan-booking-employees&edit_employee=' . $employee_id . '&message=employee_saved'));
        exit;
    }

    public function handle_delete_employee() {
        if (!current_user_can(REWAN_BOOKING_CAP_MANAGE)) {
            wp_die('Keine Berechtigung.');
        }

        $employee_id = isset($_GET['employee_id']) ? (int) $_GET['employee_id'] : 0;
        if ($employee_id <= 0) {
            wp_redirect(admin_url('admin.php?page=rewan-booking-employees&message=employee_not_found'));
            exit;
        }

        if (!wp_verify_nonce(isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '', 'rewan_booking_delete_employee_' . $employee_id)) {
            wp_die('Sicherheitsprüfung fehlgeschlagen.');
        }

        global $wpdb;

        $employees_table = $wpdb->prefix . 'rewan_booking_employees';
        $hours_table = $wpdb->prefix . 'rewan_booking_employee_hours';
        $breaks_table = $wpdb->prefix . 'rewan_booking_employee_breaks';
        $absences_table = $wpdb->prefix . 'rewan_booking_employee_absences';
        $bookings_table = $wpdb->prefix . 'rewan_booking_bookings';

        $booking_count = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM $bookings_table WHERE employee_id = %d", $employee_id)
        );

        if ($booking_count > 0) {
            wp_redirect(admin_url('admin.php?page=rewan-booking-employees&message=employee_has_bookings'));
            exit;
        }

        $wpdb->delete($hours_table, array('employee_id' => $employee_id), array('%d'));
        $wpdb->delete($breaks_table, array('employee_id' => $employee_id), array('%d'));
        $wpdb->delete($absences_table, array('employee_id' => $employee_id), array('%d'));
        $wpdb->delete($employees_table, array('id' => $employee_id), array('%d'));

        wp_redirect(admin_url('admin.php?page=rewan-booking-employees&message=employee_deleted'));
        exit;
    }

    /**
     * Validiert POST für Abwesenheit (neu oder bearbeiten). Bei Fehler wp_die.
     *
     * @return array<string,mixed> Spalten für rewan_booking_employee_absences
     */
    private function build_absence_row_from_post() {
        $employee_id = isset($_POST['absence_employee_id']) ? (int) $_POST['absence_employee_id'] : 0;
        $title = isset($_POST['absence_title']) ? sanitize_text_field(wp_unslash($_POST['absence_title'])) : '';
        $raw_type = isset($_POST['absence_type']) ? sanitize_text_field(wp_unslash($_POST['absence_type'])) : 'absence';
        $allowed_types = array('absence', 'sick', 'vacation', 'holiday', 'blocked');
        $absence_type = in_array($raw_type, $allowed_types, true) ? $raw_type : 'absence';
        $start_date = isset($_POST['absence_start_date']) ? sanitize_text_field(wp_unslash($_POST['absence_start_date'])) : '';
        $end_date = isset($_POST['absence_end_date']) ? sanitize_text_field(wp_unslash($_POST['absence_end_date'])) : '';
        $is_all_day = isset($_POST['absence_is_all_day']) ? 1 : 0;
        $hour_now = (int) current_time('G');
        $fallback_start = sprintf('%02d:00', $hour_now);
        $fallback_end_h = min($hour_now + 1, 23);
        $fallback_end = sprintf('%02d:00', $fallback_end_h);
        if ($fallback_end_h <= $hour_now) {
            $fallback_start = '22:00';
            $fallback_end = '23:00';
        }

        $start_time = isset($_POST['absence_start_time']) ? sanitize_text_field(wp_unslash($_POST['absence_start_time'])) : $fallback_start;
        $end_time = isset($_POST['absence_end_time']) ? sanitize_text_field(wp_unslash($_POST['absence_end_time'])) : $fallback_end;

        if ($employee_id <= 0 || $start_date === '' || $end_date === '') {
            wp_die(esc_html__('Bitte gültige Werte eingeben.', 'rewan-booking'));
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
            wp_die(esc_html__('Datum ungültig.', 'rewan-booking'));
        }

        if ($end_date < $start_date) {
            wp_die(esc_html__('Enddatum darf nicht vor dem Startdatum liegen.', 'rewan-booking'));
        }

        if (!preg_match('/^\d{2}:\d{2}$/', $start_time)) {
            $start_time = $fallback_start;
        }

        if (!preg_match('/^\d{2}:\d{2}$/', $end_time)) {
            $end_time = $fallback_end;
        }

        if ($is_all_day) {
            $start_time_db = '00:00:00';
            $end_time_db = '23:59:59';
        } else {
            $start_time_db = $start_time . ':00';
            $end_time_db = $end_time . ':00';
        }

        $tz = wp_timezone();
        $start_obj = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $start_date . ' ' . $start_time_db, $tz);
        $end_obj = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $end_date . ' ' . $end_time_db, $tz);
        if (!$start_obj instanceof DateTimeImmutable || !$end_obj instanceof DateTimeImmutable) {
            wp_die(esc_html__('Ungültiges Datum oder Uhrzeit.', 'rewan-booking'));
        }
        if ($end_obj <= $start_obj) {
            wp_die(esc_html__('Ende darf nicht vor dem Beginn liegen (Datum und Uhrzeit).', 'rewan-booking'));
        }

        return array(
            'employee_id' => $employee_id,
            'title' => $title,
            'absence_type' => $absence_type,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'is_all_day' => $is_all_day,
            'start_time' => $start_time_db,
            'end_time' => $end_time_db,
        );
    }

    public function handle_add_absence() {
        if (!current_user_can(REWAN_BOOKING_CAP_SALON)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rewan-booking'));
        }

        if (
            !isset($_POST['rewan_booking_add_absence_nonce']) ||
            !wp_verify_nonce(wp_unslash($_POST['rewan_booking_add_absence_nonce']), 'rewan_booking_add_absence_nonce')
        ) {
            wp_die(esc_html__('Sicherheitsprüfung fehlgeschlagen.', 'rewan-booking'));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'rewan_booking_employee_absences';
        $row = $this->build_absence_row_from_post();

        $wpdb->insert(
            $table_name,
            $row,
            array('%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );

        wp_redirect(admin_url('admin.php?page=rewan-booking-absences&message=absence_saved'));
        exit;
    }

    public function handle_save_absence() {
        if (!current_user_can(REWAN_BOOKING_CAP_SALON)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rewan-booking'));
        }

        if (
            !isset($_POST['rewan_booking_save_absence_nonce']) ||
            !wp_verify_nonce(wp_unslash($_POST['rewan_booking_save_absence_nonce']), 'rewan_booking_save_absence_nonce')
        ) {
            wp_die(esc_html__('Sicherheitsprüfung fehlgeschlagen.', 'rewan-booking'));
        }

        $absence_id = isset($_POST['absence_id']) ? (int) $_POST['absence_id'] : 0;
        if ($absence_id <= 0) {
            wp_die(esc_html__('Ungültige Abwesenheit.', 'rewan-booking'));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'rewan_booking_employee_absences';

        $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE id = %d", $absence_id));
        if ($exists === 0) {
            wp_safe_redirect(admin_url('admin.php?page=rewan-booking-absences&message=absence_not_found'));
            exit;
        }

        $row = $this->build_absence_row_from_post();

        $wpdb->update(
            $table_name,
            $row,
            array('id' => $absence_id),
            array('%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s'),
            array('%d')
        );

        wp_safe_redirect(admin_url('admin.php?page=rewan-booking-absences&message=absence_updated'));
        exit;
    }

    public function handle_quick_day_off() {
        if (!current_user_can(REWAN_BOOKING_CAP_SALON)) {
            wp_die('Keine Berechtigung.');
        }

        if (
            !isset($_POST['rewan_booking_quick_day_off_nonce']) ||
            !wp_verify_nonce(wp_unslash($_POST['rewan_booking_quick_day_off_nonce']), 'rewan_booking_quick_day_off_nonce')
        ) {
            wp_die('Sicherheitsprüfung fehlgeschlagen.');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'rewan_booking_employee_absences';

        $employee_id = isset($_POST['employee_id']) ? (int) $_POST['employee_id'] : 0;
        $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : '';
        $end_date = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : '';
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : 'Frei';
        $redirect_salon = isset($_POST['rewan_booking_redirect']) && sanitize_text_field(wp_unslash($_POST['rewan_booking_redirect'])) === 'salon';

        if ($employee_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
            wp_die('Ungültige Eingaben.');
        }

        if (!$this->salon_user_can_quick_off_for_employee($employee_id)) {
            wp_die('Keine Berechtigung.');
        }

        if ($end_date < $start_date) {
            $end_date = $start_date;
        }

        $exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name
                 WHERE employee_id = %d
                 AND NOT (end_date < %s OR start_date > %s)",
                $employee_id,
                $start_date,
                $end_date
            )
        );

        $redirect_base = $redirect_salon ? $this->admin_url_salon_today() : admin_url('admin.php?page=rewan-booking-absences');

        if ($exists > 0) {
            wp_safe_redirect(add_query_arg('message', 'quick_day_off_exists', $redirect_base));
            exit;
        }

        $wpdb->insert(
            $table_name,
            array(
                'employee_id' => $employee_id,
                'title' => $title,
                'absence_type' => 'blocked',
                'start_date' => $start_date,
                'end_date' => $end_date,
                'is_all_day' => 1,
                'start_time' => '00:00:00',
                'end_time' => '23:59:59',
            ),
            array('%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );

        wp_safe_redirect(add_query_arg('message', 'quick_day_off_saved', $redirect_base));
        exit;
    }

    public function handle_delete_absence() {
        if (!current_user_can(REWAN_BOOKING_CAP_SALON)) {
            wp_die('Keine Berechtigung.');
        }

        $raw_ids = isset($_GET['absence_ids']) ? sanitize_text_field(wp_unslash($_GET['absence_ids'])) : '';
        $ids = array();
        if ($raw_ids !== '') {
            foreach (explode(',', $raw_ids) as $part) {
                $id = (int) $part;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
            $ids = array_values(array_unique($ids));
            sort($ids);
        } elseif (isset($_GET['absence_id'])) {
            $one = (int) $_GET['absence_id'];
            if ($one > 0) {
                $ids = array($one);
            }
        }
        if (empty($ids)) {
            wp_redirect(admin_url('admin.php?page=rewan-booking-absences&message=absence_not_found'));
            exit;
        }

        $id_key = implode(',', $ids);
        $nonce_action = count($ids) > 1 || $raw_ids !== ''
            ? 'rewan_booking_delete_absence_group_' . $id_key
            : 'rewan_booking_delete_absence_' . $ids[0];
        if (!wp_verify_nonce(isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '', $nonce_action)) {
            wp_die('Sicherheitsprüfung fehlgeschlagen.');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'rewan_booking_employee_absences';
        foreach ($ids as $absence_id) {
            $wpdb->delete($table_name, array('id' => $absence_id), array('%d'));
        }

        $back = admin_url('admin.php?page=rewan-booking-absences&message=absence_deleted');
        $employee_back = isset($_GET['employee_id']) ? (int) $_GET['employee_id'] : 0;
        if ($employee_back > 0) {
            $back = add_query_arg('employee_id', $employee_back, $back);
        }
        wp_redirect($back);
        exit;
    }

    /**
     * Wochentagsnamen (ISO 1 = Montag … 7 = Sonntag).
     *
     * @return array<int,string>
     */
    private function get_global_weekday_labels() {
        return array(
            1 => __('Montag', 'rewan-booking'),
            2 => __('Dienstag', 'rewan-booking'),
            3 => __('Mittwoch', 'rewan-booking'),
            4 => __('Donnerstag', 'rewan-booking'),
            5 => __('Freitag', 'rewan-booking'),
            6 => __('Samstag', 'rewan-booking'),
            7 => __('Sonntag', 'rewan-booking'),
        );
    }

    /**
     * Uhrzeit immer 00–23, unabhängig von der Browsersprache.
     */
    private function render_time_24($name, $value) {
        if (!preg_match('/^(\d{2}):(\d{2})/', (string) $value, $match)) {
            $match = array(1 => '09', 2 => '00');
        }
        $hour = $match[1];
        $minute = $match[2];
        $minutes = array();
        for ($i = 0; $i < 60; $i += 5) {
            $minutes[] = sprintf('%02d', $i);
        }
        if (!in_array($minute, $minutes, true)) {
            $minutes[] = $minute;
            sort($minutes);
        }
        echo '<span class="rb-time24">';
        echo '<select class="rb-time24-h" aria-label="' . esc_attr__('Stunde', 'rewan-booking') . '">';
        for ($i = 0; $i < 24; $i++) {
            $hh = sprintf('%02d', $i);
            echo '<option value="' . esc_attr($hh) . '"' . selected($hour, $hh, false) . '>' . esc_html($hh) . '</option>';
        }
        echo '</select><span class="rb-time24-sep" aria-hidden="true">:</span>';
        echo '<select class="rb-time24-m" aria-label="' . esc_attr__('Minute', 'rewan-booking') . '">';
        foreach ($minutes as $mm) {
            echo '<option value="' . esc_attr($mm) . '"' . selected($minute, $mm, false) . '>' . esc_html($mm) . '</option>';
        }
        echo '</select>';
        echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($hour . ':' . $minute) . '">';
        echo '</span>';
        $this->render_time_24_script();
    }

    private function render_time_24_script() {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        ?>
        <script>
        (function () {
            function sync(wrap) {
                var hour = wrap.querySelector('.rb-time24-h');
                var minute = wrap.querySelector('.rb-time24-m');
                var hidden = wrap.querySelector('input[type="hidden"]');
                if (hour && minute && hidden) {
                    hidden.value = hour.value + ':' + minute.value;
                }
            }
            document.addEventListener('change', function (event) {
                var wrap = event.target.closest ? event.target.closest('.rb-time24') : null;
                if (wrap) {
                    sync(wrap);
                }
            });
            document.addEventListener('submit', function () {
                document.querySelectorAll('.rb-time24').forEach(sync);
            }, true);
        })();
        </script>
        <?php
    }

    public function render_opening_hours_page() {
        if (!current_user_can(REWAN_BOOKING_CAP_MANAGE)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rewan-booking'));
        }
        global $wpdb;
        $table = $wpdb->prefix . 'rewan_booking_opening_hours';
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY weekday ASC", ARRAY_A);
        if (!is_array($rows)) {
            $rows = array();
        }
        $by_weekday = array();
        foreach ($rows as $row) {
            $by_weekday[(int) $row['weekday']] = $row;
        }
        $labels = $this->get_global_weekday_labels();
        $message = isset($_GET['message']) ? sanitize_text_field(wp_unslash($_GET['message'])) : '';
        ?>
        <div class="wrap rb-oh-page">
            <h1><?php esc_html_e('Öffnungszeiten', 'rewan-booking'); ?></h1>
            <?php $this->render_admin_notice($message); ?>
            <div class="rb-oh-callout" role="note">
                <strong><?php esc_html_e('Rahmen für Termine', 'rewan-booking'); ?></strong>
                <?php esc_html_e('Hier legst du fest, wann der Laden buchbar ist. Slots entstehen aus der Arbeitszeit der Mitarbeiter, aber nur innerhalb dieser Öffnung. Wer „in den Öffnungszeiten“ arbeitet, übernimmt genau dieses Fenster.', 'rewan-booking'); ?>
            </div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="rewan_booking_save_opening_hours">
                <?php wp_nonce_field('rewan_booking_save_opening_hours_nonce', 'rewan_booking_save_opening_hours_nonce'); ?>
                <div class="rb-oh-panel">
                    <?php for ($w = 1; $w <= 7; $w++) : ?>
                        <?php
                        $row = isset($by_weekday[$w]) ? $by_weekday[$w] : array();
                        $is_open = !empty($row) ? (int) $row['is_open'] === 1 : ($w < 7);
                        $st = isset($row['start_time']) ? substr((string) $row['start_time'], 0, 5) : '09:00';
                        $et = isset($row['end_time']) ? substr((string) $row['end_time'], 0, 5) : '18:00';
                        ?>
                        <div class="rb-oh-row" data-open="<?php echo $is_open ? '1' : '0'; ?>">
                            <div class="rb-oh-day"><?php echo esc_html($labels[$w]); ?></div>
                            <label class="rb-oh-toggle">
                                <input type="checkbox" name="open[<?php echo esc_attr((string) $w); ?>]" value="1" <?php checked($is_open); ?>>
                                <?php esc_html_e('Geöffnet', 'rewan-booking'); ?>
                            </label>
                            <label>
                                <?php esc_html_e('Von', 'rewan-booking'); ?>
                                <?php $this->render_time_24('start[' . $w . ']', $st); ?>
                            </label>
                            <label>
                                <?php esc_html_e('Bis', 'rewan-booking'); ?>
                                <?php $this->render_time_24('end[' . $w . ']', $et); ?>
                            </label>
                        </div>
                    <?php endfor; ?>
                </div>
                <p class="submit"><?php submit_button(__('Öffnungszeiten speichern', 'rewan-booking'), 'primary', 'submit', false); ?></p>
            </form>
        </div>
        <script>
        (function () {
            document.querySelectorAll('.rb-oh-row').forEach(function (row) {
                var box = row.querySelector('input[type="checkbox"]');
                function sync() {
                    var on = box && box.checked;
                    row.classList.toggle('is-closed', !on);
                }
                if (box) box.addEventListener('change', sync);
                sync();
            });
        })();
        </script>
        <?php
    }

    public function handle_save_opening_hours() {
        if (!current_user_can(REWAN_BOOKING_CAP_MANAGE)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rewan-booking'));
        }
        if (
            !isset($_POST['rewan_booking_save_opening_hours_nonce']) ||
            !wp_verify_nonce(wp_unslash($_POST['rewan_booking_save_opening_hours_nonce']), 'rewan_booking_save_opening_hours_nonce')
        ) {
            wp_die(esc_html__('Sicherheitsprüfung fehlgeschlagen.', 'rewan-booking'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'rewan_booking_opening_hours';
        $opens = isset($_POST['open']) && is_array($_POST['open']) ? wp_unslash($_POST['open']) : array();
        $starts = isset($_POST['start']) && is_array($_POST['start']) ? wp_unslash($_POST['start']) : array();
        $ends = isset($_POST['end']) && is_array($_POST['end']) ? wp_unslash($_POST['end']) : array();

        for ($w = 1; $w <= 7; $w++) {
            $wk = (string) $w;
            $is_open = isset($opens[$w]) || isset($opens[$wk]) ? 1 : 0;
            $st = isset($starts[$w]) ? sanitize_text_field((string) $starts[$w]) : (isset($starts[$wk]) ? sanitize_text_field((string) $starts[$wk]) : '09:00');
            $et = isset($ends[$w]) ? sanitize_text_field((string) $ends[$w]) : (isset($ends[$wk]) ? sanitize_text_field((string) $ends[$wk]) : '18:00');
            if (!preg_match('/^\d{2}:\d{2}$/', $st)) {
                $st = '09:00';
            }
            if (!preg_match('/^\d{2}:\d{2}$/', $et)) {
                $et = '18:00';
            }
            if ($is_open && ($et . ':00') <= ($st . ':00')) {
                wp_die(esc_html__('Bei einem geöffneten Tag muss die Endzeit nach der Startzeit liegen.', 'rewan-booking'));
            }
            $wpdb->replace(
                $table,
                array(
                    'weekday' => $w,
                    'is_open' => $is_open,
                    'start_time' => $st . ':00',
                    'end_time' => $et . ':00',
                ),
                array('%d', '%d', '%s', '%s')
            );
        }

        update_option('rewan_booking_opening_seeded', '1', false);
        wp_safe_redirect(admin_url('admin.php?page=rewan-booking-hours&message=opening_hours_saved'));
        exit;
    }

    public function render_global_blocks_page() {
        if (!current_user_can(REWAN_BOOKING_CAP_MANAGE)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rewan-booking'));
        }
        global $wpdb;

        $table = $wpdb->prefix . 'rewan_booking_global_week_schedule';
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY weekday ASC", ARRAY_A);
        if (!is_array($rows)) {
            $rows = array();
        }

        $by_weekday = array();
        foreach ($rows as $r) {
            $by_weekday[(int) $r['weekday']] = $r;
        }
        $defaults = array('block_mode' => 'none', 'start_time' => '12:00:00', 'end_time' => '13:00:00');
        for ($w = 1; $w <= 7; $w++) {
            if (!isset($by_weekday[$w])) {
                $by_weekday[$w] = array_merge(array('weekday' => $w), $defaults);
            }
        }

        $labels = $this->get_global_weekday_labels();
        $message = isset($_GET['message']) ? sanitize_text_field(wp_unslash($_GET['message'])) : '';
        ?>
        <div class="wrap rb-oh-page">
            <h1><?php esc_html_e('Sperrzeit', 'rewan-booking'); ?></h1>
            <?php $this->render_admin_notice($message); ?>

            <div class="rb-oh-callout" role="note">
                <strong><?php esc_html_e('Lücke in der Öffnung', 'rewan-booking'); ?></strong>
                <?php esc_html_e('Nur Pausen innerhalb der Öffnungszeiten, zum Beispiel Mittag. Geschlossene Tage stellst du bei den Öffnungszeiten ein. Die Sperre gilt für alle Mitarbeiter.', 'rewan-booking'); ?>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="rb_week_schedule_form">
                <input type="hidden" name="action" value="rewan_booking_save_week_schedule">
                <?php wp_nonce_field('rewan_booking_save_week_schedule_nonce', 'rewan_booking_save_week_schedule_nonce'); ?>

                <div class="rb-oh-panel">
                    <?php for ($w = 1; $w <= 7; $w++) : ?>
                        <?php
                        $row = $by_weekday[$w];
                        $mode = isset($row['block_mode']) ? (string) $row['block_mode'] : 'none';
                        if (!in_array($mode, array('none', 'all_day', 'interval'), true)) {
                            $mode = 'none';
                        }
                        $st = isset($row['start_time']) ? substr((string) $row['start_time'], 0, 5) : '12:00';
                        $et = isset($row['end_time']) ? substr((string) $row['end_time'], 0, 5) : '13:00';
                        ?>
                        <div class="rb-oh-row rb-gb-row">
                            <div class="rb-oh-day"><?php echo esc_html($labels[$w]); ?></div>
                            <label>
                                <?php esc_html_e('Sperre', 'rewan-booking'); ?>
                                <select class="rb-gb-mode" name="week_mode[<?php echo esc_attr((string) $w); ?>]" id="rb_gb_mode_<?php echo esc_attr((string) $w); ?>">
                                    <option value="none" <?php selected($mode, 'none'); ?>><?php esc_html_e('Keine Sperre', 'rewan-booking'); ?></option>
                                    <option value="interval" <?php selected($mode, 'interval'); ?>><?php esc_html_e('Zeitspanne', 'rewan-booking'); ?></option>
                                    <option value="all_day" <?php selected($mode, 'all_day'); ?>><?php esc_html_e('Ganzer Tag', 'rewan-booking'); ?></option>
                                </select>
                            </label>
                            <label>
                                <?php esc_html_e('Von', 'rewan-booking'); ?>
                                <?php $this->render_time_24('week_start[' . $w . ']', $st); ?>
                            </label>
                            <label>
                                <?php esc_html_e('Bis', 'rewan-booking'); ?>
                                <?php $this->render_time_24('week_end[' . $w . ']', $et); ?>
                            </label>
                        </div>
                    <?php endfor; ?>
                </div>

                <p class="submit"><?php submit_button(__('Sperrzeit speichern', 'rewan-booking'), 'primary', 'submit', false); ?></p>
            </form>
        </div>
        <script>
        (function () {
            document.querySelectorAll('.rb-gb-row').forEach(function (row) {
                var sel = row.querySelector('.rb-gb-mode');
                function sync() {
                    if (!sel) return;
                    row.classList.toggle('is-closed', sel.value !== 'interval');
                }
                if (sel) sel.addEventListener('change', sync);
                sync();
            });
        })();
        </script>
        <?php
    }

    public function handle_save_week_schedule() {
        if (!current_user_can(REWAN_BOOKING_CAP_MANAGE)) {
            wp_die(esc_html__('Keine Berechtigung.', 'rewan-booking'));
        }
        if (
            !isset($_POST['rewan_booking_save_week_schedule_nonce']) ||
            !wp_verify_nonce(wp_unslash($_POST['rewan_booking_save_week_schedule_nonce']), 'rewan_booking_save_week_schedule_nonce')
        ) {
            wp_die(esc_html__('Sicherheitsprüfung fehlgeschlagen.', 'rewan-booking'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'rewan_booking_global_week_schedule';
        $modes = isset($_POST['week_mode']) && is_array($_POST['week_mode']) ? wp_unslash($_POST['week_mode']) : array();
        $starts = isset($_POST['week_start']) && is_array($_POST['week_start']) ? wp_unslash($_POST['week_start']) : array();
        $ends = isset($_POST['week_end']) && is_array($_POST['week_end']) ? wp_unslash($_POST['week_end']) : array();

        for ($w = 1; $w <= 7; $w++) {
            $wk = (string) $w;
            $raw_mode = isset($modes[ $w ]) ? sanitize_text_field((string) $modes[ $w ]) : (isset($modes[ $wk ]) ? sanitize_text_field((string) $modes[ $wk ]) : 'none');
            if (!in_array($raw_mode, array('none', 'all_day', 'interval'), true)) {
                $raw_mode = 'none';
            }

            $st_db = '12:00:00';
            $et_db = '13:00:00';

            if ($raw_mode === 'interval') {
                $st = isset($starts[ $w ]) ? sanitize_text_field((string) $starts[ $w ]) : (isset($starts[ $wk ]) ? sanitize_text_field((string) $starts[ $wk ]) : '');
                $et = isset($ends[ $w ]) ? sanitize_text_field((string) $ends[ $w ]) : (isset($ends[ $wk ]) ? sanitize_text_field((string) $ends[ $wk ]) : '');
                if (!preg_match('/^\d{2}:\d{2}$/', $st) || !preg_match('/^\d{2}:\d{2}$/', $et)) {
                    wp_die(esc_html__('Bitte gültige Von-/Bis-Uhrzeiten für alle Tage mit Zeitspanne eingeben.', 'rewan-booking'));
                }
                $st_db = $st . ':00';
                $et_db = $et . ':00';
                if ($et_db <= $st_db) {
                    wp_die(esc_html__('Bei Zeitspanne muss die Endzeit nach der Startzeit liegen.', 'rewan-booking'));
                }
            }

            $wpdb->replace(
                $table,
                array(
                    'weekday' => $w,
                    'block_mode' => $raw_mode,
                    'start_time' => $st_db,
                    'end_time' => $et_db,
                ),
                array('%d', '%s', '%s', '%s')
            );
        }

        wp_safe_redirect(admin_url('admin.php?page=rewan-booking-blocks&message=week_schedule_saved'));
        exit;
    }

    public function handle_save_booking() {
        if (!current_user_can(REWAN_BOOKING_CAP_SALON)) {
            wp_die('Keine Berechtigung.');
        }
        if (
            !isset($_POST['rewan_booking_save_booking_nonce']) ||
            !wp_verify_nonce(wp_unslash($_POST['rewan_booking_save_booking_nonce']), 'rewan_booking_save_booking_nonce')
        ) {
            wp_die('Sicherheit fehlgeschlagen.');
        }

        global $wpdb;
        $bookings_table = $wpdb->prefix . 'rewan_booking_bookings';
        $booking_id = isset($_POST['booking_id']) ? (int) $_POST['booking_id'] : 0;
        $status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : 'confirmed';

        if (!in_array($status, array('confirmed', 'cancelled'), true)) {
            wp_die('Ungültiger Status.');
        }

        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$bookings_table} WHERE id = %d", $booking_id), ARRAY_A);
        if (!$existing) {
            wp_die('Buchung nicht gefunden.');
        }

        $linked = $this->get_user_linked_employee_id();
        if ($this->user_is_salon_only() && $linked > 0 && (int) $existing['employee_id'] !== $linked) {
            wp_die('Keine Berechtigung.');
        }

        if (current_user_can(REWAN_BOOKING_CAP_MANAGE)) {
            $booking_date = isset($_POST['booking_date']) ? sanitize_text_field(wp_unslash($_POST['booking_date'])) : '';
            $start_raw = isset($_POST['start_time']) ? sanitize_text_field(wp_unslash($_POST['start_time'])) : '';
            $employee_id = isset($_POST['employee_id']) ? (int) $_POST['employee_id'] : 0;

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $booking_date) || !preg_match('/^\d{2}:\d{2}$/', $start_raw) || $employee_id <= 0) {
                wp_die('Ungültige Eingaben.');
            }

            $start_time = $start_raw . ':00';
            $employee_name = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->prefix}rewan_booking_employees WHERE id = %d", $employee_id));

            $wpdb->update(
                $bookings_table,
                array(
                    'status' => $status,
                    'booking_date' => $booking_date,
                    'start_time' => $start_time,
                    'employee_id' => $employee_id,
                    'employee_name' => $employee_name,
                ),
                array('id' => $booking_id),
                array('%s', '%s', '%s', '%d', '%s'),
                array('%d')
            );
        } else {
            $wpdb->update(
                $bookings_table,
                array('status' => $status),
                array('id' => $booking_id),
                array('%s'),
                array('%d')
            );
        }

        wp_safe_redirect(admin_url('admin.php?page=rewan-booking-bookings'));
        exit;
    }

    public function handle_delete_booking() {
        if (!current_user_can(REWAN_BOOKING_CAP_MANAGE)) {
            wp_die('Keine Berechtigung.');
        }
        $booking_id = isset($_GET['booking_id']) ? (int) $_GET['booking_id'] : 0;
        if (!wp_verify_nonce(isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '', 'rewan_booking_delete_booking_' . $booking_id)) { wp_die('Sicherheit fehlgeschlagen.'); }

        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'rewan_booking_bookings', array('id' => $booking_id), array('%d'));

        wp_redirect(admin_url('admin.php?page=rewan-booking-bookings'));
        exit;
    }





}