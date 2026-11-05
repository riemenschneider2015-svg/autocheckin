#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
AutoCheckin Server & FHEM Reverse Proxy for StayInLeipzig (Mechlerstr.)
100% Python 3.5+ compatible.
Listens on port 8083 (target of Cloudflare Tunnel).
Provides CORS-enabled API for GitHub Pages and proxies /fhem to local FHEM on port 8086.
"""

import http.server
import json
import os
import re
import socketserver
import subprocess
import time
import urllib.parse
import urllib.request
from datetime import datetime, timedelta

PORT = 8083
FHEM_PORT = 8086
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
os.chdir(BASE_DIR)

PINS_FILE = os.path.join(BASE_DIR, "pins.json")
LOG_FILE = os.path.join(BASE_DIR, "access_log.json")
RATE_FILE = os.path.join(BASE_DIR, "ratelimit.json")

def parse_iso(dt_str):
    if not dt_str:
        return datetime.now()
    clean = str(dt_str).strip().replace(" ", "T")
    if "T" in clean:
        parts = clean.split("T")
        date_part = parts[0]
        time_part = parts[1]
        t_sub = time_part.split(":")
        hh = t_sub[0].zfill(2)
        mm = t_sub[1].zfill(2) if len(t_sub) > 1 else "00"
        ss = t_sub[2][:2].zfill(2) if len(t_sub) > 2 else "00"
        return datetime.strptime("{0} {1}:{2}:{3}".format(date_part, hh, mm, ss), "%Y-%m-%d %H:%M:%S")
    return datetime.strptime(clean[:10], "%Y-%m-%d")

def load_data():
    if os.path.exists(PINS_FILE):
        try:
            with open(PINS_FILE, "r", encoding="utf-8") as f:
                return json.load(f)
        except Exception:
            pass
    return {"settings": {}, "pins": []}

def save_data(data):
    with open(PINS_FILE, "w", encoding="utf-8") as f:
        json.dump(data, f, indent=2, ensure_ascii=False)

def log_access(ip, pin, guest, door, success, message):
    logs = []
    if os.path.exists(LOG_FILE):
        try:
            with open(LOG_FILE, "r", encoding="utf-8") as f:
                logs = json.load(f)
        except Exception:
            logs = []
    logs.append({
        "timestamp": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        "ip": ip,
        "pin": pin[:2] + "***" if len(pin) > 2 else "***",
        "guest": guest or "Unbekannt",
        "door": door,
        "success": success,
        "message": message
    })
    if len(logs) > 500:
        logs = logs[-500:]
    try:
        with open(LOG_FILE, "w", encoding="utf-8") as f:
            json.dump(logs, f, indent=2, ensure_ascii=False)
    except Exception:
        pass

def check_rate_limit(ip, max_attempts=5, lockout_minutes=10):
    rates = {}
    if os.path.exists(RATE_FILE):
        try:
            with open(RATE_FILE, "r", encoding="utf-8") as f:
                rates = json.load(f)
        except Exception:
            rates = {}
    now = time.time()
    if ip in rates:
        entry = rates[ip]
        if entry.get("locked_until", 0) > now:
            rem = int((entry["locked_until"] - now) // 60) + 1
            return False, "Zu viele Fehlversuche. Gesperrt für {0} Minute(n). Bitte Falko anrufen (015253453390).".format(rem)
    return True, ""

def record_attempt(ip, success, max_attempts=5, lockout_minutes=10):
    rates = {}
    if os.path.exists(RATE_FILE):
        try:
            with open(RATE_FILE, "r", encoding="utf-8") as f:
                rates = json.load(f)
        except Exception:
            rates = {}
    now = time.time()
    if success:
        rates.pop(ip, None)
    else:
        if ip not in rates:
            rates[ip] = {"attempts": 1, "first": now, "locked_until": 0}
        else:
            rates[ip]["attempts"] += 1
        if rates[ip]["attempts"] >= max_attempts:
            rates[ip]["locked_until"] = now + (lockout_minutes * 60)
    try:
        with open(RATE_FILE, "w", encoding="utf-8") as f:
            json.dump(rates, f)
    except Exception:
        pass

def find_pin(pin_code, pins, settings):
    now = datetime.now()
    grace_before = timedelta(minutes=settings.get("grace_period_minutes_before", 0))
    grace_after = timedelta(minutes=settings.get("grace_period_minutes_after", 60))

    matching_entries = []
    for p in pins:
        if str(p.get("pin", "")).strip() == str(pin_code).strip() and p.get("active", True):
            try:
                valid_from = parse_iso(p.get("valid_from")) - grace_before
                valid_until = parse_iso(p.get("valid_until")) + grace_after
                matching_entries.append((p, valid_from, valid_until))
            except Exception:
                continue

    if not matching_entries:
        return False, None, "not_found"

    # 1. Check if any matching entry is CURRENTLY active
    for p, v_from, v_until in matching_entries:
        if v_from <= now <= v_until:
            return True, p, "active"

    # 2. Check future bookings (closest upcoming first)
    future_entries = [(p, v_from, v_until) for (p, v_from, v_until) in matching_entries if now < v_from]
    if future_entries:
        future_entries.sort(key=lambda x: x[1])
        return False, future_entries[0][0], "future"

    # 3. All matching bookings are expired (most recently ended)
    expired_entries = [(p, v_from, v_until) for (p, v_from, v_until) in matching_entries if now > v_until]
    if expired_entries:
        expired_entries.sort(key=lambda x: x[2], reverse=True)
        return False, expired_entries[0][0], "expired"

    return False, None, "not_found"

def trigger_door(door, settings):
    # 1. Try direct hardware GPIO script
    script = "/opt/fhem/door.sh" if door == "haustuer" else "/opt/fhem/wohnungstuer.sh"
    if os.path.exists(script):
        try:
            subprocess.Popen(["sudo", script])
            return True, "Relay geschaltet"
        except Exception as e:
            pass

    # 2. Try FHEM on port 8086 or 8083
    cmd = "set%20Tuer%20on" if door == "haustuer" else "set%20Wohnungstuer%20on"
    urls = [
        "http://127.0.0.1:{0}".format(FHEM_PORT),
        "http://127.0.0.1:8084",
        "http://127.0.0.1:8085"
    ]
    user = settings.get("fhem_user", "admin")
    password = settings.get("fhem_pass", "octasias")

    import base64
    auth_str = "{0}:{1}".format(user, password)
    auth_header = "Basic " + base64.b64encode(auth_str.encode()).decode()

    for base_url in urls:
        target_url = "{0}/fhem?cmd={1}&room=all&XHR=1".format(base_url, cmd)
        try:
            req = urllib.request.Request(target_url, headers={"Authorization": auth_header})
            with urllib.request.urlopen(req, timeout=3) as resp:
                if resp.status == 200:
                    return True, "FHEM OK"
        except Exception:
            continue
    return False, "Fehler beim Auslösen der Tür"

class CheckinHandler(http.server.SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)

    def do_OPTIONS(self):
        self.send_response(200)
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("X-Robots-Tag", "noindex, nofollow, noarchive, nosnippet")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type, Authorization, X-Requested-With")
        self.send_header("Access-Control-Max-Age", "86400")
        self.end_headers()

    def do_GET(self):
        parsed = urllib.parse.urlparse(self.path)
        # 1. Proxy to FHEM
        if parsed.path.startswith("/fhem"):
            self.proxy_to_fhem()
            return

        # 2. API Routes
        if parsed.path in ("/api.php", "/api/verify"):
            self.handle_verify(parsed)
        elif parsed.path in ("/api/admin_list",):
            self.handle_admin_list(parsed)
        elif parsed.path in ("/", "/mechler", "/mechler/"):
            self.path = "/index.html"
            super().do_GET()
        elif parsed.path in ("/admin", "/admin/"):
            self.path = "/admin.html"
            super().do_GET()
        else:
            super().do_GET()

    def do_POST(self):
        parsed = urllib.parse.urlparse(self.path)
        if parsed.path.startswith("/fhem"):
            self.proxy_to_fhem()
            return

        length = int(self.headers.get("Content-Length", 0))
        body = self.rfile.read(length).decode("utf-8") if length > 0 else ""
        try:
            payload = json.loads(body) if body else {}
        except Exception:
            payload = dict(urllib.parse.parse_qsl(body))

        if parsed.path in ("/api.php", "/api/open"):
            qs_dict = urllib.parse.parse_qs(parsed.query)
            action = payload.get("action", qs_dict.get("action", ["open"])[0])
            if action == "verify":
                self.handle_verify_payload(payload)
            elif action == "admin_save_pin":
                self.handle_admin_save(payload)
            elif action == "admin_delete_pin":
                self.handle_admin_delete(payload)
            else:
                self.handle_open(payload)
        else:
            self.send_error(404, "Not Found")

    def proxy_to_fhem(self):
        target_url = "http://127.0.0.1:{0}".format(FHEM_PORT) + self.path
        req = urllib.request.Request(target_url)
        for k, v in self.headers.items():
            if k.lower() not in ("host", "content-length"):
                req.add_header(k, v)

        data = None
        if self.command == "POST":
            length = int(self.headers.get("Content-Length", 0))
            if length > 0:
                data = self.rfile.read(length)

        try:
            with urllib.request.urlopen(req, data=data, timeout=8) as resp:
                self.send_response(resp.status)
                for k, v in resp.headers.items():
                    if k.lower() not in ("transfer-encoding",):
                        self.send_header(k, v)
                self.send_header("Access-Control-Allow-Origin", "*")
                self.end_headers()
                self.wfile.write(resp.read())
        except urllib.error.HTTPError as e:
            self.send_response(e.code)
            for k, v in e.headers.items():
                if k.lower() not in ("transfer-encoding",):
                    self.send_header(k, v)
            self.send_header("Access-Control-Allow-Origin", "*")
            self.end_headers()
            self.wfile.write(e.read())
        except Exception as e:
            self.send_json({"error": "FHEM Proxy Error: " + str(e)}, code=502)

    def send_json(self, data, code=200):
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type, Authorization, X-Requested-With")
        self.end_headers()
        self.wfile.write(json.dumps(data, ensure_ascii=False).encode("utf-8"))

    def get_client_ip(self):
        cf_ip = self.headers.get("CF-Connecting-IP")
        if cf_ip: return cf_ip
        xf = self.headers.get("X-Forwarded-For")
        if xf: return xf.split(",")[0].strip()
        return self.client_address[0]

    def handle_verify(self, parsed):
        qs = urllib.parse.parse_qs(parsed.query)
        action = qs.get("action", ["verify"])[0]
        if action == "admin_list":
            self.handle_admin_list(parsed)
            return
        if action == "ical":
            self.handle_ical(parsed)
            return
        if action == "cleaning_ical":
            self.handle_cleaning_ical(parsed)
            return
        pin = qs.get("pin", [""])[0].strip()
        self.verify_pin_response(pin)

    def handle_verify_payload(self, payload):
        pin = payload.get("pin", "").strip()
        self.verify_pin_response(pin)

    def verify_pin_response(self, pin):
        ip = self.get_client_ip()
        data = load_data()
        settings = data.get("settings", {})
        pins = data.get("pins", [])
        valid, entry, status = find_pin(pin, pins, settings)

        if valid:
            log_access(ip, pin, entry.get("name", "Gast"), "verify", True, "PIN geprüft: Aktiv für " + str(entry.get("name", "Gast")))
            self.send_json({
                "success": True,
                "status": "active",
                "guest": entry.get("name", "Gast"),
                "valid_from": entry.get("valid_from"),
                "valid_until": entry.get("valid_until"),
                "message": "PIN gueltig"
            })
        else:
            msg = "Falscher PIN"
            if status == "future":
                msg = "Check-in ist erst ab {0} moeglich.".format(entry.get("valid_from") if entry else "")
            elif status == "expired":
                msg = "PIN ist abgelaufen (Check-out war {0}).".format(entry.get("valid_until") if entry else "")
            log_access(ip, pin, entry.get("name") if entry else "", "verify", False, "PIN Pruefung: " + msg)
            self.send_json({
                "success": False,
                "status": status,
                "error": msg,
                "valid_from": entry.get("valid_from") if entry else None,
                "valid_until": entry.get("valid_until") if entry else None
            })

    def handle_open(self, payload):
        ip = self.get_client_ip()
        data = load_data()
        settings = data.get("settings", {})
        pins = data.get("pins", [])

        allowed, rmsg = check_rate_limit(ip, settings.get("max_failed_attempts", 5), settings.get("lockout_minutes", 10))
        if not allowed:
            log_access(ip, payload.get("pin", ""), "", payload.get("door", ""), False, "Gesperrt wegen Rate-Limit")
            self.send_json({"success": False, "error": rmsg, "locked": True}, code=429)
            return

        pin = str(payload.get("pin", "")).strip()
        door = str(payload.get("door", "haustuer")).strip()

        valid, entry, status = find_pin(pin, pins, settings)
        if not valid:
            record_attempt(ip, False, settings.get("max_failed_attempts", 5), settings.get("lockout_minutes", 10))
            msg = "Falscher PIN!"
            if status == "future":
                msg = "Ihr PIN ist noch nicht aktiv (ab {0}).".format(entry.get("valid_from") if entry else "")
            elif status == "expired":
                msg = "Ihr PIN ist abgelaufen (Check-out war {0}).".format(entry.get("valid_until") if entry else "")
            log_access(ip, pin, entry.get("name") if entry else "", door, False, msg)
            self.send_json({
                "success": False,
                "error": msg,
                "status": status,
                "contact": "015253453390",
                "valid_from": entry.get("valid_from") if entry else None,
                "valid_until": entry.get("valid_until") if entry else None
            })
            return

        record_attempt(ip, True)
        ok, res_msg = trigger_door(door, settings)
        door_name = "Haustür" if door == "haustuer" else "Wohnungstür"
        seconds = settings.get("door_open_seconds_house", 10) if door == "haustuer" else settings.get("door_open_seconds_apt", 30)

        if ok:
            directions = ("Bitte immer kräftig gegen die Haustür drücken, wenn es summt! Danach bis ins Dachgeschoss laufen (Tür in der Mitte mit dem grauen Willkommen-Schild)."
                          if door == "haustuer" else "Bitte immer kräftig gegen die Wohnungstür drücken, wenn es summt. Herzlich Willkommen!")
            
            # Record check-in timestamp on first successful door open
            now_str = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
            is_first = not entry.get("checked_in_at")
            if is_first:
                data = load_data()
                for p in data.get("pins", []):
                    if p.get("pin") == pin:
                        p["checked_in_at"] = now_str
                        p["first_door"] = door_name
                        break
                save_data(data)

            log_msg = "{0} geöffnet".format(door_name) + (" (Erster Check-in)" if is_first else "")
            log_access(ip, pin, entry.get("name", "Gast"), door, True, log_msg)
            self.send_json({
                "success": True,
                "door": door,
                "door_name": door_name,
                "seconds": seconds,
                "message": "{0} wurde entriegelt!".format(door_name),
                "directions": directions,
                "guest": entry.get("name", "Gast")
            })
        else:
            log_access(ip, pin, entry.get("name", "Gast"), door, False, "Fehler Türsteuerung: " + res_msg)
            self.send_json({"success": False, "error": "Türsteuerung nicht erreichbar. Bitte Falko anrufen (015253453390).", "contact": "015253453390"})

    @staticmethod
    def fold_ical(line, max_len=75):
        enc = line.encode("utf-8")
        if len(enc) <= max_len:
            return enc + b"\r\n"
        parts = []
        idx = 0
        first = True
        while idx < len(enc):
            limit = max_len if first else (max_len - 1)
            end = min(idx + limit, len(enc))
            while end < len(enc) and (enc[end] & 0xC0) == 0x80:
                end -= 1
            chunk = enc[idx:end]
            if first:
                parts.append(chunk)
                first = False
            else:
                parts.append(b" " + chunk)
            idx = end
        return b"\r\n".join(parts) + b"\r\n"

    @staticmethod
    def escape_ical(text):
        if not text: return ""
        t = str(text).replace("\\", "\\\\").replace(";", "\\;").replace(",", "\\,")
        return t.replace("\r\n", "\\n").replace("\n", "\\n").replace("\r", "\\n")

    def handle_ical(self, parsed):
        qs = urllib.parse.parse_qs(parsed.query)
        admin_pin = qs.get("admin_pin", [""])[0].strip()
        if admin_pin not in ("04157", "octasias"):
            self.send_response(403)
            self.send_header("Content-Type", "text/plain; charset=utf-8")
            self.end_headers()
            self.wfile.write(b"403 Unauthorized: Invalid admin_pin")
            return

        data = load_data()
        pins = data.get("pins", [])
        now_stamp = datetime.utcnow().strftime("%Y%m%dT%H%M%SZ")
        lines = [
            "BEGIN:VCALENDAR",
            "PRODID:-//StayInLeipzig//AutoCheckin Mechlerstr 10//DE",
            "VERSION:2.0",
            "CALSCALE:GREGORIAN",
            "METHOD:PUBLISH",
            "X-WR-CALNAME:StayInLeipzig Mechlerstr. 10",
            "X-WR-TIMEZONE:Europe/Berlin",
            "X-WR-CALDESC:Buchungen fuer Ferienwohnung Mechlerstr. 10 Leipzig",
            "BEGIN:VTIMEZONE",
            "TZID:Europe/Berlin",
            "X-LIC-LOCATION:Europe/Berlin",
            "BEGIN:DAYLIGHT",
            "TZOFFSETFROM:+0100",
            "TZOFFSETTO:+0200",
            "TZNAME:CEST",
            "DTSTART:19700329T020000",
            "RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU",
            "END:DAYLIGHT",
            "BEGIN:STANDARD",
            "TZOFFSETFROM:+0200",
            "TZOFFSETTO:+0100",
            "TZNAME:CET",
            "DTSTART:19701025T030000",
            "RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU",
            "END:STANDARD",
            "END:VTIMEZONE"
        ]

        def to_dt(s):
            clean = s.replace(" ", "T").replace("-", "").replace(":", "")
            if "T" not in clean: clean += "T000000"
            p = clean.split("T")
            return p[0][:8] + "T" + (p[1] + "000000")[:6]

        for p in pins:
            if p.get("role") == "admin": continue
            name = p.get("name", "Gast")
            pin = str(p.get("pin", ""))
            booking_id = p.get("booking_id", "PIN-" + pin)
            valid_from = p.get("valid_from", "")
            valid_until = p.get("valid_until", "")
            if not valid_from or not valid_until: continue

            dtstart = to_dt(valid_from)
            dtend = to_dt(valid_until)
            uid = "{0}@stayinleipzig.de".format(booking_id)

            desc = (
                "Gast: {0}\n"
                "PIN: {1}\n"
                "1-Klick Check-in: https://checkin.stayinleipzig.de/?code={1}\n"
                "Buchungs-ID: {2}\n"
                "Wohnung: Mechlerstraße 10, Dachgeschoss Mitte\n"
                "WLAN: gigacube-EA6A (PW: Leipzig2025#)\n"
                "Host Falko: 0152 53453390"
            ).format(name, pin, booking_id)

            lines.extend([
                "BEGIN:VEVENT",
                "UID:{0}".format(uid),
                "DTSTAMP:{0}".format(now_stamp),
                "DTSTART;TZID=Europe/Berlin:{0}".format(dtstart),
                "DTEND;TZID=Europe/Berlin:{0}".format(dtend),
                "SUMMARY:{0} (PIN: {1})".format(self.escape_ical(name), pin),
                "DESCRIPTION:{0}".format(self.escape_ical(desc)),
                "LOCATION:{0}".format(self.escape_ical("Mechlerstraße 10, 04105 Leipzig, Deutschland")),
                "STATUS:CONFIRMED",
                "TRANSP:OPAQUE",
                "END:VEVENT"
            ])

        lines.append("END:VCALENDAR")

        out = bytearray()
        for l in lines:
            c = l.strip("\r\n")
            if c: out.extend(self.fold_ical(c))

        self.send_response(200)
        self.send_header("Content-Type", "text/calendar; charset=utf-8")
        self.send_header("Content-Disposition", 'inline; filename="stayinleipzig_mechler.ics"')
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Content-Length", str(len(out)))
        self.end_headers()
        self.wfile.write(bytes(out))

    def handle_cleaning_ical(self, parsed):
        data = load_data()
        pins = data.get("pins", [])

        guest_stays = []
        for p in pins:
            if p.get("role") == "admin": continue
            valid_from = p.get("valid_from", "")
            valid_until = p.get("valid_until", "")
            if not valid_from or not valid_until: continue
            try:
                d_from = parse_iso(valid_from)
                d_until = parse_iso(valid_until)
                guest_stays.append({
                    "name": p.get("name", "Gast"),
                    "pin": str(p.get("pin", "")),
                    "booking_id": p.get("booking_id", ""),
                    "from": d_from,
                    "until": d_until
                })
            except Exception: pass

        guest_stays.sort(key=lambda s: s["until"])
        now_stamp = datetime.utcnow().strftime("%Y%m%dT%H%M%SZ")

        lines = [
            "BEGIN:VCALENDAR",
            "PRODID:-//StayInLeipzig//Putzplan Mechlerstr 10//DE",
            "VERSION:2.0",
            "CALSCALE:GREGORIAN",
            "METHOD:PUBLISH",
            "X-WR-CALNAME:Putzplan - Mechlerstr. 10 Leipzig",
            "X-WR-TIMEZONE:Europe/Berlin",
            "X-WR-CALDESC:Reinigungsplan fuer Ferienwohnung Mechlerstrasse 10",
            "BEGIN:VTIMEZONE",
            "TZID:Europe/Berlin",
            "X-LIC-LOCATION:Europe/Berlin",
            "BEGIN:DAYLIGHT",
            "TZOFFSETFROM:+0100",
            "TZOFFSETTO:+0200",
            "TZNAME:CEST",
            "DTSTART:19700329T020000",
            "RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU",
            "END:DAYLIGHT",
            "BEGIN:STANDARD",
            "TZOFFSETFROM:+0200",
            "TZOFFSETTO:+0100",
            "TZNAME:CET",
            "DTSTART:19701025T030000",
            "RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU",
            "END:STANDARD",
            "END:VTIMEZONE"
        ]

        def to_ical_dt(dt):
            return dt.strftime("%Y%m%dT%H%M%S")

        for stay in guest_stays:
            co = stay["until"]
            co_str = co.strftime("%d.%m.%Y um %H:%M Uhr")
            dtstart = to_ical_dt(co)
            clean_end = co + timedelta(hours=3)

            next_checkin = None
            for other in guest_stays:
                if other["from"] >= co:
                    if next_checkin is None or other["from"] < next_checkin["from"]:
                        next_checkin = other

            summary = "Reinigung Ferienwohnung (Wohnungswechsel)"
            buffer_info = "Keine direkte Folge-Buchung eingetragen (ausreichend Zeit)."

            if next_checkin:
                ci = next_checkin["from"]
                ci_str = ci.strftime("%d.%m.%Y um %H:%M Uhr")
                gap_hours = (ci - co).total_seconds() / 3600.0
                gap_days = int(gap_hours // 24)

                if co.date() == ci.date():
                    clean_end = ci
                    summary = "DRINGEND: Reinigung (Gaeste-Wechsel HEUTE!)"
                    buffer_info = "ACHTUNG: Neuer Gast reist HEUTE um {0} Uhr an! Bitte bis dahin fertig sein.".format(ci.strftime('%H:%M'))
                elif gap_days == 0:
                    summary = "Reinigung Ferienwohnung"
                    buffer_info = "Naechster Gast kommt in {0} Stunden ({1}).".format(int(gap_hours), ci_str)
                else:
                    buffer_info = "Wohnung ist frei bis zum {0} ({1} Tage Puffer).".format(ci.strftime('%d.%m.%Y'), gap_days)

            dtend = to_ical_dt(clean_end)
            bid = stay['booking_id'] if stay.get('booking_id') else stay['pin']
            uid = "clean-{0}-{1}@stayinleipzig.de".format(bid, dtstart[:8])

            desc = (
                "REINIGUNG & WOHNUNGSWECHSEL\n"
                "Abreise Gast: {0}\n"
                "Status: {1}\n\n"
                "Wo: Mechlerstraße 10, Dachgeschoss Mitte (StayInLeipzig)\n"
                "WLAN: gigacube-EA6A (Passwort: Leipzig2025#)\n"
                "Kontakt Falko: 0152 53453390\n\n"
                "Aufgaben:\n"
                "- Bettwaesche abziehen & frisch beziehen\n"
                "- Handtuecher austauschen\n"
                "- Bad & Dusche gruendlich reinigen\n"
                "- Kueche (Kuehlschrank, Herd, Geschirr kontrollieren)\n"
                "- Boeden saugen und feucht wischen\n"
                "- Muell entsorgen"
            ).format(co_str, buffer_info)

            lines.extend([
                "BEGIN:VEVENT",
                "UID:{0}".format(uid),
                "DTSTAMP:{0}".format(now_stamp),
                "DTSTART;TZID=Europe/Berlin:{0}".format(dtstart),
                "DTEND;TZID=Europe/Berlin:{0}".format(dtend),
                "SUMMARY:{0}".format(self.escape_ical(summary)),
                "DESCRIPTION:{0}".format(self.escape_ical(desc)),
                "LOCATION:{0}".format(self.escape_ical("Mechlerstraße 10, Dachgeschoss Mitte, 04105 Leipzig")),
                "STATUS:CONFIRMED",
                "TRANSP:OPAQUE",
                "END:VEVENT"
            ])

        lines.append("END:VCALENDAR")

        out = bytearray()
        for l in lines:
            c = l.strip("\r\n")
            if c: out.extend(self.fold_ical(c))

        self.send_response(200)
        self.send_header("Content-Type", "text/calendar; charset=utf-8")
        self.send_header("Content-Disposition", 'inline; filename="putzplan_mechlerstr.ics"')
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Content-Length", str(len(out)))
        self.end_headers()
        self.wfile.write(bytes(out))

    def handle_admin_list(self, parsed):
        qs = urllib.parse.parse_qs(parsed.query)
        admin_pin = qs.get("admin_pin", [""])[0].strip()
        if admin_pin not in ("04157", "octasias"):
            self.send_json({"success": False, "error": "Nicht autorisiert"}, code=403)
            return

        data = load_data()
        logs = []
        if os.path.exists(LOG_FILE):
            try:
                with open(LOG_FILE, "r", encoding="utf-8") as f:
                    logs = json.load(f)
            except Exception: pass

        self.send_json({
            "success": True,
            "settings": data.get("settings", {}),
            "pins": data.get("pins", []),
            "logs": list(reversed(logs[-50:]))
        })

    def handle_admin_save(self, payload):
        admin_pin = str(payload.get("admin_pin", "")).strip()
        if admin_pin not in ("04157", "octasias"):
            self.send_json({"success": False, "error": "Nicht autorisiert"}, code=403)
            return
        data = load_data()
        pins = data.get("pins", [])
        new_pin = str(payload.get("pin", "")).strip()
        name = str(payload.get("name", "Gast")).strip()
        valid_from = payload.get("valid_from")
        valid_until = payload.get("valid_until")
        booking_id = payload.get("booking_id")

        updated = False
        if booking_id:
            for p in pins:
                if p.get("booking_id") == booking_id:
                    p["pin"] = new_pin
                    p["name"] = name
                    p["valid_from"] = valid_from
                    p["valid_until"] = valid_until
                    p["active"] = True
                    updated = True
                    break

        if not updated:
            pins.append({
                "pin": new_pin,
                "name": name,
                "booking_id": booking_id or ("MANUAL-" + str(int(time.time()))),
                "valid_from": valid_from,
                "valid_until": valid_until,
                "active": True,
                "role": "guest"
            })
        data["pins"] = pins
        save_data(data)
        self.send_json({"success": True, "message": "PIN gespeichert"})

    def handle_admin_delete(self, payload):
        admin_pin = str(payload.get("admin_pin", "")).strip()
        if admin_pin not in ("04157", "octasias"):
            self.send_json({"success": False, "error": "Nicht autorisiert"}, code=403)
            return
        target_pin = str(payload.get("pin", "")).strip()
        booking_id = str(payload.get("booking_id", "")).strip()
        data = load_data()
        if booking_id:
            data["pins"] = [p for p in data.get("pins", []) if p.get("booking_id") != booking_id]
        else:
            data["pins"] = [p for p in data.get("pins", []) if str(p.get("pin")) != target_pin]
        save_data(data)
        self.send_json({"success": True, "message": "PIN geloescht"})

if __name__ == "__main__":
    print("[*] Starting AutoCheckin Server on port {0}...".format(PORT))
    socketserver.TCPServer.allow_reuse_address = True
    httpd = socketserver.TCPServer(("", PORT), CheckinHandler)
    print("[OK] Ready at http://localhost:{0}/".format(PORT))
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        print("\nShutting down server.")
    finally:
        httpd.server_close()
