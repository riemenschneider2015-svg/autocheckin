<?php
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$dataFile = __DIR__ . '/pins.json';
$logFile = __DIR__ . '/access_log.json';
$rateFile = __DIR__ . '/ratelimit.json';

function getIp() {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return $_SERVER['HTTP_CF_CONNECTING_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

function loadConfig() {
    global $dataFile;
    if (!file_exists($dataFile)) {
        return ['settings' => [], 'pins' => []];
    }
    return json_decode(file_get_contents($dataFile), true) ?: ['settings' => [], 'pins' => []];
}

function saveConfig($data) {
    global $dataFile;
    file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function checkRateLimit($ip, $maxAttempts = 5, $lockoutMinutes = 10) {
    global $rateFile;
    $rates = file_exists($rateFile) ? json_decode(file_get_contents($rateFile), true) ?: [] : [];
    $now = time();
    
    if (isset($rates[$ip])) {
        $entry = $rates[$ip];
        if ($entry['locked_until'] > $now) {
            $remaining = ceil(($entry['locked_until'] - $now) / 60);
            return [
                'allowed' => false,
                'message' => "Zu viele Fehlversuche! Login für $remaining Minute(n) gesperrt. Bitte Falko anrufen (015253453390)."
            ];
        }
        // Reset window if older than lockoutMinutes * 60
        if ($now - $entry['first_attempt'] > ($lockoutMinutes * 60)) {
            unset($rates[$ip]);
        }
    }
    return ['allowed' => true];
}

function recordAttempt($ip, $success, $maxAttempts = 5, $lockoutMinutes = 10) {
    global $rateFile;
    $rates = file_exists($rateFile) ? json_decode(file_get_contents($rateFile), true) ?: [] : [];
    $now = time();

    if ($success) {
        unset($rates[$ip]);
    } else {
        if (!isset($rates[$ip])) {
            $rates[$ip] = ['attempts' => 1, 'first_attempt' => $now, 'locked_until' => 0];
        } else {
            $rates[$ip]['attempts']++;
        }
        if ($rates[$ip]['attempts'] >= $maxAttempts) {
            $rates[$ip]['locked_until'] = $now + ($lockoutMinutes * 60);
        }
    }
    file_put_contents($rateFile, json_encode($rates));
}

function logAccess($pin, $guestName, $door, $success, $message) {
    global $logFile;
    $logs = file_exists($logFile) ? json_decode(file_get_contents($logFile), true) ?: [] : [];
    $logs[] = [
        'timestamp' => date('Y-m-d H:i:s'),
        'ip' => getIp(),
        'pin' => substr($pin, 0, 2) . '***',
        'guest' => $guestName ?: 'Unbekannt',
        'door' => $door,
        'success' => $success,
        'message' => $message,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
    ];
    // Keep last 500 logs
    if (count($logs) > 500) {
        $logs = array_slice($logs, -500);
    }
    file_put_contents($logFile, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function findPinEntry($pin, $pins, $settings) {
    $now = time();
    $graceBefore = ($settings['grace_period_minutes_before'] ?? 30) * 60;
    $graceAfter = ($settings['grace_period_minutes_after'] ?? 60) * 60;

    foreach ($pins as $entry) {
        if (trim($entry['pin']) === trim($pin) && !empty($entry['active'])) {
            $from = strtotime($entry['valid_from']) - $graceBefore;
            $until = strtotime($entry['valid_until']) + $graceAfter;

            if ($now >= $from && $now <= $until) {
                return ['valid' => true, 'entry' => $entry, 'status' => 'active'];
            }
            if ($now < $from) {
                return ['valid' => false, 'entry' => $entry, 'status' => 'future'];
            }
            if ($now > $until) {
                return ['valid' => false, 'entry' => $entry, 'status' => 'expired'];
            }
        }
    }
    return ['valid' => false, 'entry' => null, 'status' => 'not_found'];
}

function triggerFhem($door, $settings) {
    $isLocal = (strpos($_SERVER['HTTP_HOST'] ?? '', '192.168.8.') !== false || strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false);
    $baseUrl = $isLocal ? ($settings['fhem_local_url'] ?? 'http://127.0.0.1:8083') : ($settings['fhem_tunnel_url'] ?? 'https://fhem-tuer-mechler-leipzig.stayinleipzig.de');

    $cmd = '';
    if ($door === 'haustuer') {
        $cmd = 'set%20Tuer%20on';
    } elseif ($door === 'wohnungstuer') {
        $cmd = 'set%20Wohnungstuer%20on';
    } else {
        return ['success' => false, 'error' => 'Unbekannte Tür'];
    }

    $url = rtrim($baseUrl, '/') . "/fhem?cmd=$cmd&room=all&XHR=1";
    $user = $settings['fhem_user'] ?? 'admin';
    $pass = $settings['fhem_pass'] ?? 'octasias';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, "$user:$pass");
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0 && !$isLocal && !empty($settings['fhem_local_url'])) {
        // Fallback to local if running on Pi
        $urlFallback = rtrim($settings['fhem_local_url'], '/') . "/fhem?cmd=$cmd&room=all&XHR=1";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $urlFallback);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 4);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, "$user:$pass");
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
    }

    return ['success' => ($errno === 0), 'error_code' => $errno, 'http_code' => $httpCode];
}

