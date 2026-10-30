# StayInLeipzig AutoCheckin – Mechlerstraße

Modernes, sicheres und mobiles AutoCheckin-System für die Ferienwohnung Mechlerstraße in Leipzig.

## ✨ Highlights & Features

- **📱 Modernes Mobile-First Design**: Touch-optimiert für Smartphones (iPhone & Android), Deutsch & Englisch umschaltbar.
- **🔗 Direkter Check-in-Link (`?code=1234` oder `?pin=1234`)**:
  - Übergabe per Link z. B.: `https://checkin.stayinleipzig.de/mechler?code=24818`
  - PIN wird automatisch geladen und verifiziert – der Gast muss nichts mehr tippen und kann mit 1 Klick die Haustür oder Wohnungstür öffnen!
- **⌨️ On-Screen Touch-Keypad**: Falls der Gast den PIN manuell eintippen möchte (Große Zifferntasten 0–9, Löschen, Haptik).
- **⏳ Zeitfenster-Gültigkeit (Check-in bis Check-out)**:
  - Jeder PIN ist nur im gebuchten Zeitraum (von `valid_from` bis `valid_until`) gültig.
  - Vor dem Check-in oder nach dem Check-out verfällt der PIN automatisch.
  - Einstellbare Kulanzzeiten (z. B. 30 Min. früherer Check-in, 60 Min. verspäteter Check-out).
- **📅 Booking.com iCal Auto-Sync**:
  - `booking_sync.py` liest automatisch die Reservierungen aus dem Booking.com iCal-Kalender aus und erzeugt passende PINs.
- **🛡️ Brute-Force & Ratenbegrenzung**:
  - Max. 5 Fehlversuche pro IP, danach 10 Minuten Sperre.
  - Ausführliches Audit-Log (`access_log.json`) mit Zeitstempel, Gast, Tür und Status.
- **🚪 Tür-Steuerung**:
  - Haustür: Öffnung für 10 Sekunden (mit Live-Countdown).
  - Wohnungstür: Öffnung für 30 Sekunden (mit Live-Countdown).
  - Direkte Steuerung über FHEM (lokal oder über den Cloudflare-Tunnel `fhem-tuer-mechler-leipzig.stayinleipzig.de`).
- **👑 Admin-Dashboard**:
  - Unter `/admin.html` (Master-PIN: `04157` oder `octasias`):
  - PINs anlegen, bearbeiten, löschen, Zufalls-PINs generieren, Gast-Links kopieren und Live-Logs einsehen.

---

## 📁 Dateistruktur

```text
autocheckin/mechler/
├── index.html          # Modernes Web-Frontend (Gast-Interface, GitHub Pages tauglich)
├── index.php           # PHP-Wrapper für Standard-Webspace
├── api.php             # PHP-Backend (PIN-Prüfung, Zeitfenster, FHEM-Ansteuerung)
├── server.py           # Standalone Python-Server (läuft auf Raspberry Pi oder PC)
├── pins.json           # Datenbank aller aktiven und zeitgesteuerten PINs
├── admin.html          # Admin-Dashboard (PINs verwalten, Links kopieren, Logs)
├── admin.php           # PHP-Wrapper für das Admin-Dashboard
├── booking_sync.py     # Booking.com iCal-Synchronisation
└── _legacy_backup/     # Gesichertes Original-System
```

---

## 🚀 Bereitstellung & Hosting

### Option 1: Auf deinem Webserver (`stayinleipzig.de`)
Lade den Ordner `mechler` einfach auf deinen Webspace unter `checkin.stayinleipzig.de/mechler` hoch.
Fertig! PHP führt `api.php` aus und leitet Tür-Befehle über den Cloudflare-Tunnel an den Raspberry Pi weiter.

### Option 2: Auf dem Raspberry Pi direkt
Auf dem Raspberry Pi kann `server.py` direkt im Hintergrund laufen:
```bash
python3 /home/pi/autocheckin/mechler/server.py
```
Über den Cloudflare-Tunnel ist die Seite direkt weltweit unter `https://fhem-tuer-mechler-leipzig.stayinleipzig.de/` erreichbar.

### Option 3: GitHub Pages
1. Initialisiere dieses Verzeichnis als Git-Repository:
   ```bash
   git init
   git add .
   git commit -m "Initial commit AutoCheckin Mechlerstr"
   ```
2. Erstelle ein Repository bei GitHub (z. B. `companyukns/autocheckin`).
3. Push zu GitHub und aktiviere **GitHub Pages** unter Settings ➔ Pages.
4. Fertig!
