<?php
// Ambil flash message (kalau ada) & data user yang login
$flash = getFlash();
$u = currentUser();
?>

<header class="bg-slate-950/72 backdrop-blur-2xl border-b border-indigo-300/12 px-4 py-3 flex items-center justify-between sticky top-0 z-20 shadow-lg shadow-slate-950/20">
  <!-- Kiri: tombol hamburger (mobile) + breadcrumb judul halaman -->
  <div class="flex items-center gap-3">
    <button onclick="toggleSidebar()" class="lg:hidden p-2 rounded-xl hover:bg-indigo-500/10 text-blue-200 transition-colors">
      <i data-lucide="menu" class="w-5 h-5"></i>
    </button>

    <div class="hidden sm:flex items-center gap-2 text-sm text-blue-200/60">
      <i data-lucide="home" class="w-3.5 h-3.5 text-blue-300/70"></i>
      <span class="text-blue-200/35">/</span>
      <span class="text-white font-bold"><?= e($pageTitle ?? 'Dashboard') ?></span>
    </div>
  </div>

  <!-- Kanan: quick search, dark mode toggle, user dropdown -->
  <div class="flex items-center gap-2 md:gap-3">
    <!-- Quick search: Enter redirect ke files.php?search= (lihat handleQuickSearch di footer.php) -->
    <div class="relative hidden md:block">
      <i data-lucide="search" class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-blue-200/50"></i>
      <input type="text" id="quickSearch" placeholder="Cari file atau folder" onkeydown="handleQuickSearch(event)"
        class="pl-10 pr-4 py-2.5 text-sm border border-indigo-300/15 rounded-2xl bg-white/[0.045] text-blue-50 placeholder:text-blue-200/45 focus:outline-none focus:ring-2 focus:ring-purple-500/40 focus:border-purple-400/40 w-72 transition-all shadow-inner shadow-slate-950/20">
    </div>

    <!-- Toggle dark/light mode (lihat toggleDark di footer.php) -->
    <button id="darkToggle" onclick="toggleDark()" class="p-2.5 rounded-2xl hover:bg-indigo-500/10 text-blue-200/70 hover:text-white transition-colors" aria-label="Toggle dark mode">
      <i data-lucide="moon" class="w-5 h-5 dark-icon-moon"></i>
      <i data-lucide="sun" class="w-5 h-5 dark-icon-sun hidden"></i>
    </button>

    <!-- Dropdown user: avatar, info singkat, link profil & logout -->
    <div class="relative" id="userDropdown">
      <button onclick="toggleUserMenu()" class="flex items-center gap-2 p-1.5 rounded-2xl hover:bg-indigo-500/10 transition-colors">
        <div class="w-10 h-10 rounded-full crystal-logo flex items-center justify-center text-white text-sm font-extrabold overflow-hidden ring-2 ring-white/10">
          <?php if (!empty($u['avatar'])): ?>
            <img src="<?= e(APP_URL . '/uploads/avatars/' . $u['avatar']) ?>" alt="Avatar" class="w-full h-full object-cover">
          <?php else: ?>
            <?= strtoupper(substr($u['username'], 0, 1)) ?>
          <?php endif; ?>
        </div>
        <i data-lucide="chevron-down" class="w-4 h-4 text-blue-200/60 hidden sm:block"></i>
      </button>

      <div id="userMenu" class="hidden absolute right-0 top-full mt-2 w-60 bg-slate-950/92 backdrop-blur-2xl rounded-2xl shadow-2xl shadow-slate-950/60 border border-indigo-300/15 py-1.5 z-50 overflow-hidden">
        <div class="px-4 py-3 border-b border-indigo-300/10">
          <p class="font-bold text-sm text-white"><?= e($u['username']) ?></p>
          <p class="text-xs text-blue-200/60">NIM: <?= e($u['nim'] ?? '-') ?></p>
          <p class="text-xs text-purple-200/70 mt-0.5"><?= e($u['team_role'] ?? '-') ?></p>
        </div>

        <a href="<?= APP_URL ?>/profile.php" class="flex items-center gap-2 px-4 py-2.5 text-sm text-blue-100/80 hover:bg-indigo-500/10 hover:text-white transition-colors">
          <i data-lucide="user" class="w-4 h-4"></i> Profil Saya
        </a>

        <div class="px-4 text-xs text-blue-100/45 border-t border-indigo-300/10">
        </div>

        <div class="border-t border-indigo-300/10 mx-3">
          <a href="<?= APP_URL ?>/logout.php" class="flex items-center gap-2 px-4 py-2.5 text-sm text-red-300 hover:bg-red-500/10 hover:text-red-200 transition-colors">
            <i data-lucide="log-out" class="w-4 h-4"></i> Keluar
          </a>
        </div>
      </div>
    </div>
  </div>
</header>

<!-- Container toast notification (diisi lewat showToast() di footer.php) -->
<div id="toast-container"></div>

<!-- Kalau ada flash message dari session, tampilkan sebagai toast saat halaman dimuat -->
<?php if ($flash): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  showToast('<?= e($flash['msg']) ?>', '<?= e($flash['type']) ?>');
});
</script>
<?php endif; ?>