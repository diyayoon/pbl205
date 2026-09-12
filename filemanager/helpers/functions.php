<?php
/**
 * Global Helper Functions
 */

// Escape output HTML biar aman dari XSS
function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function redirect(string $path): void {
    header('Location: ' . APP_URL . $path);
    exit;
}

// Generate/reuse CSRF token, auto-refresh kalau sudah expired
function csrfGenerate(): string {
    $expired = isset($_SESSION['csrf_time']) && (time() - $_SESSION['csrf_time'] > CSRF_TOKEN_EXPIRY);

    if (empty($_SESSION[CSRF_TOKEN_NAME]) || $expired) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
        $_SESSION['csrf_time']     = time();
    }

    return $_SESSION[CSRF_TOKEN_NAME];
}

function csrfField(): string {
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . csrfGenerate() . '">';
}

// Verifikasi token pakai hash_equals biar aman dari timing attack
function csrfVerify(string $token): bool {
    if (empty($_SESSION[CSRF_TOKEN_NAME])) return false;

    return hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

// Ambil IP client asli, cek header proxy dulu baru REMOTE_ADDR
function clientIp(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);

            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    return '0.0.0.0';
}

// Format ukuran file jadi B/KB/MB/GB/TB
function formatSize(int $bytes): string {
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];

    $i = $bytes > 0 ? floor(log($bytes) / log(1024)) : 0;
    $i = min($i, count($u) - 1);

    return round($bytes / (1024 ** $i), 2) . ' ' . $u[$i];
}

