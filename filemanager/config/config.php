<?php

function envValue(string $key, mixed $default = null): mixed {
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

define('APP_NAME',    'File Manager');
define('APP_URL',     envValue('APP_URL', 'http://localhost:8080'));
define('APP_ROOT',    dirname(__DIR__));
define('APP_ENV',     'production');

define('DB_HOST',    envValue('DB_HOST', ''));
define('DB_PORT',    envValue('DB_PORT', '3306'));
define('DB_NAME',    envValue('DB_NAME', 'filemanager_db'));
define('DB_USER',    envValue('DB_USER', ''));
define('DB_PASS',    envValue('DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');

define('SESSION_NAME',     'fm_sess');
define('SESSION_LIFETIME', 7200);
define('SESSION_SECURE',   true);

define('UPLOAD_MAX_SIZE', 104857600);
define('UPLOAD_PATH',     APP_ROOT . '/uploads');

define('SAMBA_HOST',     envValue('SAMBA_HOST', ''));
define('SAMBA_SHARE',    envValue('SAMBA_SHARE', 'IT'));
define('SAMBA_DOMAIN',   envValue('SAMBA_DOMAIN', ''));
define('SAMBA_USER',     envValue('SAMBA_USER', ''));
define('SAMBA_PASSWORD', envValue('SAMBA_PASSWORD', ''));

// Ekstensi yang diblok total, apapun MIME type-nya
define('BLOCKED_EXTENSIONS', [
    'php','php3','php4','php5','phtml','phar',
    'exe','bat','sh','cmd','com','scr',
    'js','vbs','vbe','jse','wsf','wsh',
    'msi','msc','jar','reg','asp','aspx',
]);

// Daftar MIME type yang diizinkan untuk upload
define('ALLOWED_MIME_TYPES', [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'application/zip','application/x-zip-compressed',
    'application/x-rar-compressed','application/gzip',
    'text/plain','text/csv',
    'image/jpeg','image/png','image/gif','image/webp',
    'video/mp4','audio/mpeg','audio/wav',
]);

define('CSRF_TOKEN_NAME',   'csrf_token');
define('CSRF_TOKEN_EXPIRY', 3600);
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_MIN',  15);

define('PER_PAGE', 15);

define('DIVISIONS', [
    'finance' => 'Finance',
    'hr'      => 'Human Resources',
    'it'      => 'IT',
]);

date_default_timezone_set('Asia/Jakarta');

if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}