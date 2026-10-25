<?php
function ensureBaseSchema(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS admins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        full_name VARCHAR(100) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS students (
        student_id VARCHAR(50) PRIMARY KEY,
        full_name VARCHAR(100) NOT NULL,
        gender VARCHAR(10) NULL,
        grade_level VARCHAR(50) NULL,
        section VARCHAR(50) NULL,
        grade_section VARCHAR(50) NOT NULL,
        parent_phone VARCHAR(20) NOT NULL,
        username VARCHAR(100) NULL UNIQUE,
        password_hash VARCHAR(255) NULL,
        qr_payload VARCHAR(255) NOT NULL DEFAULT ''
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS attendance_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id VARCHAR(50) NOT NULL,
        log_type ENUM('IN', 'OUT', 'AM_IN', 'AM_OUT', 'PM_IN', 'PM_OUT') NOT NULL,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE
    )");
    $conn->query("ALTER TABLE attendance_logs MODIFY log_type ENUM('IN', 'OUT', 'AM_IN', 'AM_OUT', 'PM_IN', 'PM_OUT') NOT NULL");

    $attendanceLogColumns = [];
    $attendanceLogColumnResult = $conn->query('SHOW COLUMNS FROM attendance_logs');
    if ($attendanceLogColumnResult) {
        while ($row = $attendanceLogColumnResult->fetch_assoc()) {
            $attendanceLogColumns[] = $row['Field'];
        }
    }
    if (!in_array('latitude', $attendanceLogColumns, true)) {
        $conn->query('ALTER TABLE attendance_logs ADD COLUMN latitude DECIMAL(10,7) NULL');
    }
    if (!in_array('longitude', $attendanceLogColumns, true)) {
        $conn->query('ALTER TABLE attendance_logs ADD COLUMN longitude DECIMAL(10,7) NULL');
    }

    ensureAttendanceSettingsSchema($conn);
    ensureAttendanceCertificateSchema($conn);
    ensureGradeSectionsSchema($conn);
    ensureSmsLogSchema($conn);
    ensureStudentColumns($conn);
}

function ensureAttendanceCertificateSchema(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS attendance_certificates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id VARCHAR(50) NOT NULL,
        teacher_id INT NULL,
        certificate_type VARCHAR(100) NOT NULL DEFAULT 'Punctuality',
        award_date DATE NOT NULL,
        notes VARCHAR(255) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_student_certificate (student_id, certificate_type, award_date),
        KEY idx_teacher_certificate (teacher_id),
        KEY idx_certificate_date (award_date),
        FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE
    )");
}

function ensureGradeSectionsSchema(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS grade_sections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        grade_level VARCHAR(50) NOT NULL,
        section VARCHAR(50) NOT NULL,
        UNIQUE KEY unique_grade_section (grade_level, section)
    )");
}

function ensureAttendanceSettingsSchema(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS attendance_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value VARCHAR(255) NOT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    $defaults = [
        'am_in_threshold_minutes' => '300',
        'am_out_threshold_minutes' => '420',
        'pm_in_threshold_minutes' => '780',
        'pm_out_threshold_minutes' => '960',
        'am_tardy_after_minutes' => '495',
        'pm_tardy_after_minutes' => '780',
        'allow_saturday_classes' => '0',
        'geotagging_enabled' => '0',
        'geotag_latitude' => '',
        'geotag_longitude' => '',
        'geotag_radius_meters' => '100',
        'scan_mode' => 'all',
        'sms_active_gateway' => 'cloud',
        'sms_send_to_parents' => '1',
        'sms_daily_limit' => '0',
        'sms_cloud_url' => 'api.sms-gate.app:443',
        'sms_cloud_login' => 'TWCN8J',
        'sms_cloud_password' => 'fudhud6xizfub7',
        'sms_cloud_device_id' => 'OKGeFSzsyI4jnAhEMyt6Q',
        'sms_local_url' => '192.168.254.165:8080',
        'sms_local_login' => 'sms',
        'sms_local_password' => 'hpZ8sQCu',
        'sms_local_device_id' => '00000000550f8e43000001a156d59e1a',
    ];

    foreach ($defaults as $key => $value) {
        $stmt = $conn->prepare('INSERT IGNORE INTO attendance_settings (setting_key, setting_value) VALUES (?, ?)');
        if ($stmt) {
            $stmt->bind_param('ss', $key, $value);
            $stmt->execute();
        }
    }
}

