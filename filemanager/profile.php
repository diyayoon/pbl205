<?php
require_once __DIR__ . '/middleware/auth.php';
requireLogin();

$pageTitle = 'Profil Saya';
$user = currentUser();

require_once APP_ROOT . '/views/partials/header.php';
require_once APP_ROOT . '/views/partials/sidebar.php';
?>

<div class="flex-1 flex flex-col min-w-0">
  <?php require_once APP_ROOT . '/views/partials/navbar.php'; ?>

  <main class="flex-1 p-6 overflow-auto relative">
    <div class="pointer-events-none absolute inset-0 opacity-70">
      <div class="absolute top-0 right-0 w-[520px] h-[520px] bg-purple-600/10 blur-3xl rounded-full"></div>
      <div class="absolute bottom-20 left-20 w-[420px] h-[420px] bg-blue-600/10 blur-3xl rounded-full"></div>
    </div>

    <div class="relative z-10">
      <div class="mb-7">
        <h1 class="text-3xl font-extrabold text-white tracking-tight">Profil Saya</h1>
        <p class="text-sm text-blue-100/60 mt-1">Informasi akun pengguna.</p>
      </div>

      <div class="profile-card rounded-2xl border border-white/10 bg-white/[0.045] backdrop-blur-xl shadow-xl shadow-slate-950/20 p-6">
        <div class="flex items-center gap-4 mb-6">
          <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-white text-2xl font-bold shadow-lg shadow-purple-500/25 ring-2 ring-white/10">
            <?= strtoupper(substr($user['username'], 0, 1)) ?>
          </div>

          <div>
            <h2 class="text-xl font-bold text-white"><?= e($user['username']) ?></h2>
            <p class="text-sm text-blue-100/60"><?= e($user['team_role'] ?? '-') ?></p>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="profile-field rounded-xl border border-white/10 bg-white/[0.04] p-4">
            <p class="text-xs uppercase tracking-wider text-blue-200/50 font-semibold mb-1">Username</p>
            <p class="text-white font-semibold"><?= e($user['username']) ?></p>
          </div>

          <div class="profile-field rounded-xl border border-white/10 bg-white/[0.04] p-4">
            <p class="text-xs uppercase tracking-wider text-blue-200/50 font-semibold mb-1">NIM</p>
            <p class="text-white font-semibold"><?= e($user['nim'] ?? '-') ?></p>
          </div>

          <div class="profile-field rounded-xl border border-white/10 bg-white/[0.04] p-4">
            <p class="text-xs uppercase tracking-wider text-blue-200/50 font-semibold mb-1">Role</p>
            <p class="text-white font-semibold"><?= e($user['team_role'] ?? '-') ?></p>
          </div>

          <div class="profile-field rounded-xl border border-white/10 bg-white/[0.04] p-4">
            <p class="text-xs uppercase tracking-wider text-blue-200/50 font-semibold mb-1">Jobdesk</p>
            <p class="text-white font-semibold"><?= e($user['jobdesk'] ?? '-') ?></p>
          </div>
        </div>
      </div>
    </div>
  </main>
</div>

<?php require_once APP_ROOT . '/views/partials/footer.php'; ?>