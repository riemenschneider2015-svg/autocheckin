"""
Booking.com iCal Auto-Sync & PIN Generator for StayInLeipzig (Mechlerstr.)
Automatically syncs upcoming reservations from Booking.com iCal export
and generates time-restricted PIN codes in pins.json.
"""

import json
import os
import re
import urllib.request
from datetime import datetime, timedelta

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
PINS_FILE = os.path.join(BASE_DIR, "pins.json")

def load_pins():
    if os.path.exists(PINS_FILE):
        with open(PINS_FILE, "r", encoding="utf-8") as f:
            return json.load(f)
    return {"settings": {}, "pins": []}

def save_pins(data):
    with open(PINS_FILE, "w", encoding="utf-8") as f:
        json.dump(data, f, indent=2, ensure_ascii=False)

def parse_ical(content):
    """Simple robust iCal parser for Booking.com VEVENTs"""
    events = []
    lines = content.replace("\r\n", "\n").replace("\r", "\n").split("\n")
    
    current = None
    for line in lines:
        line = line.strip()
        if line == "BEGIN:VEVENT":
            current = {}
        elif line == "END:VEVENT" and current is not None:
            if "DTSTART" in current and "DTEND" in current:
                events.append(current)
            current = None
        elif current is not None and ":" in line:
            parts = line.split(":", 1)
            key = parts[0].split(";")[0]
            val = parts[1]
            current[key] = val
            
    return events

def parse_date(date_str):
    """Converts iCal date format (20261101 or 20261101T150000Z) to datetime"""
    date_str = date_str.strip()
    if len(date_str) == 8 and date_str.isdigit():
        return datetime.strptime(date_str, "%Y%m%d")
    elif "T" in date_str:
        clean = date_str.replace("Z", "")
        return datetime.strptime(clean[:15], "%Y%m%dT%H%M%S")
    return None

def sync_from_ical_url(ical_url_or_file):
    """Fetches iCal feed and updates pins.json"""
    print(f"[*] Syncing Booking.com calendar from: {ical_url_or_file}")
    
    if ical_url_or_file.startswith("http://") or ical_url_or_file.startswith("https://"):
        req = urllib.request.Request(ical_url_or_file, headers={"User-Agent": "StayInLeipzig-AutoCheckin/1.0"})
        with urllib.request.urlopen(req, timeout=15) as resp:
            content = resp.read().decode("utf-8", errors="ignore")
    else:
        with open(ical_url_or_file, "r", encoding="utf-8") as f:
            content = f.read()

    events = parse_ical(content)
    print(f"[+] Found {len(events)} calendar events in feed.")

    data = load_pins()
    existing_pins = data.get("pins", [])
    existing_by_booking = {p.get("booking_id"): p for p in existing_pins if "booking_id" in p}

    now = datetime.now()
    added_count = 0
    updated_count = 0

    for ev in events:
        start_dt = parse_date(ev.get("DTSTART", ""))
        end_dt = parse_date(ev.get("DTEND", ""))
        summary = ev.get("SUMMARY", "Booking.com Gast")
        uid = ev.get("UID", "")

        if not start_dt or not end_dt:
            continue

        # Standard check-in at 14:00, check-out at 11:00
        checkin_dt = start_dt.replace(hour=14, minute=0, second=0)
        checkout_dt = end_dt.replace(hour=11, minute=0, second=0)

        # Skip events that checked out more than 3 days ago
        if checkout_dt < (now - timedelta(days=3)):
            continue

        # Extract booking reference
        booking_id = uid
        match = re.search(r"(\d{6,})", uid + " " + summary)
        if match:
            booking_id = f"BOOK-{match.group(1)}"
            # PIN is derived from the last 5 digits of booking ID
            pin_code = match.group(1)[-5:]
        else:
            pin_code = str(abs(hash(uid)) % 90000 + 10000)

        guest_name = summary.replace("CLOSED - ", "").replace("CLOSED", "").strip() or "Booking Gast"

        if booking_id in existing_by_booking:
            # Update dates
            p = existing_by_booking[booking_id]
            p["valid_from"] = checkin_dt.isoformat()
            p["valid_until"] = checkout_dt.isoformat()
            p["name"] = guest_name
            updated_count += 1
        else:
            new_pin = {
                "pin": pin_code,
                "name": guest_name,
                "booking_id": booking_id,
                "valid_from": checkin_dt.isoformat(),
                "valid_until": checkout_dt.isoformat(),
                "active": True,
                "role": "guest"
            }
            existing_pins.append(new_pin)
            existing_by_booking[booking_id] = new_pin
            added_count += 1
            print(f"  [+] New PIN generated: {pin_code} for {guest_name} ({checkin_dt.strftime('%d.%m.%Y')} - {checkout_dt.strftime('%d.%m.%Y')})")

    data["pins"] = existing_pins
    save_pins(data)
    print(f"[OK] Sync complete: {added_count} new bookings added, {updated_count} updated.")
    return added_count, updated_count

if __name__ == "__main__":
    import sys
    if len(sys.argv) > 1:
        sync_from_ical_url(sys.argv[1])
    else:
        print("Usage: python booking_sync.py <ICAL_URL_OR_FILE>")