function ensureSmsLogSchema(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS sms_send_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        phone VARCHAR(20) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'sent',
        sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_sms_sent_at (sent_at)
    )");
}

function getAttendanceSetting(mysqli $conn, string $key, int $default): int
{
    $stmt = $conn->prepare('SELECT setting_value FROM attendance_settings WHERE setting_key = ? LIMIT 1');
    if (!$stmt) {
        return $default;
    }

    $stmt->bind_param('s', $key);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $value = (int) ($row['setting_value'] ?? 0);
        $result->free();
        if ($value > 0) {
            return $value;
        }
    } elseif ($result) {
        $result->free();
    }

    $stmt = $conn->prepare('INSERT INTO attendance_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    if ($stmt) {
        $defaultValue = (string) $default;
        $stmt->bind_param('ss', $key, $defaultValue);
        $stmt->execute();
    }

    return $default;
}

function setAttendanceSetting(mysqli $conn, string $key, int $value): void
{
    $stmt = $conn->prepare('INSERT INTO attendance_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    if (!$stmt) {
        return;
    }

    $valueString = (string) $value;
    $stmt->bind_param('ss', $key, $valueString);
    $stmt->execute();
}

function isGeotaggingEnabled(mysqli $conn): bool
{
    $stmt = $conn->prepare('SELECT setting_value FROM attendance_settings WHERE setting_key = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }

    $key = 'geotagging_enabled';
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $result->free();
        return (string) ($row['setting_value'] ?? '0') === '1';
    }
    if ($result) {
        $result->free();
    }

    return false;
}

function setGeotaggingEnabled(mysqli $conn, bool $enabled): void
{
    $stmt = $conn->prepare('INSERT INTO attendance_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    if (!$stmt) {
        return;
    }

    $key = 'geotagging_enabled';
    $value = $enabled ? '1' : '0';
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
}

function getGeotagSetting(mysqli $conn, string $key): string
{
    $stmt = $conn->prepare('SELECT setting_value FROM attendance_settings WHERE setting_key = ? LIMIT 1');
    if (!$stmt) {
        return '';
    }

    $stmt->bind_param('s', $key);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $result->free();
        return (string) ($row['setting_value'] ?? '');
    }
    if ($result) {
        $result->free();
    }

    return '';
}

