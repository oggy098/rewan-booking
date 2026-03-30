<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rewan_Booking_Admin {

    public function init() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_post_rewan_booking_save_settings', array($this, 'handle_save_settings'));

        add_action('admin_post_rewan_booking_add_service', array($this, 'handle_add_service'));
        add_action('admin_post_rewan_booking_save_service', array($this, 'handle_save_service'));
        add_action('admin_post_rewan_booking_delete_service', array($this, 'handle_delete_service'));

        add_action('admin_post_rewan_booking_save_employee', array($this, 'handle_save_employee'));
        add_action('admin_post_rewan_booking_delete_employee', array($this, 'handle_delete_employee'));

        add_action('admin_post_rewan_booking_add_absence', array($this, 'handle_add_absence'));
        add_action('admin_post_rewan_booking_quick_day_off', array($this, 'handle_quick_day_off'));
        add_action('admin_post_rewan_booking_delete_absence', array($this, 'handle_delete_absence'));
        add_action('admin_post_rewan_booking_save_booking', array($this, 'handle_save_booking'));
        add_action('admin_post_rewan_booking_delete_booking', array($this, 'handle_delete_booking'));
    }

    public function enqueue_styles() {
        wp_enqueue_style(
            'rewan-booking-frontend-css',
            REWAN_BOOKING_URL . 'assets/css/rewan-booking-frontend.css',
            array(),
            REWAN_BOOKING_VERSION
        );
    }

    public function add_admin_menu() {
        add_menu_page(
            'Rewan Booking',
            'Rewan Booking',
            'manage_options',
            'rewan-booking',
            array($this, 'render_dashboard_page'),
            'dashicons-calendar-alt',
            26
        );

        add_submenu_page(
            'rewan-booking',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'rewan-booking',
            array($this, 'render_dashboard_page')
        );

        add_submenu_page(
            'rewan-booking',
            'Kalender',
            'Kalender',
            'manage_options',
            'rewan-booking-calendar',
            array($this, 'render_calendar_page')
        );

        add_submenu_page(
            'rewan-booking',
            'Dienstleistungen',
            'Dienstleistungen',
            'manage_options',
            'rewan-booking-services',
            array($this, 'render_services_page')
        );

        add_submenu_page(
            'rewan-booking',
            'Mitarbeiter',
            'Mitarbeiter',
            'manage_options',
            'rewan-booking-employees',
            array($this, 'render_employees_page')
        );

        add_submenu_page(
            'rewan-booking',
            'Abwesenheiten',
            'Abwesenheiten',
            'manage_options',
            'rewan-booking-absences',
            array($this, 'render_absences_page')
        );

        add_submenu_page(
            'rewan-booking',
            'Buchungen',
            'Buchungen',
            'manage_options',
            'rewan-booking-bookings',
            array($this, 'render_bookings_page')
        );
    }

    public function render_dashboard_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'rewan_booking_bookings';
        $message = isset($_GET['message']) ? sanitize_text_field(wp_unslash($_GET['message'])) : '';
        $notification_email = get_option('rewan_booking_notification_email', 'info@barbershop-rewan.ch');

        $today = current_time('Y-m-d');
        $now_time = current_time('H:i:s');
        $start_week = date('Y-m-d', strtotime('monday this week', strtotime($today)));
        $start_month = date('Y-m-01', strtotime($today));

        $bookings_today = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM $table WHERE booking_date = %s AND status = 'confirmed'", $today)
        );
        $bookings_week = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM $table WHERE booking_date >= %s AND status = 'confirmed'", $start_week)
        );
        $bookings_month = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM $table WHERE booking_date >= %s AND status = 'confirmed'", $start_month)
        );
        $cancelled_month = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM $table WHERE booking_date >= %s AND status = 'cancelled'", $start_month)
        );

        $revenue_week = (float) $wpdb->get_var(
            $wpdb->prepare("SELECT COALESCE(SUM(total_price), 0) FROM $table WHERE booking_date >= %s AND status = 'confirmed'", $start_week)
        );
        $revenue_month = (float) $wpdb->get_var(
            $wpdb->prepare("SELECT COALESCE(SUM(total_price), 0) FROM $table WHERE booking_date >= %s AND status = 'confirmed'", $start_month)
        );

        $next_bookings = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, customer_name, employee_name, booking_date, start_time
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

        $today_per_employee = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT employee_name, COUNT(*) AS total
                 FROM $table
                 WHERE booking_date = %s AND status = %s
                 GROUP BY employee_name
                 ORDER BY total DESC, employee_name ASC",
                $today,
                'confirmed'
            ),
            ARRAY_A
        );
        ?>
        <div class="wrap">
            <h1>Rewan Booking Dashboard</h1>
            <?php $this->render_admin_notice($message); ?>
            <style>
                .rb-admin-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:16px; margin-top:16px; }
                .rb-admin-card { background:#fff; border:1px solid #e8e8e8; border-radius:14px; padding:16px; box-shadow:0 2px 6px rgba(0,0,0,0.04); }
                .rb-admin-title { margin:0; font-size:13px; color:#666; text-transform:uppercase; letter-spacing:.05em; }
                .rb-admin-value { margin:8px 0 0; font-size:28px; font-weight:700; line-height:1.1; }
                .rb-admin-links { display:flex; flex-wrap:wrap; gap:10px; margin-top:10px; }
                .rb-admin-table { width:100%; border-collapse:collapse; margin-top:8px; }
                .rb-admin-table td { padding:9px 4px; border-bottom:1px solid #f1f1f1; }
                .rb-admin-badge { display:inline-block; padding:2px 8px; border-radius:999px; background:#f4f4f4; font-size:12px; }
                @media (max-width: 960px) {
                    .rb-admin-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
                }
                @media (max-width: 680px) {
                    .rb-admin-grid { grid-template-columns:1fr; }
                    .rb-admin-value { font-size:24px; }
                }
            </style>

            <div class="rb-admin-grid">
                <div class="rb-admin-card"><p class="rb-admin-title">Termine heute</p><p class="rb-admin-value"><?php echo (int) $bookings_today; ?></p></div>
                <div class="rb-admin-card"><p class="rb-admin-title">Termine diese Woche</p><p class="rb-admin-value"><?php echo (int) $bookings_week; ?></p></div>
                <div class="rb-admin-card"><p class="rb-admin-title">Termine diesen Monat</p><p class="rb-admin-value"><?php echo (int) $bookings_month; ?></p></div>
                <div class="rb-admin-card"><p class="rb-admin-title">Umsatz diese Woche</p><p class="rb-admin-value" style="color:#1f8f4e;"><?php echo esc_html(number_format($revenue_week, 2, '.', '\'')); ?> CHF</p></div>
                <div class="rb-admin-card"><p class="rb-admin-title">Umsatz diesen Monat</p><p class="rb-admin-value" style="color:#1f8f4e;"><?php echo esc_html(number_format($revenue_month, 2, '.', '\'')); ?> CHF</p></div>
                <div class="rb-admin-card"><p class="rb-admin-title">Storniert (Monat)</p><p class="rb-admin-value" style="color:#b32d2e;"><?php echo (int) $cancelled_month; ?></p></div>
            </div>

            <div class="rb-admin-grid" style="margin-top:18px;">
                <div class="rb-admin-card">
                    <h2 style="margin:0 0 8px;">Schnellzugriff</h2>
                    <div class="rb-admin-links">
                        <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=rewan-booking-calendar')); ?>">Kalender öffnen</a>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=rewan-booking-bookings')); ?>">Buchungen</a>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=rewan-booking-absences')); ?>">Abwesenheiten</a>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=rewan-booking-employees')); ?>">Mitarbeiter</a>
                    </div>
                    <p style="margin-top:14px;"><strong>Shortcode:</strong> <code>[rewan_booking_form]</code></p>
                </div>

                <div class="rb-admin-card">
                    <h2 style="margin:0 0 8px;">Nächste Termine</h2>
                    <?php if (!empty($next_bookings)) : ?>
                        <table class="rb-admin-table">
                            <?php foreach ($next_bookings as $booking) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html(date_i18n('d.m.Y', strtotime($booking['booking_date']))); ?></strong> · <?php echo esc_html(substr($booking['start_time'], 0, 5)); ?></td>
                                    <td><?php echo esc_html($booking['customer_name']); ?><br><span class="rb-admin-badge"><?php echo esc_html($booking['employee_name']); ?></span></td>
                                    <td style="text-align:right;"><a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=rewan-booking-bookings&edit_booking=' . (int) $booking['id'])); ?>">Öffnen</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    <?php else : ?>
                        <p>Keine kommenden Termine.</p>
                    <?php endif; ?>
                </div>

                <div class="rb-admin-card">
                    <h2 style="margin:0 0 8px;">Heute pro Mitarbeiter</h2>
                    <?php if (!empty($today_per_employee)) : ?>
                        <table class="rb-admin-table">
                            <?php foreach ($today_per_employee as $row) : ?>
                                <tr>
                                    <td><?php echo esc_html($row['employee_name']); ?></td>
                                    <td style="text-align:right;"><strong><?php echo (int) $row['total']; ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    <?php else : ?>
                        <p>Heute noch keine Termine.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="rb-admin-grid" style="margin-top:18px;">
                <div class="rb-admin-card">
                    <h2 style="margin:0 0 8px;">Benachrichtigungs-E-Mail</h2>
                    <p style="margin-top:0;">Diese E-Mail erhält neue Buchungsinfos für den Betrieb.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="rewan_booking_save_settings">
                        <?php wp_nonce_field('rewan_booking_save_settings_nonce', 'rewan_booking_save_settings_nonce'); ?>
                        <input
                            type="email"
                            name="notification_email"
                            value="<?php echo esc_attr($notification_email); ?>"
                            class="regular-text"
                            style="max-width:420px;width:100%;"
                            required
                        >
                        <p style="margin-top:10px;">
                            <?php submit_button('E-Mail speichern', 'primary', 'submit', false); ?>
                        </p>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_services_page() {
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
        <div class="wrap">
            <h1>Dienstleistungen</h1>

            <?php $this->render_admin_notice($message); ?>
            <style>
                .rb-admin-simple-card { background:#fff; border:1px solid #e8e8e8; border-radius:14px; padding:16px; margin:14px 0; }
                .rb-admin-simple-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; }
                .rb-admin-simple-grid input, .rb-admin-simple-grid textarea { width:100%; }
                .rb-service-list { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; margin-top:14px; }
                .rb-service-item { background:#fff; border:1px solid #e8e8e8; border-radius:12px; padding:12px; }
                .rb-service-head { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:8px; }
                .rb-pill { display:inline-block; background:#f0f0f0; padding:2px 8px; border-radius:999px; font-size:12px; }
                @media (max-width: 960px) { .rb-admin-simple-grid { grid-template-columns:1fr 1fr; } }
                @media (max-width: 680px) { .rb-admin-simple-grid, .rb-service-list { grid-template-columns:1fr; } }
            </style>

            <div class="rb-admin-simple-card">
                <h2 style="margin-top:0;">Neue Dienstleistung hinzufügen</h2>
                <p>Nur die wichtigsten Felder ausfüllen und speichern.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="rewan_booking_save_service">
                    <input type="hidden" name="service_id" value="0">
                    <?php wp_nonce_field('rewan_booking_save_service_nonce', 'rewan_booking_save_service_nonce'); ?>

                    <div class="rb-admin-simple-grid">
                        <div><label>Name</label><input type="text" name="service_name" value="<?php echo esc_attr($new_service['name']); ?>" required></div>
                        <div><label>Preis (CHF)</label><input type="number" name="service_price" min="0" step="0.01" value="<?php echo esc_attr($new_service['price']); ?>" required></div>
                        <div><label>Dauer (Minuten)</label><input type="number" name="service_duration" min="1" step="1" value="<?php echo esc_attr($new_service['duration']); ?>" required></div>
                        <div style="grid-column:1 / -1;"><label>Beschreibung</label><textarea name="service_description" rows="3"><?php echo esc_textarea($new_service['description']); ?></textarea></div>
                        <div style="grid-column:1 / -1;"><label>Bild-URL (optional)</label><input type="url" name="service_image_url" value="<?php echo esc_attr($new_service['image_url']); ?>"></div>
                    </div>
                    <p style="margin-top:10px;">
                        <label><input type="checkbox" name="service_is_active" value="1" checked> Aktiv anzeigen</label>
                    </p>
                    <?php submit_button('Dienstleistung speichern', 'primary', 'submit', false); ?>
                </form>
            </div>

            <h2>Bestehende Dienstleistungen (zum Bearbeiten öffnen)</h2>
            <div class="rb-service-list">
                <?php if (!empty($services)) : ?>
                    <?php foreach ($services as $row) : ?>
                        <div class="rb-service-item">
                            <div class="rb-service-head">
                                <strong><?php echo esc_html($row['name']); ?></strong>
                                <span class="rb-pill"><?php echo (int) $row['is_active'] === 1 ? 'Aktiv' : 'Inaktiv'; ?></span>
                            </div>
                            <div style="font-size:13px;color:#555;margin-bottom:8px;">
                                <?php echo esc_html(number_format((float) $row['price'], 2, '.', '\'')); ?> CHF · <?php echo esc_html($row['duration']); ?> Min
                            </div>
                            <details>
                                <summary style="cursor:pointer;">Bearbeiten</summary>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
                                    <input type="hidden" name="action" value="rewan_booking_save_service">
                                    <input type="hidden" name="service_id" value="<?php echo esc_attr($row['id']); ?>">
                                    <?php wp_nonce_field('rewan_booking_save_service_nonce', 'rewan_booking_save_service_nonce'); ?>

                                    <p><label>Name<br><input type="text" name="service_name" value="<?php echo esc_attr($row['name']); ?>" required></label></p>
                                    <p><label>Preis (CHF)<br><input type="number" name="service_price" min="0" step="0.01" value="<?php echo esc_attr($row['price']); ?>" required></label></p>
                                    <p><label>Dauer (Minuten)<br><input type="number" name="service_duration" min="1" step="1" value="<?php echo esc_attr($row['duration']); ?>" required></label></p>
                                    <p><label>Beschreibung<br><textarea name="service_description" rows="3"><?php echo esc_textarea(isset($row['description']) ? $row['description'] : ''); ?></textarea></label></p>
                                    <p><label>Bild-URL<br><input type="url" name="service_image_url" value="<?php echo esc_attr(isset($row['image_url']) ? $row['image_url'] : ''); ?>"></label></p>
                                    <p><label><input type="checkbox" name="service_is_active" value="1" <?php checked((int) $row['is_active'], 1); ?>> Aktiv anzeigen</label></p>
                                    <p>
                                        <?php submit_button('Änderung speichern', 'primary', 'submit', false); ?>
                                        <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=rewan_booking_delete_service&service_id=' . (int) $row['id']), 'rewan_booking_delete_service_' . (int) $row['id'])); ?>" onclick="return confirm('Dienstleistung wirklich löschen?');">Löschen</a>
                                    </p>
                                </form>
                            </details>
                        </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <p>Keine Dienstleistungen gefunden.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function render_employees_page() {
        global $wpdb;

        $employees_table = $wpdb->prefix . 'rewan_booking_employees';

        $employees = $wpdb->get_results("SELECT * FROM $employees_table ORDER BY name ASC", ARRAY_A);

        $message = isset($_GET['message']) ? sanitize_text_field(wp_unslash($_GET['message'])) : '';
        ?>
        <div class="wrap">
            <h1>Mitarbeiter</h1>

            <?php $this->render_admin_notice($message); ?>
            <style>
                .rb-emp-card { background:#fff; border:1px solid #e8e8e8; border-radius:14px; padding:14px; margin:12px 0; }
                .rb-emp-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; }
                .rb-emp-list { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; margin-top:14px; }
                .rb-emp-head { display:flex; align-items:center; justify-content:space-between; gap:10px; }
                .rb-emp-row { display:grid; grid-template-columns:130px 1fr 1fr 1fr 1fr; gap:8px; align-items:center; margin-top:6px; }
                @media (max-width: 960px) { .rb-emp-grid, .rb-emp-list { grid-template-columns:1fr; } .rb-emp-row { grid-template-columns:1fr 1fr; } .rb-emp-row strong { grid-column:1/-1; } }
            </style>

            <div class="rb-emp-card">
                <h2 style="margin-top:0;">Neuen Mitarbeiter schnell hinzufügen</h2>
                <p>Name und E-Mail reichen. Arbeitszeiten werden automatisch auf Standard gesetzt.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="rewan_booking_save_employee">
                    <input type="hidden" name="employee_id" value="0">
                    <?php wp_nonce_field('rewan_booking_save_employee_nonce', 'rewan_booking_save_employee_nonce'); ?>

                    <div class="rb-emp-grid">
                        <div><label>Name<br><input type="text" name="employee_name" required></label></div>
                        <div><label>E-Mail<br><input type="email" name="employee_email" required></label></div>
                        <div><label>Bild-URL (optional)<br><input type="url" name="employee_image_url"></label></div>
                    </div>
                    <p><label><input type="checkbox" name="employee_is_active" value="1" checked> Aktiv</label></p>
                    <?php submit_button('Mitarbeiter speichern', 'primary', 'submit', false); ?>
                </form>
            </div>

            <h2>Mitarbeiter verwalten</h2>
            <div class="rb-emp-list">
                <?php if (!empty($employees)) : ?>
                    <?php foreach ($employees as $row) : ?>
                        <?php
                        $employee_id = (int) $row['id'];
                        $today = current_time('Y-m-d');
                        $tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));
                        ?>
                        <div class="rb-emp-card">
                            <div class="rb-emp-head">
                                <strong><?php echo esc_html($row['name']); ?></strong>
                                <span><?php echo (int) $row['is_active'] === 1 ? 'Aktiv' : 'Inaktiv'; ?></span>
                            </div>
                            <div style="color:#666;font-size:13px;margin-top:4px;"><?php echo esc_html($row['email']); ?></div>

                            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="rewan_booking_quick_day_off">
                                    <input type="hidden" name="employee_id" value="<?php echo esc_attr($employee_id); ?>">
                                    <input type="hidden" name="start_date" value="<?php echo esc_attr($today); ?>">
                                    <input type="hidden" name="end_date" value="<?php echo esc_attr($today); ?>">
                                    <input type="hidden" name="title" value="Frei (Heute)">
                                    <?php wp_nonce_field('rewan_booking_quick_day_off_nonce', 'rewan_booking_quick_day_off_nonce'); ?>
                                    <button type="submit" class="button button-small">Heute frei</button>
                                </form>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="rewan_booking_quick_day_off">
                                    <input type="hidden" name="employee_id" value="<?php echo esc_attr($employee_id); ?>">
                                    <input type="hidden" name="start_date" value="<?php echo esc_attr($tomorrow); ?>">
                                    <input type="hidden" name="end_date" value="<?php echo esc_attr($tomorrow); ?>">
                                    <input type="hidden" name="title" value="Frei (Morgen)">
                                    <?php wp_nonce_field('rewan_booking_quick_day_off_nonce', 'rewan_booking_quick_day_off_nonce'); ?>
                                    <button type="submit" class="button button-small">Morgen frei</button>
                                </form>
                                <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=rewan-booking-absences&employee_id=' . $employee_id)); ?>">Freie Tage öffnen</a>
                            </div>

                            <details style="margin-top:10px;">
                                <summary style="cursor:pointer;">Bearbeiten (nur Profil)</summary>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
                                    <input type="hidden" name="action" value="rewan_booking_save_employee">
                                    <input type="hidden" name="employee_id" value="<?php echo esc_attr($employee_id); ?>">
                                    <?php wp_nonce_field('rewan_booking_save_employee_nonce', 'rewan_booking_save_employee_nonce'); ?>

                                    <p><label>Name<br><input type="text" name="employee_name" value="<?php echo esc_attr($row['name']); ?>" required></label></p>
                                    <p><label>E-Mail<br><input type="email" name="employee_email" value="<?php echo esc_attr($row['email']); ?>" required></label></p>
                                    <p><label>Bild-URL<br><input type="url" name="employee_image_url" value="<?php echo esc_attr($row['image_url']); ?>"></label></p>
                                    <p><label><input type="checkbox" name="employee_is_active" value="1" <?php checked((int) $row['is_active'], 1); ?>> Aktiv</label></p>

                                    <p style="margin-top:10px;">
                                        <?php submit_button('Änderungen speichern', 'primary', 'submit', false); ?>
                                        <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=rewan_booking_delete_employee&employee_id=' . $employee_id), 'rewan_booking_delete_employee_' . $employee_id)); ?>" onclick="return confirm('Mitarbeiter wirklich löschen? Das geht nur, wenn noch keine Buchungen zugewiesen sind.');">Löschen</a>
                                    </p>
                                </form>
                            </details>
                        </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <p>Keine Mitarbeiter gefunden.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function render_absences_page() {
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

        $today = current_time('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));
        $message = isset($_GET['message']) ? sanitize_text_field(wp_unslash($_GET['message'])) : '';
        ?>
        <div class="wrap">
            <h1>Abwesenheiten / Ferien / Feiertage</h1>

            <?php $this->render_admin_notice($message); ?>

            <h2>Einfach: Heute, Morgen oder Zeitraum setzen</h2>
            <p>Optimiert für Handy: entweder 1-Klick oder direkt Von/Bis im Kalender wählen.</p>
            <style>
                .rb-quick-off-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; margin:12px 0 20px; }
                .rb-quick-off-card { background:#fff; border:1px solid #e8e8e8; border-radius:12px; padding:12px; }
                .rb-quick-off-actions { display:flex; flex-wrap:wrap; gap:8px; margin-top:8px; }
                .rb-quick-off-range { display:grid; grid-template-columns:1fr 1fr auto; gap:8px; margin-top:10px; align-items:end; }
                .rb-quick-off-range label { display:block; font-size:12px; color:#666; margin-bottom:4px; }
                .rb-quick-off-range input[type="date"] { width:100%; min-height:38px; }
                .rb-abs-form { background:#fff;border:1px solid #e8e8e8;border-radius:12px;padding:14px; margin-top:12px; }
                .rb-abs-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
                .rb-abs-field label { display:block; font-weight:600; margin-bottom:4px; }
                .rb-abs-field input, .rb-abs-field select { width:100%; min-height:38px; }
                .rb-abs-field-full { grid-column:1 / -1; }
                .rb-abs-time-wrap { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
                .rb-abs-mobile-list { display:none; gap:10px; margin-top:12px; }
                .rb-abs-mobile-card { background:#fff;border:1px solid #e8e8e8;border-radius:12px;padding:10px; }
                @media (max-width: 960px) { .rb-quick-off-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
                @media (max-width: 680px) {
                    .rb-quick-off-grid { grid-template-columns:1fr; }
                    .rb-quick-off-actions .button, .rb-quick-off-range .button { width:100%; text-align:center; }
                    .rb-quick-off-range { grid-template-columns:1fr; }
                    .rb-abs-grid, .rb-abs-time-wrap { grid-template-columns:1fr; }
                    .rb-abs-table { display:none; }
                    .rb-abs-mobile-list { display:grid; }
                }
            </style>
            <div class="rb-quick-off-grid">
                <?php foreach ($employees as $employee) : ?>
                    <div class="rb-quick-off-card">
                        <strong><?php echo esc_html($employee['name']); ?></strong>
                        <div class="rb-quick-off-actions">
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="rewan_booking_quick_day_off">
                                <input type="hidden" name="employee_id" value="<?php echo esc_attr($employee['id']); ?>">
                                <input type="hidden" name="start_date" value="<?php echo esc_attr($today); ?>">
                                <input type="hidden" name="end_date" value="<?php echo esc_attr($today); ?>">
                                <input type="hidden" name="title" value="Frei (Heute)">
                                <?php wp_nonce_field('rewan_booking_quick_day_off_nonce', 'rewan_booking_quick_day_off_nonce'); ?>
                                <button type="submit" class="button button-primary">Heute frei</button>
                            </form>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="rewan_booking_quick_day_off">
                                <input type="hidden" name="employee_id" value="<?php echo esc_attr($employee['id']); ?>">
                                <input type="hidden" name="start_date" value="<?php echo esc_attr($tomorrow); ?>">
                                <input type="hidden" name="end_date" value="<?php echo esc_attr($tomorrow); ?>">
                                <input type="hidden" name="title" value="Frei (Morgen)">
                                <?php wp_nonce_field('rewan_booking_quick_day_off_nonce', 'rewan_booking_quick_day_off_nonce'); ?>
                                <button type="submit" class="button">Morgen frei</button>
                            </form>
                        </div>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="rb-quick-off-range">
                            <input type="hidden" name="action" value="rewan_booking_quick_day_off">
                            <input type="hidden" name="employee_id" value="<?php echo esc_attr($employee['id']); ?>">
                            <input type="hidden" name="title" value="Ferien / Frei">
                            <?php wp_nonce_field('rewan_booking_quick_day_off_nonce', 'rewan_booking_quick_day_off_nonce'); ?>
                            <div>
                                <label>Von</label>
                                <input type="date" name="start_date" value="<?php echo esc_attr($today); ?>" required>
                            </div>
                            <div>
                                <label>Bis</label>
                                <input type="date" name="end_date" value="<?php echo esc_attr($today); ?>" required>
                            </div>
                            <button type="submit" class="button button-primary">Zeitraum frei</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>

            <details class="rb-abs-form" open>
                <summary style="cursor:pointer;font-weight:600;">Manuell eintragen (einfach)</summary>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px;">
                    <input type="hidden" name="action" value="rewan_booking_add_absence">
                    <?php wp_nonce_field('rewan_booking_add_absence_nonce', 'rewan_booking_add_absence_nonce'); ?>

                    <div class="rb-abs-grid">
                        <div class="rb-abs-field">
                            <label for="absence_employee_id">Mitarbeiter</label>
                            <select name="absence_employee_id" id="absence_employee_id" required>
                                <option value="">Bitte wählen</option>
                                <?php foreach ($employees as $employee) : ?>
                                    <option value="<?php echo esc_attr($employee['id']); ?>" <?php selected($prefill_employee_id, (int) $employee['id']); ?>><?php echo esc_html($employee['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="rb-abs-field">
                            <label for="absence_type">Typ</label>
                            <select name="absence_type" id="absence_type">
                                <option value="absence">Abwesenheit</option>
                                <option value="vacation">Ferien</option>
                                <option value="holiday">Feiertag</option>
                                <option value="blocked">Blockiert</option>
                            </select>
                        </div>
                        <div class="rb-abs-field rb-abs-field-full">
                            <label for="absence_title">Titel / Bemerkung</label>
                            <input type="text" id="absence_title" name="absence_title" placeholder="z. B. Ferien, Feiertag, Arzt">
                        </div>
                        <div class="rb-abs-field">
                            <label for="absence_start_date">Von Datum</label>
                            <input type="date" id="absence_start_date" name="absence_start_date" required>
                        </div>
                        <div class="rb-abs-field">
                            <label for="absence_end_date">Bis Datum</label>
                            <input type="date" id="absence_end_date" name="absence_end_date" required>
                        </div>
                        <div class="rb-abs-field rb-abs-field-full">
                            <label>
                                <input type="checkbox" id="absence_is_all_day" name="absence_is_all_day" value="1" checked>
                                Ganzer Tag (empfohlen)
                            </label>
                        </div>
                        <div class="rb-abs-field rb-abs-field-full rb-abs-time-wrap" id="rb_abs_time_wrap">
                            <div>
                                <label for="absence_start_time">Von Uhrzeit</label>
                                <input type="time" id="absence_start_time" name="absence_start_time" value="09:00">
                            </div>
                            <div>
                                <label for="absence_end_time">Bis Uhrzeit</label>
                                <input type="time" id="absence_end_time" name="absence_end_time" value="18:00">
                            </div>
                        </div>
                    </div>

                    <?php submit_button('Abwesenheit speichern'); ?>
                </form>
            </details>

            <hr>

            <h2>Vorhandene Einträge</h2>
            <table class="widefat fixed striped rb-abs-table">
                <thead>
                    <tr>
                        <th style="width:7%;">ID</th>
                        <th style="width:18%;">Mitarbeiter</th>
                        <th style="width:18%;">Typ</th>
                        <th style="width:18%;">Titel</th>
                        <th style="width:14%;">Von</th>
                        <th style="width:14%;">Bis</th>
                        <th style="width:11%;">Aktion</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($absences)) : ?>
                        <?php foreach ($absences as $row) : ?>
                            <tr>
                                <td><?php echo esc_html($row['id']); ?></td>
                                <td><?php echo esc_html($row['employee_name']); ?></td>
                                <td><?php echo esc_html($row['absence_type']); ?></td>
                                <td><?php echo esc_html($row['title']); ?></td>
                                <td>
                                    <?php echo esc_html($row['start_date']); ?>
                                    <?php if ((int) $row['is_all_day'] !== 1) : ?>
                                        <br><small><?php echo esc_html(substr($row['start_time'], 0, 5)); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo esc_html($row['end_date']); ?>
                                    <?php if ((int) $row['is_all_day'] !== 1) : ?>
                                        <br><small><?php echo esc_html(substr($row['end_time'], 0, 5)); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=rewan_booking_delete_absence&absence_id=' . (int) $row['id']), 'rewan_booking_delete_absence_' . (int) $row['id'])); ?>" onclick="return confirm('Eintrag wirklich löschen?');">Löschen</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="7">Keine Abwesenheiten vorhanden.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <div class="rb-abs-mobile-list">
                <?php if (!empty($absences)) : ?>
                    <?php foreach ($absences as $row) : ?>
                        <div class="rb-abs-mobile-card">
                            <strong><?php echo esc_html($row['employee_name']); ?></strong><br>
                            <small><?php echo esc_html($row['absence_type']); ?> · <?php echo esc_html($row['title']); ?></small>
                            <div style="margin-top:6px;">
                                <div><strong>Von:</strong> <?php echo esc_html($row['start_date']); ?><?php if ((int) $row['is_all_day'] !== 1) : ?> (<?php echo esc_html(substr($row['start_time'], 0, 5)); ?>)<?php endif; ?></div>
                                <div><strong>Bis:</strong> <?php echo esc_html($row['end_date']); ?><?php if ((int) $row['is_all_day'] !== 1) : ?> (<?php echo esc_html(substr($row['end_time'], 0, 5)); ?>)<?php endif; ?></div>
                            </div>
                            <div style="margin-top:8px;">
                                <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=rewan_booking_delete_absence&absence_id=' . (int) $row['id']), 'rewan_booking_delete_absence_' . (int) $row['id'])); ?>" onclick="return confirm('Eintrag wirklich löschen?');">Löschen</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <script>
                (function () {
                    const allDay = document.getElementById('absence_is_all_day');
                    const timeWrap = document.getElementById('rb_abs_time_wrap');
                    const startDate = document.getElementById('absence_start_date');
                    const endDate = document.getElementById('absence_end_date');
                    if (!allDay || !timeWrap || !startDate || !endDate) {
                        return;
                    }
                    function updateTimeVisibility() {
                        timeWrap.style.display = allDay.checked ? 'none' : 'grid';
                    }
                    allDay.addEventListener('change', updateTimeVisibility);
                    startDate.addEventListener('change', function () {
                        endDate.min = startDate.value || '';
                        if (endDate.value && startDate.value && endDate.value < startDate.value) {
                            endDate.value = startDate.value;
                        }
                    });
                    updateTimeVisibility();
                })();
            </script>
        </div>
        <?php
    }

    public function render_bookings_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rewan_booking_bookings';
        $employees_table = $wpdb->prefix . 'rewan_booking_employees';

        $edit_id = isset($_GET['edit_booking']) ? (int) $_GET['edit_booking'] : 0;

        // --- BEARBEITEN ANSICHT ---
        if ($edit_id > 0) {
            $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $edit_id), ARRAY_A);
            if (!$booking) { echo '<div class="wrap"><h1>Buchung nicht gefunden.</h1></div>'; return; }
            
            $employees = $wpdb->get_results("SELECT id, name FROM $employees_table WHERE is_active = 1", ARRAY_A);
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
                        <tr>
                            <th>Datum (Verschieben)</th>
                            <td><input type="date" name="booking_date" value="<?php echo esc_attr($booking['booking_date']); ?>" required></td>
                        </tr>
                        <tr>
                            <th>Zeit (Verschieben)</th>
                            <td><input type="time" name="start_time" value="<?php echo esc_attr(substr($booking['start_time'], 0, 5)); ?>" required></td>
                        </tr>
                        <tr>
                            <th>Mitarbeiter</th>
                            <td>
                                <select name="employee_id">
                                    <?php foreach($employees as $emp): ?>
                                        <option value="<?php echo esc_attr($emp['id']); ?>" <?php selected($booking['employee_id'], $emp['id']); ?>><?php echo esc_html($emp['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
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

        $sql = "SELECT * FROM $table_name";
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY booking_date DESC, start_time DESC';

        $bookings = !empty($params)
            ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A)
            : $wpdb->get_results($sql, ARRAY_A);

        ?>
        <div class="wrap">
            <h1>Buchungen</h1>
            <style>
                .rb-booking-filters { display:flex; gap:8px; flex-wrap:wrap; margin:12px 0; }
                .rb-booking-date-filter { display:flex; gap:8px; flex-wrap:wrap; align-items:end; margin-bottom:12px; }
                .rb-booking-date-filter label { display:block; font-size:12px; color:#666; margin-bottom:4px; }
                .rb-booking-date-filter input[type="date"] { min-height:36px; }
                .rb-bookings-mobile { display:none; gap:10px; }
                .rb-booking-card { background:#fff; border:1px solid #e8e8e8; border-radius:12px; padding:12px; }
                .rb-booking-meta { color:#666; font-size:13px; }
                .rb-booking-actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }
                @media (max-width: 680px) {
                    .rb-bookings-table { display:none; }
                    .rb-bookings-mobile { display:grid; }
                    .rb-booking-filters .button { flex:1 1 calc(50% - 8px); text-align:center; }
                    .rb-booking-date-filter { display:grid; grid-template-columns:1fr; }
                    .rb-booking-date-filter .button { width:100%; text-align:center; }
                }
            </style>
            <div class="rb-booking-filters">
                <a class="button <?php echo $filter === 'today' ? 'button-primary' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=rewan-booking-bookings&filter=today')); ?>">Heute</a>
                <a class="button <?php echo $filter === 'tomorrow' ? 'button-primary' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=rewan-booking-bookings&filter=tomorrow')); ?>">Morgen</a>
                <a class="button <?php echo $filter === 'upcoming' ? 'button-primary' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=rewan-booking-bookings&filter=upcoming')); ?>">Kommend</a>
                <a class="button <?php echo $filter === 'all' ? 'button-primary' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=rewan-booking-bookings&filter=all')); ?>">Alle</a>
                <a class="button <?php echo $filter === 'cancelled' ? 'button-primary' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=rewan-booking-bookings&filter=cancelled')); ?>">Storniert</a>
            </div>
            <form method="get" class="rb-booking-date-filter">
                <input type="hidden" name="page" value="rewan-booking-bookings">
                <input type="hidden" name="filter" value="<?php echo esc_attr($filter); ?>">
                <div>
                    <label for="rb_from_date">Von</label>
                    <input type="date" id="rb_from_date" name="from_date" value="<?php echo esc_attr($from_date); ?>">
                </div>
                <div>
                    <label for="rb_to_date">Bis</label>
                    <input type="date" id="rb_to_date" name="to_date" value="<?php echo esc_attr($to_date); ?>">
                </div>
                <button type="submit" class="button">Filter anwenden</button>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=rewan-booking-bookings&filter=' . $filter)); ?>">Zurücksetzen</a>
            </form>
            <table class="widefat fixed striped rb-bookings-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Kunde</th>
                        <th>Mitarbeiter</th>
                        <th>Datum</th>
                        <th>Zeit</th>
                        <th>Service</th>
                        <th>Status</th>
                        <th>Aktion</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($bookings)) : ?>
                        <?php foreach ($bookings as $booking) : ?>
                            <?php
                            $service_text = $booking['services'];
                            $service_decoded = json_decode($booking['services'], true);
                            if (is_array($service_decoded)) {
                                $service_text = implode(', ', $service_decoded);
                            }
                            ?>
                            <tr>
                                <td><?php echo esc_html($booking['id']); ?></td>
                                <td>
                                    <?php echo esc_html($booking['customer_name']); ?><br>
                                    <small><?php echo esc_html($booking['customer_phone']); ?></small>
                                </td>
                                <td><?php echo esc_html($booking['employee_name']); ?></td>
                                <td><?php echo esc_html(date_i18n('d.m.Y', strtotime($booking['booking_date']))); ?></td>
                                <td><?php echo esc_html(substr($booking['start_time'], 0, 5)); ?></td>
                                <td><?php echo esc_html($service_text); ?></td>
                                <td>
                                    <?php if($booking['status'] === 'cancelled'): ?>
                                        <span style="color:red;font-weight:bold;">Storniert</span>
                                    <?php else: ?>
                                        <span style="color:green;">Bestätigt</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=rewan-booking-bookings&edit_booking=' . (int) $booking['id'])); ?>">Bearbeiten</a>
                                    <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=rewan_booking_delete_booking&booking_id=' . (int) $booking['id']), 'rewan_booking_delete_booking_' . (int) $booking['id'])); ?>" onclick="return confirm('Buchung wirklich löschen?');" style="color:#b32d2e;">Löschen</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="8">Noch keine Buchungen vorhanden.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <div class="rb-bookings-mobile">
                <?php if (!empty($bookings)) : ?>
                    <?php foreach ($bookings as $booking) : ?>
                        <?php
                        $service_text = $booking['services'];
                        $service_decoded = json_decode($booking['services'], true);
                        if (is_array($service_decoded)) {
                            $service_text = implode(', ', $service_decoded);
                        }
                        ?>
                        <div class="rb-booking-card">
                            <strong><?php echo esc_html($booking['customer_name']); ?></strong>
                            <div class="rb-booking-meta"><?php echo esc_html($booking['customer_phone']); ?></div>
                            <div style="margin-top:6px;">
                                <strong><?php echo esc_html(date_i18n('D, d.m.Y', strtotime($booking['booking_date']))); ?></strong>
                                · <?php echo esc_html(substr($booking['start_time'], 0, 5)); ?>
                            </div>
                            <div class="rb-booking-meta" style="margin-top:4px;">Barber: <?php echo esc_html($booking['employee_name']); ?></div>
                            <div class="rb-booking-meta">Service: <?php echo esc_html($service_text); ?></div>
                            <div style="margin-top:6px;">
                                <?php if($booking['status'] === 'cancelled'): ?>
                                    <span style="color:#b32d2e;font-weight:700;">Storniert</span>
                                <?php else: ?>
                                    <span style="color:#1f8f4e;font-weight:700;">Bestätigt</span>
                                <?php endif; ?>
                            </div>
                            <div class="rb-booking-actions">
                                <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=rewan-booking-bookings&edit_booking=' . (int) $booking['id'])); ?>">Bearbeiten</a>
                                <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=rewan_booking_delete_booking&booking_id=' . (int) $booking['id']), 'rewan_booking_delete_booking_' . (int) $booking['id'])); ?>" onclick="return confirm('Buchung wirklich löschen?');" style="color:#b32d2e;">Löschen</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <div class="rb-booking-card">Keine Buchungen für diesen Filter gefunden.</div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function render_calendar_page() {
        global $wpdb;

        $bookings_table = $wpdb->prefix . 'rewan_booking_bookings';

        $view = isset($_GET['view']) ? sanitize_text_field(wp_unslash($_GET['view'])) : 'week';
        if (!in_array($view, array('day', 'week', 'month', 'list'), true)) {
            $view = 'week';
        }

        $base_date = isset($_GET['date']) ? sanitize_text_field(wp_unslash($_GET['date'])) : current_time('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $base_date)) {
            $base_date = current_time('Y-m-d');
        }

        $range_start = $base_date;
        $range_end = $base_date;
        $prev_date = $base_date;
        $next_date = $base_date;

        if ($view === 'day') {
            $range_start = $base_date;
            $range_end = $base_date;
            $prev_date = date('Y-m-d', strtotime($base_date . ' -1 day'));
            $next_date = date('Y-m-d', strtotime($base_date . ' +1 day'));
        } elseif ($view === 'week') {
            $range_start = date('Y-m-d', strtotime('monday this week', strtotime($base_date)));
            $range_end = date('Y-m-d', strtotime($range_start . ' +6 day'));
            $prev_date = date('Y-m-d', strtotime($base_date . ' -7 day'));
            $next_date = date('Y-m-d', strtotime($base_date . ' +7 day'));
        } elseif ($view === 'month') {
            $range_start = date('Y-m-01', strtotime($base_date));
            $range_end = date('Y-m-t', strtotime($base_date));
            $prev_date = date('Y-m-d', strtotime($range_start . ' -1 month'));
            $next_date = date('Y-m-d', strtotime($range_start . ' +1 month'));
        } else {
            $range_start = $base_date;
            $range_end = date('Y-m-d', strtotime($base_date . ' +30 day'));
            $prev_date = date('Y-m-d', strtotime($base_date . ' -30 day'));
            $next_date = date('Y-m-d', strtotime($base_date . ' +30 day'));
        }

        $bookings = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $bookings_table WHERE booking_date BETWEEN %s AND %s ORDER BY booking_date ASC, start_time ASC",
                $range_start,
                $range_end
            ),
            ARRAY_A
        );

        $calendar = array();
        foreach ($bookings as $booking) {
            $calendar[$booking['booking_date']][] = $booking;
        }
        ?>
        <div class="wrap">
            <h1>Kalenderansicht</h1>

            <p style="display:flex;flex-wrap:wrap;gap:8px;">
                <a class="button <?php echo $view === 'day' ? 'button-primary' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=rewan-booking-calendar&view=day&date=' . $base_date)); ?>">Tag</a>
                <a class="button <?php echo $view === 'week' ? 'button-primary' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=rewan-booking-calendar&view=week&date=' . $base_date)); ?>">Woche</a>
                <a class="button <?php echo $view === 'month' ? 'button-primary' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=rewan-booking-calendar&view=month&date=' . $base_date)); ?>">Monat</a>
                <a class="button <?php echo $view === 'list' ? 'button-primary' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=rewan-booking-calendar&view=list&date=' . $base_date)); ?>">Liste</a>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=rewan-booking-bookings')); ?>">Alle Buchungen öffnen</a>
            </p>

            <p>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=rewan-booking-calendar&view=' . $view . '&date=' . $prev_date)); ?>">← Zurück</a>
                <strong style="margin:0 12px;"><?php echo esc_html(date_i18n('d.m.Y', strtotime($range_start))); ?> - <?php echo esc_html(date_i18n('d.m.Y', strtotime($range_end))); ?></strong>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=rewan-booking-calendar&view=' . $view . '&date=' . $next_date)); ?>">Weiter →</a>
            </p>

            <?php if ($view === 'month') : ?>
                <?php
                $days_in_month = (int) date('t', strtotime($range_start));
                $first_weekday = (int) date('N', strtotime($range_start));
                ?>
                <table class="widefat striped" style="table-layout:fixed;">
                    <thead><tr><th>Mo</th><th>Di</th><th>Mi</th><th>Do</th><th>Fr</th><th>Sa</th><th>So</th></tr></thead>
                    <tbody><tr>
                    <?php
                    $cell = 1;
                    for ($empty = 1; $empty < $first_weekday; $empty++, $cell++) {
                        echo '<td style="height:120px;background:#fafafa;"></td>';
                    }
                    for ($day = 1; $day <= $days_in_month; $day++, $cell++) {
                        $date = date('Y-m-d', strtotime(date('Y-m', strtotime($range_start)) . '-' . str_pad((string) $day, 2, '0', STR_PAD_LEFT)));
                        $day_entries = isset($calendar[$date]) ? $calendar[$date] : array();
                        echo '<td style="vertical-align:top;height:120px;">';
                        echo '<strong>' . esc_html((string) $day) . '</strong>';
                        if (!empty($day_entries)) {
                            echo '<div style="margin-top:6px;"><span style="background:#d4af37;color:#111;padding:2px 6px;border-radius:10px;font-size:11px;font-weight:700;">' . count($day_entries) . ' Termine</span></div>';
                            $preview = array_slice($day_entries, 0, 2);
                            foreach ($preview as $entry) {
                                echo '<div style="margin-top:6px;font-size:12px;line-height:1.35;">' . esc_html(substr($entry['start_time'], 0, 5)) . ' · ' . esc_html($entry['customer_name']) . '</div>';
                            }
                        }
                        echo '</td>';
                        if ($cell % 7 === 0 && $day !== $days_in_month) {
                            echo '</tr><tr>';
                        }
                    }
                    while (($cell - 1) % 7 !== 0) {
                        echo '<td style="height:120px;background:#fafafa;"></td>';
                        $cell++;
                    }
                    ?>
                    </tr></tbody>
                </table>
            <?php elseif ($view === 'list') : ?>
                <table class="widefat striped">
                    <thead><tr><th>Datum</th><th>Zeit</th><th>Kunde</th><th>Mitarbeiter</th><th>Status</th><th>Aktion</th></tr></thead>
                    <tbody>
                    <?php if (!empty($bookings)) : ?>
                        <?php foreach ($bookings as $entry) : ?>
                            <tr>
                                <td><?php echo esc_html(date_i18n('d.m.Y', strtotime($entry['booking_date']))); ?></td>
                                <td><?php echo esc_html(substr($entry['start_time'], 0, 5)); ?></td>
                                <td><?php echo esc_html($entry['customer_name']); ?></td>
                                <td><?php echo esc_html($entry['employee_name']); ?></td>
                                <td><?php echo esc_html($entry['status']); ?></td>
                                <td><a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=rewan-booking-bookings&edit_booking=' . (int) $entry['id'])); ?>">Bearbeiten</a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="6">Keine Buchungen in diesem Zeitraum.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <?php
                $cursor = $range_start;
                while ($cursor <= $range_end) :
                    $day_entries = isset($calendar[$cursor]) ? $calendar[$cursor] : array();
                    ?>
                    <div style="background:#fff;border:1px solid #e8e8e8;border-radius:12px;padding:12px 14px;margin-bottom:10px;">
                        <h3 style="margin:0 0 8px;"><?php echo esc_html(date_i18n('l, d.m.Y', strtotime($cursor))); ?></h3>
                        <?php if (!empty($day_entries)) : ?>
                            <?php foreach ($day_entries as $entry) : ?>
                                <div style="display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-top:1px solid #f1f1f1;">
                                    <div>
                                        <strong><?php echo esc_html(substr($entry['start_time'], 0, 5)); ?></strong> · <?php echo esc_html($entry['customer_name']); ?><br>
                                        <small><?php echo esc_html($entry['employee_name']); ?></small>
                                    </div>
                                    <div><a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=rewan-booking-bookings&edit_booking=' . (int) $entry['id'])); ?>">Bearbeiten</a></div>
                                </div>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <p style="margin:0;color:#666;">Keine Buchung.</p>
                        <?php endif; ?>
                    </div>
                    <?php
                    $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
                endwhile;
                ?>
            <?php endif; ?>
        </div>
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
            'absence_deleted' => 'Abwesenheit gelöscht.',
            'quick_day_off_saved' => 'Freier Tag wurde gesetzt.',
            'settings_saved' => 'Einstellungen gespeichert.',
        );

        $error_messages = array(
            'employee_has_bookings' => 'Mitarbeiter kann nicht gelöscht werden, weil bereits Buchungen vorhanden sind.',
            'service_not_found' => 'Dienstleistung nicht gefunden.',
            'employee_not_found' => 'Mitarbeiter nicht gefunden.',
            'absence_not_found' => 'Abwesenheit nicht gefunden.',
            'quick_day_off_exists' => 'Für diesen Zeitraum gibt es bereits eine Abwesenheit.',
            'invalid_notification_email' => 'Bitte eine gültige E-Mail-Adresse eingeben.',
        );

        if (isset($success_messages[$message])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($success_messages[$message]) . '</p></div>';
        } elseif (isset($error_messages[$message])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($error_messages[$message]) . '</p></div>';
        }
    }

    public function handle_add_service() {
        $this->handle_save_service();
    }

    public function handle_save_settings() {
        if (!current_user_can('manage_options')) {
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
            wp_redirect(admin_url('admin.php?page=rewan-booking&message=invalid_notification_email'));
            exit;
        }

        update_option('rewan_booking_notification_email', $notification_email);
        wp_redirect(admin_url('admin.php?page=rewan-booking&message=settings_saved'));
        exit;
    }

    public function handle_save_service() {
        if (!current_user_can('manage_options')) { wp_die('Keine Berechtigung.'); }
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
        if (!current_user_can('manage_options')) {
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
        if (!current_user_can('manage_options')) {
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
        $hours_input = isset($_POST['hours']) ? (array) $_POST['hours'] : array();
        $breaks_input = isset($_POST['breaks']) ? (array) $_POST['breaks'] : array();

        if (empty($name) || empty($email)) {
            wp_die('Bitte Name und E-Mail korrekt eingeben.');
        }

        if ($employee_id > 0) {
            $wpdb->update(
                $employees_table,
                array(
                    'name' => $name,
                    'email' => $email,
                    'image_url' => $image_url,
                    'is_active' => $is_active,
                ),
                array('id' => $employee_id),
                array('%s', '%s', '%s', '%d'),
                array('%d')
            );
        } else {
            $wpdb->insert(
                $employees_table,
                array(
                    'name' => $name,
                    'email' => $email,
                    'image_url' => $image_url,
                    'is_active' => $is_active,
                ),
                array('%s', '%s', '%s', '%d')
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
        if (!current_user_can('manage_options')) {
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

    public function handle_add_absence() {
        if (!current_user_can('manage_options')) {
            wp_die('Keine Berechtigung.');
        }

        if (
            !isset($_POST['rewan_booking_add_absence_nonce']) ||
            !wp_verify_nonce($_POST['rewan_booking_add_absence_nonce'], 'rewan_booking_add_absence_nonce')
        ) {
            wp_die('Sicherheitsprüfung fehlgeschlagen.');
        }

        global $wpdb;

        $table_name = $wpdb->prefix . 'rewan_booking_employee_absences';

        $employee_id = isset($_POST['absence_employee_id']) ? (int) $_POST['absence_employee_id'] : 0;
        $title = isset($_POST['absence_title']) ? sanitize_text_field(wp_unslash($_POST['absence_title'])) : '';
        $absence_type = isset($_POST['absence_type']) ? sanitize_text_field(wp_unslash($_POST['absence_type'])) : 'absence';
        $start_date = isset($_POST['absence_start_date']) ? sanitize_text_field(wp_unslash($_POST['absence_start_date'])) : '';
        $end_date = isset($_POST['absence_end_date']) ? sanitize_text_field(wp_unslash($_POST['absence_end_date'])) : '';
        $is_all_day = isset($_POST['absence_is_all_day']) ? 1 : 0;
        $start_time = isset($_POST['absence_start_time']) ? sanitize_text_field(wp_unslash($_POST['absence_start_time'])) : '09:00';
        $end_time = isset($_POST['absence_end_time']) ? sanitize_text_field(wp_unslash($_POST['absence_end_time'])) : '18:00';

        if ($employee_id <= 0 || empty($start_date) || empty($end_date)) {
            wp_die('Bitte gültige Werte eingeben.');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
            wp_die('Datum ungültig.');
        }

        if ($end_date < $start_date) {
            wp_die('Enddatum darf nicht vor Startdatum liegen.');
        }

        if (!preg_match('/^\d{2}:\d{2}$/', $start_time)) {
            $start_time = '09:00';
        }

        if (!preg_match('/^\d{2}:\d{2}$/', $end_time)) {
            $end_time = '18:00';
        }

        if ($is_all_day) {
            $start_time = '00:00';
            $end_time = '23:59';
        }

        $wpdb->insert(
            $table_name,
            array(
                'employee_id' => $employee_id,
                'title' => $title,
                'absence_type' => $absence_type,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'is_all_day' => $is_all_day,
                'start_time' => $start_time . ':00',
                'end_time' => $end_time . ':00',
            ),
            array('%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );

        wp_redirect(admin_url('admin.php?page=rewan-booking-absences&message=absence_saved'));
        exit;
    }

    public function handle_quick_day_off() {
        if (!current_user_can('manage_options')) {
            wp_die('Keine Berechtigung.');
        }

        if (
            !isset($_POST['rewan_booking_quick_day_off_nonce']) ||
            !wp_verify_nonce($_POST['rewan_booking_quick_day_off_nonce'], 'rewan_booking_quick_day_off_nonce')
        ) {
            wp_die('Sicherheitsprüfung fehlgeschlagen.');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'rewan_booking_employee_absences';

        $employee_id = isset($_POST['employee_id']) ? (int) $_POST['employee_id'] : 0;
        $start_date = isset($_POST['start_date']) ? sanitize_text_field(wp_unslash($_POST['start_date'])) : '';
        $end_date = isset($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : '';
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : 'Frei';

        if ($employee_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
            wp_die('Ungültige Eingaben.');
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

        if ($exists > 0) {
            wp_redirect(admin_url('admin.php?page=rewan-booking-absences&message=quick_day_off_exists'));
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

        wp_redirect(admin_url('admin.php?page=rewan-booking-absences&message=quick_day_off_saved'));
        exit;
    }

    public function handle_delete_absence() {
        if (!current_user_can('manage_options')) {
            wp_die('Keine Berechtigung.');
        }

        $absence_id = isset($_GET['absence_id']) ? (int) $_GET['absence_id'] : 0;
        if ($absence_id <= 0) {
            wp_redirect(admin_url('admin.php?page=rewan-booking-absences&message=absence_not_found'));
            exit;
        }

        if (!wp_verify_nonce(isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '', 'rewan_booking_delete_absence_' . $absence_id)) {
            wp_die('Sicherheitsprüfung fehlgeschlagen.');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'rewan_booking_employee_absences';

        $wpdb->delete($table_name, array('id' => $absence_id), array('%d'));

        wp_redirect(admin_url('admin.php?page=rewan-booking-absences&message=absence_deleted'));
        exit;
    }

    public function handle_save_booking() {
        if (!current_user_can('manage_options')) { wp_die('Keine Berechtigung.'); }
        if (!isset($_POST['rewan_booking_save_booking_nonce']) || !wp_verify_nonce($_POST['rewan_booking_save_booking_nonce'], 'rewan_booking_save_booking_nonce')) { wp_die('Sicherheit fehlgeschlagen.'); }

        global $wpdb;
        $booking_id = isset($_POST['booking_id']) ? (int) $_POST['booking_id'] : 0;
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'confirmed';
        $booking_date = isset($_POST['booking_date']) ? sanitize_text_field($_POST['booking_date']) : '';
        $start_time = isset($_POST['start_time']) ? sanitize_text_field($_POST['start_time']) . ':00' : '';
        $employee_id = isset($_POST['employee_id']) ? (int) $_POST['employee_id'] : 0;

        // Hole den Namen des neuen Mitarbeiters
        $employee_name = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->prefix}rewan_booking_employees WHERE id = %d", $employee_id));

        if ($booking_id > 0) {
            $wpdb->update(
                $wpdb->prefix . 'rewan_booking_bookings',
                array(
                    'status' => $status,
                    'booking_date' => $booking_date,
                    'start_time' => $start_time,
                    'employee_id' => $employee_id,
                    'employee_name' => $employee_name
                ),
                array('id' => $booking_id),
                array('%s', '%s', '%s', '%d', '%s'),
                array('%d')
            );
        }
        wp_redirect(admin_url('admin.php?page=rewan-booking-bookings'));
        exit;
    }

    public function handle_delete_booking() {
        if (!current_user_can('manage_options')) { wp_die('Keine Berechtigung.'); }
        $booking_id = isset($_GET['booking_id']) ? (int) $_GET['booking_id'] : 0;
        if (!wp_verify_nonce(isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '', 'rewan_booking_delete_booking_' . $booking_id)) { wp_die('Sicherheit fehlgeschlagen.'); }

        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'rewan_booking_bookings', array('id' => $booking_id), array('%d'));

        wp_redirect(admin_url('admin.php?page=rewan-booking-bookings'));
        exit;
    }





}