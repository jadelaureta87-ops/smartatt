<?php
require_once __DIR__ . '/config.php';

if (file_exists(__DIR__ . '/admin_helpers.php')) {
    require_once __DIR__ . '/admin_helpers.php';
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

function normalizePhoneNumber($number) {
    $number = trim((string) $number);
    $number = preg_replace('/[^0-9+]/', '', $number);

    if ($number === '') {
        return '';
    }

    if (str_starts_with($number, '+')) {
        return $number;
    }

    if (str_starts_with($number, '0')) {
        return '+63' . substr($number, 1);
    }

    if (str_starts_with($number, '63')) {
        return '+' . $number;
    }

    return '+' . $number;
}

function getAttendanceConn(): ?mysqli
{
    static $resolved = false;
    static $handle = null;

    if ($resolved) {
        return $handle;
    }
    $resolved = true;

    // Reuse an already-open connection from the calling script when available.
    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
        return $handle = $GLOBALS['conn'];
    }

    $dbFile = __DIR__ . '/db.php';
    if (!file_exists($dbFile)) {
        return $handle = null;
    }

    $conn = null;
    try {
        require $dbFile;
    } catch (\Throwable $e) {
        error_log('Attendance DB load for SMS settings failed: ' . $e->getMessage());
        return $handle = null;
    }

    return $handle = ($conn instanceof mysqli) ? $conn : null;
}

function resolveGatewayConfig(): array
{
    $fallback = [
        'active' => 'cloud',
        'url' => defined('SMS_GATEWAY_URL') ? SMS_GATEWAY_URL : 'https://api.sms-gate.app/3rdparty/v1',
        'login' => defined('SMS_GATEWAY_LOGIN') ? SMS_GATEWAY_LOGIN : '',
        'password' => defined('SMS_GATEWAY_PASSWORD') ? SMS_GATEWAY_PASSWORD : '',
        'device_id' => '',
    ];

    $helpersFile = __DIR__ . '/admin_helpers.php';
    if (!file_exists($helpersFile)) {
        return $fallback;
    }
    require_once $helpersFile;

    $conn = getAttendanceConn();
    if ($conn === null) {
        return $fallback;
    }

    try {
        $gateway = getActiveGatewayConfig($conn);
        if ($gateway === null || $gateway['url'] === '') {
            return $fallback;
        }

        return [
            'active' => getSmsGatewayConfig($conn)['active'],
            'url' => $gateway['url'],
            'login' => $gateway['login'],
            'password' => $gateway['password'],
            'device_id' => $gateway['device_id'],
        ];
    } catch (\Throwable $e) {
        error_log('SMS gateway config lookup failed, using config.php defaults: ' . $e->getMessage());
        return $fallback;
    }
}

function isParentSmsDisabled(): bool
{
    if (!file_exists(__DIR__ . '/admin_helpers.php')) {
        return false;
    }
    require_once __DIR__ . '/admin_helpers.php';

    $conn = getAttendanceConn();
    if ($conn === null) {
        return false;
    }

    try {
        return !isSmsEnabledForParents($conn);
    } catch (\Throwable $e) {
        error_log('Parent SMS setting lookup failed, assuming enabled: ' . $e->getMessage());
        return false;
    }
}

function sendSMS($number, $message) {
    $number = normalizePhoneNumber($number);

    if ($number === '') {
        error_log('SMS not sent: empty phone number.');
        return false;
    }

    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        error_log('SMS not sent: composer dependencies are missing. Run "composer install" first.');
        return false;
    }

    $gateway = resolveGatewayConfig();

    try {
        $client = new AndroidSmsGateway\Client(
            $gateway['login'],
            $gateway['password'],
            $gateway['url']
        );

        $message = (new AndroidSmsGateway\Domain\MessageBuilder($message, [$number]))
            ->setWithDeliveryReport(true)
            ->build();

        $client->SendMessage($message);
        return true;
    } catch (\Throwable $e) {
        error_log('SMS send failure via ' . $gateway['active'] . ' gateway (' . $gateway['url'] . ') for ' . $number . ': ' . $e->getMessage());
        return false;
    }
}

function sendAttendanceSMS($fullName, $parentPhone, $logType, $timestamp) {
    if (isParentSmsDisabled()) {
        return 'disabled';
    }

    $conn = getAttendanceConn();
    if ($conn !== null && function_exists('hasReachedSmsDailyLimit') && hasReachedSmsDailyLimit($conn)) {
        return 'limit_reached';
    }

    $time = date('h:i A', strtotime($timestamp));
    $date = date('Y-m-d', strtotime($timestamp));
    $message = "Notice: Your Son/Daughter, {$fullName}, has recorded {$logType} at Baggao National Agricultural School at {$time} on {$date}.";

    if (normalizePhoneNumber($parentPhone) === '') {
        return 'missing_phone';
    }

    $sent = sendSMS($parentPhone, $message);
    if ($conn !== null && function_exists('recordSmsSend')) {
        recordSmsSend($conn, normalizePhoneNumber($parentPhone), $sent ? 'sent' : 'failed');
    }

    return $sent ? 'sent' : 'failed';
}
?>
