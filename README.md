# Rewan Booking

WordPress-Plugin für das **Buchungssystem des Barbershop Rewan** ([barbershop-rewan.ch](https://barbershop-rewan.ch/)). Kunden buchen Dienstleistungen und Mitarbeiter über ein Formular auf der Website; die Verwaltung erfolgt im WordPress-Admin.

**Version:** 1.0.0 · **Lizenz:** GPL-2.0 · **Textdomain:** `rewan-booking`

---

## Projektstruktur

```
rewan-booking/
├── rewan-booking.php              # Plugin-Hauptdatei (Header, Konstanten, Hooks)
├── includes/
│   ├── class-rewan-booking-activator.php   # Aktivierung: DB-Tabellen, Standarddaten
│   ├── class-rewan-booking-admin.php       # Admin-Menü, Seiten, Formular-Verarbeitung
│   └── class-rewan-booking-frontend.php    # Shortcode, Buchungsformular, AJAX, E-Mails
├── assets/
│   ├── css                        # optional: zusätzliche Styles (siehe Hinweis unten)
│   └── js                         # optional: zusätzliche Skripte
└── README.md
```

### Dateien im Überblick

| Bereich | Datei | Aufgabe |
|--------|--------|---------|
| Bootstrap | `rewan-booking.php` | Lädt Klassen, registriert Aktivierungshook und `plugins_loaded` |
| Datenbank | `class-rewan-booking-activator.php` | Erstellt Tabellen mit `dbDelta`, fügt Beispiel-Dienstleistungen und -Mitarbeiter ein |
| Backend | `class-rewan-booking-admin.php` | Dashboard, Kalender, CRUD für Services, Mitarbeiter, Abwesenheiten, Buchungen |
| Frontend | `class-rewan-booking-frontend.php` | Shortcode `[rewan_booking_form]`, Zeitslot-AJAX, Buchungsabschluss, Benachrichtigungen |

Bei Aktivierung werden folgende Tabellen mit Präfix `wp_` (bzw. deinem `$table_prefix`) angelegt:

- `rewan_booking_services` — Dienstleistungen (Name, Beschreibung, Bild-URL, Preis, Dauer, aktiv)
- `rewan_booking_employees` — Mitarbeiter (Name, E-Mail, Bild, aktiv)
- `rewan_booking_employee_hours` — Arbeitszeiten pro Wochentag
- `rewan_booking_employee_breaks` — Pausen pro Wochentag
- `rewan_booking_employee_absences` — Abwesenheiten / Urlaub / Sondertitel
- `rewan_booking_bookings` — Buchungen inkl. Kundendaten, Zeiten, Preis, Status, Zahlungsart

---

## Funktionen und Eigenschaften

### Öffentliche Website

- **Shortcode:** `[rewan_booking_form]` — Buchungsformular in beliebige Seite/Beitrag einbinden
- Auswahl **Dienstleistung(en)**, **Mitarbeiter**, **Datum**; freie **Zeitslots** per **AJAX** (`rewan_booking_get_slots`) unter Berücksichtigung von Arbeitszeiten, Pausen und Abwesenheiten
- Responsives UI mit integriertem Styling (dunkles Theme, goldene Akzente)
- Nach erfolgreicher Buchung: **E-Mail an Kunde**, an **Mitarbeiter** und optional an die im Admin hinterlegte **Benachrichtigungs-E-Mail**

### WordPress-Admin („Rewan Booking“)

- **Dashboard:** Kennzahlen (Termine heute/Woche/Monat, Umsatz, Stornos), nächste Termine, Verteilung pro Mitarbeiter, Einstellung Benachrichtigungs-E-Mail
- **Kalender:** Übersicht der Termine
- **Dienstleistungen:** anlegen, bearbeiten, löschen (Preis in CHF, Dauer in Minuten, optional Bild-URL)
- **Mitarbeiter:** Stammdaten, Arbeitszeiten und Pausen pro Wochentag
- **Abwesenheiten:** Einträge und Schnellaktionen (z. B. freier Tag)
- **Buchungen:** Liste, Bearbeitung, Löschen; Status (z. B. bestätigt / storniert) und Zahlungsart (z. B. vor Ort)

### Technik

- WordPress-APIs: `$wpdb`, Nonces, `admin_post` / `admin_post_nopriv`, `wp_ajax` / `wp_ajax_nopriv`, `wp_mail`
- Keine Composer-Abhängigkeiten; klassisches PHP in einer Plugin-Struktur

---

## Installation

1. Ordner `rewan-booking` nach `wp-content/plugins/` kopieren (oder als ZIP unter **Plugins → Installieren → Hochladen**).
2. Plugin **Rewan Booking** aktivieren — Tabellen werden automatisch erstellt.
3. Eine Seite anlegen und den Shortcode `[rewan_booking_form]` einfügen.
4. Unter **Rewan Booking** Dienstleistungen, Mitarbeiter und E-Mail für Benachrichtigungen pflegen.

Voraussetzungen: WordPress mit PHP und eine funktionierende E-Mail-Konfiguration (für `wp_mail`), falls Benachrichtigungen genutzt werden.

---

## Hinweis zu `assets/`

Im Repository sind unter `assets/css` und `assets/js` Platzhalter vorgesehen. Das Formular-Styling liegt überwiegend **inline** in `class-rewan-booking-frontend.php`. Zusätzliche Dateien kannst du bei Bedarf ergänzen und in den Klassen per `wp_enqueue_*` einbinden.

---

## Repository

https://github.com/oggy098/rewan-booking
