<?php
header('Content-Type: application/json');
require_once 'kiosk_auth.php';
require_once 'db.php';
require_once 'admin_helpers.php';
require_once 'sms_helper.php';

if (!isKioskAuthenticated()) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Kiosk is locked. Enter the kiosk password first."]);
    exit;
}

$student_id = isset($_POST['student_id']) ? trim($_POST['student_id']) : '';
$punch_type = strtoupper(trim((string) ($_POST['punch_type'] ?? '')));
$allowedPunchTypes = getAllowedPunchTypes($conn);

if (empty($student_id)) {
    echo json_encode(["status" => "error", "message" => "No QR code scanned."]);
    exit;
}

if (!in_array($punch_type, $allowedPunchTypes, true)) {
    $allowedLabels = implode(' and ', array_map(static function ($type) {
        return str_replace('_', ' ', $type);
    }, $allowedPunchTypes));
    echo json_encode(["status" => "error", "message" => "Only {$allowedLabels} scans are allowed right now."]);
    exit;
}

// 1. Verify student existence
$stmt = $conn->prepare("SELECT full_name, parent_phone FROM students WHERE student_id = ?");
$stmt->bind_param("s", $student_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(["status" => "error", "message" => "Student ID not recognized."]);
    exit;
}

$student = $result->fetch_assoc();
$result->free();
$stmt->close();
$full_name = $student['full_name'];
$parent_phone = normalizePhoneNumber($student['parent_phone']);

// 1b. Resolve and validate the device geolocation sent by the kiosk.
$rawLat = trim((string) ($_POST['latitude'] ?? ''));
$rawLng = trim((string) ($_POST['longitude'] ?? ''));
$scanLat = null;
$scanLng = null;
if ($rawLat !== '' && $rawLng !== '' && is_numeric($rawLat) && is_numeric($rawLng)) {
    $candidateLat = (float) $rawLat;
    $candidateLng = (float) $rawLng;
    if ($candidateLat >= -90 && $candidateLat <= 90 && $candidateLng >= -180 && $candidateLng <= 180) {
        $scanLat = $candidateLat;
        $scanLng = $candidateLng;
    }
}

if (isGeotaggingEnabled($conn)) {
    if ($scanLat === null || $scanLng === null) {
        echo json_encode([
            "status" => "error",
            "message" => "Location access is required to scan. Allow location permission on the kiosk device and try again."
        ]);
        exit;
    }

    $geotagConfig = getGeotagConfig($conn);
    $centerLat = $geotagConfig['latitude'];
    $centerLng = $geotagConfig['longitude'];
    if ($centerLat !== '' && $centerLng !== '' && $geotagConfig['radius_meters'] > 0) {
        $distance = distanceInMeters($scanLat, $scanLng, (float) $centerLat, (float) $centerLng);
        if ($distance > $geotagConfig['radius_meters']) {
            echo json_encode([
                "status" => "error",
                "message" => "You are " . (int) round($distance) . " m away from the allowed scanning area. Scanning is only permitted within " . $geotagConfig['radius_meters'] . " m of the school's set location."
            ]);
            exit;
        }
    }
}

// Serialize scans for this student so two simultaneous requests cannot create duplicates.
$conn->begin_transaction();
$lockStmt = $conn->prepare('SELECT student_id FROM students WHERE student_id = ? FOR UPDATE');
$lockStmt->bind_param('s', $student_id);
$lockStmt->execute();
$lockResult = $lockStmt->get_result();
if ($lockResult) {
    $lockResult->free();
}
$lockStmt->close();
$safeStudentId = $conn->real_escape_string($student_id);

// 2. Check the selected action while the student row remains locked.
$lastLogResult = $conn->query("SELECT log_type, timestamp FROM attendance_logs WHERE student_id = '{$safeStudentId}' AND DATE(timestamp) = CURDATE() ORDER BY id DESC LIMIT 1");
$last_log = $lastLogResult ? $lastLogResult->fetch_assoc() : null;
if ($lastLogResult) {
    $lastLogResult->free();
}

$duplicateStmt = $conn->prepare('SELECT id, timestamp FROM attendance_logs WHERE student_id = ? AND log_type = ? AND DATE(timestamp) = CURDATE() LIMIT 1');
$duplicateStmt->bind_param('ss', $student_id, $punch_type);
$duplicateStmt->execute();
$duplicate = $duplicateStmt->get_result()->fetch_assoc();
if ($duplicate) {
    $conn->rollback();
    echo json_encode([
        "status" => "error",
        "student_name" => $full_name,
        "log_type" => $punch_type,
        "message" => "Your {$punch_type} punch is already recorded today at " . date('h:i A', strtotime($duplicate['timestamp'])) . "."
    ]);
    exit;
}

$log_type = $punch_type;

if ($last_log) {
    $last_time = strtotime($last_log['timestamp']);
    $time_diff = time() - $last_time;

    // Block rapid double-scanning within 120 seconds (2 minutes)
    if ($time_diff < 120) {
        $conn->rollback();
        echo json_encode([
            "status" => "warning", 
            "message" => "Already logged recently. Please wait a moment."
        ]);
        exit;
    }

}

