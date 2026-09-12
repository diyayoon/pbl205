<?php
/* index.php — Halaman Login */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/functions.php';
require_once __DIR__ . '/models/UserModel.php';
require_once __DIR__ . '/helpers/AuditLogger.php';

ini_set('session.name', SESSION_NAME);
if (session_status() === PHP_SESSION_NONE) session_start();

if (isLoggedIn()) redirect('/dashboard.php');

$error   = '';
$timeout = isset($_GET['timeout']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!csrfVerify($token)) {
    $error = 'Token keamanan tidak valid. Refresh halaman dan coba lagi.';

    } else {
        $identifier = trim($_POST['username'] ?? '');
        $password   = $_POST['password'] ?? '';

        if (empty($identifier) || empty($password)) {
            $error = 'Username/NIM dan password wajib diisi.';
        } else {
            $userModel = new UserModel();
            $user      = $userModel->findByUsernameOrEmail($identifier);

            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true);

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user']    = [
                    'id'          => $user['id'],
                    'username'    => $user['username'],
                    'nim'         => $user['nim'],
                    'system_role' => $user['system_role'],
                    'team_role'   => $user['team_role'],
                    'jobdesk'     => $user['jobdesk'],
                    'avatar'      => $user['avatar'],
                ];

                $_SESSION['last_activity'] = time();

                AuditLogger::log(
                    "LOGIN",
                    "SUCCESS",
                    [
                        "username" => $user['username']
                    ]
                );

                $redirect = $_GET['redirect'] ?? '/dashboard.php';
                header('Location: ' . APP_URL . $redirect);
                exit;
            } else {

                AuditLogger::log(
                    "LOGIN",
                    "FAILED",
                    [
                        "username" => $identifier
                    ]
                );

                $error = 'Username atau password salah.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — File Manager</title>

<script>
(function() {
  const saved = localStorage.getItem('darkMode');

  if (saved === 'light') {
    document.documentElement.classList.remove('dark');
  } else {
    document.documentElement.classList.add('dark');
    if (!saved) localStorage.setItem('darkMode', 'dark');
  }
})();
</script>

<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = {
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        primary: {
          DEFAULT: '#0ea5e9',
          hover: '#0284c7',
          light: '#e0f2fe'
        }
      }
    }
  }
}
</script>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300..700&display=swap" rel="stylesheet">
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>