function setGeotagSetting(mysqli $conn, string $key, string $value): void
{
    $stmt = $conn->prepare('INSERT INTO attendance_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
}

function getScanMode(mysqli $conn): string
{
    $mode = trim(getGeotagSetting($conn, 'scan_mode'));
    return in_array($mode, ['in_out', 'all'], true) ? $mode : 'all';
}

function getAllowedPunchTypes(mysqli $conn): array
{
    if (getScanMode($conn) === 'in_out') {
        return ['AM_IN', 'PM_OUT'];
    }

    return ['AM_IN', 'AM_OUT', 'PM_IN', 'PM_OUT'];
}

function isSmsEnabledForParents(mysqli $conn): bool
{
    return trim(getGeotagSetting($conn, 'sms_send_to_parents')) !== '0';
}

function getSmsDailyLimit(mysqli $conn): int
{
    return max(0, (int) getGeotagSetting($conn, 'sms_daily_limit'));
}

function countSmsSentToday(mysqli $conn): int
{
    ensureSmsLogSchema($conn);
    $result = $conn->query("SELECT COUNT(*) AS total FROM sms_send_log WHERE status = 'sent' AND DATE(sent_at) = CURDATE()");
    if ($result) {
        $row = $result->fetch_assoc();
        $result->free();
        return (int) ($row['total'] ?? 0);
    }
    return 0;
}

function hasReachedSmsDailyLimit(mysqli $conn): bool
{
    $limit = getSmsDailyLimit($conn);
    if ($limit <= 0) {
        return false;
    }
    return countSmsSentToday($conn) >= $limit;
}

function recordSmsSend(mysqli $conn, string $phone, string $status = 'sent'): void
{
    ensureSmsLogSchema($conn);
    $stmt = $conn->prepare('INSERT INTO sms_send_log (phone, status, sent_at) VALUES (?, ?, NOW())');
    if ($stmt) {
        $stmt->bind_param('ss', $phone, $status);
        $stmt->execute();
    }
}

function getSmsGatewayConfig(mysqli $conn): array
{
    $active = trim(getGeotagSetting($conn, 'sms_active_gateway'));
    $active = in_array($active, ['cloud', 'local'], true) ? $active : 'cloud';

    $cloud = [
        'url' => trim(getGeotagSetting($conn, 'sms_cloud_url')),
        'login' => trim(getGeotagSetting($conn, 'sms_cloud_login')),
        'password' => (string) getGeotagSetting($conn, 'sms_cloud_password'),
        'device_id' => trim(getGeotagSetting($conn, 'sms_cloud_device_id')),
    ];
    $local = [
        'url' => trim(getGeotagSetting($conn, 'sms_local_url')),
        'login' => trim(getGeotagSetting($conn, 'sms_local_login')),
        'password' => (string) getGeotagSetting($conn, 'sms_local_password'),
        'device_id' => trim(getGeotagSetting($conn, 'sms_local_device_id')),
    ];

    // Fall back to config.php values when the admin has not set cloud values yet.
    if ($cloud['url'] === '') {
        $cloud['url'] = defined('SMS_GATEWAY_URL') ? SMS_GATEWAY_URL : '';
    }
    if ($cloud['login'] === '') {
        $cloud['login'] = defined('SMS_GATEWAY_LOGIN') ? SMS_GATEWAY_LOGIN : '';
    }
    if ($cloud['password'] === '') {
        $cloud['password'] = defined('SMS_GATEWAY_PASSWORD') ? SMS_GATEWAY_PASSWORD : '';
    }

    return [
        'active' => $active,
        'cloud' => $cloud,
        'local' => $local,
    ];
}

function normalizeGatewayUrl(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $hasScheme = (bool) preg_match('#^https?://#i', $url);
    $isCloud = str_contains(strtolower($url), 'sms-gate.app');

    if (!$hasScheme) {
        // Cloud hosts use HTTPS; local/LAN servers are plain HTTP.
        $url = ($isCloud ? 'https://' : 'http://') . $url;
    }

    $url = rtrim($url, '/');

    // Only the public cloud server serves the API under /3rdparty/v1.
    // Self-hosted / local servers expose the API at the root (e.g. /messages).
    if ($isCloud && !preg_match('#/3rdparty/v\d+$#i', $url)) {
        $url .= '/3rdparty/v1';
    }

    return $url;
}

function getActiveGatewayConfig(mysqli $conn): ?array
{
    $config = getSmsGatewayConfig($conn);
    $gateway = $config[$config['active']] ?? null;
    if ($gateway === null) {
        return null;
    }

    $gateway['url'] = normalizeGatewayUrl($gateway['url']);

    return $gateway;
}

function getGeotagConfig(mysqli $conn): array
{
    $latitude = trim(getGeotagSetting($conn, 'geotag_latitude'));
    $longitude = trim(getGeotagSetting($conn, 'geotag_longitude'));
    $radius = (int) getGeotagSetting($conn, 'geotag_radius_meters');

    return [
        'latitude' => is_numeric($latitude) ? $latitude : '',
        'longitude' => is_numeric($longitude) ? $longitude : '',
        'radius_meters' => $radius > 0 ? $radius : 0,
    ];
}

function distanceInMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earthRadius = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

    return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function normalizeCoordinateString(string $value): string
{
    $value = str_replace(
        ["\u{00A0}", "\u{2032}", "\u{2033}", "\u{02BA}", "\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}"],
        [' ', "'", '"', '"', "'", "'", '"', '"'],
        $value
    );

    return trim((string) preg_replace('/\s+/', ' ', $value));
}

function dmsToDecimal(string $degrees, string $minutes, string $seconds, string $hemisphere): float
{
    $decimal = abs((float) $degrees) + ((float) $minutes / 60.0) + ((float) $seconds / 3600.0);
    if (str_starts_with(trim($degrees), '-') || in_array(strtoupper($hemisphere), ['S', 'W'], true)) {
        $decimal = -$decimal;
    }

    return $decimal;
}

function parseCoordinateToDecimal(string $value): ?float
{
    $v = normalizeCoordinateString($value);
    if ($v === '') {
        return null;
    }

    // Decimal degrees: 18.358472 / -18.358472 / 18.358472°
    if (preg_match('/^([+-]?\d+(?:\.\d+)?)\s*°?$/u', $v, $m)) {
        return (float) $m[1];
    }

    // Degrees/minutes/seconds with optional hemisphere (prefix or suffix):
    // 18°21'30.50"N , N18°21'30.50" , 121°49'12.30"E , 18 21 30.5 , -18°21'30.50"
    if (preg_match('/^([nsew])?\s*(-?\d{1,3})\s*°?\s*(\d{1,2}(?:\.\d+)?)\s*\'?\s*(?:(\d+(?:\.\d+)?)\s*"?)?\s*([nsew])?$/iu', $v, $m)) {
        $minutes = (float) $m[3];
        $seconds = (float) ($m[4] ?? 0);
        if ($minutes >= 60 || $seconds >= 60) {
            return null;
        }
        $hemisphere = ($m[5] ?? '') !== '' ? strtoupper($m[5]) : strtoupper((string) ($m[1] ?? ''));

        return dmsToDecimal($m[2], $m[3], (string) ($m[4] ?? ''), $hemisphere);
    }

    return null;
}

function parseCoordinatePair(string $value): ?array
{
    $v = normalizeCoordinateString($value);
    if ($v === '') {
        return null;
    }

    // Decimal degrees pair: 18.358472, 121.820083 (Google Earth "decimal degrees" copy)
    if (preg_match('/^([+-]?\d+(?:\.\d+)?)\s*°?\s*[,;\s]\s*([+-]?\d+(?:\.\d+)?)\s*°?$/u', $v, $m)) {
        $a = (float) $m[1];
        $b = (float) $m[2];
        if (abs($a) <= 90 && abs($b) <= 180) {
            return ['latitude' => $a, 'longitude' => $b];
        }
        if (abs($b) <= 90 && abs($a) <= 180) {
            return ['latitude' => $b, 'longitude' => $a];
        }

        return null;
    }

    $dms = "([-+]?\\d{1,3})\\s*°?\\s*(\\d{1,2})\\s*'?\\s*(?:(\\d+(?:\\.\\d+)?)\\s*\"?)?";

    // Hemisphere at the end: 18°21'30.50"N 121°49'12.30"E (latitude first, then longitude)
    if (preg_match('/^' . $dms . '\s*([ns])[,;\s]*' . $dms . '\s*([ew])$/iu', $v, $m)) {
        return [
            'latitude' => dmsToDecimal($m[1], $m[2], (string) ($m[3] ?? ''), strtoupper($m[4])),
            'longitude' => dmsToDecimal($m[5], $m[6], (string) ($m[7] ?? ''), strtoupper($m[8])),
        ];
    }

    // Hemisphere at the end, longitude first: 121°49'12.30"E 18°21'30.50"N
    if (preg_match('/^' . $dms . '\s*([ew])[,;\s]*' . $dms . '\s*([ns])$/iu', $v, $m)) {
        return [
            'latitude' => dmsToDecimal($m[5], $m[6], (string) ($m[7] ?? ''), strtoupper($m[8])),
            'longitude' => dmsToDecimal($m[1], $m[2], (string) ($m[3] ?? ''), strtoupper($m[4])),
        ];
    }

    // Hemisphere at the start: N18°21'30.50" E121°49'12.30"
    if (preg_match('/^([ns])\s*' . $dms . '[,;\s]*([ew])\s*' . $dms . '$/iu', $v, $m)) {
        return [
            'latitude' => dmsToDecimal($m[2], $m[3], (string) ($m[4] ?? ''), strtoupper($m[1])),
            'longitude' => dmsToDecimal($m[6], $m[7], (string) ($m[8] ?? ''), strtoupper($m[5])),
        ];
    }

    // Hemisphere at the start, longitude first: E121°49'12.30" N18°21'30.50"
    if (preg_match('/^([ew])\s*' . $dms . '[,;\s]*([ns])\s*' . $dms . '$/iu', $v, $m)) {
        return [
            'latitude' => dmsToDecimal($m[6], $m[7], (string) ($m[8] ?? ''), strtoupper($m[5])),
            'longitude' => dmsToDecimal($m[2], $m[3], (string) ($m[4] ?? ''), strtoupper($m[1])),
        ];
    }

    return null;
}

function formatMinutesToTime(int $minutes): string
{
    $hours = intdiv($minutes, 60);
    $mins = $minutes % 60;
    $period = $hours >= 12 ? 'PM' : 'AM';
    $displayHour = $hours % 12;
    if ($displayHour === 0) {
        $displayHour = 12;
    }

    return sprintf('%02d:%02d %s', $displayHour, $mins, $period);
}

function parseTimeToMinutes(string $value): int
{
    $trimmed = trim($value);
    if (preg_match('/^(\d{1,2}):(\d{2})\s*(AM|PM)?$/i', $trimmed, $matches)) {
        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];
        if ($hours >= 0 && $hours <= 23 && $minutes >= 0 && $minutes <= 59) {
            if (!empty($matches[3])) {
                if ($hours < 1 || $hours > 12) {
                    return -1;
                }
                $hours %= 12;
                if (strtoupper($matches[3]) === 'PM') {
                    $hours += 12;
                }
            }
            return ($hours * 60) + $minutes;
        }
    }

    return -1;
}

