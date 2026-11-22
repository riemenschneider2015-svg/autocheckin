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
                'message' => "Zu viele Fehlversuche! Login für $remaining Minute(n) gesperrt. Bitte Falko anrufen (+49 152 53453390)."
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

    $matches = [];
    foreach ($pins as $entry) {
        if (trim($entry['pin']) === trim($pin) && !empty($entry['active'])) {
            $from = strtotime($entry['valid_from']) - $graceBefore;
            $until = strtotime($entry['valid_until']) + $graceAfter;
            $matches[] = [
                'entry' => $entry,
                'from' => $from,
                'until' => $until
            ];
        }
    }

    if (empty($matches)) {
        return ['valid' => false, 'entry' => null, 'status' => 'not_found'];
    }

    // 1. Check if any match is currently active
    foreach ($matches as $m) {
        if ($now >= $m['from'] && $now <= $m['until']) {
            return ['valid' => true, 'entry' => $m['entry'], 'status' => 'active'];
        }
    }

    // 2. Check for upcoming / future booking (closest upcoming first)
    $future = [];
    foreach ($matches as $m) {
        if ($now < $m['from']) {
            $future[] = $m;
        }
    }
    if (!empty($future)) {
        usort($future, function($a, $b) { return $a['from'] - $b['from']; });
        return ['valid' => false, 'entry' => $future[0]['entry'], 'status' => 'future'];
    }

    // 3. All matches are in past (most recently ended)
    usort($matches, function($a, $b) { return $b['until'] - $a['until']; });
    return ['valid' => false, 'entry' => $matches[0]['entry'], 'status' => 'expired'];
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

function sendWolMagicPacket($macAddress, $ipHint = '') {
    $cleanMac = preg_replace('/[^0-9A-Fa-f]/', '', (string)$macAddress);
    if (strlen($cleanMac) !== 12) {
        return ['success' => false, 'message' => 'Keine gültige TV MAC-Adresse konfiguriert'];
    }
    $macBytes = hex2bin($cleanMac);
    if ($macBytes === false) {
        return ['success' => false, 'message' => 'MAC-Fehler'];
    }
    $packet = str_repeat(chr(0xFF), 6) . str_repeat($macBytes, 16);

    $targets = ['255.255.255.255', '192.168.8.255', '192.168.178.255', '192.168.1.255'];
    if (!empty($ipHint) && strpos($ipHint, '.') !== false) {
        $parts = explode('.', trim($ipHint));
        if (count($parts) === 4) {
            $subBcast = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.255';
            if (!in_array($subBcast, $targets, true)) {
                array_unshift($targets, $subBcast);
            }
            $targets[] = trim($ipHint);
        }
    }

    $sent = 0;
    if (function_exists('socket_create')) {
        $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sock) {
            @socket_set_option($sock, SOL_SOCKET, SO_BROADCAST, 1);
            foreach ($targets as $host) {
                foreach ([9, 7, 2304] as $port) {
                    if (@socket_sendto($sock, $packet, strlen($packet), 0, $host, $port) !== false) {
                        $sent++;
                    }
                }
            }
            @socket_close($sock);
        }
    }
    if ($sent === 0) {
        foreach ($targets as $host) {
            foreach ([9, 7] as $port) {
                $fp = @stream_socket_client("udp://$host:$port", $errno, $errstr, 0.5);
                if ($fp) {
                    @fwrite($fp, $packet);
                    @fclose($fp);
                    $sent++;
                }
            }
        }
    }
    return [
        'success' => ($sent > 0),
        'message' => "Wake-on-LAN Magic Packet gesendet ($sent Pakete an $macAddress)"
    ];
}

function readArpTablePhp() {
    $arp = [];
    if (file_exists('/proc/net/arp')) {
        $lines = @file('/proc/net/arp', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines)) {
            foreach (array_slice($lines, 1) as $line) {
                $cols = preg_split('/\s+/', trim($line));
                if (count($cols) >= 4 && $cols[3] !== '00:00:00:00:00:00') {
                    $arp[$cols[0]] = strtoupper($cols[3]);
                }
            }
        }
    }
    return $arp;
}

