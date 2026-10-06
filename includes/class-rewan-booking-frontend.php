<?php

if (!defined('ABSPATH')) {
    exit;
}

class Rewan_Booking_Frontend {

    public function init() {
        $this->maybe_migrate_legacy_weekday_schema();

        add_shortcode('rewan_booking_form', array($this, 'render_booking_form'));

        add_action('admin_post_nopriv_rewan_booking_submit', array($this, 'handle_booking_submission'));
        add_action('admin_post_rewan_booking_submit', array($this, 'handle_booking_submission'));

        add_action('wp_ajax_rewan_booking_get_slots', array($this, 'ajax_get_slots'));
        add_action('wp_ajax_nopriv_rewan_booking_get_slots', array($this, 'ajax_get_slots'));
        add_action('wp_ajax_rewan_booking_closed_days', array($this, 'ajax_closed_days'));
        add_action('wp_ajax_nopriv_rewan_booking_closed_days', array($this, 'ajax_closed_days'));
    }

    /**
     * One-time migration for old weekday schema (0..6) to ISO schema (1..7).
     */
    private function maybe_migrate_legacy_weekday_schema() {
        $flag = get_option('rewan_booking_weekday_schema_migrated', '');
        if ($flag === '1') {
            return;
        }

        global $wpdb;

        $hours_table = $wpdb->prefix . 'rewan_booking_employee_hours';
        $breaks_table = $wpdb->prefix . 'rewan_booking_employee_breaks';
        $global_table = $wpdb->prefix . 'rewan_booking_global_week_schedule';

        $this->migrate_employee_weekdays_to_iso($hours_table);
        $this->migrate_employee_weekdays_to_iso($breaks_table);
        $this->migrate_global_weekdays_to_iso($global_table);

        update_option('rewan_booking_weekday_schema_migrated', '1', false);
    }

    private function migrate_employee_weekdays_to_iso($table_name) {
        global $wpdb;

        $employee_ids = $wpdb->get_col("SELECT DISTINCT employee_id FROM {$table_name}");
        if (!is_array($employee_ids) || empty($employee_ids)) {
            return;
        }

        foreach ($employee_ids as $employee_id_raw) {
            $employee_id = (int) $employee_id_raw;
            if ($employee_id <= 0) {
                continue;
            }

            $has_zero = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_name} WHERE employee_id = %d AND weekday = 0",
                    $employee_id
                )
            );
            $has_seven = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_name} WHERE employee_id = %d AND weekday = 7",
                    $employee_id
                )
            );
            $max_weekday = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COALESCE(MAX(weekday), -1) FROM {$table_name} WHERE employee_id = %d",
                    $employee_id
                )
            );

            $is_legacy = ($has_seven === 0) && ($has_zero > 0 || ($max_weekday >= 0 && $max_weekday <= 6));
            if (!$is_legacy) {
                continue;
            }

            // Phase 1: move to temporary range to avoid unique collisions.
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table_name}
                     SET weekday = weekday + 10
                     WHERE employee_id = %d",
                    $employee_id
                )
            );

            // Phase 2: normalize to ISO range (+1 net shift).
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table_name}
                     SET weekday = weekday - 9
                     WHERE employee_id = %d
                     AND weekday BETWEEN 10 AND 16",
                    $employee_id
                )
            );
        }
    }

    private function migrate_global_weekdays_to_iso($table_name) {
        global $wpdb;

        $has_zero = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE weekday = 0");
        $has_seven = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE weekday = 7");
        $max_weekday = (int) $wpdb->get_var("SELECT COALESCE(MAX(weekday), -1) FROM {$table_name}");

        $is_legacy = ($has_seven === 0) && ($has_zero > 0 || ($max_weekday >= 0 && $max_weekday <= 6));
        if (!$is_legacy) {
            return;
        }

        // Phase 1: temporary range avoids PK collisions on weekday.
        $wpdb->query("UPDATE {$table_name} SET weekday = weekday + 10");
        // Phase 2: normalize to ISO range (+1 net shift).
        $wpdb->query("UPDATE {$table_name} SET weekday = weekday - 9 WHERE weekday BETWEEN 10 AND 16");
    }

    public function render_booking_form() {
        global $wpdb;

        $services_table = $wpdb->prefix . 'rewan_booking_services';
        $employees_table = $wpdb->prefix . 'rewan_booking_employees';

        $services = $wpdb->get_results(
            "SELECT * FROM $services_table WHERE is_active = 1 ORDER BY name ASC",
            ARRAY_A
        );

        $employees = $wpdb->get_results(
            "SELECT * FROM $employees_table WHERE is_active = 1 ORDER BY name ASC",
            ARRAY_A
        );

        $success = isset($_GET['booking']) && $_GET['booking'] === 'success';
        $error = isset($_GET['booking_error']) ? sanitize_text_field(wp_unslash($_GET['booking_error'])) : '';
        $receipt = null;
        if ($success && isset($_GET['receipt'])) {
            $receipt_token = preg_replace('/[^A-Za-z0-9]/', '', (string) wp_unslash($_GET['receipt']));
            $stored_receipt = $receipt_token !== '' ? get_transient('rewan_booking_receipt_' . $receipt_token) : false;
            if (is_array($stored_receipt)) {
                $receipt = $stored_receipt;
            }
        }
        $shop = class_exists('Rewan_Booking_Mail') ? Rewan_Booking_Mail::shop() : array('name' => 'Barbershop Rewan');
        $book_again_url = remove_query_arg(array('booking', 'booking_error', 'receipt'));

        $min_date = current_time('Y-m-d');
        $ajax_nonce = wp_create_nonce('rewan_booking_slots_nonce');
        wp_enqueue_script('jquery');
        wp_enqueue_script('jquery-ui-datepicker');

        ob_start();
        ?>
        <div class="rb-wrap">
            <style>

/* ==========================================================================
   Rewan Booking - Frontend Styles (Optimiert)
   ========================================================================== */

/* --- 1. Grundgerüst & Typografie --- */
.rb-wrap {
    max-width: 1600px; /* Etwas schmaler, da wir nur noch 1 Spalte haben */
    margin: 50px auto;
    color: #f5f1e8;
    font-family: inherit;
    padding-bottom: 86px;
}

.rb-shell {
    background: radial-gradient(circle at top right, rgba(212,175,55,0.16), transparent 30%),
                radial-gradient(circle at bottom left, rgba(212,175,55,0.08), transparent 24%),
                linear-gradient(180deg, #0d0d0d 0%, #141414 100%);
    border: 1px solid rgba(212,175,55,0.18);
    border-radius: 28px;
    overflow: visible !important;
    box-shadow: 0 30px 80px rgba(0,0,0,0.38);
}

.rb-top {
    padding: 36px 40px 22px;
    border-bottom: 1px solid rgba(212,175,55,0.16);
    text-align: center; /* Zentriert wirkt edler bei einspaltigem Layout */
}

.rb-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border: 1px solid rgba(212,175,55,0.22);
    background: rgba(212,175,55,0.08);
    color: #d4af37;
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 14px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    margin-bottom: 16px;
}

.rb-headline {
    margin: 0 0 10px;
    color: #fff;
    font-size: 46px;
    line-height: 1.1;
}

.rb-sub {
    margin: 0;
    color: #cbbfa9;
    font-size: 19px;
    line-height: 1.5;
}

.rb-guidance {
    margin-top: 16px;
    padding: 12px 14px;
    border-radius: 12px;
    border: 1px solid rgba(212,175,55,0.25);
    background: rgba(212,175,55,0.08);
    color: #efe7d3;
    font-size: 14px;
    text-align: left;
}

.rb-sticky-bar {
    display: none;
    position: fixed;
    left: 14px;
    right: 14px;
    bottom: 12px;
    z-index: 9999;
    padding: 10px 12px 12px;
    border-radius: 12px;
    border: 1px solid rgba(212,175,55,0.35);
    background: rgba(12,12,12,0.96);
    box-shadow: 0 12px 28px rgba(0,0,0,0.38);
    color: #efe7d3;
    font-size: 12px;
    line-height: 1.4;
}

.rb-sticky-top {
    display: flex;
    gap: 10px;
    justify-content: space-between;
    align-items: center;
    padding-bottom: 6px;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    margin-bottom: 6px;
}

.rb-sticky-services {
    font-weight: 700;
    color: #fff;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.rb-sticky-meta {
    color: #d4af37;
    white-space: nowrap;
}

.rb-sticky-message {
    color: #ded6c7;
}

/* --- 2. Layout & Panels --- */
.rb-content {
    padding: 30px 40px 40px;
    display: flex;
    flex-direction: column; /* Alles untereinander */
    gap: 30px;
}

.rb-panel {
    background: rgba(255,255,255,0.02);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 22px;
    padding: 25px;
    margin: 25px;
}

.rb-panel h3 {
    margin: 0 0 24px;
    color: #fff;
    font-size: 26px;
    border-bottom: 1px solid rgba(255,255,255,0.05);
    padding-bottom: 12px;
}

.rb-panel.is-locked {
    opacity: 0.45;
    pointer-events: none;
    user-select: none;
}

.rb-panel.is-hidden {
    display: none;
}

.rb-step-help {
    margin: -8px 0 16px;
    color: #cbbfa9;
    font-size: 14px;
}

.rb-selected-services {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 12px;
}

.rb-selected-chip {
    background: rgba(212,175,55,0.12);
    border: 1px solid rgba(212,175,55,0.4);
    border-radius: 999px;
    color: #f3ebd8;
    padding: 6px 10px;
    font-size: 12px;
    line-height: 1.2;
}

.rb-next-btn {
    margin-top: 18px;
    border: 1px solid rgba(212,175,55,0.45);
    color: #d4af37;
    background: rgba(212,175,55,0.08);
    border-radius: 12px;
    padding: 12px 16px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    width: 100%;
}

.rb-next-btn[hidden] {
    display: none;
}

.rb-back-btn {
    display: inline-flex;
    margin: 0 0 14px;
    padding: 0;
    border: none;
    background: transparent;
    color: #d4af37;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
}

.rb-assign-note {
    margin: 14px 0 0;
    color: #efe7d3;
    font-size: 15px;
}

.rb-confirm {
    max-width: 520px;
    margin: 0 auto;
}

.rb-confirm p {
    color: #cbbfa9;
    font-size: 16px;
    line-height: 1.5;
}

.rb-confirm-actions {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-top: 18px;
}

.rb-confirm-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    width: 100%;
    min-height: 54px;
    margin: 0;
    padding: 14px 16px;
    box-sizing: border-box;
    border-radius: 16px;
    border: 1px solid rgba(212,175,55,0.5);
    background: rgba(212,175,55,0.08);
    color: #f6edd4;
    font-size: 17px;
    font-weight: 700;
    line-height: 1.2;
    text-align: center;
    text-decoration: none;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
}

.rb-confirm-btn svg {
    width: 22px;
    height: 22px;
    flex: 0 0 22px;
}

.rb-confirm-btn.is-primary {
    color: #16120a;
    border-color: transparent;
    background: linear-gradient(180deg, #e8c45a 0%, #c4961f 100%);
    box-shadow: 0 10px 22px rgba(212,175,55,0.2);
}

.rb-confirm-btn:hover {
    filter: brightness(1.08);
}

.rb-confirm-btn:focus-visible {
    outline: 2px solid #e0b941;
    outline-offset: 3px;
}

@media (prefers-reduced-motion: reduce) {
    .rb-confirm-btn {
        transition: none;
    }
}

.rb-again {
    display: inline-block;
    margin-top: 14px;
    color: #d4af37;
    font-weight: 700;
    text-decoration: none;
}

/* --- 3. Dienstleistungen --- */
.rb-service-grid {
    display: grid;
    /* Das dynamische Grid: Karten sind mind. 280px breit. Wenn mehr Platz ist, passen mehr nebeneinander */
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); 
    gap: 20px;
}

.rb-service {
    position: relative;
    display: block;
    cursor: pointer;
}

.rb-service input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.rb-service-box {
    padding: 16px 16px 20px 16px; /* Oben weniger Padding, damit das Bild schön abschließt */
    border-radius: 20px;
    background: #121212;
    border: 1px solid rgba(255,255,255,0.08);
    transition: all 0.2s ease;
    display: flex;
    flex-direction: column;
    gap: 16px;
    height: 100%;
}

