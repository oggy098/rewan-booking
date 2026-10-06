# Rewan Booking

Eigenes WordPress-Buchungssystem für den Barbershop. Kunden buchen auf der Website Dienstleistung, Mitarbeiter und Uhrzeit. Der Salon verwaltet Termine im WordPress-Admin, im dunklen Gold-Look der Seite.

**Version:** 1.3.0 · **Lizenz:** GPL-2.0 · **Shortcode:** `[rewan_booking_form]`

Repository: [github.com/oggy098/rewan-booking](https://github.com/oggy098/rewan-booking)

---

## Was das Plugin kann

- Buchungsformular auf der Website, dunkel mit Gold
- Freie Zeiten alle 30 Minuten, passend zur Dauer der Dienstleistung
- E-Mail an Kunde, Mitarbeiter und eine Benachrichtigungsadresse
- Admin ohne Bookly: Dashboard, Kalender, Buchungen, Öffnungszeiten, Sperrzeit, Ferien, Dienstleistungen, Mitarbeiter
- Update über GitHub, ohne bestehende Buchungen zu löschen

Bookly wird nicht mehr geladen. Das Plugin kann allein laufen.

---

## Admin

| Bereich | Seiten |
| --- | --- |
| Heute | Dashboard, Kalender, Buchungen |
| Zeiten | Öffnungszeiten, Sperrzeit, Ferien |
| Angebot | Dienstleistungen, Mitarbeiter |

**Öffnungszeiten** sind der Rahmen: pro Wochentag geöffnet oder zu, von und bis.

**Slots** kommen aus der Arbeitszeit der Person, aber nur innerhalb der Öffnung. Neue Mitarbeiter starten mit „Arbeitet in den Öffnungszeiten“. Bestehende Mitarbeiter behalten ihre eigenen Zeiten. Pause, Sperrzeit, Ferien und schon gebuchte Termine fallen danach weg.

---

## Installation

1. Ordner `rewan-booking` nach `wp-content/plugins/` kopieren.
2. Plugin **Rewan Booking** aktivieren. Tabellen werden angelegt, vorhandene Einträge bleiben.
3. Eine Seite mit dem Shortcode `[rewan_booking_form]` anlegen.
4. Öffnungszeiten, Dienstleistungen, Mitarbeiter und die Benachrichtigungs-E-Mail pflegen.

Voraussetzung: WordPress mit PHP und eine funktionierende `wp_mail`-Einrichtung, wenn E-Mails rausgehen sollen.

---

## Update, ohne Einträge zu verlieren

Buchungen, Dienstleistungen, Mitarbeiter, Zeiten und Einstellungen liegen in der WordPress-Datenbank. Ein Update ersetzt nur Dateien im Plugin-Ordner.

1. In `rewan-booking.php` die Zeile `Version:` und `REWAN_BOOKING_VERSION` gemeinsam erhöhen, zum Beispiel `1.3.1`.
2. Nach `main` pushen.
3. In WordPress unter **Dashboard → Aktualisierungen** prüfen. WordPress übernimmt die neue Version und lässt die Datenbankeinträge stehen.

Ist das Repo öffentlich, braucht es keinen GitHub-Token. Ist es privat, im Plugin-Dashboard einen Token mit Leserecht auf dieses Repo speichern.

Die Version **1.3.0** einmal per Datei-Upload auf die Website legen, damit die Update-Funktion dort ankommt. Danach reichen Push und die höhere Versionsnummer.

---

## Projektstruktur

```
rewan-booking/
├── rewan-booking.php
├── includes/
│   ├── class-rewan-booking-activator.php   # Tabellen, Update ohne Datenverlust
│   ├── class-rewan-booking-admin.php       # Admin-Seiten
│   ├── class-rewan-booking-frontend.php    # Formular, Slots, E-Mails
│   ├── class-rewan-booking-schedule.php    # Öffnung und wirksame Arbeitszeit
│   ├── class-rewan-booking-updater.php     # Update von GitHub main
│   └── rewan-booking-calendar-bookly.php   # Ferien-Kalender, ohne Bookly-Plugin
└── assets/css, assets/js
```

Tabellen (Präfix in der Regel `wp_`):

- `rewan_booking_services`
- `rewan_booking_employees`
- `rewan_booking_employee_hours`
- `rewan_booking_employee_breaks`
- `rewan_booking_employee_absences`
- `rewan_booking_bookings`
- `rewan_booking_global_week_schedule` — wiederkehrende Sperrzeiten
- `rewan_booking_opening_hours` — Laden-Öffnungszeiten
