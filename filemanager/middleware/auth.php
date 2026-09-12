<?php
/**
 * Auth Middleware
 */

require_once __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/helpers/functions.php';

// Konfigurasi session: nama, lifetime, cookie httponly/secure/samesite
ini_set('session.name',             SESSION_NAME);
ini_set('session.gc_maxlifetime',   SESSION_LIFETIME);
ini_set('session.cookie_httponly',  '1');
ini_set('session.cookie_secure',    SESSION_SECURE ? '1' : '0');
ini_set('session.cookie_samesite',  'Strict');
ini_set('session.use_strict_mode',  '1');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Deteksi apakah request ini fetch/AJAX (dikirim dengan header X-Requested-With)
function isAjaxRequest(): bool {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

// Kirim response sesuai jenis request: JSON 401 untuk AJAX, redirect untuk page load biasa
function respondSessionExpired(string $redirectTo): void {
    if (isAjaxRequest()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'session_expired']);
        exit;
    }
    redirect($redirectTo);
}

// Cek idle timeout, kalau lewat batas SESSION_LIFETIME langsung logout paksa
function checkSessionTimeout(): void {
    if (isset($_SESSION['last_activity'])) {
        if (time() - $_SESSION['last_activity'] > SESSION_LIFETIME) {
            session_unset();
            session_destroy();
            redirect('/index.php?timeout=1');
        }
    }
    $_SESSION['last_activity'] = time();
}

// Wajibkan login, redirect ke halaman login (dengan URL asal) kalau belum
function requireLogin(): void {
    checkSessionTimeout();
    if (!isLoggedIn()) {
        redirect('/index.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/dashboard.php'));
    }
}

// Wajibkan role general_admin/superadmin, tolak & redirect kalau bukan
function requireGeneralAdmin(): void {
    requireLogin();
    if (!isGeneralAdmin()) {
        flash('error', 'Akses ditolak. Hanya General Admin yang diizinkan.');
        redirect('/dashboard.php');
    }
}

// Wajibkan akses ke divisi tertentu
function requireDivisionAccess(string $division): void {
    requireLogin();
    if (!canAccessDivision($division)) {
        flash('error', 'Akses ditolak. Anda tidak memiliki izin ke divisi ini.');
        redirect('/dashboard.php');
    }
}

// Regenerasi session ID (dipanggil setelah login berhasil, cegah session fixation)
function regenerateSession(): void {
    session_regenerate_id(true);
    $_SESSION['last_activity'] = time();
}