.rb-service input:checked + .rb-service-box {
    border-color: rgba(212,175,55,0.75);
    background: linear-gradient(180deg, rgba(212,175,55,0.11), rgba(212,175,55,0.04));
    box-shadow: 0 10px 20px rgba(0,0,0,0.25);
    transform: translateY(-3px); /* Hebt die Karte beim Anklicken leicht an */
}

.rb-service-header {
    display: flex;
    flex-direction: column; /* Stapelt das Bild ÜBER den Text */
    gap: 16px;
    align-items: flex-start;
}

.rb-service-img {
    width: 100%; /* Nutzt die volle Breite der Karte */
    height: 180px; /* Schön großes Format */
    border-radius: 12px;
    object-fit: cover;
    background: #222;
    border: 1px solid rgba(255,255,255,0.05);
}

.rb-service-title {
    display: block;
    color: #fff;
    font-weight: 700;
    margin-bottom: 8px;
    font-size: 22px; /* Schrift noch etwas größer */
}

.rb-service-meta {
    color: #d4af37;
    font-size: 17px;
    font-weight: 600;
}

.rb-service-desc {
    font-size: 15px;
    color: #9f9788;
    line-height: 1.6;
    margin-top: auto; /* Drückt die Beschreibung nach unten, falls Karten unterschiedlich hoch sind */
}

/* --- 4. Mitarbeiter --- */
.rb-employee-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); /* Große Karten nebeneinander */
    gap: 20px;
}

.rb-employee-card {
    position: relative;
    display: block;
    cursor: pointer;
}

.rb-employee-card input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.rb-employee-box {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding: 12px 12px 24px 12px; /* Oben weniger Padding, damit Bild bündiger abschließt */
    border-radius: 20px;
    background: #121212;
    border: 1px solid rgba(255,255,255,0.08);
    transition: all 0.2s ease;
}

.rb-employee-card input:checked + .rb-employee-box {
    border-color: rgba(212,175,55,0.8);
    background: linear-gradient(180deg, rgba(212,175,55,0.15), rgba(212,175,55,0.05));
    box-shadow: 0 10px 20px rgba(0,0,0,0.3);
    transform: translateY(-4px);
}

.rb-employee-img {
    width: 100%; /* Volle Breite der Box */
    height: 320px; /* Hochformat (Portrait) */
    border-radius: 12px;
    object-fit: cover;
    background: #222;
    margin-bottom: 16px;
}

/* Fix für das "A" (Allgemein) Bild, damit es im Hochformat nicht verzerrt */
.rb-employee-img[style*="display:flex"] {
    font-size: 60px;
}

.rb-employee-name {
    color: #fff;
    font-weight: 700;
    font-size: 22px;
    display: block;
}

.rb-employee-note {
    color: #cbbfa9;
    font-size: 15px;
    display: block;
    margin-top: 6px;
}

/* --- 5. Formulare & Slots --- */
.rb-field {
    margin-bottom: 20px;
}

.rb-label {
    display: block;
    margin-bottom: 10px;
    color: #efe7d3;
    font-size: 18px;
    font-weight: 600;
}

.rb-input,
.rb-textarea {
    width: 100%;
    padding: 16px;
    border-radius: 14px;
    border: 1px solid rgba(255,255,255,0.10);
    background: #101010;
    color: #fff !important;
    -webkit-text-fill-color: #fff !important;
    caret-color: #fff;
    font-size: 18px;
    box-sizing: border-box;
    outline: none;
    transition: border-color 0.2s;
    color-scheme: dark;
}

.rb-input::placeholder,
.rb-textarea::placeholder {
    color: #a79e8f;
    opacity: 1;
}

.rb-input:-webkit-autofill,
.rb-input:-webkit-autofill:hover,
.rb-input:-webkit-autofill:focus,
.rb-textarea:-webkit-autofill,
.rb-textarea:-webkit-autofill:hover,
.rb-textarea:-webkit-autofill:focus {
    -webkit-text-fill-color: #fff !important;
    box-shadow: 0 0 0 1000px #101010 inset !important;
    transition: background-color 9999s ease-in-out 0s;
}

.rb-input::-webkit-calendar-picker-indicator {
    filter: invert(1);
    cursor: pointer;
}

.rb-input:focus,
.rb-textarea:focus {
    border-color: rgba(212,175,55,0.65);
    box-shadow: 0 0 0 3px rgba(212,175,55,0.08);
}

.rb-slots {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
    gap: 12px;
}

.rb-slot {
    border: 1px solid rgba(255,255,255,0.08);
    background: #101010;
    color: #fff;
    border-radius: 12px;
    padding: 14px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s ease;
    font-size: 17px;
    font-weight: 500;
}

.rb-slot:hover {
    border-color: rgba(212,175,55,0.55);
    background: rgba(255,255,255,0.03);
}

.rb-slot.is-active {
    border-color: #d4af37;
    color: #111;
    background: #d4af37;
    font-weight: 700;
}

/* --- 6. Meldungen --- */
.rb-info,
.rb-error,
.rb-success {
    padding: 18px 20px;
    border-radius: 14px;
    margin-bottom: 20px;
    font-size: 16px;
    line-height: 1.5;
}

.rb-info {
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.08);
    color: #ddd4c3;
}

.rb-error {
    background: rgba(180,55,55,0.18);
    border: 1px solid rgba(221,98,98,0.28);
    color: #ffd7d7;
}

.rb-success {
    background: rgba(65,128,72,0.18);
    border: 1px solid rgba(95,172,104,0.30);
    color: #def7df;
}

.rb-success-modal {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.68);
    z-index: 10020;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 18px;
}