function getAttendancePeriodRange(string $selectedDate, string $period): array
{
    $timestamp = strtotime($selectedDate ?: date('Y-m-d'));
    if ($period === 'monthly') {
        $start = date('Y-m-01', $timestamp);
        $end = date('Y-m-t', $timestamp);
        return ['start' => $start, 'end' => $end];
    }

    $start = date('Y-m-d', strtotime('monday this week', $timestamp));
    $end = date('Y-m-d', strtotime('sunday this week', $timestamp));
    return ['start' => $start, 'end' => $end];
}

function countOnTimeAttendanceForPeriod(mysqli $conn, string $studentId, string $startDate, string $endDate, string $attendanceCutoff): int
{
    $count = 0;
    $cursor = strtotime($startDate);
    $endTimestamp = strtotime($endDate);
    while ($cursor <= $endTimestamp) {
        $date = date('Y-m-d', $cursor);
        if (!isSchoolDay($conn, $date)) {
            $cursor = strtotime('+1 day', $cursor);
            continue;
        }

        $overrideStmt = $conn->prepare('SELECT status FROM attendance_overrides WHERE student_id = ? AND for_date = ? LIMIT 1');
        if ($overrideStmt) {
            $overrideStmt->bind_param('ss', $studentId, $date);
            $overrideStmt->execute();
            $overrideResult = $overrideStmt->get_result();
            $override = $overrideResult ? $overrideResult->fetch_assoc() : null;
            $overrideStmt->close();
            if ($override && $override['status'] === 'On time') {
                $count++;
                $cursor = strtotime('+1 day', $cursor);
                continue;
            }
        }

        $punchStmt = $conn->prepare("SELECT log_type, `timestamp` FROM attendance_logs WHERE student_id = ? AND log_type IN ('AM_IN', 'AM_OUT', 'PM_IN', 'PM_OUT') AND DATE(`timestamp`) = ? ORDER BY `timestamp` ASC");
        if (!$punchStmt) {
            $cursor = strtotime('+1 day', $cursor);
            continue;
        }

        $punchStmt->bind_param('ss', $studentId, $date);
        $punchStmt->execute();
        $punches = ['AM_IN' => null, 'AM_OUT' => null, 'PM_IN' => null, 'PM_OUT' => null];
        $punchResult = $punchStmt->get_result();
        while ($punchResult && ($punch = $punchResult->fetch_assoc())) {
            $punchType = $punch['log_type'];
            if ($punches[$punchType] === null) {
                $punches[$punchType] = $punch['timestamp'];
            }
        }
        $punchStmt->close();

        $amInTime = $punches['AM_IN'];
        if ($amInTime) {
            $timeOnly = date('H:i:s', strtotime($amInTime));
            if (strtotime($timeOnly) <= strtotime($attendanceCutoff)) {
                $count++;
            }
        }

        $cursor = strtotime('+1 day', $cursor);
    }

    return $count;
}