function probeSamsungTvPhp($ipAddr, $timeoutSec = 1) {
    $ch = curl_init("http://$ipAddr:8001/api/v2/");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeoutSec);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
    $raw = @curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw && $code === 200) {
        $info = json_decode($raw, true);
        if (is_array($info)) {
            $dev = $info['device'] ?? [];
            $mac = strtoupper($dev['wifiMac'] ?? ($dev['mac'] ?? ''));
            $name = $dev['name'] ?? ($info['name'] ?? ($dev['modelName'] ?? 'Samsung Smart TV'));
            $model = $dev['modelName'] ?? '';
            return ['ip' => $ipAddr, 'mac' => $mac, 'name' => $name, 'model' => $model];
        }
    }
    return null;
}

function wakeAndGreetTvPhp($entry, $door, &$config, $manual = false) {
    $settings = &$config['settings'];
    $guestName = $entry['name'] ?? 'Gast';
    $guestPin = (string)($entry['pin'] ?? '');
    $salutation = $entry['salutation'] ?? 'auto';

    $settings['tv_last_guest'] = [
        'name' => $guestName,
        'salutation' => $salutation,
        'pin' => $guestPin,
        'valid_from' => $entry['valid_from'] ?? '',
        'valid_until' => $entry['valid_until'] ?? '',
        'woke_at' => date('Y-m-d H:i:s'),
        'door' => $door
    ];
    saveConfig($config);

    $tvMac = trim($settings['tv_mac'] ?? '') ?: '74:6D:FA:7B:B0:3B';
    $tvIp = trim($settings['tv_ip'] ?? '') ?: '192.168.8.113';

    $wolRes = sendWolMagicPacket($tvMac, $tvIp);
    sendWolMagicPacket('94:E6:BA:B1:04:D2', $tvIp);

    if (!empty($tvIp)) {
        $welcomeUrl = 'https://checkin.stayinleipzig.de/tv.html' . ($guestPin ? ('?pin=' . urlencode($guestPin)) : '');
        foreach (['3202010022079', 'org.tizen.browser', 'CloudBrowser'] as $appId) {
            $ch = curl_init("http://$tvIp:8001/api/v2/applications/$appId");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $welcomeUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 1);
            @curl_exec($ch);
            curl_close($ch);
        }
    }

    $trig = $manual ? 'Manuell (Admin)' : 'Haustür Check-in';
    logAccess($guestPin ?: 'TV', $guestName, 'tv', true, "Smart-TV Wake-on-LAN ($trig): " . $wolRes['message']);
    return $wolRes;
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
        'contact' => $settings['contact_phone'] ?? '+4915253453390'
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
            'contact' => $settings['contact_phone'] ?? '+4915253453390'
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

        // Auto Wake-on-LAN & TV Welcome when guest opens Haustür (or on first check-in)
        $autoWake = $settings['tv_auto_wake'] ?? true;
        if (($door === 'haustuer' || $isFirstCheckin) && $autoWake !== false) {
            wakeAndGreetTvPhp($entry, $door, $config, false);
        }

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
            'error' => 'Die Türsteuerung konnte nicht erreicht werden. Bitte rufen Sie direkt Falko an (+49 152 53453390).',
            'contact' => $settings['contact_phone'] ?? '+4915253453390'
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
              . "📞 Kontakt Falko: +49 152 53453390\\n\\n"
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
              . "Host Falko: +49 152 53453390";

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
    $salutation = trim($input['salutation'] ?? 'auto') ?: 'auto';
    $bookingId = trim($input['booking_id'] ?? ('MANUAL-' . time()));
    $from = trim($input['valid_from'] ?? date('Y-m-d\TH:i:s'));
    $until = trim($input['valid_until'] ?? date('Y-m-d\TH:i:s', strtotime('+3 days')));

    if (empty($pin)) {
        echo json_encode(['success' => false, 'error' => 'PIN darf nicht leer sein']);
        exit();
    }

    $updated = false;
    if (!empty($bookingId)) {
        foreach ($pins as &$p) {
            if ($p['booking_id'] === $bookingId) {
                $p['pin'] = $pin;
                $p['name'] = $name;
                $p['salutation'] = $salutation;
                $p['valid_from'] = $from;
                $p['valid_until'] = $until;
                $p['active'] = true;
                $updated = true;
                break;
            }
        }
    }
    if (!$updated) {
        $pins[] = [
            'pin' => $pin,
            'name' => $name,
            'salutation' => $salutation,
            'booking_id' => $bookingId ?: ('MANUAL-' . time()),
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
    $targetBookingId = trim($input['booking_id'] ?? '');
    $filtered = [];
    foreach ($pins as $p) {
        if (!empty($targetBookingId)) {
            if (($p['booking_id'] ?? '') === $targetBookingId) continue;
        } else {
            if ($p['pin'] === $targetPin) continue;
        }
        $filtered[] = $p;
    }
    $config['pins'] = $filtered;
    saveConfig($config);
    echo json_encode(['success' => true, 'pins' => $filtered]);
    exit();
}

// ACTION: TV STATUS (FOR SMART-TV WELCOME DASHBOARD tv.html)
if ($action === 'tv_status') {
    $reqPin = trim($_GET['pin'] ?? ($input['pin'] ?? ''));
    $guestPins = array_values(array_filter($pins, function($p) {
        return (($p['role'] ?? '') !== 'admin') && !empty($p['active']);
    }));

    $chosen = null;
    if (!empty($reqPin)) {
        foreach ($guestPins as $p) {
            if (trim((string)$p['pin']) === $reqPin) {
                $chosen = $p;
                break;
            }
        }
    }
    if (!$chosen && !empty($settings['tv_last_guest']['name'])) {
        $chosen = $settings['tv_last_guest'];
    }
    $now = time();
    if (!$chosen) {
        foreach ($guestPins as $p) {
            $vf = strtotime($p['valid_from'] ?? '') - 12 * 3600;
            $vu = strtotime($p['valid_until'] ?? '') + 6 * 3600;
            if ($vf && $vu && $now >= $vf && $now <= $vu) {
                $chosen = $p;
                if (!empty($p['checked_in_at'])) break;
            }
        }
    }
    if (!$chosen && !empty($guestPins)) {
        $chosen = $guestPins[0];
    }

    echo json_encode([
        'success' => true,
        'guest' => $chosen ?: ['name' => 'Liebe Gäste', 'salutation' => 'auto'],
        'tv_custom_m3u' => $settings['tv_custom_m3u'] ?? '',
        'tv_ip' => $settings['tv_ip'] ?? '',
        'tv_mac' => $settings['tv_mac'] ?? '',
        'tv_name' => $settings['tv_name'] ?? '',
        'tv_auto_wake' => $settings['tv_auto_wake'] ?? true
    ]);
    exit();
}

// ACTION: ADMIN - DISCOVER SAMSUNG TV ON LAN
if ($action === 'admin_discover_tv') {
    $adminPin = trim($input['admin_pin'] ?? '');
    if ($adminPin !== 'octasias' && $adminPin !== '04157') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Nicht autorisiert']);
        exit();
    }
    $arp = readArpTablePhp();
    $found = null;
    $candidates = array_keys($arp);
    if (!empty($settings['tv_ip']) && !in_array($settings['tv_ip'], $candidates, true)) {
        array_unshift($candidates, $settings['tv_ip']);
    }
    foreach ($candidates as $cip) {
        $res = probeSamsungTvPhp($cip, 1);
        if ($res) {
            if (empty($res['mac']) && isset($arp[$cip])) {
                $res['mac'] = $arp[$cip];
            }
            $found = $res;
            break;
        }
    }
    if ($found) {
        $config['settings']['tv_ip'] = $found['ip'];
        if (!empty($found['mac'])) $config['settings']['tv_mac'] = $found['mac'];
        if (!empty($found['name'])) $config['settings']['tv_name'] = $found['name'];
        saveConfig($config);
        echo json_encode(['success' => true, 'tv' => $found, 'arp' => $arp]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Kein eingeschalteter Samsung TV auf Port 8001 gefunden (bitte TV einmalig einschalten oder IP/MAC manuell eintragen).',
            'arp' => $arp
        ]);
    }
    exit();
}