.rb-success-card {
    width: 100%;
    max-width: 520px;
    background: linear-gradient(180deg, #131313 0%, #0a0a0a 100%);
    border: 1px solid rgba(212,175,55,0.4);
    border-radius: 18px;
    box-shadow: 0 24px 54px rgba(0,0,0,0.5);
    padding: 24px 20px;
    text-align: center;
}

.rb-success-card h4 {
    margin: 0 0 10px;
    color: #d4af37;
    font-size: 28px;
}

.rb-success-card p {
    margin: 0;
    color: #efe7d3;
    font-size: 16px;
    line-height: 1.5;
}

.rb-success-close {
    margin-top: 16px;
    border: 1px solid rgba(212,175,55,0.45);
    color: #111;
    background: linear-gradient(180deg, #e0b941 0%, #b58b1d 100%);
    border-radius: 12px;
    padding: 10px 18px;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
}

/* --- 7. Sidebar & Zusammenfassung (Jetzt Unten) --- */
.rb-sidebar {
    width: 100%;
    margin-top: 10px;
}

.rb-summary {
    background: linear-gradient(180deg, #131313 0%, #0a0a0a 100%);
    border: 1px solid rgba(212,175,55,0.3);
    border-radius: 22px;
    padding: 34px;
    box-shadow: 0 15px 40px rgba(0,0,0,0.6);
    position: relative;
    overflow: hidden;
}

.rb-summary::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 1px;
    background: linear-gradient(90deg, transparent, rgba(212,175,55,0.75), transparent);
}

.rb-summary h3 {
    margin: 0 0 24px;
    color: #d4af37;
    font-size: 28px;
    text-align: center;
}

.rb-summary-row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 16px 0;
    border-bottom: 1px dashed rgba(255,255,255,0.15);
    color: #ddd4c3;
    font-size: 18px;
}

.rb-summary-row:last-of-type {
    border-bottom: none;
    padding-bottom: 0;
}

.rb-summary-row strong {
    color: #fff;
    text-align: right;
    max-width: 65%;
    word-break: break-word;
}

.rb-total {
    color: #d4af37 !important;
    font-size: 24px !important;
    font-weight: 700;
}

.rb-btn {
    width: 100%;
    margin-top: 30px;
    border: none;
    border-radius: 16px;
    padding: 20px;
    font-size: 22px;
    font-weight: 700;
    cursor: pointer;
    color: #111;
    background: linear-gradient(180deg, #e0b941 0%, #b58b1d 100%);
    box-shadow: 0 10px 20px rgba(212,175,55,0.2);
    transition: transform 0.2s, filter 0.2s;
    text-transform: uppercase;
}

.rb-btn:hover {
    filter: brightness(1.1);
    transform: translateY(-2px);
}

.rb-small {
    margin-top: 20px;
    color: #888;
    font-size: 15px;
    line-height: 1.5;
    text-align: center;
}

/* --- 8. Media Queries --- */
@media (max-width: 768px) {
    .rb-top {
        padding: 30px 20px 20px;
    }
    .rb-content {
        padding: 20px;
    }
    .rb-headline {
        font-size: 34px;
    }
    .rb-guidance {
        font-size: 13px;
    }
    .rb-panel {
        padding: 20px;
    }
    .rb-employee-img {
        height: 168px;
    }
    .rb-sticky-message {
        display: none;
    }
    .rb-summary {
        padding: 24px 20px;
    }
    .rb-panel h3 {
        font-size: 22px;
    }
    .rb-step-help {
        font-size: 13px;
    }
    .rb-wrap {
        padding-bottom: 96px;
    }
    .rb-sticky-bar {
        display: block;
    }
}

/* =========================================
   Kalender-Auswahl (Frontend Datepicker)
   ========================================= */

.rb-date-field {
    position: relative;
}

.rb-date-field .rb-input {
    padding-right: 52px;
    cursor: pointer;
    font-size: 17px;
    letter-spacing: 0.01em;
}

.rb-date-field .rb-date-input {
    background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="%23d4af37" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>');
    background-position: right 14px center;
    background-repeat: no-repeat;
    background-size: 24px 24px;
    opacity: 0.92;
}

.rb-date-field .rb-date-input::placeholder {
    color: #b8ae9b;
    font-size: 16px;
    letter-spacing: 0.02em;
}

.ui-datepicker.rb-datepicker-ui {
    z-index: 10050 !important;
    width: 360px;
    max-width: calc(100vw - 24px);
    padding: 12px 12px 10px;
    border: 1px solid rgba(212,175,55,0.35);
    border-radius: 14px;
    background: linear-gradient(180deg, #121212 0%, #0b0b0b 100%);
    box-shadow: 0 20px 40px rgba(0,0,0,0.45);
    color: #f3ead7;
}

.ui-datepicker.rb-datepicker-ui .ui-datepicker-header {
    position: relative;
    margin-bottom: 6px;
    padding: 4px 28px;
    background: transparent;
    border: none;
    color: #fff;
}

.ui-datepicker.rb-datepicker-ui td.ui-state-disabled span,
.ui-datepicker.rb-datepicker-ui td.rb-day-closed span {
    opacity: 0.28;
    text-decoration: line-through;
}

.ui-datepicker.rb-datepicker-ui .ui-datepicker-title {
    text-align: center;
    font-size: 21px;
    font-weight: 700;
}

.ui-datepicker.rb-datepicker-ui .ui-datepicker-prev,
.ui-datepicker.rb-datepicker-ui .ui-datepicker-next {
    position: absolute;
    top: 2px;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    border: 1px solid rgba(255,255,255,0.12);
    background: rgba(255,255,255,0.03);
    cursor: pointer;
}

.ui-datepicker.rb-datepicker-ui .ui-datepicker-prev { left: 0; }
.ui-datepicker.rb-datepicker-ui .ui-datepicker-next { right: 0; }

.ui-datepicker.rb-datepicker-ui .ui-datepicker-prev span,
.ui-datepicker.rb-datepicker-ui .ui-datepicker-next span {
    display: block;
    width: 100%;
    height: 100%;
    text-indent: -9999px;
    position: relative;
}

.ui-datepicker.rb-datepicker-ui .ui-datepicker-prev span::before,
.ui-datepicker.rb-datepicker-ui .ui-datepicker-next span::before {
    content: "";
    position: absolute;
    top: 50%;
    left: 50%;
    width: 8px;
    height: 8px;
    border-top: 2px solid #d4af37;
    border-right: 2px solid #d4af37;
    transform-origin: center;
}

.ui-datepicker.rb-datepicker-ui .ui-datepicker-prev span::before {
    transform: translate(-35%, -50%) rotate(-135deg);
}

.ui-datepicker.rb-datepicker-ui .ui-datepicker-next span::before {
    transform: translate(-65%, -50%) rotate(45deg);
}

.ui-datepicker.rb-datepicker-ui table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 6px;
    margin: 0;
}

.ui-datepicker.rb-datepicker-ui th {
    padding: 6px 0;
    font-size: 13px;
    font-weight: 700;
    color: #cbbfa9;
    text-transform: uppercase;
}

.ui-datepicker.rb-datepicker-ui td {
    padding: 0;
}

.ui-datepicker.rb-datepicker-ui td a,
.ui-datepicker.rb-datepicker-ui td span {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 42px;
    height: 42px;
    border-radius: 10px;
    border: 1px solid transparent;
    color: #fff;
    text-decoration: none;
    font-size: 18px;
    font-weight: 600;
}

.ui-datepicker.rb-datepicker-ui td a:hover {
    border-color: rgba(212,175,55,0.55);
    background: rgba(212,175,55,0.12);
}

.ui-datepicker.rb-datepicker-ui td.ui-datepicker-current-day a {
    border-color: #d4af37;
    background: #d4af37;
    color: #111;
}

.ui-datepicker.rb-datepicker-ui td.ui-datepicker-today a {
    box-shadow: inset 0 0 0 1px rgba(212,175,55,0.55);
}

.ui-datepicker.rb-datepicker-ui .ui-state-disabled span {
    opacity: 0.35;
}

.ui-datepicker.rb-datepicker-ui .ui-datepicker-buttonpane {
    margin-top: 6px;
    padding-top: 8px;
    border-top: 1px solid rgba(255,255,255,0.08);
}

.ui-datepicker.rb-datepicker-ui .ui-datepicker-buttonpane button {
    border: 1px solid rgba(212,175,55,0.4);
    background: rgba(212,175,55,0.08);
    color: #e9dcbd;
    border-radius: 8px;
    padding: 6px 10px;
    font-size: 12px;
    cursor: pointer;
}

@media (max-width: 480px) {
    .ui-datepicker.rb-datepicker-ui {
        width: calc(100vw - 20px);
        max-width: calc(100vw - 20px);
        padding: 10px 8px 8px;
    }
    .ui-datepicker.rb-datepicker-ui table {
        border-spacing: 4px;
    }
    .ui-datepicker.rb-datepicker-ui td a,
    .ui-datepicker.rb-datepicker-ui td span {
        width: 36px;
        height: 36px;
        font-size: 16px;
    }
}




/* =========================================
   Zahlungsicons in der Zusammenfassung
   ========================================= */

.rb-payment-methods {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 8px;
}

.rb-payment-icons {
    display: flex;
    gap: 12px;
    color: #9f9788; /* Dezentes Grau-Gold passend zur Beschreibung */
    align-items: center;
}

.rb-payment-icons svg {
    width: 26px;
    height: 26px;
}

.rb-twint-icon {
    font-weight: 800;
    font-size: 13px;
    letter-spacing: 0.5px;
    border: 2px solid currentColor;
    border-radius: 6px;
    padding: 0 5px;
    line-height: 22px;
    height: 26px;
    box-sizing: border-box;
    display: flex;
    align-items: center;
}

.rb-download-btn {
    width: 100%;
    margin-top: 14px;
    background: transparent;
    border: 1px solid rgba(212,175,55,0.4);
    color: #d4af37;
    padding: 16px;
    border-radius: 16px;
    font-size: 18px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.rb-download-btn:hover {
    background: rgba(212,175,55,0.1);
}


            </style>

            <div class="rb-shell">
                <div class="rb-top">
                    <span class="rb-badge"><?php echo esc_html($shop['name']); ?></span>
                    <h2 class="rb-headline">Termin buchen</h2>
                    <?php if ($receipt) : ?>
                        <p class="rb-sub">Dein Termin ist gebucht. Die Bestätigung geht per E-Mail raus.</p>
                    <?php else : ?>
                        <p class="rb-sub">Wähle eine Dienstleistung, deinen Barber und eine freie Zeit. Zahlung erfolgt vor Ort.</p>
                        <div id="rb_guidance" class="rb-guidance">Wähle eine Dienstleistung.</div>
                    <?php endif; ?>
                </div>

                <?php if ($receipt) : ?>
                    <div class="rb-content">
                        <div class="rb-panel rb-confirm">
                            <h3>Termin gebucht</h3>
                            <p>Danke. Die Angaben stehen auch in der E-Mail. Schau bei Bedarf im Spam-Ordner nach.</p>
                            <div class="rb-summary">
                                <h3>Zusammenstellung</h3>
                                <div class="rb-summary-row"><span>Dienstleistung</span><strong><?php echo esc_html($receipt['services']); ?></strong></div>
                                <div class="rb-summary-row"><span>Dauer</span><strong><?php echo esc_html($receipt['duration']); ?> Min</strong></div>
                                <div class="rb-summary-row"><span>Preis</span><strong><?php echo esc_html($receipt['price']); ?></strong></div>
                                <div class="rb-summary-row"><span>Barber</span><strong><?php echo esc_html($receipt['employee']); ?></strong></div>
                                <div class="rb-summary-row"><span>Datum</span><strong><?php echo esc_html($receipt['date']); ?></strong></div>
                                <div class="rb-summary-row"><span>Uhrzeit</span><strong><?php echo esc_html($receipt['time']); ?></strong></div>
                            </div>
                            <div class="rb-confirm-actions">
                                <button type="button" class="rb-confirm-btn is-primary" id="rb_download_image">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><path d="M21 15l-5-5L5 21"></path></svg>
                                    <span>Termin als Bild speichern</span>
                                </button>
                                <a class="rb-confirm-btn" href="<?php echo esc_url(home_url('/')); ?>">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11l9-8 9 8"></path><path d="M5 10v10h14V10"></path></svg>
                                    <span>Zurück zur Startseite</span>
                                </a>
                                <a class="rb-confirm-btn" href="<?php echo esc_url($book_again_url); ?>">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"></rect><path d="M16 2v4M8 2v4M3 10h18M12 14v4M10 16h4"></path></svg>
                                    <span>Weiteren Termin buchen</span>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php else : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="rewan-booking-form">
                    <input type="hidden" name="action" value="rewan_booking_submit">
                    <input type="hidden" name="selected_slot" id="rb_selected_slot" value="">
                    <input type="hidden" name="employee_id" id="rb_employee_id_hidden" value="">
                    <?php wp_nonce_field('rewan_booking_submit_nonce', 'rewan_booking_submit_nonce'); ?>

                    <div class="rb-content">
                        <div>
                            <?php if ($success) : ?>
                                <div class="rb-success">Dein Termin wurde erfolgreich gebucht.</div>
                            <?php endif; ?>

                            <?php if (!empty($error)) : ?>
                                <div class="rb-error"><?php echo esc_html($this->get_error_message($error)); ?></div>
                            <?php endif; ?>

                            <div class="rb-panel" id="rb-step-services">
                                <h3>1. Dienstleistungen</h3>
                                <p class="rb-step-help">Wähle genau eine Dienstleistung.</p>
                                <div class="rb-service-grid">
                                    <?php foreach ($services as $service) : ?>
                                        <label class="rb-service">
                                            <input type="radio" name="service_ids[]" value="<?php echo esc_attr($service['id']); ?>" data-price="<?php echo esc_attr($service['price']); ?>" data-duration="<?php echo esc_attr($service['duration']); ?>" data-name="<?php echo esc_attr($service['name']); ?>" class="rb-service-checkbox">
                                            <span class="rb-service-box">
                                                <div class="rb-service-header">
                                                    <?php if (!empty($service['image_url'])) : ?>
                                                        <img class="rb-service-img" src="<?php echo esc_url($service['image_url']); ?>" alt="">
                                                    <?php endif; ?>
                                                    <div>
                                                        <span class="rb-service-title"><?php echo esc_html($service['name']); ?></span>
                                                        <span class="rb-service-meta"><?php echo esc_html(number_format((float) $service['price'], 2, '.', '\'')); ?> CHF · <?php echo esc_html($service['duration']); ?> Min</span>
                                                    </div>
                                                </div>
                                                <?php if (!empty($service['description'])) : ?>
                                                    <span class="rb-service-desc"><?php echo esc_html($service['description']); ?></span>
                                                <?php endif; ?>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <div id="rb_selected_services" class="rb-selected-services"></div>
                                <button type="button" class="rb-next-btn" id="rb-next-to-employee" hidden>Weiter zu Mitarbeiter</button>
                            </div>

                            <div class="rb-panel" id="rb-step-employee">
                                <button type="button" class="rb-back-btn" data-step="services">Zurück</button>
                                <h3>2. Mitarbeiter wählen</h3>
                                <p class="rb-step-help">Wähle einen Barber oder „Egal wer“.</p>
                                <div class="rb-employee-grid">
                                    <?php foreach ($employees as $employee) : ?>
                                        <label class="rb-employee-card">
                                            <input type="radio" name="rb_employee_choice" value="<?php echo esc_attr($employee['id']); ?>" class="rb-employee-radio" data-name="<?php echo esc_attr($employee['name']); ?>">
                                            <span class="rb-employee-box">
                                                <?php if (!empty($employee['image_url'])) : ?>
                                                    <img class="rb-employee-img" src="<?php echo esc_url($employee['image_url']); ?>" alt="<?php echo esc_attr($employee['name']); ?>">
                                                <?php else : ?>
                                                    <div class="rb-employee-img" style="display:flex;align-items:center;justify-content:center;color:#d4af37;font-weight:700;font-size:60px;"><?php echo esc_html(strtoupper(substr($employee['name'], 0, 1))); ?></div>
                                                <?php endif; ?>
                                                <span>
                                                    <span class="rb-employee-name"><?php echo esc_html($employee['name']); ?></span>
                                                    <span class="rb-employee-note">Direkt bei diesem Barber</span>
                                                </span>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>

                                    <label class="rb-employee-card">
                                        <input type="radio" name="rb_employee_choice" value="general" class="rb-employee-radio">
                                        <span class="rb-employee-box">
                                            <div class="rb-employee-img" style="display:flex;align-items:center;justify-content:center;color:#d4af37;font-weight:700;font-size:32px;">Egal</div>
                                            <span>
                                                <span class="rb-employee-name">Egal wer</span>
                                                <span class="rb-employee-note">Erster freier Barber</span>
                                            </span>
                                        </span>
                                    </label>
                                </div>
                                <button type="button" class="rb-next-btn" id="rb-next-to-slots" hidden>Weiter zu Datum und Uhrzeit</button>
                            </div>

                            <div class="rb-panel" id="rb-step-slots">
                                <button type="button" class="rb-back-btn" data-step="employee">Zurück</button>
                                <h3>3. Datum & freie Zeiten</h3>
                                <p class="rb-step-help">Datum wählen und eine freie Zeit antippen. Geschlossene Tage sind ausgegraut.</p>
                                <div class="rb-field rb-date-field">
                                    <label class="rb-label" for="booking_date_display">Datum</label>
                                    <input type="text" id="booking_date_display" class="rb-input rb-date-input" inputmode="none" autocomplete="off" value="<?php echo esc_attr(date_i18n('d/m/Y', strtotime($min_date))); ?>" placeholder="dd/mm/jjjj" required>
                                    <input type="hidden" id="booking_date" name="booking_date" min="<?php echo esc_attr($min_date); ?>" data-min-date="<?php echo esc_attr($min_date); ?>" value="<?php echo esc_attr($min_date); ?>" required>
                                </div>

                                <div class="rb-info" id="rb-slot-info">
                                    Wähle zuerst Dienstleistung, Mitarbeiter und Datum. Danach zeigen wir dir nur freie Slots.
                                </div>

                                <div id="rb-slots" class="rb-slots"></div>
                                <p class="rb-assign-note" id="rb-assign-note" hidden></p>
                                <button type="button" class="rb-next-btn" id="rb-next-to-contact" hidden>Weiter zu deinen Daten</button>
                            </div>

                        <div class="rb-panel" id="rb-step-contact">
                            <button type="button" class="rb-back-btn" data-step="slots">Zurück</button>
                            <h3>4. Deine Daten</h3>
                            <p class="rb-step-help">Zum Schluss Name, E-Mail und Telefon ausfüllen.</p>
                            <div class="rb-field">
                                <label class="rb-label" for="customer_name">Name</label>
                                <input type="text" id="customer_name" name="customer_name" class="rb-input" placeholder="Dein Vor- und Nachname" required>
                            </div>
                            <div class="rb-field">
                                <label class="rb-label" for="customer_email">E-Mail</label>
                                <input type="email" id="customer_email" name="customer_email" class="rb-input" placeholder="beispiel@email.ch" required>
                            </div>
                            <div class="rb-field">
                                <label class="rb-label" for="customer_phone">Telefon</label>
                                <input type="text" id="customer_phone" name="customer_phone" class="rb-input" placeholder="079 123 45 67" required>
                            </div>
                            <div class="rb-field">
                                <label class="rb-label" for="customer_notes">Notiz</label>
                                <textarea id="customer_notes" name="customer_notes" rows="4" class="rb-textarea" placeholder="Besondere Wünsche oder Anmerkungen (Optional)"></textarea>
                            </div>
                        </div>
                        </div>

                        <div class="rb-sidebar">
                            <div class="rb-summary">
                                <h3>Zusammenstellung</h3>

                                <div class="rb-summary-row">
                                    <span>Dienstleistung</span>
                                    <strong id="rb_summary_services">Noch nichts gewählt</strong>
                                </div>

                                <div class="rb-summary-row">
                                    <span>Dauer</span>
                                    <strong id="rb_summary_duration">0 Min</strong>
                                </div>

                                <div class="rb-summary-row">
                                    <span>Preis</span>
                                    <strong id="rb_summary_price">0.00 CHF</strong>
                                </div>

                                <div class="rb-summary-row">
                                    <span>Barber</span>
                                    <strong id="rb_summary_employee">-</strong>
                                </div>

                                <div class="rb-summary-row">
                                    <span>Datum</span>
                                    <strong id="rb_summary_date">-</strong>
                                </div>

                                <div class="rb-summary-row">
                                    <span>Uhrzeit</span>
                                    <strong id="rb_summary_time">-</strong>
                                </div>

                                <div class="rb-summary-row" style="align-items: flex-start;">
                                    <span>Bezahlung</span>
                                    <div class="rb-payment-methods">
                                        <strong class="rb-total">vor Ort</strong>
                                        <div class="rb-payment-icons">
                                            <svg title="Barzahlung" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2"></rect><circle cx="12" cy="12" r="2"></circle><path d="M6 12h.01M18 12h.01"></path></svg>
                                            
                                            <svg title="Kartenzahlung" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
                                            
                                            <div title="TWINT" class="rb-twint-icon">TWINT</div>
                                        </div>
                                    </div>
                                </div>

                                <p class="rb-small">
                                    Der Slot wird beim Abschicken nochmals geprüft. So vermeiden wir Doppelbuchungen sauber.
                                </p>

                                <button type="submit" class="rb-btn">Termin verbindlich buchen</button>
                                <button type="button" class="rb-download-btn" id="rb_download_image" style="<?php echo $success ? 'display: block;' : 'display: none;'; ?>">
                                    <?php echo $success ? 'Du hast erfolgreich deinen Termin gebucht - als Bild zusammenstellen' : 'Zusammenstellung als Bild speichern'; ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
                <?php endif; ?>
            </div>
            <?php if ($success && !$receipt) : ?>
                <div id="rb_success_modal" class="rb-success-modal">
                    <div class="rb-success-card">
                        <h4>Termin erfolgreich gebucht</h4>
                        <p>Vielen Dank! Deine Buchung wurde gespeichert und bestätigt. Du erhältst in Kürze eine E-Mail mit allen Terminangaben. Bitte prüfe bei Bedarf auch deinen Spam-Ordner.</p>
                        <button type="button" id="rb_success_close" class="rb-success-close">OK</button>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (!$receipt) : ?>
            <div id="rb_sticky_bar" class="rb-sticky-bar">
                <div class="rb-sticky-top">
                    <span id="rb_sticky_services" class="rb-sticky-services">Noch nichts gewählt</span>
                    <span id="rb_sticky_meta" class="rb-sticky-meta">0 Min · 0.00 CHF</span>
                </div>
                <div id="rb_sticky_message" class="rb-sticky-message">Wähle eine Dienstleistung.</div>
            </div>
            <?php endif; ?>

            <script>
                const bookingWasSuccessful = <?php echo $success ? 'true' : 'false'; ?>;
                document.addEventListener('DOMContentLoaded', function () {
                    const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                    const ajaxNonce = <?php echo wp_json_encode($ajax_nonce); ?>;
                    let stepOverride = '';
                    let slotAssignees = {};
                    let closedWeekdays = [];
                    let closedDates = [];
                    let holidayJump = false;
                    let allowScroll = false;

                    const serviceCheckboxes = document.querySelectorAll('.rb-service-checkbox');
                    const employeeRadios = document.querySelectorAll('.rb-employee-radio');
                    const employeeHidden = document.getElementById('rb_employee_id_hidden');
                    const bookingDate = document.getElementById('booking_date');
                    const bookingDateDisplay = document.getElementById('booking_date_display');
                    const slotWrap = document.getElementById('rb-slots');
                    const slotInfo = document.getElementById('rb-slot-info');
                    const selectedSlotInput = document.getElementById('rb_selected_slot');
                    const submitBtn = document.querySelector('.rb-btn');

                    const panelEmployee = document.getElementById('rb-step-employee');
                    const panelSlots = document.getElementById('rb-step-slots');
                    const panelContact = document.getElementById('rb-step-contact');

                    const nextToEmployee = document.getElementById('rb-next-to-employee');
                    const nextToSlots = document.getElementById('rb-next-to-slots');
                    const nextToContact = document.getElementById('rb-next-to-contact');

                    const summaryServices = document.getElementById('rb_summary_services');
                    const summaryDuration = document.getElementById('rb_summary_duration');
                    const summaryPrice = document.getElementById('rb_summary_price');
                    const summaryEmployee = document.getElementById('rb_summary_employee');
                    const summaryDate = document.getElementById('rb_summary_date');
                    const summaryTime = document.getElementById('rb_summary_time');
                    const guidance = document.getElementById('rb_guidance');
                    const stickyMessage = document.getElementById('rb_sticky_message');
                    const selectedServicesWrap = document.getElementById('rb_selected_services');
                    const stickyServices = document.getElementById('rb_sticky_services');
                    const stickyMeta = document.getElementById('rb_sticky_meta');
                    const successModal = document.getElementById('rb_success_modal');
                    const successCloseBtn = document.getElementById('rb_success_close');

                    // Falls das Formular auf einer Seite nicht vorhanden ist, abbrechen.
                    if (!bookingDate || !bookingDateDisplay || !slotWrap || !slotInfo || !selectedSlotInput || !employeeHidden) {
                        return;
                    }

                    if (successModal) {
                        if (successCloseBtn) {
                            successCloseBtn.addEventListener('click', function () {
                                successModal.style.display = 'none';
                            });
                        }

                        successModal.addEventListener('click', function (event) {
                            if (event.target === successModal) {
                                successModal.style.display = 'none';
                            }
                        });
                    }

                    function getSelectedServiceIds() {
                        return Array.from(serviceCheckboxes)
                            .filter(cb => cb.checked)
                            .map(cb => cb.value);
                    }

                    function getSelectedEmployeeValue() {
                        const checked = document.querySelector('.rb-employee-radio:checked');
                        return checked ? checked.value : '';
                    }

                    function getSelectedEmployeeText() {
                        const checked = document.querySelector('.rb-employee-radio:checked');
                        if (!checked) {
                            return '-';
                        }
                        if (checked.value === 'general') {
                            if (selectedSlotInput.value && slotAssignees[selectedSlotInput.value]) {
                                return slotAssignees[selectedSlotInput.value];
                            }
                            return 'Egal wer';
                        }
                        return checked.dataset.name || '-';
                    }

                    function hasSelectedService() {
                        return getSelectedServiceIds().length > 0;
                    }

                    function hasSelectedEmployee() {
                        return !!getSelectedEmployeeValue();
                    }

                    function hasSelectedDate() {
                        return !!bookingDate.value;
                    }

                    function hasSelectedSlot() {
                        return !!selectedSlotInput.value;
                    }

                    function setLocked(panel, locked) {
                        if (!panel) {
                            return;
                        }
                        panel.classList.toggle('is-locked', !!locked);
                    }

                    function smoothScrollTo(element) {
                        if (!element) {
                            return;
                        }
                        element.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }

                    function updateStepFlow() {
                        const serviceReady = hasSelectedService();
                        const employeeReady = hasSelectedEmployee();
                        const dateReady = hasSelectedDate();
                        const slotReady = hasSelectedSlot();

                        setLocked(panelEmployee, !serviceReady);
                        setLocked(panelSlots, !(serviceReady && employeeReady));
                        setLocked(panelContact, !(serviceReady && employeeReady && dateReady && slotReady));

                        nextToEmployee.hidden = !serviceReady;
                        nextToSlots.hidden = !(serviceReady && employeeReady);
                        nextToContact.hidden = !(serviceReady && employeeReady && dateReady && slotReady);

                        if (submitBtn) {
                            submitBtn.disabled = !(serviceReady && employeeReady && dateReady && slotReady);
                            submitBtn.style.opacity = submitBtn.disabled ? '0.55' : '1';
                            submitBtn.style.cursor = submitBtn.disabled ? 'not-allowed' : 'pointer';
                        }
                    }

                    function getCurrentStep() {
                        if (stepOverride) {
                            return stepOverride;
                        }
                        if (!hasSelectedService()) {
                            return 'services';
                        }
                        if (!hasSelectedEmployee()) {
                            return 'employee';
                        }
                        return 'slots';
                    }

                    function updateVisibleStep() {
                        const currentStep = getCurrentStep();
                        const stepMap = {
                            services: document.getElementById('rb-step-services'),
                            employee: panelEmployee,
                            slots: panelSlots,
                            contact: panelContact
                        };

                        Object.keys(stepMap).forEach(function (key) {
                            const panel = stepMap[key];
                            if (!panel) {
                                return;
                            }
                            panel.classList.toggle('is-hidden', key !== currentStep);
                        });

                        const activePanel = stepMap[currentStep];
                        if (allowScroll && activePanel) {
                            smoothScrollTo(activePanel);
                        }
                    }

                    function updateSelectedServicesChips(names) {
                        if (!selectedServicesWrap) {
                            return;
                        }
                        selectedServicesWrap.innerHTML = '';
                        if (!names.length) {
                            return;
                        }
                        names.forEach(function (name) {
                            const chip = document.createElement('span');
                            chip.className = 'rb-selected-chip';
                            chip.textContent = name;
                            selectedServicesWrap.appendChild(chip);
                        });
                    }

                    function updateGuidanceText(names) {
                        if (!guidance) {
                            if (!stickyMessage) {
                                return;
                            }
                        }

                        function setGuidanceMessage(message) {
                            if (guidance) {
                                guidance.textContent = message;
                            }
                            if (stickyMessage) {
                                stickyMessage.textContent = message;
                            }
                        }
                        const serviceReady = hasSelectedService();
                        const employeeText = getSelectedEmployeeText();
                        const dateReady = hasSelectedDate();
                        const slotReady = hasSelectedSlot();

                        if (!serviceReady) {
                            setGuidanceMessage('Wähle eine Dienstleistung.');
                            return;
                        }
                        if (!hasSelectedEmployee()) {
                            setGuidanceMessage(names.join(', ') + '. Jetzt den Barber wählen.');
                            return;
                        }
                        if (!dateReady || !slotReady) {
                            setGuidanceMessage(names.join(', ') + ' bei ' + employeeText + '. Jetzt Datum und eine freie Zeit wählen.');
                            return;
                        }
                        setGuidanceMessage(names.join(', ') + ' bei ' + employeeText + ' um ' + selectedSlotInput.value + '. Jetzt Name, E-Mail und Telefon eintragen.');
                    }

                    function updateStickySummary(names, totalDuration, totalPrice, dateText, timeText, employeeText) {
                        if (!stickyServices || !stickyMeta) {
                            return;
                        }
                        stickyServices.textContent = names.length ? names.join(', ') : 'Noch nichts gewählt';
                        const datePart = dateText && dateText !== '-' ? dateText : '-';
                        const timePart = timeText && timeText !== '-' ? timeText : '-';
                        const employeePart = employeeText && employeeText !== '-' ? employeeText : 'Barber offen';
                        stickyMeta.textContent = totalDuration + ' Min · ' + totalPrice.toFixed(2) + ' CHF · ' + datePart + ' · ' + timePart + ' · ' + employeePart;
                    }

                    loadSlots();

                    function updateSummary() {
                        let totalPrice = 0;
                        let totalDuration = 0;
                        let names = [];

                        serviceCheckboxes.forEach(function (checkbox) {
                            if (checkbox.checked) {
                                totalPrice += parseFloat(checkbox.dataset.price || 0);
                                totalDuration += parseInt(checkbox.dataset.duration || 0, 10);
                                names.push(checkbox.dataset.name);
                            }
                        });

                        summaryServices.textContent = names.length ? names.join(', ') : 'Noch nichts gewählt';
                        updateSelectedServicesChips(names);
                        summaryDuration.textContent = totalDuration + ' Min';
                        summaryPrice.textContent = totalPrice.toFixed(2) + ' CHF';
                        summaryEmployee.textContent = getSelectedEmployeeText();
                        let formattedDate = '-';
                        if (bookingDate.value) {
                            const dateObj = new Date(bookingDate.value);
                            const days = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
                            const dayName = days[dateObj.getDay()];
                            const day = String(dateObj.getDate()).padStart(2, '0');
                            const month = String(dateObj.getMonth() + 1).padStart(2, '0');
                            const year = dateObj.getFullYear();
                            formattedDate = dayName + ', ' + day + '.' + month + '.' + year;
                            summaryDate.textContent = formattedDate;
                        } else {
                            summaryDate.textContent = '-';
                        }
                        const selectedTime = selectedSlotInput.value ? selectedSlotInput.value : '-';
                        summaryTime.textContent = selectedTime;
                        updateStickySummary(names, totalDuration, totalPrice, formattedDate, selectedTime, getSelectedEmployeeText());

                        employeeHidden.value = getSelectedEmployeeValue();
                        const assignNote = document.getElementById('rb-assign-note');
                        if (assignNote) {
                            const picked = getSelectedEmployeeValue() === 'general' && selectedSlotInput.value && slotAssignees[selectedSlotInput.value];
                            assignNote.hidden = !picked;
                            assignNote.textContent = picked ? ('Dein Barber: ' + slotAssignees[selectedSlotInput.value] + '. Das ist der erste freie an dieser Zeit.') : '';
                        }
                        updateGuidanceText(names);
                        updateStepFlow();
                        updateVisibleStep();
                    }

                    function resetSlots(message) {
                        slotWrap.innerHTML = '';
                        selectedSlotInput.value = '';
                        summaryTime.textContent = '-';
                        if (message) {
                            slotInfo.textContent = message;
                        }
                        updateSummary();
                    }

                    function renderSlots(slots, emptyMessage) {
                        slotWrap.innerHTML = '';
                        selectedSlotInput.value = '';
                        summaryTime.textContent = '-';
                        updateSummary();

                        if (!slots.length) {
                            slotInfo.textContent = emptyMessage || 'An diesem Tag ist leider nichts mehr frei.';
                            holidayJump = false;
                            updateStepFlow();
                            return;
                        }

                        slotInfo.textContent = holidayJump
                            ? 'Wegen Ferien oder Schliessung liegt der nächste freie Tag hier. Wähle eine Uhrzeit.'
                            : 'Wähle eine freie Zeit.';
                        holidayJump = false;

                        slots.forEach(function (slot) {
                            const btn = document.createElement('button');
                            btn.type = 'button';
                            btn.className = 'rb-slot';
                            btn.textContent = slot;

                            btn.addEventListener('click', function () {
                                document.querySelectorAll('.rb-slot').forEach(function (el) {
                                    el.classList.remove('is-active');
                                });
                                btn.classList.add('is-active');
                                selectedSlotInput.value = slot;
                                updateSummary();
                            });

                            slotWrap.appendChild(btn);
                        });
                    }

                    async function loadSlots() {
                        const serviceIds = getSelectedServiceIds();
                        const employeeId = getSelectedEmployeeValue();
                        const date = bookingDate.value;

                        updateSummary();

                        if (!serviceIds.length || !employeeId || !date) {
                            resetSlots('Wähle zuerst Dienstleistung, Mitarbeiter und Datum. Danach zeigen wir dir nur freie Slots.');
                            updateStepFlow();
                            return;
                        }

                        slotInfo.textContent = 'Freie Slots werden geladen...';
                        slotWrap.innerHTML = '';

                        const formData = new URLSearchParams();
                        formData.append('action', 'rewan_booking_get_slots');
                        formData.append('nonce', ajaxNonce);
                        formData.append('employee_id', employeeId);
                        formData.append('booking_date', date);
                        serviceIds.forEach(id => formData.append('service_ids[]', id));

                        try {
                            const response = await fetch(ajaxUrl, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                                },
                                body: formData.toString()
                            });

                            const data = await response.json();

                            if (!data || !data.success) {
                                resetSlots(data && data.data && data.data.message ? data.data.message : 'Slots konnten nicht geladen werden.');
                                updateStepFlow();
                                return;
                            }

                            slotAssignees = data.data.assignees || {};
                            renderSlots(data.data.slots || [], data.data.message || '');
                        } catch (error) {
                            resetSlots('Slots konnten nicht geladen werden.');
                            updateStepFlow();
                        }
                    }

                    serviceCheckboxes.forEach(function (checkbox) {
                        checkbox.addEventListener('change', function () {
                            stepOverride = 'employee';
                            loadSlots();
                        });
                    });

                    employeeRadios.forEach(function (radio) {
                        radio.addEventListener('change', function () {
                            stepOverride = 'slots';
                            loadClosedDays(true).then(function (state) {
                                if (state === 'blocked') {
                                    resetSlots('In den nächsten Monaten ist kein freier Termin.');
                                    return;
                                }
                                loadSlots();
                            });
                        });
                    });

                    bookingDate.addEventListener('change', function () {
                        if (stepOverride === 'contact') {
                            stepOverride = 'slots';
                        }
                        loadSlots();
                    });
                    
                    function initFrontendDatepicker() {
                        if (!window.jQuery || !window.jQuery.fn || typeof window.jQuery.fn.datepicker !== 'function') {
                            return;
                        }
                        const $date = window.jQuery(bookingDateDisplay);
                        if ($date.hasClass('hasDatepicker')) {
                            return;
                        }

                        let minDateOpt = 0;
                        const minRaw = bookingDate.getAttribute('data-min-date') || bookingDate.getAttribute('min') || '';
                        if (minRaw) {
                            try {
                                minDateOpt = window.jQuery.datepicker.parseDate('yy-mm-dd', minRaw);
                            } catch (_err) {
                                minDateOpt = 0;
                            }
                        }

                        $date.datepicker({
                            dateFormat: 'dd/mm/yy',
                            altField: '#booking_date',
                            altFormat: 'yy-mm-dd',
                            minDate: minDateOpt,
                            maxDate: 120,
                            firstDay: 1,
                            beforeShowDay: function (date) {
                                const week = date.getDay() === 0 ? 7 : date.getDay();
                                const iso = date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
                                if (closedWeekdays.indexOf(week) !== -1 || closedDates.indexOf(iso) !== -1) {
                                    return [false, 'rb-day-closed', 'Geschlossen'];
                                }
                                return [true, '', ''];
                            },
                            monthNames: ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'],
                            monthNamesShort: ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'],
                            dayNames: ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'],
                            dayNamesShort: ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'],
                            // jQuery UI expects Sunday-first order and rotates by firstDay.
                            // Keeping this array Monday-first shifts labels by one day.
                            dayNamesMin: ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'],
                            prevText: 'Zurück',
                            nextText: 'Weiter',
                            currentText: 'Heute',
                            showOtherMonths: true,
                            selectOtherMonths: true,
                            showButtonPanel: true,
                            beforeShow: function (_input, inst) {
                                setTimeout(function () {
                                    if (inst && inst.dpDiv) {
                                        inst.dpDiv.addClass('rb-datepicker-ui');
                                    }
                                }, 0);
                            },
                            onSelect: function () {
                                bookingDate.dispatchEvent(new Event('change', { bubbles: true }));
                            }
                        });

                        // Falls bereits ein ISO-Datum gesetzt ist, in DD/MM/JJJJ anzeigen.
                        if (bookingDate.value) {
                            try {
                                const iso = bookingDate.value.split('-');
                                if (iso.length === 3) {
                                    bookingDateDisplay.value = iso[2] + '/' + iso[1] + '/' + iso[0];
                                }
                            } catch (_err) {
                                // ignore
                            }
                        }

                        bookingDateDisplay.addEventListener('focus', function () {
                            $date.datepicker('show');
                        });
                        bookingDateDisplay.addEventListener('click', function () {
                            $date.datepicker('show');
                        });
                    }

                    function dateIsClosed(iso) {
                        if (!iso) {
                            return false;
                        }
                        const parts = iso.split('-');
                        if (parts.length !== 3) {
                            return false;
                        }
                        const date = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
                        const week = date.getDay() === 0 ? 7 : date.getDay();
                        return closedWeekdays.indexOf(week) !== -1 || closedDates.indexOf(iso) !== -1;
                    }

                    function moveOffClosedDate() {
                        if (!bookingDate.value || !dateIsClosed(bookingDate.value) || !window.jQuery || !window.jQuery.fn.datepicker) {
                            return false;
                        }
                        const start = window.jQuery.datepicker.parseDate('yy-mm-dd', bookingDate.value);
                        for (let i = 1; i <= 120; i++) {
                            const next = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
                            const iso = next.getFullYear() + '-' + String(next.getMonth() + 1).padStart(2, '0') + '-' + String(next.getDate()).padStart(2, '0');
                            if (!dateIsClosed(iso)) {
                                window.jQuery(bookingDateDisplay).datepicker('setDate', next);
                                return true;
                            }
                        }
                        return false;
                    }

                    function applyIsoDate(iso) {
                        const parts = String(iso).split('-');
                        if (parts.length !== 3 || !window.jQuery || !window.jQuery.fn.datepicker) {
                            return false;
                        }
                        const date = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
                        window.jQuery(bookingDateDisplay).datepicker('setDate', date);
                        return true;
                    }

                    async function loadClosedDays(announce) {
                        const formData = new URLSearchParams();
                        formData.append('action', 'rewan_booking_closed_days');
                        formData.append('nonce', ajaxNonce);
                        formData.append('employee_id', getSelectedEmployeeValue());
                        formData.append('booking_date', bookingDate.value || '');
                        getSelectedServiceIds().forEach(function (id) {
                            formData.append('service_ids[]', id);
                        });
                        let nextDate = '';
                        try {
                            const response = await fetch(ajaxUrl, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                                body: formData.toString()
                            });
                            const data = await response.json();
                            if (data && data.success && data.data) {
                                closedWeekdays = (data.data.weekdays || []).map(function (value) { return Number(value); });
                                closedDates = data.data.dates || [];
                                nextDate = data.data.next_date || '';
                                if (window.jQuery && window.jQuery.fn.datepicker) {
                                    window.jQuery(bookingDateDisplay).datepicker('refresh');
                                }
                            }
                        } catch (error) {
                            closedWeekdays = [];
                            closedDates = [];
                        }
                        if (nextDate && nextDate !== bookingDate.value && applyIsoDate(nextDate)) {
                            if (announce) {
                                holidayJump = true;
                            }
                            return 'moved';
                        }
                        if (dateIsClosed(bookingDate.value)) {
                            if (moveOffClosedDate()) {
                                if (announce) {
                                    holidayJump = true;
                                }
                                return 'moved';
                            }
                            return 'blocked';
                        }
                        return 'same';
                    }

                    initFrontendDatepicker();
                    document.querySelectorAll('.rb-back-btn').forEach(function (button) {
                        button.addEventListener('click', function () {
                            stepOverride = button.getAttribute('data-step') || '';
                            allowScroll = true;
                            updateVisibleStep();
                        });
                    });
                    if (nextToEmployee) {
                        nextToEmployee.addEventListener('click', function () {
                            stepOverride = 'employee';
                            allowScroll = true;
                            updateVisibleStep();
                        });
                    }
                    if (nextToSlots) {
                        nextToSlots.addEventListener('click', function () {
                            stepOverride = 'slots';
                            allowScroll = true;
                            updateVisibleStep();
                        });
                    }
                    if (nextToContact) {
                        nextToContact.addEventListener('click', function () {
                            stepOverride = 'contact';
                            allowScroll = true;
                            updateVisibleStep();
                        });
                    }

                    updateSummary();
                    updateStepFlow();
                    updateVisibleStep();
                    allowScroll = true;
                    loadClosedDays();
                });



                                if (bookingWasSuccessful) {
                                const script = document.createElement('script');
                                script.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
                                document.head.appendChild(script);
                                }

                                // Funktion zum Download
                                function setupDownload() {
                                    const downloadBtn = document.getElementById('rb_download_image');
                                    if (!downloadBtn) return;

                                    // Download nur nach erfolgreicher Buchung erlauben.
                                    if (!bookingWasSuccessful) {
                                        downloadBtn.style.display = 'none';
                                        return;
                                    }
                                    if (!downloadBtn.classList.contains('rb-confirm-btn')) {
                                        downloadBtn.style.display = 'block';
                                    }

                                    downloadBtn.addEventListener('click', function() {
                                        const summaryElement = document.querySelector('.rb-summary');
                                        
                                        // Buttons für das Foto verstecken
                                        const actionButtons = document.querySelectorAll('.rb-btn, .rb-download-btn, .rb-small, .rb-again');
                                        actionButtons.forEach(btn => btn.style.visibility = 'hidden');
                                        
                                        html2canvas(summaryElement, {
                                            backgroundColor: '#131313',
                                            scale: 2
                                        }).then(canvas => {
                                            actionButtons.forEach(btn => btn.style.visibility = 'visible');
                                            const link = document.createElement('a');
                                            link.download = 'Termin-Rewan.png';
                                            link.href = canvas.toDataURL('image/png');
                                            link.click();
                                        }).catch(err => {
                                            console.error("Fehler beim Erstellen des Bildes:", err);
                                            actionButtons.forEach(btn => btn.style.visibility = 'visible');
                                        });
                                    });
                                }

                                // Ausführen
                                if (document.readyState === 'loading') {
                                    document.addEventListener('DOMContentLoaded', setupDownload);
                                } else {
                                    setupDownload();
                                }

                            </script>
                        </div>
                        <?php
                        return ob_get_clean();
                    }

    public function ajax_get_slots() {
        if (
            !isset($_POST['nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'rewan_booking_slots_nonce')
        ) {
            wp_send_json_error(array('message' => 'Sicherheitsprüfung fehlgeschlagen.'));
        }

        $service_ids = isset($_POST['service_ids']) ? array_map('intval', (array) wp_unslash($_POST['service_ids'])) : array();
        $employee_id_raw = isset($_POST['employee_id']) ? sanitize_text_field(wp_unslash($_POST['employee_id'])) : '';
        $booking_date = isset($_POST['booking_date']) ? sanitize_text_field(wp_unslash($_POST['booking_date'])) : '';

        if (empty($service_ids) || empty($employee_id_raw) || empty($booking_date)) {
            wp_send_json_error(array('message' => 'Bitte zuerst alles auswählen.'));
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $booking_date)) {
            wp_send_json_error(array('message' => 'Ungültiges Datum.'));
        }

        $total_duration = $this->get_total_duration_by_service_ids($service_ids);
        if ($total_duration <= 0) {
            wp_send_json_error(array('message' => 'Ungültige Dienstleistungen.'));
        }

        $assignees = array();
        if ($employee_id_raw === 'general') {
            $assignees = $this->get_general_slot_assignees($booking_date, $total_duration);
            $slots = array_keys($assignees);
        } else {
            $employee_id = (int) $employee_id_raw;
            $slots = $this->get_available_slots_for_employee($employee_id, $booking_date, $total_duration);
        }

        $message = '';
        if (empty($slots)) {
            $message = $this->public_empty_slot_message($employee_id_raw, $booking_date);
        }

        wp_send_json_success(array(
            'slots' => array_values($slots),
            'assignees' => $assignees,
            'message' => $message,
        ));
    }

    public function ajax_closed_days() {
        if (
            !isset($_POST['nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'rewan_booking_slots_nonce')
        ) {
            wp_send_json_error(array('message' => 'Sicherheitsprüfung fehlgeschlagen.'));
        }

        $employee_raw = isset($_POST['employee_id']) ? sanitize_text_field(wp_unslash($_POST['employee_id'])) : '';
        $from = current_time('Y-m-d');
        $until = date('Y-m-d', strtotime($from . ' +120 days'));
        $weekdays = array();
        for ($iso = 1; $iso <= 7; $iso++) {
            if ($this->weekday_shut_for_choice($employee_raw, $iso)) {
                $weekdays[] = $iso;
            }
        }

        $service_ids = isset($_POST['service_ids']) ? array_map('intval', (array) wp_unslash($_POST['service_ids'])) : array();
        $duration = $this->get_total_duration_by_service_ids($service_ids);
        $requested = isset($_POST['booking_date']) ? sanitize_text_field(wp_unslash($_POST['booking_date'])) : '';
        $search_from = $from;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $requested) && $requested >= $from && $requested <= $until) {
            $search_from = $requested;
        }
        $next_date = '';
        if ($duration > 0 && $employee_raw !== '') {
            $next_date = $this->next_bookable_date($employee_raw, $duration, $search_from);
        }

        wp_send_json_success(array(
            'weekdays' => $weekdays,
            'dates' => $this->closed_dates_for_choice($employee_raw, $from, $until),
            'next_date' => $next_date,
        ));
    }

    public function handle_booking_submission() {
        if (
            !isset($_POST['rewan_booking_submit_nonce']) ||
            !wp_verify_nonce($_POST['rewan_booking_submit_nonce'], 'rewan_booking_submit_nonce')
        ) {
            $this->redirect_with_error('security');
        }

        global $wpdb;

        $services_table = $wpdb->prefix . 'rewan_booking_services';
        $employees_table = $wpdb->prefix . 'rewan_booking_employees';
        $bookings_table = $wpdb->prefix . 'rewan_booking_bookings';

        $service_ids = isset($_POST['service_ids']) ? array_map('intval', (array) wp_unslash($_POST['service_ids'])) : array();
        $employee_id_raw = isset($_POST['employee_id']) ? sanitize_text_field(wp_unslash($_POST['employee_id'])) : '';
        $booking_date = isset($_POST['booking_date']) ? sanitize_text_field(wp_unslash($_POST['booking_date'])) : '';
        $booking_time = isset($_POST['selected_slot']) ? sanitize_text_field(wp_unslash($_POST['selected_slot'])) : '';
        $customer_name = isset($_POST['customer_name']) ? sanitize_text_field(wp_unslash($_POST['customer_name'])) : '';
        $customer_email = isset($_POST['customer_email']) ? sanitize_email(wp_unslash($_POST['customer_email'])) : '';
        $customer_phone = isset($_POST['customer_phone']) ? sanitize_text_field(wp_unslash($_POST['customer_phone'])) : '';
        $customer_notes = isset($_POST['customer_notes']) ? sanitize_textarea_field(wp_unslash($_POST['customer_notes'])) : '';

        if (empty($service_ids)) {
            $this->redirect_with_error('no_services');
        }

        if (empty($employee_id_raw) || empty($booking_date) || empty($booking_time) || empty($customer_name) || empty($customer_email) || empty($customer_phone)) {
            $this->redirect_with_error('missing_fields');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $booking_date) || !preg_match('/^\d{2}:\d{2}$/', $booking_time)) {
            $this->redirect_with_error('invalid_datetime');
        }

        $placeholders = implode(',', array_fill(0, count($service_ids), '%d'));
        $query = $wpdb->prepare(
            "SELECT * FROM $services_table WHERE id IN ($placeholders) AND is_active = 1",
            $service_ids
        );
        $services = $wpdb->get_results($query, ARRAY_A);

        if (empty($services)) {
            $this->redirect_with_error('invalid_services');
        }

        $total_price = 0;
        $total_duration = 0;
        $service_names = array();

        foreach ($services as $service) {
            $total_price += (float) $service['price'];
            $total_duration += (int) $service['duration'];
            $service_names[] = $service['name'];
        }

        $start_datetime = strtotime($booking_date . ' ' . $booking_time . ':00');
        if (!$start_datetime) {
            $this->redirect_with_error('invalid_datetime');
        }

        $end_datetime = strtotime('+' . $total_duration . ' minutes', $start_datetime);
        $start_time = date('H:i:s', $start_datetime);
        $end_time = date('H:i:s', $end_datetime);

        if ($employee_id_raw === 'general') {
            $employee = $this->find_first_available_employee($booking_date, $start_time, $end_time);
            if (!$employee) {
                $this->redirect_with_error('no_employee_available');
            }
        } else {
            $employee_id = (int) $employee_id_raw;
            $employee = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM $employees_table WHERE id = %d AND is_active = 1",
                    $employee_id
                ),
                ARRAY_A
            );

            if (!$employee) {
                $this->redirect_with_error('invalid_employee');
            }

            if (!$this->is_bookable_for_employee($employee_id, $booking_date, $start_time, $end_time)) {
                $this->redirect_with_error('timeslot_taken');
            }
        }

        $inserted = $wpdb->insert(
            $bookings_table,
            array(
                'customer_name'  => $customer_name,
                'customer_email' => $customer_email,
                'customer_phone' => $customer_phone,
                'notes'          => $customer_notes,
                'services'       => wp_json_encode($service_names),
                'employee_id'    => (int) $employee['id'],
                'employee_name'  => $employee['name'],
                'booking_date'   => $booking_date,
                'start_time'     => $start_time,
                'end_time'       => $end_time,
                'total_price'    => $total_price,
                'total_duration' => $total_duration,
                'status'         => 'confirmed',
                'payment_method' => 'vor_ort',
            ),
            array('%s','%s','%s','%s','%s','%d','%s','%s','%s','%s','%f','%d','%s','%s')
        );

        if (!$inserted) {
            $this->redirect_with_error('db_error');
        }

        $this->send_booking_emails(
            $employee,
            $customer_name,
            $customer_email,
            $customer_phone,
            $service_names,
            $booking_date,
            $start_time,
            $end_time,
            $total_price,
            $customer_notes
        );

        $date_obj = new DateTime($booking_date);
        $day_names = array('Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag');
        $date_label = $day_names[(int) $date_obj->format('w')] . ', ' . $date_obj->format('d.m.Y');
        $time_label = substr($start_time, 0, 5) . ' – ' . substr($end_time, 0, 5);
        $price_label = number_format((float) $total_price, 2, '.', '\'') . ' CHF';

        $receipt_token = wp_generate_password(20, false, false);
        set_transient('rewan_booking_receipt_' . $receipt_token, array(
            'services' => implode(', ', $service_names),
            'duration' => (string) $total_duration,
            'price' => $price_label,
            'employee' => (string) $employee['name'],
            'date' => $date_label,
            'time' => $time_label,
        ), 30 * MINUTE_IN_SECONDS);

        $referer = wp_get_referer();
        if (!$referer) {
            $referer = home_url('/');
        }
        $referer = remove_query_arg(array('booking', 'booking_error', 'receipt'), $referer);

        wp_redirect(add_query_arg(array(
            'booking' => 'success',
            'receipt' => $receipt_token,
        ), $referer));
        exit;
    }

    private function get_total_duration_by_service_ids($service_ids) {
        global $wpdb;

        if (empty($service_ids)) {
            return 0;
        }

        $services_table = $wpdb->prefix . 'rewan_booking_services';
        $placeholders = implode(',', array_fill(0, count($service_ids), '%d'));

        $query = $wpdb->prepare(
            "SELECT duration FROM $services_table WHERE id IN ($placeholders) AND is_active = 1",
            $service_ids
        );

        $rows = $wpdb->get_results($query, ARRAY_A);

        if (empty($rows)) {
            return 0;
        }

        $duration = 0;
        foreach ($rows as $row) {
            $duration += (int) $row['duration'];
        }

        return $duration;
    }

    private function get_general_slot_assignees($booking_date, $total_duration) {
        global $wpdb;

        $employees_table = $wpdb->prefix . 'rewan_booking_employees';
        $employees = $wpdb->get_results(
            "SELECT id, name FROM $employees_table WHERE is_active = 1 ORDER BY id ASC",
            ARRAY_A
        );
        if (empty($employees)) {
            return array();
        }

        $assignees = array();
        foreach ($employees as $employee) {
            $slots = $this->get_available_slots_for_employee((int) $employee['id'], $booking_date, $total_duration);
            foreach ($slots as $slot) {
                if (!isset($assignees[$slot])) {
                    $assignees[$slot] = (string) $employee['name'];
                }
            }
        }
        ksort($assignees);
        return $assignees;
    }

    private function public_empty_slot_message($employee_raw, $booking_date) {
        $employees = $this->choice_employees($employee_raw);
        foreach ($employees as $employee) {
            $schedule = $this->get_effective_schedule_for_date((int) $employee['id'], $booking_date);
            if ($schedule && (int) $schedule['is_working'] === 1) {
                return 'An diesem Tag ist leider nichts mehr frei.';
            }
        }
        return 'An diesem Tag ist geschlossen.';
    }

    private function choice_employees($employee_raw) {
        global $wpdb;
        $table = $wpdb->prefix . 'rewan_booking_employees';
        if ($employee_raw !== '' && $employee_raw !== 'general' && ctype_digit((string) $employee_raw)) {
            return $wpdb->get_results(
                $wpdb->prepare("SELECT id, name FROM $table WHERE id = %d AND is_active = 1", (int) $employee_raw),
                ARRAY_A
            );
        }
        return $wpdb->get_results(
            "SELECT id, name FROM $table WHERE is_active = 1 ORDER BY id ASC",
            ARRAY_A
        );
    }

    private function weekday_shut_for_choice($employee_raw, $iso) {
        if ($this->weekday_has_all_day_block($iso)) {
            return true;
        }
        if (class_exists('Rewan_Booking_Schedule')) {
            $open = Rewan_Booking_Schedule::opening_row($iso);
            if (is_array($open) && (int) $open['is_open'] !== 1) {
                return true;
            }
        }
        $employees = $this->choice_employees($employee_raw);
        if (empty($employees)) {
            return false;
        }
        $sample = $this->sample_date_for_iso($iso);
        foreach ($employees as $employee) {
            $schedule = $this->get_effective_schedule_for_date((int) $employee['id'], $sample);
            if ($schedule && (int) $schedule['is_working'] === 1) {
                return false;
            }
        }
        return true;
    }

    private function weekday_has_all_day_block($iso) {
        global $wpdb;
        $table = $wpdb->prefix . 'rewan_booking_global_week_schedule';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ($exists !== $table) {
            return false;
        }
        $mode = $this->detect_global_weekday_mode($table);
        $primary = ($mode === 'legacy') ? ($iso - 1) : $iso;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT block_mode FROM {$table} WHERE weekday = %d", $primary),
            ARRAY_A
        );
        return is_array($row) && isset($row['block_mode']) && $row['block_mode'] === 'all_day';
    }

    private function sample_date_for_iso($iso) {
        $today = strtotime(current_time('Y-m-d') . ' 12:00:00');
        $current = (int) date('N', $today);
        $add = ($iso - $current + 7) % 7;
        return date('Y-m-d', strtotime('+' . $add . ' days', $today));
    }

    /**
     * Erster Tag ab $from mit mindestens einem freien Slot. Ferientage werden übersprungen.
     */
    private function next_bookable_date($employee_raw, $total_duration, $from) {
        $total_duration = (int) $total_duration;
        if ($total_duration <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $from)) {
            return '';
        }

        $until = date('Y-m-d', strtotime($from . ' +120 days'));
        $closed = array_flip($this->closed_dates_for_choice($employee_raw, $from, $until));
        $shut = array();
        for ($iso = 1; $iso <= 7; $iso++) {
            if ($this->weekday_shut_for_choice($employee_raw, $iso)) {
                $shut[$iso] = true;
            }
        }

        $cursor = date_create_immutable($from . ' 12:00:00', wp_timezone());
        if (!$cursor instanceof DateTimeImmutable) {
            return '';
        }

        $specific = ($employee_raw !== 'general' && ctype_digit((string) $employee_raw));
        $employee_id = $specific ? (int) $employee_raw : 0;
        $open_days_checked = 0;

        for ($i = 0; $i <= 120; $i++) {
            $day = $cursor->modify('+' . $i . ' days');
            if (!$day instanceof DateTimeImmutable) {
                break;
            }
            $iso_date = $day->format('Y-m-d');
            $week = (int) $day->format('N');
            if (isset($shut[$week]) || isset($closed[$iso_date])) {
                continue;
            }

            $open_days_checked++;
            if ($open_days_checked > 40) {
                break;
            }

            if ($specific) {
                $slots = $this->get_available_slots_for_employee($employee_id, $iso_date, $total_duration);
            } else {
                $slots = $this->get_general_slot_assignees($iso_date, $total_duration);
            }
            if (!empty($slots)) {
                return $iso_date;
            }
        }

        return '';
    }

    private function closed_dates_for_choice($employee_raw, $from, $until) {
        $employees = $this->choice_employees($employee_raw);
        $dates = $this->expand_all_day_ranges($this->global_all_day_blocks($from, $until), $from, $until);
        if (empty($employees)) {
            return array_values(array_unique($dates));
        }

        $sets = array();
        foreach ($employees as $employee) {
            $sets[] = array_flip($this->expand_all_day_ranges(
                $this->employee_all_day_absences((int) $employee['id'], $from, $until),
                $from,
                $until
            ));
        }
        if (!empty($sets)) {
            foreach (array_keys($sets[0]) as $date) {
                $shared = true;
                foreach ($sets as $set) {
                    if (!isset($set[$date])) {
                        $shared = false;
                        break;
                    }
                }
                if ($shared) {
                    $dates[] = $date;
                }
            }
        }
        $dates = array_values(array_unique($dates));
        sort($dates);
        return $dates;
    }

    private function global_all_day_blocks($from, $until) {
        global $wpdb;
        $table = $wpdb->prefix . 'rewan_booking_global_blocks';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ($exists !== $table) {
            return array();
        }
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT start_date, end_date FROM {$table}
                 WHERE is_all_day = 1 AND end_date >= %s AND start_date <= %s",
                $from,
                $until
            ),
            ARRAY_A
        );
    }

    private function employee_all_day_absences($employee_id, $from, $until) {
        global $wpdb;
        $table = $wpdb->prefix . 'rewan_booking_employee_absences';
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT start_date, end_date FROM {$table}
                 WHERE employee_id = %d AND is_all_day = 1 AND end_date >= %s AND start_date <= %s",
                $employee_id,
                $from,
                $until
            ),
            ARRAY_A
        );
    }

    private function expand_all_day_ranges($rows, $from, $until) {
        $dates = array();
        $from_ts = strtotime($from . ' 12:00:00');
        $until_ts = strtotime($until . ' 12:00:00');
        if (!$from_ts || !$until_ts || !is_array($rows)) {
            return $dates;
        }
        foreach ($rows as $row) {
            $start = strtotime($row['start_date'] . ' 12:00:00');
            $end = strtotime($row['end_date'] . ' 12:00:00');
            if (!$start || !$end) {
                continue;
            }
            if ($start < $from_ts) {
                $start = $from_ts;
            }
            if ($end > $until_ts) {
                $end = $until_ts;
            }
            for ($cursor = $start; $cursor <= $end; $cursor = strtotime('+1 day', $cursor)) {
                $dates[] = date('Y-m-d', $cursor);
            }
        }
        return $dates;
    }

    private function get_available_slots_for_employee($employee_id, $booking_date, $total_duration) {
        $schedule = $this->get_effective_schedule_for_date($employee_id, $booking_date);

        if (!$schedule || (int) $schedule['is_working'] !== 1) {
            return array();
        }

        $day_start = strtotime($booking_date . ' ' . $schedule['start_time']);
        $day_end = strtotime($booking_date . ' ' . $schedule['end_time']);

        if (!$day_start || !$day_end || $day_end <= $day_start) {
            return array();
        }

        $slot_step = 30 * 60;
        $duration_seconds = $total_duration * 60;
        $latest_start = $day_end - $duration_seconds;

        if ($latest_start < $day_start) {
            return array();
        }

        $slots = array();

        $current_date_str = current_time('Y-m-d');
        $current_time_str = current_time('H:i:s');

        for ($current = $day_start; $current <= $latest_start; $current += $slot_step) {
            $start_time = date('H:i:s', $current);
            $end_time = date('H:i:s', $current + $duration_seconds);

            // Vergangene Zeiten am heutigen Tag blockieren (+ kleine Pufferzeit von 5 Min)
            if ($booking_date === $current_date_str && $start_time < date('H:i:s', strtotime($current_time_str . ' +5 minutes'))) {
                continue;
            }

            if ($this->get_slot_block_reason($employee_id, $booking_date, $start_time, $end_time) !== '') {
                continue;
            }

            $slots[] = date('H:i', $current);
        }

        return $slots;
    }

    private function get_employee_schedule_for_date($employee_id, $booking_date) {
        global $wpdb;

        $hours_table = $wpdb->prefix . 'rewan_booking_employee_hours';
        $weekday_iso = (int) date('N', strtotime($booking_date)); // 1=Mo ... 7=So
        $weekday_legacy = $weekday_iso - 1; // Legacy schema: 0=Mo ... 6=So
        $weekday_mode = $this->detect_employee_weekday_mode($hours_table, $employee_id);
        $use_legacy = ($weekday_mode === 'legacy');
        $primary_weekday = $use_legacy ? $weekday_legacy : $weekday_iso;
        $fallback_weekday = $use_legacy ? $weekday_iso : $weekday_legacy;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $hours_table
                 WHERE employee_id = %d
                 AND weekday IN (%d, %d)
                 ORDER BY CASE WHEN weekday = %d THEN 0 ELSE 1 END
                 LIMIT 1",
                $employee_id,
                $primary_weekday,
                $fallback_weekday,
                $primary_weekday
            ),
            ARRAY_A
        );
    }

    private function get_employee_break_for_date($employee_id, $booking_date) {
        global $wpdb;

        $breaks_table = $wpdb->prefix . 'rewan_booking_employee_breaks';
        $weekday_iso = (int) date('N', strtotime($booking_date)); // 1=Mo ... 7=So
        $weekday_legacy = $weekday_iso - 1; // Legacy schema: 0=Mo ... 6=So
        $weekday_mode = $this->detect_employee_weekday_mode($breaks_table, $employee_id);
        $use_legacy = ($weekday_mode === 'legacy');
        $primary_weekday = $use_legacy ? $weekday_legacy : $weekday_iso;
        $fallback_weekday = $use_legacy ? $weekday_iso : $weekday_legacy;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $breaks_table
                 WHERE employee_id = %d
                 AND weekday IN (%d, %d)
                 ORDER BY CASE WHEN weekday = %d THEN 0 ELSE 1 END
                 LIMIT 1",
                $employee_id,
                $primary_weekday,
                $fallback_weekday,
                $primary_weekday
            ),
            ARRAY_A
        );
    }

    /**
     * Arbeitszeit, begrenzt durch die Laden-Öffnung. Ohne Öffnungstabelle bleibt die bisherige Arbeitszeit.
     *
     * @return array<string,mixed>|null
     */
    private function get_effective_schedule_for_date($employee_id, $booking_date) {
        if (class_exists('Rewan_Booking_Schedule')) {
            return Rewan_Booking_Schedule::effective_for_employee((int) $employee_id, (string) $booking_date);
        }
        return $this->get_employee_schedule_for_date($employee_id, $booking_date);
    }

    private function is_within_working_hours($employee_id, $booking_date, $start_time, $end_time) {
        $schedule = $this->get_effective_schedule_for_date($employee_id, $booking_date);

        if (!$schedule || (int) $schedule['is_working'] !== 1) {
            return false;
        }

        return ($start_time >= $schedule['start_time'] && $end_time <= $schedule['end_time']);
    }

    private function overlaps_break($employee_id, $booking_date, $start_time, $end_time) {
        $break = $this->get_employee_break_for_date($employee_id, $booking_date);

        if (!$break || (int) $break['is_enabled'] !== 1) {
            return false;
        }

        return ($start_time < $break['break_end'] && $end_time > $break['break_start']);
    }

    /**
     * Prüft Überschneidung eines Buchungsintervalls mit einer Abwesenheits-/Sperrzeile (gleiche Logik wie Admin).
     *
     * @param string               $booking_date Y-m-d
     * @param string               $start_time   H:i:s
     * @param string               $end_time     H:i:s
     * @param array<string,mixed> $row          DB-Zeile mit start_date, end_date, is_all_day, start_time, end_time
     */
    private function slot_overlaps_block_row($booking_date, $start_time, $end_time, array $row) {
        if ((int) $row['is_all_day'] === 1) {
            return true;
        }

        $sd = (string) $row['start_date'];
        $ed = (string) $row['end_date'];
        $rst = (string) $row['start_time'];
        $ret = (string) $row['end_time'];

        if ($sd === $ed) {
            return ($start_time < $ret && $end_time > $rst);
        }

        if ($booking_date === $sd) {
            $virt_end = '23:59:59';

            return ($start_time < $virt_end && $end_time > $rst);
        }

        if ($booking_date === $ed) {
            return ($start_time < $ret && $end_time > '00:00:00');
        }

        return true;
    }

    private function overlaps_global_booking_block($booking_date, $start_time, $end_time) {
        global $wpdb;

        $table = $wpdb->prefix . 'rewan_booking_global_week_schedule';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ($exists !== $table) {
            return false;
        }

        $ts = strtotime($booking_date . ' 12:00:00');
        if (!$ts) {
            return false;
        }
        $weekday_iso = (int) date('N', $ts); // 1=Mo ... 7=So
        if ($weekday_iso < 1 || $weekday_iso > 7) {
            return false;
        }
        $weekday_legacy = $weekday_iso - 1; // Legacy schema: 0=Mo ... 6=So
        $weekday_mode = $this->detect_global_weekday_mode($table);
        $use_legacy = ($weekday_mode === 'legacy');
        $primary_weekday = $use_legacy ? $weekday_legacy : $weekday_iso;
        $fallback_weekday = $use_legacy ? $weekday_iso : $weekday_legacy;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE weekday IN (%d, %d)
                 ORDER BY CASE WHEN weekday = %d THEN 0 ELSE 1 END
                 LIMIT 1",
                $primary_weekday,
                $fallback_weekday,
                $primary_weekday
            ),
            ARRAY_A
        );
        if (!is_array($row) || empty($row['block_mode'])) {
            return false;
        }

        $mode = (string) $row['block_mode'];
        if ($mode === 'none' || $mode === '') {
            return false;
        }
        if ($mode === 'all_day') {
            return true;
        }
        if ($mode !== 'interval') {
            return false;
        }

        $rst = (string) $row['start_time'];
        $ret = (string) $row['end_time'];

        return ($start_time < $ret && $end_time > $rst);
    }

    /**
     * Detect employee weekday schema: 'iso' (1..7), 'legacy' (0..6), or 'mixed'.
     */
    private function detect_employee_weekday_mode($table_name, $employee_id) {
        global $wpdb;

        $has_zero = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} WHERE employee_id = %d AND weekday = 0",
                $employee_id
            )
        );
        $has_seven = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} WHERE employee_id = %d AND weekday = 7",
                $employee_id
            )
        );
        $max_weekday = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(MAX(weekday), -1) FROM {$table_name} WHERE employee_id = %d",
                $employee_id
            )
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

    /**
     * Detect global weekday schema: 'iso' (1..7), 'legacy' (0..6), or 'mixed'.
     */
    private function detect_global_weekday_mode($table_name) {
        global $wpdb;

        $has_zero = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table_name} WHERE weekday = 0"
        );
        $has_seven = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table_name} WHERE weekday = 7"
        );
        $max_weekday = (int) $wpdb->get_var(
            "SELECT COALESCE(MAX(weekday), -1) FROM {$table_name}"
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

    private function overlaps_absence($employee_id, $booking_date, $start_time, $end_time) {
        global $wpdb;

        $absences_table = $wpdb->prefix . 'rewan_booking_employee_absences';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $absences_table
                 WHERE employee_id = %d
                 AND %s BETWEEN start_date AND end_date",
                $employee_id,
                $booking_date
            ),
            ARRAY_A
        );

        if (empty($rows)) {
            return false;
        }

        foreach ($rows as $row) {
            if ($this->slot_overlaps_block_row($booking_date, $start_time, $end_time, $row)) {
                return true;
            }
        }

        return false;
    }

    private function has_booking_conflict($employee_id, $booking_date, $start_time, $end_time) {
        global $wpdb;

        $bookings_table = $wpdb->prefix . 'rewan_booking_bookings';

        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $bookings_table
                 WHERE employee_id = %d
                 AND booking_date = %s
                 AND status = %s
                 AND start_time < %s
                 AND end_time > %s",
                $employee_id,
                $booking_date,
                'confirmed',
                $end_time,
                $start_time
            )
        );

        return $count > 0;
    }

    private function is_bookable_for_employee($employee_id, $booking_date, $start_time, $end_time) {
        return $this->get_slot_block_reason($employee_id, $booking_date, $start_time, $end_time) === '';
    }

    private function get_slot_block_reason($employee_id, $booking_date, $start_time, $end_time) {
        if ($this->overlaps_global_booking_block($booking_date, $start_time, $end_time)) {
            return 'global_block';
        }
        if (!$this->is_within_working_hours($employee_id, $booking_date, $start_time, $end_time)) {
            return 'outside_working_hours';
        }
        if ($this->overlaps_break($employee_id, $booking_date, $start_time, $end_time)) {
            return 'break';
        }
        if ($this->overlaps_absence($employee_id, $booking_date, $start_time, $end_time)) {
            return 'absence';
        }
        if ($this->has_booking_conflict($employee_id, $booking_date, $start_time, $end_time)) {
            return 'booking_conflict';
        }

        return '';
    }

    private function build_slot_debug_info($employee_id, $booking_date, $total_duration) {
        $schedule = $this->get_effective_schedule_for_date($employee_id, $booking_date);
        if (!$schedule || (int) $schedule['is_working'] !== 1) {
            return array(
                'employee_id' => (int) $employee_id,
                'booking_date' => (string) $booking_date,
                'total_duration' => (int) $total_duration,
                'summary' => 'Mitarbeiter hat an diesem Tag keine aktiven Arbeitszeiten.',
                'reasons' => array('outside_working_hours' => 1),
            );
        }

        $day_start = strtotime($booking_date . ' ' . $schedule['start_time']);
        $day_end = strtotime($booking_date . ' ' . $schedule['end_time']);
        if (!$day_start || !$day_end || $day_end <= $day_start) {
            return array(
                'employee_id' => (int) $employee_id,
                'booking_date' => (string) $booking_date,
                'total_duration' => (int) $total_duration,
                'summary' => 'Arbeitszeit ist ungültig gespeichert (Start/Ende).',
                'reasons' => array('outside_working_hours' => 1),
            );
        }

        $slot_step = 30 * 60;
        $duration_seconds = max(1, (int) $total_duration) * 60;
        $latest_start = $day_end - $duration_seconds;
        $reasons = array(
            'global_block' => 0,
            'outside_working_hours' => 0,
            'break' => 0,
            'absence' => 0,
            'booking_conflict' => 0,
            'past_time' => 0,
        );
        $bookable = 0;
        $current_date_str = current_time('Y-m-d');
        $current_time_plus = date('H:i:s', strtotime(current_time('H:i:s') . ' +5 minutes'));

        for ($current = $day_start; $current <= $latest_start; $current += $slot_step) {
            $start_time = date('H:i:s', $current);
            $end_time = date('H:i:s', $current + $duration_seconds);

            if ($booking_date === $current_date_str && $start_time < $current_time_plus) {
                $reasons['past_time']++;
                continue;
            }

            $reason = $this->get_slot_block_reason($employee_id, $booking_date, $start_time, $end_time);
            if ($reason === '') {
                $bookable++;
            } elseif (isset($reasons[$reason])) {
                $reasons[$reason]++;
            }
        }

        $labels = array(
            'global_block' => 'globale Sperrzeit',
            'outside_working_hours' => 'Arbeitszeit',
            'break' => 'Pause',
            'absence' => 'Abwesenheit/Ferien',
            'booking_conflict' => 'bereits gebucht',
            'past_time' => 'Vergangenheit',
        );
        $parts = array();
        foreach ($reasons as $key => $count) {
            if ($count > 0) {
                $parts[] = $labels[$key] . ': ' . $count;
            }
        }
        $summary = $bookable > 0
            ? 'Freie Slots gefunden: ' . $bookable
            : 'Keine Slots frei. Blockiert durch: ' . (empty($parts) ? 'unbekannt' : implode(', ', $parts));

        return array(
            'employee_id' => (int) $employee_id,
            'booking_date' => (string) $booking_date,
            'total_duration' => (int) $total_duration,
            'work_start' => (string) $schedule['start_time'],
            'work_end' => (string) $schedule['end_time'],
            'bookable_slots' => $bookable,
            'reasons' => $reasons,
            'summary' => $summary,
        );
    }

    private function find_first_available_employee($booking_date, $start_time, $end_time) {
        global $wpdb;

        $employees_table = $wpdb->prefix . 'rewan_booking_employees';

        $employees = $wpdb->get_results(
            "SELECT * FROM $employees_table WHERE is_active = 1 ORDER BY id ASC",
            ARRAY_A
        );

        if (empty($employees)) {
            return null;
        }

        foreach ($employees as $employee) {
            $employee_id = (int) $employee['id'];

            if ($this->is_bookable_for_employee($employee_id, $booking_date, $start_time, $end_time)) {
                return $employee;
            }
        }

        return null;
    }

    private function send_booking_emails($employee, $customer_name, $customer_email, $customer_phone, $service_names, $booking_date, $start_time, $end_time, $total_price, $customer_notes) {
        $date_obj = new DateTime($booking_date);
        $days = array('Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag');
        $date_label = $days[(int) $date_obj->format('w')] . ', ' . $date_obj->format('d.m.Y');
        $time_label = substr($start_time, 0, 5) . ' – ' . substr($end_time, 0, 5);
        if (!class_exists('Rewan_Booking_Mail')) {
            return;
        }
        Rewan_Booking_Mail::send_booking(array(
            'customer_name' => $customer_name,
            'customer_email' => $customer_email,
            'customer_phone' => $customer_phone,
            'notes' => $customer_notes,
            'service_list' => implode(', ', $service_names),
            'date_label' => $date_label,
            'time_label' => $time_label,
            'employee_name' => (string) $employee['name'],
            'employee_email' => (string) $employee['email'],
            'price_label' => number_format((float) $total_price, 2, '.', "'") . ' CHF',
        ));
    }

    private function redirect_with_error($error_code) {
        $referer = wp_get_referer();
        if (!$referer) {
            $referer = home_url('/');
        }

        wp_redirect(add_query_arg('booking_error', rawurlencode($error_code), $referer));
        exit;
    }

    private function get_error_message($code) {
        $messages = array(
            'security'              => 'Sicherheitsprüfung fehlgeschlagen.',
            'no_services'           => 'Bitte wähle mindestens eine Dienstleistung aus.',
            'missing_fields'        => 'Bitte fülle alle Pflichtfelder aus.',
            'invalid_datetime'      => 'Datum oder Uhrzeit ist ungültig.',
            'invalid_services'      => 'Die gewählten Dienstleistungen sind ungültig.',
            'invalid_employee'      => 'Der gewählte Mitarbeiter ist ungültig.',
            'timeslot_taken'        => 'Dieser Slot ist nicht mehr verfügbar. Bitte wähle eine andere Zeit.',
            'no_employee_available' => 'Zu dieser Zeit ist leider kein Mitarbeiter verfügbar.',
            'db_error'              => 'Die Buchung konnte nicht gespeichert werden.',
        );

        return isset($messages[$code]) ? $messages[$code] : 'Es ist ein unbekannter Fehler aufgetreten.';
    }

}