function isWeekendDate(string $date): bool
{
    $dayOfWeek = (int) date('N', strtotime($date));
    return $dayOfWeek === 6 || $dayOfWeek === 7;
}

function isSchoolDay(mysqli $conn, string $date): bool
{
    $dayOfWeek = (int) date('N', strtotime($date));
    if ($dayOfWeek >= 1 && $dayOfWeek <= 5) {
        return true;
    }
    if ($dayOfWeek === 6) {
        return getAttendanceSetting($conn, 'allow_saturday_classes', 0) === 1;
    }

    return false;
}

function ensureStudentColumns(mysqli $conn): void
{
    $columns = [];
    $result = $conn->query('SHOW COLUMNS FROM students');

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
    }

    $alterStatements = [
        ['username', 'VARCHAR(100) NULL UNIQUE'],
        ['password_hash', 'VARCHAR(255) NULL'],
        ['qr_payload', 'VARCHAR(255) NOT NULL DEFAULT ""'],
        ['grade_level', 'VARCHAR(50) NULL'],
        ['section', 'VARCHAR(50) NULL'],
        ['gender', 'VARCHAR(10) NULL'],
    ];

    foreach ($alterStatements as [$column, $definition]) {
        if (!in_array($column, $columns, true)) {
            $conn->query("ALTER TABLE students ADD COLUMN {$column} {$definition}");
        }
    }

    $conn->query("UPDATE students SET grade_level = COALESCE(NULLIF(TRIM(grade_level), ''), CASE WHEN LOCATE(' - ', grade_section) > 0 THEN TRIM(SUBSTRING_INDEX(grade_section, ' - ', 1)) ELSE '' END) WHERE grade_level IS NULL OR grade_level = ''");
    $conn->query("UPDATE students SET section = COALESCE(NULLIF(TRIM(section), ''), CASE WHEN LOCATE(' - ', grade_section) > 0 THEN TRIM(SUBSTRING_INDEX(grade_section, ' - ', -1)) ELSE '' END) WHERE section IS NULL OR section = ''");
    $conn->query("UPDATE students SET grade_section = CONCAT(COALESCE(NULLIF(TRIM(grade_level), ''), ''), CASE WHEN COALESCE(NULLIF(TRIM(section), ''), '') <> '' THEN CONCAT(' - ', TRIM(section)) ELSE '' END) WHERE TRIM(COALESCE(grade_section, '')) = ''");
}

function adminExists(mysqli $conn, string $username): bool
{
    $stmt = $conn->prepare('SELECT id FROM admins WHERE username = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result->num_rows > 0;
}

function studentUserExists(mysqli $conn, string $username): bool
{
    $stmt = $conn->prepare('SELECT student_id FROM students WHERE username = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result->num_rows > 0;
}

function generateStudentId(mysqli $conn): string
{
    $result = $conn->query("SELECT MAX(CAST(SUBSTRING(student_id, 5) AS UNSIGNED)) AS max_id FROM students WHERE student_id LIKE 'STU-%'");
    $row = $result ? $result->fetch_assoc() : null;
    $maxId = $row && $row['max_id'] ? (int) $row['max_id'] : 1000;

    return 'STU-' . str_pad((string) ($maxId + 1), 4, '0', STR_PAD_LEFT);
}

function buildQrUrl(string $payload): string
{
    return 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($payload);
}