// Read input
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = $_GET['action'] ?? ($input['action'] ?? 'open');
$ip = getIp();

$config = loadConfig();
$settings = $config['settings'] ?? [];
$pins = $config['pins'] ?? [];

// Rate limiting check
$rateStatus = checkRateLimit($ip, $settings['max_failed_attempts'] ?? 5, $settings['lockout_minutes'] ?? 10);
if (!$rateStatus['allowed'] && $action !== 'settings') {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'error' => $rateStatus['message'],
        'locked' => true,
        'contact' => $settings['contact_phone'] ?? '015253453390'
    ]);
    exit();
}

// ACTION: VERIFY PIN (Check without opening)
if ($action === 'verify') {
    $pin = trim($input['pin'] ?? ($_GET['pin'] ?? ''));
    if (!$pin) {
        echo json_encode(['success' => false, 'error' => 'Kein PIN angegeben']);
        exit();
    }

    $result = findPinEntry($pin, $pins, $settings);
    if ($result['valid']) {
        echo json_encode([
            'success' => true,
            'status' => 'active',
            'guest' => $result['entry']['name'] ?? 'Gast',
            'valid_from' => $result['entry']['valid_from'],
            'valid_until' => $result['entry']['valid_until'],
            'role' => $result['entry']['role'] ?? 'guest',
            'message' => 'PIN gültig'
        ]);
    } else {
        $status = $result['status'];
        $msg = 'PIN ungültig';
        if ($status === 'future') {
            $msg = 'Check-in ist erst ab ' . date('d.m.Y H:i', strtotime($result['entry']['valid_from'])) . ' Uhr möglich.';
        } elseif ($status === 'expired') {
            $msg = 'Dieser PIN ist leider abgelaufen (Check-out war am ' . date('d.m.Y H:i', strtotime($result['entry']['valid_until'])) . ' Uhr).';
        }
        echo json_encode(['success' => false, 'status' => $status, 'error' => $msg]);
    }
    exit();
}

