#!/usr/bin/env python3
"""
Standalone AutoCheckin Server for StayInLeipzig (Mechlerstr.)
Can run on Windows, Mac, Linux, or directly on the Raspberry Pi.
Serves the modern frontend and handles secure time-restricted PIN verification & door relays.
"""

import http.server
import json
import os
import socketserver
import time
import urllib.parse
import urllib.request
from datetime import datetime, timedelta

PORT = 8080
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
PINS_FILE = os.path.join(BASE_DIR, "pins.json")
LOG_FILE = os.path.join(BASE_DIR, "access_log.json")
RATE_FILE = os.path.join(BASE_DIR, "ratelimit.json")

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
    with open(LOG_FILE, "w", encoding="utf-8") as f:
        json.dump(logs, f, indent=2, ensure_ascii=False)

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
            return False, f"Zu viele Fehlversuche. Gesperrt für {rem} Minute(n)."
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
    with open(RATE_FILE, "w", encoding="utf-8") as f:
        json.dump(rates, f)

def find_pin(pin_code, pins, settings):
    now = datetime.now()
    grace_before = timedelta(minutes=settings.get("grace_period_minutes_before", 30))
    grace_after = timedelta(minutes=settings.get("grace_period_minutes_after", 60))

    for p in pins:
        if str(p.get("pin", "")).strip() == str(pin_code).strip() and p.get("active", True):
            try:
                valid_from = datetime.fromisoformat(p["valid_from"]) - grace_before
                valid_until = datetime.fromisoformat(p["valid_until"]) + grace_after
            except Exception:
                continue

            if valid_from <= now <= valid_until:
                return True, p, "active"
            elif now < valid_from:
                return False, p, "future"
            elif now > valid_until:
                return False, p, "expired"
    return False, None, "not_found"

def trigger_fhem(door, settings):
    cmd = "set%20Tuer%20on" if door == "haustuer" else "set%20Wohnungstuer%20on"
    urls = [
        settings.get("fhem_local_url", "http://127.0.0.1:8083"),
        settings.get("fhem_tunnel_url", "https://fhem-tuer-mechler-leipzig.stayinleipzig.de")
    ]
    user = settings.get("fhem_user", "admin")
    password = settings.get("fhem_pass", "octasias")

    import base64
    auth_header = "Basic " + base64.b64encode(f"{user}:{password}".encode()).decode()

    for base_url in urls:
        if not base_url: continue
        target_url = f"{base_url.rstrip('/')}/fhem?cmd={cmd}&room=all&XHR=1"
        try:
            req = urllib.request.Request(target_url, headers={"Authorization": auth_header})
            with urllib.request.urlopen(req, timeout=4) as resp:
                if resp.status == 200:
                    return True, "OK"
        except Exception as e:
            continue
    return False, "Verbindung zu FHEM fehlgeschlagen"

class CheckinHandler(http.server.SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=BASE_DIR, **kwargs)

    def do_OPTIONS(self):
        self.send_response(200)
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type, Authorization")
        self.end_headers()

    def do_GET(self):
        parsed = urllib.parse.urlparse(self.path)
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
        length = int(self.headers.get("Content-Length", 0))
        body = self.rfile.read(length).decode("utf-8") if length > 0 else ""
        try:
            payload = json.loads(body) if body else {}
        except Exception:
            payload = dict(urllib.parse.parse_qsl(body))

        if parsed.path in ("/api.php", "/api/open"):
            action = payload.get("action", urllib.parse.parse_qs(parsed.query).get("action", ["open"])[0])
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

    def send_json(self, data, code=200):
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Access-Control-Allow-Origin", "*")
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
        data = load_data()
        settings = data.get("settings", {})
        pins = data.get("pins", [])
        valid, entry, status = find_pin(pin, pins, settings)

        if valid:
            self.send_json({
                "success": True,
                "status": "active",
                "guest": entry.get("name", "Gast"),
                "valid_from": entry.get("valid_from"),
                "valid_until": entry.get("valid_until"),
                "message": "PIN gültig"
            })
        else:
            msg = "Falscher PIN"
            if status == "future":
                msg = f"Check-in ist erst ab {entry.get('valid_from')} möglich."
            elif status == "expired":
                msg = f"PIN ist abgelaufen (Check-out war {entry.get('valid_until')})."
            self.send_json({"success": False, "status": status, "error": msg})

    def handle_open(self, payload):
        ip = self.get_client_ip()
        data = load_data()
        settings = data.get("settings", {})
        pins = data.get("pins", [])

        allowed, rmsg = check_rate_limit(ip, settings.get("max_failed_attempts", 5), settings.get("lockout_minutes", 10))
        if not allowed:
            self.send_json({"success": False, "error": rmsg, "locked": True}, code=429)
            return

        pin = str(payload.get("pin", "")).strip()
        door = str(payload.get("door", "haustuer")).strip()

        valid, entry, status = find_pin(pin, pins, settings)
        if not valid:
            record_attempt(ip, False, settings.get("max_failed_attempts", 5), settings.get("lockout_minutes", 10))
            msg = "Falscher PIN!"
            if status == "future":
                msg = f"Ihr PIN ist noch nicht aktiv (ab {entry.get('valid_from')})."
            elif status == "expired":
                msg = f"Ihr PIN ist abgelaufen (Check-out war {entry.get('valid_until')})."
            log_access(ip, pin, entry.get("name") if entry else "", door, False, msg)
            self.send_json({"success": False, "error": msg, "status": status})
            return

        # Valid PIN!
        record_attempt(ip, True)
        ok, res_msg = trigger_fhem(door, settings)
        door_name = "Haustür" if door == "haustuer" else "Wohnungstür"
        seconds = settings.get("door_open_seconds_house", 10) if door == "haustuer" else settings.get("door_open_seconds_apt", 30)

        if ok:
            directions = ("Bitte jetzt gegen die Haustür drücken und bis ins Dachgeschoss laufen. Die Wohnung in der Mitte rechts ist Ihre Ferienwohnung."
                          if door == "haustuer" else "Bitte jetzt gegen die Wohnungstür drücken. Herzlich Willkommen!")
            log_access(ip, pin, entry.get("name", "Gast"), door, True, f"{door_name} geöffnet")
            self.send_json({
                "success": True,
                "door": door,
                "door_name": door_name,
                "seconds": seconds,
                "message": f"{door_name} wurde entriegelt!",
                "directions": directions,
                "guest": entry.get("name", "Gast")
            })
        else:
            log_access(ip, pin, entry.get("name", "Gast"), door, False, f"Fehler Türsteuerung: {res_msg}")
            self.send_json({"success": False, "error": "Türsteuerung nicht erreichbar. Bitte Sophie anrufen."})

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
                "booking_id": payload.get("booking_id", f"MANUAL-{int(time.time())}"),
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
        self.send_json({"success": True, "message": "PIN gelöscht"})

if __name__ == "__main__":
    print(f"[*] Starting AutoCheckin Server on port {PORT}...")
    with socketserver.TCPServer(("", PORT), CheckinHandler) as httpd:
        print(f"[OK] Ready at http://localhost:{PORT}/")
        try:
            httpd.serve_forever()
        except KeyboardInterrupt:
            print("\nShutting down server.")