<style>
  * {
    font-family: 'Inter', sans-serif;
  }

  html,
  body {
    min-height: 100%;
  }

  body.gradient-bg {
    position: relative;
    overflow: hidden;
    color: #0f172a;
    background:
      radial-gradient(circle at 16% 12%, rgba(255, 255, 255, 0.95) 0%, rgba(224, 242, 254, 0.68) 22%, transparent 44%),
      radial-gradient(circle at 82% 18%, rgba(56, 189, 248, 0.58) 0%, rgba(96, 165, 250, 0.38) 28%, transparent 56%),
      radial-gradient(circle at 48% 92%, rgba(14, 165, 233, 0.36) 0%, rgba(186, 230, 253, 0.46) 32%, transparent 60%),
      linear-gradient(135deg, #f8fdff 0%, #e0f7ff 30%, #bae6fd 58%, #93c5fd 100%);
    background-attachment: fixed;
  }

  html.dark body.gradient-bg {
    color: #e5edff;
    background:
      radial-gradient(circle at 15% 15%, rgba(99, 102, 241, 0.55) 0%, transparent 30%),
      radial-gradient(circle at 85% 20%, rgba(168, 85, 247, 0.48) 0%, transparent 32%),
      radial-gradient(circle at 50% 90%, rgba(37, 99, 235, 0.42) 0%, transparent 35%),
      linear-gradient(135deg, #0f172a 0%, #1e1b4b 28%, #4c1d95 58%, #1d4ed8 100%);
  }

  .gradient-bg::before {
    content: "";
    position: absolute;
    inset: 0;
    pointer-events: none;
    background:
      linear-gradient(rgba(14, 165, 233, 0.10) 1px, transparent 1px),
      linear-gradient(90deg, rgba(59, 130, 246, 0.08) 1px, transparent 1px),
      radial-gradient(circle at 25% 22%, rgba(14, 165, 233, 0.24) 0 1px, transparent 2px),
      radial-gradient(circle at 75% 36%, rgba(96, 165, 250, 0.24) 0 1px, transparent 2px);
    background-size: 48px 48px, 48px 48px, 150px 150px, 210px 210px;
  }

  html.dark .gradient-bg::before {
    background:
      linear-gradient(rgba(255, 255, 255, 0.035) 1px, transparent 1px),
      linear-gradient(90deg, rgba(255, 255, 255, 0.035) 1px, transparent 1px);
    background-size: 48px 48px;
    mask-image: linear-gradient(to bottom, rgba(0,0,0,0.7), transparent);
  }

  .gradient-bg::after {
    content: "";
    position: absolute;
    width: 760px;
    height: 760px;
    right: -190px;
    bottom: -260px;
    border-radius: 9999px;
    pointer-events: none;
    background: radial-gradient(circle, rgba(14, 165, 233, 0.38), rgba(96, 165, 250, 0.18) 46%, transparent 70%);
    filter: blur(58px);
  }

  html.dark .gradient-bg::after {
    background: radial-gradient(circle, rgba(59, 130, 246, 0.28), rgba(124, 58, 237, 0.20) 46%, transparent 72%);
  }

  .login-shell {
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: 28rem;
    margin-left: auto;
    margin-right: auto;
  }

  .login-brand {
    width: 100%;
    display: flex;
    flex-direction: column;
    align-items: center !important;
    justify-content: center !important;
    text-align: center !important;
    margin-left: auto;
    margin-right: auto;
  }

  .logo-center-row {
    width: 100% !important;
    display: flex !important;
    justify-content: center !important;
    align-items: center !important;
  }

  .logo-box {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    float: none !important;
    position: static !important;
    margin-left: auto !important;
    margin-right: auto !important;
    background: linear-gradient(135deg, #38bdf8, #60a5fa 58%, #bae6fd);
    border: 1px solid rgba(255, 255, 255, 0.62);
    box-shadow: 0 20px 60px rgba(14, 165, 233, 0.28), inset 0 0 0 1px rgba(255,255,255,0.35);
  }

  html.dark .logo-box {
  background: linear-gradient(135deg, #2563eb, #8b5cf6 52%, #d946ef);
  border: 1px solid rgba(255, 255, 255, 0.24);
  box-shadow: 0 20px 60px rgba(124, 58, 237, 0.42), inset 0 0 0 1px rgba(255,255,255,0.24);
  }

  .brand-title {
    display: block !important;
    width: 100% !important;
    text-align: center !important;
    background: linear-gradient(90deg, #0369a1 0%, #2563eb 48%, #0891b2 100%);
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
    text-shadow: 0 12px 34px rgba(14, 165, 233, 0.16);
  }

  html.dark .brand-title {
    background: linear-gradient(90deg, #bfdbfe 0%, #c4b5fd 48%, #f0abfc 100%);
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
    text-shadow: 0 12px 34px rgba(168, 85, 247, 0.22);
  }

  .brand-subtitle {
    display: block !important;
    width: 100% !important;
    text-align: center !important;
    color: rgba(15, 23, 42, 0.66);
  }

  html.dark .brand-subtitle {
    color: rgba(255, 255, 255, 0.78);
  }

  .theme-toggle-login {
    position: fixed;
    top: 22px;
    right: 22px;
    z-index: 5;
    width: 46px;
    height: 46px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    border: 1px solid rgba(14, 165, 233, 0.26);
    background: rgba(255, 255, 255, 0.68);
    color: #075985;
    box-shadow: 0 16px 40px rgba(14, 165, 233, 0.18), inset 0 0 0 1px rgba(255,255,255,0.58);
    backdrop-filter: blur(16px);
    transition: all 0.2s ease;
  }

  .theme-toggle-login:hover {
    transform: translateY(-1px);
    background: rgba(255, 255, 255, 0.82);
  }

  html.dark .theme-toggle-login {
    border-color: rgba(167, 139, 250, 0.26);
    background: rgba(15, 23, 42, 0.48);
    color: #dbeafe;
    box-shadow: 0 16px 40px rgba(124, 58, 237, 0.22), inset 0 0 0 1px rgba(255,255,255,0.08);
  }

  .login-card {
    background: rgba(255, 255, 255, 0.84);
    border: 1px solid rgba(14, 165, 233, 0.32);
    box-shadow: 0 30px 90px rgba(14, 165, 233, 0.24), inset 0 0 0 1px rgba(255,255,255,0.72);
    backdrop-filter: blur(20px);
  }

  html.dark .login-card {
    background: rgba(255, 255, 255, 0.94);
    border: 1px solid rgba(255, 255, 255, 0.52);
    box-shadow: 0 30px 90px rgba(15, 23, 42, 0.38), inset 0 0 0 1px rgba(255,255,255,0.08);
  }

  .login-input {
    transition: all 0.2s ease;
    border-color: rgba(14, 165, 233, 0.32);
    background: rgba(255, 255, 255, 0.82);
    color: #0f172a;
  }

  .login-input:focus {
    border-color: #38bdf8;
    box-shadow: 0 0 0 4px rgba(14, 165, 233, 0.14);
  }

  html.dark .login-input:focus {
    border-color: #7c3aed;
    box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.14);
  }

  .login-button {
    background: linear-gradient(90deg, #0ea5e9 0%, #2563eb 58%, #22d3ee 100%);
    box-shadow: 0 16px 35px rgba(14, 165, 233, 0.30);
    transition: all 0.2s ease;
  }

  html.dark .login-button {
    background: linear-gradient(90deg, #2563eb 0%, #7c3aed 55%, #9333ea 100%);
    box-shadow: 0 16px 35px rgba(124, 58, 237, 0.35);
  }

  .login-button:hover {
    transform: translateY(-1px);
    filter: brightness(1.05);
    box-shadow: 0 18px 42px rgba(14, 165, 233, 0.38);
  }

  html.dark .login-button:hover {
    box-shadow: 0 18px 42px rgba(37, 99, 235, 0.42);
  }

  .login-button:active {
    transform: translateY(0);
  }

  .login-footer {
    color: rgba(15, 23, 42, 0.52);
  }

  html.dark .login-footer {
    color: rgba(255, 255, 255, 0.60);
  }
</style>
</head>

<body class="min-h-screen gradient-bg flex items-center justify-center p-4">
<button type="button" id="loginThemeToggle" class="theme-toggle-login" onclick="toggleLoginTheme()" aria-label="Toggle theme">
  <i data-lucide="moon" class="w-5 h-5 login-icon-moon"></i>
  <i data-lucide="sun" class="w-5 h-5 login-icon-sun hidden"></i>
</button>

<div class="login-shell">
  <div class="login-brand mb-8">
    <div class="logo-center-row">
      <div class="logo-box w-20 h-20 rounded-3xl backdrop-blur-sm">
        <i data-lucide="files" class="w-11 h-11 text-white"></i>
      </div>
    </div>

    <h1 class="brand-title text-4xl font-extrabold tracking-tight leading-tight mt-5">
      File Manager
    </h1>

    <p class="brand-subtitle text-sm mt-2">
      Secure File Sharing
    </p>
  </div>

  <div class="login-card rounded-2xl p-6">
    <h2 class="text-xl font-bold text-gray-900 mb-6">Masuk ke Akun</h2>

    <?php if ($timeout): ?>
      <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-lg px-4 py-3 mb-4 text-sm">
        Sesi Anda telah berakhir. Silakan login kembali.
      </div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg px-4 py-3 mb-4 text-sm">
        <?= e($error) ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="" novalidate>
      <?= csrfField() ?>

      <div class="mb-5">
        <label class="block text-sm font-semibold text-gray-700 mb-1.5">Username atau NIM</label>
        <input
          type="text"
          name="username"
          value="<?= e($_POST['username'] ?? '') ?>"
          class="login-input w-full px-4 py-3 border rounded-xl text-sm focus:outline-none"
          placeholder="Masukkan username atau NIM"
          autocomplete="username"
          required
        >
      </div>

      <div class="mb-5">
        <label class="block text-sm font-semibold text-gray-700 mb-1.5">Password</label>
        <input
          type="password"
          name="password"
          class="login-input w-full px-4 py-3 border rounded-xl text-sm focus:outline-none"
          placeholder="Masukkan password"
          autocomplete="current-password"
          required
        >
      </div>

      <button type="submit" class="login-button w-full text-white font-semibold py-3 px-4 rounded-xl">
        Masuk
      </button>
    </form>
  </div>

  <p class="login-footer text-center text-xs mt-6">
    &copy; <?= date('Y') ?> File Manager. All rights reserved.
  </p>
</div>

<script>
function updateLoginThemeIcon() {
  const isDark = document.documentElement.classList.contains('dark');
  document.querySelectorAll('.login-icon-moon').forEach(el => el.classList.toggle('hidden', isDark));
  document.querySelectorAll('.login-icon-sun').forEach(el => el.classList.toggle('hidden', !isDark));
}

function toggleLoginTheme() {
  const html = document.documentElement;
  html.classList.toggle('dark');
  localStorage.setItem('darkMode', html.classList.contains('dark') ? 'dark' : 'light');
  updateLoginThemeIcon();
}

document.addEventListener('DOMContentLoaded', function() {
  if (typeof lucide !== 'undefined') lucide.createIcons();
  updateLoginThemeIcon();
});
</script>
</body>
</html>