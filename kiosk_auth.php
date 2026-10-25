<?php
require_once __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Strict',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

function isKioskAuthenticated(): bool
{
    return !empty($_SESSION['kiosk_authenticated']);
}

function authenticateKiosk(string $password): bool
{
    $configuredPassword = defined('KIOSK_PASSWORD') ? KIOSK_PASSWORD : '';

    if ($configuredPassword === '' || !hash_equals($configuredPassword, $password)) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['kiosk_authenticated'] = true;
    return true;
}

function logoutKiosk(): void
{
    unset($_SESSION['kiosk_authenticated']);
}
