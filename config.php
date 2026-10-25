<?php
// SMS gateway (android-sms-gateway / SMS Gate) configuration.
// Values can be overridden with environment variables.

if (!defined('SMS_GATEWAY_URL')) {
    define('SMS_GATEWAY_URL', getenv('SMS_GATEWAY_URL') ?: 'https://api.sms-gate.app/3rdparty/v1');
}

if (!defined('SMS_GATEWAY_LOGIN')) {
    define('SMS_GATEWAY_LOGIN', getenv('SMS_GATEWAY_LOGIN') ?: 'TWCN8J');
}

if (!defined('SMS_GATEWAY_PASSWORD')) {
    define('SMS_GATEWAY_PASSWORD', getenv('SMS_GATEWAY_PASSWORD') ?: 'fudhud6xizfub7');
}

if (!defined('KIOSK_PASSWORD')) {
    define('KIOSK_PASSWORD', getenv('KIOSK_PASSWORD') ?: 'smart2026');
}

if (!defined('MASTER_TEACHER_USERNAME')) {
    define('MASTER_TEACHER_USERNAME', getenv('MASTER_TEACHER_USERNAME') ?: 'mteacher');
}

if (!defined('MASTER_TEACHER_PASSWORD')) {
    define('MASTER_TEACHER_PASSWORD', getenv('MASTER_TEACHER_PASSWORD') ?: 'mt2026');
}