// ACTION: ADMIN - SAVE TV SETTINGS & CHANNEL LIST
if ($action === 'admin_tv_settings') {
    $adminPin = trim($input['admin_pin'] ?? '');
    if ($adminPin !== 'octasias' && $adminPin !== '04157') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Nicht autorisiert']);
        exit();
    }
    if (isset($input['tv_ip'])) $config['settings']['tv_ip'] = trim($input['tv_ip']);
    if (isset($input['tv_mac'])) $config['settings']['tv_mac'] = strtoupper(trim($input['tv_mac']));
    if (isset($input['tv_auto_wake'])) $config['settings']['tv_auto_wake'] = (bool)$input['tv_auto_wake'];
    if (isset($input['tv_custom_m3u'])) $config['settings']['tv_custom_m3u'] = (string)$input['tv_custom_m3u'];
    saveConfig($config);
    echo json_encode(['success' => true, 'settings' => $config['settings']]);
    exit();
}

// ACTION: ADMIN - WAKE TV & GREET GUEST NOW
if ($action === 'admin_wake_tv') {
    $adminPin = trim($input['admin_pin'] ?? '');
    if ($adminPin !== 'octasias' && $adminPin !== '04157') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Nicht autorisiert']);
        exit();
    }
    if (!empty($input['tv_ip'])) $config['settings']['tv_ip'] = trim($input['tv_ip']);
    if (!empty($input['tv_mac'])) $config['settings']['tv_mac'] = strtoupper(trim($input['tv_mac']));
    $entry = [
        'name' => trim($input['guest_name'] ?? '') ?: 'Gast',
        'salutation' => trim($input['salutation'] ?? 'auto') ?: 'auto',
        'pin' => trim($input['pin'] ?? ''),
        'valid_from' => $input['valid_from'] ?? '',
        'valid_until' => $input['valid_until'] ?? ''
    ];
    $wolRes = wakeAndGreetTvPhp($entry, 'haustuer', $config, true);
    echo json_encode([
        'success' => true,
        'message' => 'Wake-on-LAN & TV-Begrüßung für „' . $entry['name'] . '“ gestartet! (' . $wolRes['message'] . ')',
        'tv_ip' => $config['settings']['tv_ip'] ?? '192.168.8.113',
        'tv_mac' => $config['settings']['tv_mac'] ?? '74:6D:FA:7B:B0:3B'
    ]);
    exit();
}

// ACTION: LAUNCH NATIVE SAMSUNG TV APP (WHITELISTED)
if ($action === 'tv_launch_app') {
    $allowed = [
        '3202010022079' => 'Samsung Browser',
        '3201907018807' => 'Netflix',
        '111299001912' => 'YouTube',
        '3201910019365' => 'Prime Video',
        '3201606009684' => 'Spotify',
        '3201901017640' => 'Disney+'
    ];
    $appId = trim($input['app_id'] ?? ($_GET['app_id'] ?? ''));
    if (!isset($allowed[$appId])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'App nicht freigegeben']);
        exit();
    }
    $tvIp = trim($settings['tv_ip'] ?? '') ?: '192.168.8.113';
    $ch = curl_init("http://$tvIp:8001/api/v2/applications/$appId");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, '');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    @curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo json_encode(['success' => ($code === 200 || $code === 201), 'app' => $allowed[$appId], 'tv_ip' => $tvIp]);
    exit();
}

echo json_encode(['success' => false, 'error' => 'Ungültige Aktion']);
?>