// Generate UUID v4 buat nama file unik di disk
function generateUuid(): string {
    $d = random_bytes(16);

    $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
    $d[8] = chr(ord($d[8]) & 0x3f | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

// Bersihkan nama file dari karakter berbahaya/aneh
function sanitizeFilename(string $f): string {
    $f = str_replace("\0", '', $f);
    $f = preg_replace('/[^\w\s\-.]/', '', $f);

    return substr(trim(preg_replace('/\s+/', '_', $f)) ?: 'file', 0, 200);
}

function fileExt(string $f): string {
    return strtolower(pathinfo($f, PATHINFO_EXTENSION));
}

// Cek ekstensi termasuk daftar blacklist (mis. .php, .exe)
function isDangerous(string $ext): bool {
    return in_array(strtolower($ext), BLOCKED_EXTENSIONS, true);
}

// Mapping ekstensi -> icon & warna buat tampilan file list
function fileIconClass(string $ext): array {
    $map = [
        'pdf'  => ['lucide-file-text', 'text-red-500', 'bg-red-100 dark:bg-red-900/30'],
        'doc'  => ['lucide-file-text', 'text-blue-500', 'bg-blue-100 dark:bg-blue-900/30'],
        'docx' => ['lucide-file-text', 'text-blue-500', 'bg-blue-100 dark:bg-blue-900/30'],
        'xls'  => ['lucide-table-2', 'text-green-500', 'bg-green-100 dark:bg-green-900/30'],
        'xlsx' => ['lucide-table-2', 'text-green-500', 'bg-green-100 dark:bg-green-900/30'],
        'ppt'  => ['lucide-presentation', 'text-orange-500', 'bg-orange-100 dark:bg-orange-900/30'],
        'pptx' => ['lucide-presentation', 'text-orange-500', 'bg-orange-100 dark:bg-orange-900/30'],
        'zip'  => ['lucide-archive', 'text-yellow-500', 'bg-yellow-100 dark:bg-yellow-900/30'],
        'rar'  => ['lucide-archive', 'text-yellow-500', 'bg-yellow-100 dark:bg-yellow-900/30'],
        'txt'  => ['lucide-file', 'text-gray-500', 'bg-gray-100 dark:bg-gray-900/30'],
        'csv'  => ['lucide-database', 'text-teal-500', 'bg-teal-100 dark:bg-teal-900/30'],
        'jpg'  => ['lucide-image', 'text-purple-500', 'bg-purple-100 dark:bg-purple-900/30'],
        'jpeg' => ['lucide-image', 'text-purple-500', 'bg-purple-100 dark:bg-purple-900/30'],
        'png'  => ['lucide-image', 'text-purple-500', 'bg-purple-100 dark:bg-purple-900/30'],
        'mp4'  => ['lucide-video', 'text-pink-500', 'bg-pink-100 dark:bg-pink-900/30'],
        'mp3'  => ['lucide-music', 'text-indigo-500', 'bg-indigo-100 dark:bg-indigo-900/30'],
    ];

    return $map[$ext] ?? ['lucide-file', 'text-gray-400', 'bg-gray-100 dark:bg-gray-900/30'];
}

function fmtDate(string $dt, string $fmt = 'd M Y, H:i'): string {
    return date($fmt, strtotime($dt));
}

// Format waktu relatif ("5 menit lalu", dst), fallback ke tanggal kalau udah lama
function timeAgo(string $dt): string {
    $d = time() - strtotime($dt);

    if ($d < 60) return 'Baru saja';
    if ($d < 3600) return floor($d / 60) . ' menit lalu';
    if ($d < 86400) return floor($d / 3600) . ' jam lalu';
    if ($d < 604800) return floor($d / 86400) . ' hari lalu';

    return fmtDate($dt, 'd M Y');
}

// Simpan pesan flash ke session (ditampilkan sekali di request berikutnya)
function flash(string $type, string $msg): void {
    $_SESSION['flash'] = [
        'type' => $type,
        'msg'  => $msg
    ];
}

// Ambil & hapus flash message dari session
function getFlash(): ?array {
    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $f;
}

function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

function currentUser(): ?array {
    return $_SESSION['user'] ?? null;
}

function isSuperAdmin(): bool {
    $u = currentUser();
    return $u && ($u['system_role'] ?? '') === 'superadmin';
}

function isGeneralAdmin(): bool {
    $u = currentUser();
    $role = $u['system_role'] ?? '';

    return $role === 'general_admin' || $role === 'superadmin';
}

function isMember(): bool {
    $u = currentUser();
    return $u && ($u['system_role'] ?? '') === 'member';
}

// TODO: masih selalu return isGeneralAdmin(), belum cek $div spesifik
function canAccessDivision(string $div): bool {
    return isGeneralAdmin();
}

function divisionLabel(string $div): string {
    return DIVISIONS[$div] ?? ucfirst($div);
}

// Hitung metadata pagination (total halaman, offset, dst)
function paginate(int $total, int $page, int $perPage = PER_PAGE): array {
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = max(1, min($page, $pages));

    return [
        'total'  => $total,
        'pages'  => $pages,
        'page'   => $page,
        'offset' => ($page - 1) * $perPage,
        'limit'  => $perPage,
    ];
}

/* Department Access */

// Cek apakah user adalah admin di departemen tertentu (by slug)
function isDeptAdmin(string $deptSlug): bool {
    if (isSuperAdmin()) return true;

    $u = currentUser();
    if (!$u) return false;

    require_once APP_ROOT . '/models/UserModel.php';

    $userModel = new UserModel();
    $depts = $userModel->getAdminDepartments((int)$u['id']);

    foreach ($depts as $d) {
        if ($d['slug'] === $deptSlug) {
            return true;
        }
    }

    return false;
}

// Cek apakah user (dengan role apa pun) tergabung di departemen tertentu
function canAccessDept(string $deptSlug): bool {
    if (isSuperAdmin()) return true;

    $u = currentUser();
    if (!$u) return false;

    require_once APP_ROOT . '/models/UserModel.php';

    $userModel = new UserModel();
    $depts = $userModel->getUserDepartments((int)$u['id']);

    foreach ($depts as $d) {
        if ($d['slug'] === $deptSlug) {
            return true;
        }
    }

    return false;
}