$current_time = new DateTime('now', new DateTimeZone('Asia/Manila'));
$current_minutes = ((int) $current_time->format('H') * 60) + (int) $current_time->format('i');

$thresholdDefaults = ['AM_IN' => 300, 'AM_OUT' => 420, 'PM_IN' => 780, 'PM_OUT' => 960];
$thresholdMinutes = [
    'AM_IN' => getAttendanceSetting($conn, 'am_in_threshold_minutes', $thresholdDefaults['AM_IN']),
    'AM_OUT' => getAttendanceSetting($conn, 'am_out_threshold_minutes', $thresholdDefaults['AM_OUT']),
    'PM_IN' => getAttendanceSetting($conn, 'pm_in_threshold_minutes', $thresholdDefaults['PM_IN']),
    'PM_OUT' => getAttendanceSetting($conn, 'pm_out_threshold_minutes', $thresholdDefaults['PM_OUT']),
];
$thresholdOrderIsValid = $thresholdMinutes['AM_IN'] < $thresholdMinutes['AM_OUT']
    && $thresholdMinutes['AM_OUT'] < $thresholdMinutes['PM_IN']
    && $thresholdMinutes['PM_IN'] < $thresholdMinutes['PM_OUT'];

if (!$thresholdOrderIsValid) {
    $conn->rollback();
    echo json_encode([
        "status" => "error",
        "message" => "Attendance thresholds are not configured in chronological order. Please contact an administrator."
    ]);
    exit;
}

$windowStarts = $thresholdMinutes;
$windowEnds = [
    'AM_IN' => $thresholdMinutes['AM_OUT'],
    'AM_OUT' => $thresholdMinutes['PM_IN'],
    'PM_IN' => $thresholdMinutes['PM_OUT'],
    'PM_OUT' => 1440,
];
$is_allowed = $current_minutes >= $windowStarts[$log_type] && $current_minutes < $windowEnds[$log_type];
$window_message = $log_type . ' is only allowed from ' . formatMinutesToTime($windowStarts[$log_type])
    . ' until ' . formatMinutesToTime($windowEnds[$log_type]) . '.';

if (!$is_allowed) {
    $conn->rollback();
    echo json_encode([
        "status" => "error",
        "message" => $window_message
    ]);
    exit;
}

if ($scanLat !== null && $scanLng !== null) {
    $insertStmt = $conn->prepare('INSERT INTO attendance_logs (student_id, log_type, latitude, longitude) VALUES (?, ?, ?, ?)');
    $insertStmt->bind_param('ssdd', $student_id, $log_type, $scanLat, $scanLng);
} else {
    $insertStmt = $conn->prepare('INSERT INTO attendance_logs (student_id, log_type) VALUES (?, ?)');
    $insertStmt->bind_param('ss', $student_id, $log_type);
}
if (!$insertStmt->execute()) {
    $conn->rollback();
    echo json_encode(["status" => "error", "message" => "Attendance could not be recorded. Please try again."]);
    exit;
}
$conn->commit();

// 4. Dispatch SMS
$time_str = date("h:i A");
$full_time = date("Y-m-d h:i A");

if (substr($log_type, -3) === '_IN') {
    $sms_message = "Notice:Good Day! Your Son/Daughter, {$full_name}, has recorded {$log_type} at Baggao National Agricultural School at {$time_str} on {$full_time}.";
} else {
    $sms_message = "Notice: Your Son/Daughter, {$full_name}, has recorded {$log_type} at Baggao National Agricultural School at {$time_str} on {$full_time}.";
}

$sms_sent = false;
$sms_status = 'not_attempted';
if (!isSmsEnabledForParents($conn)) {
    $sms_status = 'disabled';
} elseif (!empty($parent_phone)) {
    $sms_sent = sendSMS($parent_phone, $sms_message);
    $sms_status = $sms_sent ? 'sent' : 'failed';
} else {
    $sms_status = 'missing_phone';
}

$status = 'success';
$message = 'Scan successful! Parent SMS was sent.';
if ($sms_status === 'failed') {
    $status = 'warning';
    $message = 'Scan recorded, but the parent SMS could not be sent. Please verify the SMS gateway and phone number.';
} elseif ($sms_status === 'missing_phone') {
    $status = 'warning';
    $message = 'Scan recorded, but no parent phone number is available for SMS.';
} elseif ($sms_status === 'disabled') {
    $status = 'success';
    $message = 'Scan recorded. Parent SMS notifications are turned off.';
} elseif ($sms_status === 'not_attempted') {
    $status = 'warning';
    $message = 'Scan recorded, but SMS was not attempted.';
}

// Return response to frontend UI
echo json_encode([
    "status" => $status,
    "student_name" => $full_name,
    "log_type" => $log_type,
    "time" => $time_str,
    "message" => $message,
    "sms_status" => $sms_status,
    "parent_phone" => $parent_phone
]);
?>