// ACTION: OPEN DOOR
if ($action === 'open') {
    $pin = trim($input['pin'] ?? '');
    $door = trim($input['door'] ?? 'haustuer'); // haustuer | wohnungstuer

    if (empty($pin)) {
        echo json_encode(['success' => false, 'error' => 'Bitte gib deinen PIN ein.']);
        exit();
    }

    $result = findPinEntry($pin, $pins, $settings);

    if (!$result['valid']) {
        recordAttempt($ip, false, $settings['max_failed_attempts'] ?? 5, $settings['lockout_minutes'] ?? 10);
        $status = $result['status'];
        $msg = 'Falscher PIN!';
        if ($status === 'future') {
            $msg = 'Ihr PIN ist noch nicht aktiv. Check-in ist ab ' . date('d.m.Y H:i', strtotime($result['entry']['valid_from'])) . ' Uhr.';
        } elseif ($status === 'expired') {
            $msg = 'Ihr PIN ist abgelaufen. Check-out war am ' . date('d.m.Y H:i', strtotime($result['entry']['valid_until'])) . ' Uhr.';
        }
        logAccess($pin, $result['entry']['name'] ?? '', $door, false, $msg);
        echo json_encode([
            'success' => false,
            'error' => $msg,
            'status' => $status,
            'contact' => $settings['contact_phone'] ?? '015253453390'
        ]);
        exit();
    }

    // PIN is valid!
    recordAttempt($ip, true);
    $entry = $result['entry'];

    $fhemRes = triggerFhem($door, $settings);

    if ($fhemRes['success']) {
        $doorName = ($door === 'haustuer') ? 'Haustür' : 'Wohnungstür';
        $seconds = ($door === 'haustuer') ? ($settings['door_open_seconds_house'] ?? 10) : ($settings['door_open_seconds_apt'] ?? 30);
        $directions = ($door === 'haustuer')
            ? 'Bitte immer kräftig gegen die Haustür drücken, wenn es summt! Danach die Treppe hoch bis ins Dachgeschoss laufen (Tür in der Mitte mit dem grauen Willkommen-Schild).'
            : 'Bitte immer kräftig gegen die Wohnungstür drücken, wenn es summt. Herzlich Willkommen!';

        // Record check-in timestamp on first successful door open
        $nowStr = date('Y-m-d H:i:s');
        $isFirstCheckin = empty($entry['checked_in_at']);
        if ($isFirstCheckin) {
            foreach ($pins as &$p) {
                if ($p['pin'] === $pin) {
                    $p['checked_in_at'] = $nowStr;
                    $p['first_door'] = $doorName;
                    break;
                }
            }
            file_put_contents($dataFile, json_encode(['settings' => $settings, 'pins' => $pins], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        $logMsg = "$doorName erfolgreich geöffnet" . ($isFirstCheckin ? " (Erster Check-in)" : "");
        logAccess($pin, $entry['name'] ?? '', $door, true, $logMsg);

        echo json_encode([
            'success' => true,
            'door' => $door,
            'door_name' => $doorName,
            'seconds' => $seconds,
            'message' => "$doorName wurde entriegelt!",
            'directions' => $directions,
            'guest' => $entry['name'] ?? 'Gast'
        ]);
    } else {
        logAccess($pin, $entry['name'] ?? '', $door, false, "FHEM-Steuerungsfehler: " . ($fhemRes['error_code'] ?? 'timeout'));
        echo json_encode([
            'success' => false,
            'error' => 'Die Türsteuerung konnte nicht erreicht werden. Bitte rufen Sie direkt Falko an (015253453390).',
            'contact' => $settings['contact_phone'] ?? '015253453390'
        ]);
    }
    exit();
}

// ACTION: CLEANING ICAL - CALENDAR FEED FOR CLEANING STAFF
if ($action === 'cleaning_ical') {
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="putzplan_mechlerstr.ics"');

    // Collect and sort guest stays
    $guestStays = [];
    foreach ($pins as $p) {
        if (($p['role'] ?? '') === 'admin') continue;
        $vFrom = $p['valid_from'] ?? '';
        $vUntil = $p['valid_until'] ?? '';
        if (!$vFrom || !$vUntil) continue;

        $guestStays[] = [
            'name' => $p['name'] ?? 'Gast',
            'pin' => $p['pin'] ?? '',
            'booking_id' => $p['booking_id'] ?? '',
            'from' => strtotime($vFrom),
            'until' => strtotime($vUntil)
        ];
    }

    usort($guestStays, function($a, $b) {
        return $a['until'] - $b['until'];
    });

    $nowStamp = gmdate('Ymd\THis\Z');
    echo "BEGIN:VCALENDAR\r\n";
    echo "VERSION:2.0\r\n";
    echo "PRODID:-//StayInLeipzig//Putzplan Mechlerstr 10//DE\r\n";
    echo "CALSCALE:GREGORIAN\r\n";
    echo "METHOD:PUBLISH\r\n";
    echo "X-WR-CALNAME:Putzplan - Mechlerstr. 10 Leipzig\r\n";
    echo "X-WR-TIMEZONE:Europe/Berlin\r\n";
    echo "X-WR-CALDESC:Reinigungsplan fuer die Ferienwohnung Mechlerstr. 10\r\n";

    foreach ($guestStays as $stay) {
        $co = $stay['until'];
        $coStr = date('d.m.Y \u\m H:i \U\h\r', $co);
        $dtStart = date('Ymd\THis', $co);
        $cleanEnd = $co + (3 * 3600); // Standard 3h

        // Find next checkin
        $nextCheckin = null;
        foreach ($guestStays as $other) {
            if ($other['from'] >= $co) {
                if ($nextCheckin === null || $other['from'] < $nextCheckin['from']) {
                    $nextCheckin = $other;
                }
            }
        }

        $summary = "🧹 Reinigung Ferienwohnung (Wohnungswechsel)";
        $bufferInfo = "Keine direkte Folge-Buchung eingetragen (ausreichend Zeit).";

        if ($nextCheckin) {
            $ci = $nextCheckin['from'];
            $ciStr = date('d.m.Y \u\m H:i \U\h\r', $ci);
            $gapHours = round(($ci - $co) / 3600);
            $gapDays = floor($gapHours / 24);

            if (date('Y-m-d', $co) === date('Y-m-d', $ci)) {
                $cleanEnd = $ci;
                $summary = "🚨 DRINGEND: Reinigung (Gäste-Wechsel HEUTE!)";
                $bufferInfo = "⚠️ ACHTUNG: Neuer Gast reist HEUTE um " . date('H:i', $ci) . " Uhr an! Bitte bis dahin fertig sein.";
            } elseif ($gapDays === 0) {
                $summary = "🧹 Reinigung Ferienwohnung";
                $bufferInfo = "Nächster Gast kommt in " . $gapHours . " Stunden (" . $ciStr . ").";
            } else {
                $bufferInfo = "Wohnung ist frei bis zum " . date('d.m.Y', $ci) . " (" . $gapDays . " Tage Puffer).";
            }
        }

        $dtEnd = date('Ymd\THis', $cleanEnd);
        $uid = "CLEAN-" . ($stay['booking_id'] ?: $stay['pin']) . "@stayinleipzig.de";

        $desc = "🧹 REINIGUNG & WOHNUNGSWECHSEL\\n"
              . "Abreise bisheriger Gast: " . $coStr . "\\n"
              . "Status: " . $bufferInfo . "\\n\\n"
              . "📍 Wo: Mechlerstraße 10, Dachgeschoss Mitte (StayInLeipzig)\\n"
              . "WLAN: gigacube-EA6A (Passwort: Leipzig2025#)\\n"
              . "📞 Kontakt Falko: 0152 53453390\\n\\n"
              . "Aufgaben:\\n"
              . "- Bettwäsche abziehen & frisch beziehen\\n"
              . "- Handtücher austauschen\\n"
              . "- Bad & Dusche gründlich reinigen\\n"
              . "- Küche (Kühlschrank, Herd, Geschirr kontrollieren)\\n"
              . "- Böden saugen und feucht wischen\\n"
              . "- Müll entsorgen";

        echo "BEGIN:VEVENT\r\n";
        echo "UID:" . $uid . "\r\n";
        echo "DTSTAMP:" . $nowStamp . "\r\n";
        echo "DTSTART:" . $dtStart . "\r\n";
        echo "DTEND:" . $dtEnd . "\r\n";
        echo "SUMMARY:" . $summary . "\r\n";
        echo "DESCRIPTION:" . $desc . "\r\n";
        echo "LOCATION:Mechlerstraße 10, Dachgeschoss Mitte, 04105 Leipzig\r\n";
        echo "STATUS:CONFIRMED\r\n";
        echo "END:VEVENT\r\n";
    }

    echo "END:VCALENDAR\r\n";
    exit();
}

// ACTION: ICAL - CALENDAR FEED
if ($action === 'ical') {
    $adminPin = trim($input['admin_pin'] ?? ($_GET['admin_pin'] ?? ''));
    $masterFound = false;
    foreach ($pins as $p) {
        if (($p['role'] ?? '') === 'admin' && trim($p['pin']) === $adminPin) {
            $masterFound = true;
            break;
        }
    }
    if (!$masterFound && $adminPin !== 'octasias' && $adminPin !== '04157') {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "403 Unauthorized: Invalid or missing admin_pin";
        exit();
    }

    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="stayinleipzig_mechler.ics"');

    $nowStamp = gmdate('Ymd\THis\Z');
    echo "BEGIN:VCALENDAR\r\n";
    echo "VERSION:2.0\r\n";
    echo "PRODID:-//StayInLeipzig//AutoCheckin Mechlerstr 10//DE\r\n";
    echo "CALSCALE:GREGORIAN\r\n";
    echo "METHOD:PUBLISH\r\n";
    echo "X-WR-CALNAME:StayInLeipzig Mechlerstr. 10\r\n";
    echo "X-WR-TIMEZONE:Europe/Berlin\r\n";
    echo "X-WR-CALDESC:Buchungen und Gaeste fuer Ferienwohnung Mechlerstr. 10 Leipzig\r\n";

    foreach ($pins as $p) {
        if (($p['role'] ?? '') === 'admin') continue;
        $name = $p['name'] ?? 'Gast';
        $pinCode = $p['pin'] ?? '';
        $bookingId = $p['booking_id'] ?? ("PIN-" . $pinCode);
        $vFrom = $p['valid_from'] ?? '';
        $vUntil = $p['valid_until'] ?? '';
        if (!$vFrom || !$vUntil) continue;

        $dtStart = date('Ymd\THis', strtotime($vFrom));
        $dtEnd = date('Ymd\THis', strtotime($vUntil));
        $uid = $bookingId . "@stayinleipzig.de";

        $desc = "Gast: " . $name . "\\n"
              . "PIN: " . $pinCode . "\\n"
              . "1-Klick Check-in Link: https://checkin.stayinleipzig.de/?code=" . $pinCode . "\\n"
              . "Buchungs-ID: " . $bookingId . "\\n"
              . "Wohnung: Mechlerstraße 10, Dachgeschoss Mitte\\n"
              . "WLAN: gigacube-EA6A (PW: Leipzig2025#)\\n"
              . "Host Falko: 0152 53453390";

        echo "BEGIN:VEVENT\r\n";
        echo "UID:" . $uid . "\r\n";
        echo "DTSTAMP:" . $nowStamp . "\r\n";
        echo "DTSTART:" . $dtStart . "\r\n";
        echo "DTEND:" . $dtEnd . "\r\n";
        echo "SUMMARY:" . $name . " (PIN: " . $pinCode . ")\r\n";
        echo "DESCRIPTION:" . $desc . "\r\n";
        echo "LOCATION:Mechlerstraße 10, 04105 Leipzig, Deutschland\r\n";
        echo "STATUS:CONFIRMED\r\n";
        echo "END:VEVENT\r\n";
    }

    echo "END:VCALENDAR\r\n";
    exit();
}

// ACTION: ADMIN - LIST PINS
if ($action === 'admin_list') {
    $adminPin = trim($input['admin_pin'] ?? ($_GET['admin_pin'] ?? ''));
    $masterFound = false;
    foreach ($pins as $p) {
        if (($p['role'] ?? '') === 'admin' && trim($p['pin']) === $adminPin) {
            $masterFound = true;
            break;
        }
    }
    if (!$masterFound && $adminPin !== 'octasias' && $adminPin !== '04157') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Keine Administrator-Berechtigung']);
        exit();
    }

    $logs = file_exists($logFile) ? json_decode(file_get_contents($logFile), true) ?: [] : [];
    echo json_encode([
        'success' => true,
        'settings' => $settings,
        'pins' => $pins,
        'logs' => array_reverse(array_slice($logs, -50))
    ]);
    exit();
}

// ACTION: ADMIN - ADD / EDIT PIN
if ($action === 'admin_save_pin') {
    $adminPin = trim($input['admin_pin'] ?? '');
    if ($adminPin !== 'octasias' && $adminPin !== '04157') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Nicht autorisiert']);
        exit();
    }

    $pin = trim($input['pin'] ?? '');
    $name = trim($input['name'] ?? 'Gast');
    $bookingId = trim($input['booking_id'] ?? ('MANUAL-' . time()));
    $from = trim($input['valid_from'] ?? date('Y-m-d\TH:i:s'));
    $until = trim($input['valid_until'] ?? date('Y-m-d\TH:i:s', strtotime('+3 days')));

    if (empty($pin)) {
        echo json_encode(['success' => false, 'error' => 'PIN darf nicht leer sein']);
        exit();
    }

    $updated = false;
    foreach ($pins as &$p) {
        if ($p['pin'] === $pin || $p['booking_id'] === $bookingId) {
            $p['pin'] = $pin;
            $p['name'] = $name;
            $p['booking_id'] = $bookingId;
            $p['valid_from'] = $from;
            $p['valid_until'] = $until;
            $p['active'] = true;
            $updated = true;
            break;
        }
    }
    if (!$updated) {
        $pins[] = [
            'pin' => $pin,
            'name' => $name,
            'booking_id' => $bookingId,
            'valid_from' => $from,
            'valid_until' => $until,
            'active' => true,
            'role' => 'guest'
        ];
    }

    $config['pins'] = $pins;
    saveConfig($config);
    echo json_encode(['success' => true, 'message' => 'PIN erfolgreich gespeichert', 'pins' => $pins]);
    exit();
}

// ACTION: ADMIN - DELETE PIN
if ($action === 'admin_delete_pin') {
    $adminPin = trim($input['admin_pin'] ?? '');
    if ($adminPin !== 'octasias' && $adminPin !== '04157') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Nicht autorisiert']);
        exit();
    }
    $targetPin = trim($input['pin'] ?? '');
    $filtered = [];
    foreach ($pins as $p) {
        if ($p['pin'] !== $targetPin) {
            $filtered[] = $p;
        }
    }
    $config['pins'] = $filtered;
    saveConfig($config);
    echo json_encode(['success' => true, 'pins' => $filtered]);
    exit();
}

echo json_encode(['success' => false, 'error' => 'Ungültige Aktion']);
?>
