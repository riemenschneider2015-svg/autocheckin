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
    clean = str(dt_str).replace(" ", "T")
    if "T" in clean:
        parts = clean.split("T")
        date_part = parts[0]
        time_part = parts[1][:8]
        return datetime.strptime(date_part + " " + time_part, "%Y-%m-%d %H:%M:%S")
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
            return False, "Zu viele Fehlversuche. Gesperrt fuer {0} Minute(n). Bitte Falko anrufen (015253453390).".format(rem)
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

    for p in pins:
        if str(p.get("pin", "")).strip() == str(pin_code).strip() and p.get("active", True):
            try:
                valid_from = parse_iso(p.get("valid_from")) - grace_before
                valid_until = parse_iso(p.get("valid_until")) + grace_after
            except Exception:
                continue

            if valid_from <= now <= valid_until:
                return True, p, "active"
            elif now < valid_from:
                return False, p, "future"
            elif now > valid_until:
                return False, p, "expired"
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
    return False, "Fehler beim Ausloesen der Tuer"

class CheckinHandler(http.server.SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)

    def do_OPTIONS(self):
        self.send_response(200)
        self.send_header("Access-Control-Allow-Origin", "*")
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
            log_access(ip, pin, entry.get("name", "Gast"), "verify", True, "PIN geprueft: Aktiv fuer " + str(entry.get("name", "Gast")))
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
        door_name = "Haustuer" if door == "haustuer" else "Wohnungstuer"
        seconds = settings.get("door_open_seconds_house", 10) if door == "haustuer" else settings.get("door_open_seconds_apt", 30)

        if ok:
            directions = ("Bitte jetzt gegen die Haustuer druecken und bis ins Dachgeschoss laufen. Die Tuer in der Mitte mit dem grauen Willkommen-Schild ist Ihre Ferienwohnung."
                          if door == "haustuer" else "Bitte jetzt gegen die Wohnungstuer druecken. Herzlich Willkommen!")
            log_access(ip, pin, entry.get("name", "Gast"), door, True, "{0} geoeffnet".format(door_name))
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
            log_access(ip, pin, entry.get("name", "Gast"), door, False, "Fehler Tuersteuerung: " + res_msg)
            self.send_json({"success": False, "error": "Tuersteuerung nicht erreichbar. Bitte Falko anrufen (015253453390).", "contact": "015253453390"})

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

        updated = False
        for p in pins:
            if str(p.get("pin")) == new_pin:
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
                "booking_id": payload.get("booking_id", "MANUAL-" + str(int(time.time()))),
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
        target = str(payload.get("pin", "")).strip()
        data = load_data()
        data["pins"] = [p for p in data.get("pins", []) if str(p.get("pin")) != target]
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
