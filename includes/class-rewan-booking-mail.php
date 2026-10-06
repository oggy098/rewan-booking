<?php
/**
 * Bestätigungsmails: feste Gestaltung, bearbeitbare Texte, gleiche Ausgabe für Vorschau und Versand.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Rewan_Booking_Mail {

    public static function init() {
        add_filter('wp_mail_from', array(__CLASS__, 'filter_from_email'));
        add_filter('wp_mail_from_name', array(__CLASS__, 'filter_from_name'));
        add_action('phpmailer_init', array(__CLASS__, 'configure_phpmailer'));
    }

    /**
     * @return array{from_name:string,from_email:string,smtp_enabled:int,smtp_host:string}
     */
    public static function delivery() {
        $saved = get_option('rewan_booking_mail_delivery', array());
        if (!is_array($saved)) {
            $saved = array();
        }
        $host = isset($saved['smtp_host']) ? (string) $saved['smtp_host'] : '';
        if (!preg_match('/^[a-z0-9.-]+$/i', $host)) {
            $host = 'asmtp.mail.hostpoint.ch';
        }
        return array(
            'from_name' => isset($saved['from_name']) ? (string) $saved['from_name'] : '',
            'from_email' => isset($saved['from_email']) ? sanitize_email($saved['from_email']) : '',
            'smtp_enabled' => !empty($saved['smtp_enabled']) ? 1 : 0,
            'smtp_host' => $host,
        );
    }

    public static function smtp_password() {
        return (string) get_option('rewan_booking_smtp_password', '');
    }

    public static function from_address() {
        $email = self::delivery()['from_email'];
        return is_email($email) ? $email : '';
    }

    public static function from_name() {
        $name = trim(self::delivery()['from_name']);
        if ($name === '') {
            $name = trim(self::shop()['name']);
        }
        return $name;
    }

    public static function filter_from_email($email) {
        $from = self::from_address();
        return $from !== '' ? $from : $email;
    }

    public static function filter_from_name($name) {
        if (self::from_address() === '') {
            return $name;
        }
        $own = self::from_name();
        return $own !== '' ? $own : $name;
    }

    /**
     * @param PHPMailer\PHPMailer\PHPMailer $phpmailer
     */
    public static function configure_phpmailer($phpmailer) {
        $from = self::from_address();
        if ($from !== '') {
            $phpmailer->setFrom($from, self::from_name(), false);
            $phpmailer->Sender = $from;
            $phpmailer->clearReplyTos();
            $phpmailer->addReplyTo($from, self::from_name());
        }

        $delivery = self::delivery();
        $password = self::smtp_password();
        if (empty($delivery['smtp_enabled']) || $from === '' || $password === '') {
            return;
        }

        $phpmailer->isSMTP();
        $phpmailer->Host = $delivery['smtp_host'];
        $phpmailer->Port = 587;
        $phpmailer->SMTPAuth = true;
        $phpmailer->AuthType = 'PLAIN';
        $phpmailer->Username = $from;
        $phpmailer->Password = $password;
        $phpmailer->SMTPSecure = 'tls';
        $phpmailer->SMTPAutoTLS = true;
        $phpmailer->CharSet = 'UTF-8';
        $phpmailer->Timeout = 20;
    }

    /**
     * @return bool
     */
    public static function send_delivery_test($to) {
        $to = sanitize_email($to);
        if (!is_email($to)) {
            return false;
        }
        $error = '';
        $listener = function ($wp_error) use (&$error) {
            if ($wp_error instanceof WP_Error) {
                $message = $wp_error->get_error_message();
                $password = self::smtp_password();
                if ($password !== '') {
                    $message = str_replace($password, '••••', $message);
                }
                $error = $message;
            }
        };
        add_action('wp_mail_failed', $listener);
        $shop = self::from_name();
        $sent = wp_mail(
            $to,
            'Test: Mail von ' . $shop,
            "Das ist eine Testmail von Rewan Booking.\n\nWenn sie im Posteingang liegt, klappt der Versand über Hostpoint."
        );
        remove_action('wp_mail_failed', $listener);
        if (!$sent) {
            set_transient('rewan_booking_smtp_error', $error !== '' ? $error : 'Der Mailserver hat die Testmail abgelehnt.', 2 * MINUTE_IN_SECONDS);
        }
        return (bool) $sent;
    }

    public static function shop() {
        $defaults = array(
            'name' => 'Barbershop Rewan',
            'address' => 'Baslerstrasse 140, 5222 Umiken',
            'phone' => '078 211 88 20',
            'web' => 'barbershop-rewan.ch',
        );
        $saved = get_option('rewan_booking_shop', array());
        if (!is_array($saved)) {
            $saved = array();
        }
        $shop = $defaults;
        foreach ($defaults as $key => $default) {
            if (isset($saved[$key])) {
                $shop[$key] = (string) $saved[$key];
            }
        }
        return $shop;
    }

    public static function default_copy() {
        return array(
            'customer_subject' => 'Dein Termin bei {shop}',
            'customer_intro' => 'Hallo {name}, vielen Dank für deine Buchung. Wir freuen uns auf dich.',
            'customer_note' => 'Wenn du nicht kommen kannst, ruf uns bitte rechtzeitig an: {shop_phone}',
            'staff_subject' => 'Neuer Termin mit {name}',
            'staff_intro' => 'Hallo {barber}, du hast einen neuen Termin.',
            'staff_note' => 'Telefon für Rückfragen: {phone}',
            'owner_subject' => 'Neue Buchung: {name}, {date} {time}',
            'owner_intro' => 'Eine neue Buchung ist eingegangen.',
            'owner_note' => '',
        );
    }

    public static function stored_copy() {
        $saved = get_option('rewan_booking_email_copy', array());
        if (!is_array($saved)) {
            $saved = array();
        }
        $copy = self::default_copy();
        foreach ($copy as $key => $default) {
            if (!array_key_exists($key, $saved)) {
                continue;
            }
            $value = (string) $saved[$key];
            if (strpos($key, 'subject') !== false && trim($value) === '') {
                continue;
            }
            $copy[$key] = $value;
        }
        return $copy;
    }

    /**
     * @param array<string,string> $overrides Ungespeicherte Texte für die Vorschau.
     * @return array<string,string>
     */
    public static function copy_with_overrides($overrides) {
        $copy = self::stored_copy();
        if (!is_array($overrides)) {
            return $copy;
        }
        foreach (self::default_copy() as $key => $default) {
            if (isset($overrides[$key])) {
                $copy[$key] = (string) $overrides[$key];
            }
        }
        return $copy;
    }

    /**
     * @param array<string,string> $shop_overrides
     * @return array<string,string>
     */
    public static function sample($shop_overrides = array()) {
        $shop = self::shop();
        if (is_array($shop_overrides)) {
            foreach (array('name', 'address', 'phone', 'web') as $key) {
                if (isset($shop_overrides[$key])) {
                    $shop[$key] = (string) $shop_overrides[$key];
                }
            }
        }
        return array(
            'name' => 'Max Muster',
            'service' => 'Haarschnitt',
            'date' => 'Montag, 12.10.2026',
            'time' => '10:00 – 10:30',
            'barber' => 'Lea Muster',
            'price' => "35.00 CHF",
            'phone' => '079 123 45 67',
            'notes' => 'Bitte etwas kürzer an den Seiten.',
            'shop' => $shop['name'],
            'address' => $shop['address'],
            'shop_phone' => $shop['phone'],
            'web' => $shop['web'],
        );
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function send_booking($payload) {
        $vars = array(
            'name' => (string) $payload['customer_name'],
            'service' => (string) $payload['service_list'],
            'date' => (string) $payload['date_label'],
            'time' => (string) $payload['time_label'],
            'barber' => (string) $payload['employee_name'],
            'price' => (string) $payload['price_label'],
            'phone' => (string) $payload['customer_phone'],
            'notes' => trim((string) $payload['notes']) !== '' ? (string) $payload['notes'] : '–',
            'shop' => self::shop()['name'],
            'address' => self::shop()['address'],
            'shop_phone' => self::shop()['phone'],
            'web' => self::shop()['web'],
        );
        $copy = self::stored_copy();
        $headers = array('Content-Type: text/html; charset=UTF-8');

        $customer = self::compose('customer', $vars, $copy);
        wp_mail((string) $payload['customer_email'], $customer['subject'], $customer['html'], $headers);

        $staff = self::compose('staff', $vars, $copy);
        wp_mail((string) $payload['employee_email'], $staff['subject'], $staff['html'], $headers);

        $owner_email = (string) get_option('rewan_booking_notification_email', '');
        if ($owner_email !== '' && strtolower($owner_email) !== strtolower((string) $payload['employee_email'])) {
            $owner = self::compose('owner', $vars, $copy);
            wp_mail($owner_email, $owner['subject'], $owner['html'], $headers);
        }
    }

    /**
     * @return bool
     */
    public static function send_samples($to) {
        $to = sanitize_email($to);
        if (!is_email($to)) {
            return false;
        }
        $vars = self::sample();
        $copy = self::stored_copy();
        $headers = array('Content-Type: text/html; charset=UTF-8');
        $ok = true;
        foreach (array('customer', 'staff', 'owner') as $type) {
            $mail = self::compose($type, $vars, $copy);
            $sent = wp_mail($to, 'Test: ' . $mail['subject'], $mail['html'], $headers);
            if (!$sent) {
                $ok = false;
            }
        }
        return $ok;
    }

    /**
     * @param array<string,string> $vars
     * @param array<string,string> $copy
     * @return array{subject:string,html:string}
     */
    public static function compose($type, $vars, $copy) {
        $type = in_array($type, array('customer', 'staff', 'owner'), true) ? $type : 'customer';
        $subject_key = $type . '_subject';
        $subject = self::fill_plain(isset($copy[$subject_key]) ? $copy[$subject_key] : '', $vars);
        if ($subject === '') {
            $subject = 'Termin';
        }
        return array(
            'subject' => $subject,
            'html' => self::html($type, $vars, $copy),
        );
    }

    /**
     * @param array<string,string> $vars
     * @param array<string,string> $copy
     */
    private static function html($type, $vars, $copy) {
        $intro = self::fill_html(isset($copy[$type . '_intro']) ? $copy[$type . '_intro'] : '', $vars);
        $note = self::fill_html(isset($copy[$type . '_note']) ? $copy[$type . '_note'] : '', $vars);

        $email_style = 'font-family: Arial, sans-serif; background-color: #0a0a0a; color: #f5f1e8; padding: 40px 15px;';
        $container_style = 'max-width: 600px; margin: 0 auto; background: #131313; border: 1px solid #d4af37; border-radius: 22px; padding: 30px;';
        $table_style = 'width: 100%; border-collapse: collapse; margin: 20px 0; font-size: 16px;';
        $td_label = 'padding: 12px 0; border-bottom: 1px dashed rgba(255,255,255,0.1); color: #cbbfa9;';
        $td_value = 'padding: 12px 0; border-bottom: 1px dashed rgba(255,255,255,0.1); text-align: right; color: #fff; font-weight: bold;';

        $rows = '';
        if ($type === 'staff') {
            $rows = self::row('Kunde', $vars['name'], $td_label, $td_value)
                . self::row('Datum', $vars['date'], $td_label, $td_value)
                . self::row('Uhrzeit', $vars['time'], $td_label, $td_value)
                . self::row('Dienstleistung', $vars['service'], $td_label, $td_value)
                . self::row('Notiz', $vars['notes'], $td_label, $td_value);
        } elseif ($type === 'owner') {
            $rows = self::row('Barber', $vars['barber'], $td_label, $td_value)
                . self::row('Kunde', $vars['name'], $td_label, $td_value)
                . self::row('Telefon', $vars['phone'], $td_label, $td_value)
                . self::row('Dienstleistung', $vars['service'], $td_label, $td_value)
                . self::row('Datum', $vars['date'], $td_label, $td_value)
                . self::row('Uhrzeit', $vars['time'], $td_label, $td_value)
                . self::row('Preis', $vars['price'], $td_label, $td_value);
        } else {
            $rows = self::row('Dienstleistung', $vars['service'], $td_label, $td_value)
                . self::row('Datum', $vars['date'], $td_label, $td_value)
                . self::row('Zeit', $vars['time'], $td_label, $td_value)
                . self::row('Barber', $vars['barber'], $td_label, $td_value)
                . self::row('Preis', $vars['price'], $td_label, $td_value);
        }

        $shop_box = self::shop_box($vars);
        $note_html = $note !== ''
            ? "<p style='font-size: 14px; color: #cbbfa9; text-align: center; margin-top: 22px;'>{$note}</p>"
            : '';
        $owner_link = '';
        if ($type === 'owner') {
            $url = esc_url(admin_url('admin.php?page=rewan-booking-bookings'));
            $owner_link = "<div style='text-align: center; margin-top: 20px;'><a href='{$url}' style='background: #d4af37; color: #111; padding: 12px 25px; border-radius: 10px; text-decoration: none; font-weight: bold;'>Buchungen öffnen</a></div>";
        }
        $title = $type === 'customer' ? 'Dein Termin steht' : ($type === 'staff' ? 'Neuer Termin' : 'Neue Buchung');

        return "<div style='{$email_style}'><div style='{$container_style}'>"
            . "<h2 style='color: #d4af37; text-align: center; font-size: 28px; margin-top: 0;'>{$title}</h2>"
            . "<p style='text-align: center; font-size: 17px; color: #ddd4c3;'>{$intro}</p>"
            . "<table style='{$table_style}'>{$rows}</table>"
            . $shop_box
            . $note_html
            . $owner_link
            . '</div></div>';
    }

    /**
     * @param array<string,string> $vars
     */
    private static function shop_box($vars) {
        $lines = array();
        if (trim($vars['address']) !== '') {
            $lines[] = nl2br(esc_html($vars['address']));
        }
        if (trim($vars['shop_phone']) !== '') {
            $lines[] = 'Telefon: ' . esc_html($vars['shop_phone']);
        }
        if (trim($vars['web']) !== '') {
            $lines[] = esc_html($vars['web']);
        }
        $detail = empty($lines) ? '' : "<p style='margin: 5px 0 0; font-size: 14px; color: #cbbfa9;'>" . implode('<br>', $lines) . '</p>';
        return "<div style='background: rgba(212,175,55,0.1); border-radius: 12px; padding: 15px; margin-top: 8px; text-align: center;'>"
            . "<p style='margin: 0; color: #d4af37; font-weight: bold;'>" . esc_html($vars['shop']) . '</p>'
            . $detail
            . '</div>';
    }

    private static function row($label, $value, $td_label, $td_value) {
        return '<tr><td style="' . $td_label . '">' . esc_html($label) . '</td><td style="' . $td_value . '">' . esc_html((string) $value) . '</td></tr>';
    }

    /**
     * @param array<string,string> $vars
     */
    private static function fill_plain($template, $vars) {
        $out = (string) $template;
        foreach ($vars as $key => $value) {
            $out = str_replace('{' . $key . '}', (string) $value, $out);
        }
        $out = trim(preg_replace('/\s+/', ' ', $out));
        return $out;
    }

    /**
     * @param array<string,string> $vars
     */
    private static function fill_html($template, $vars) {
        $out = esc_html((string) $template);
        foreach ($vars as $key => $value) {
            $out = str_replace('{' . $key . '}', esc_html((string) $value), $out);
        }
        return nl2br($out);
    }
}
