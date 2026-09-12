<?php
/* FOOTER PARTIAL */
?>

</div><!-- /#appLayout -->

<!-- ── 2. LOAD JS EKSTERNAL ──────────────────────────────────────────────────
     app.js  → fungsi modal tambah/edit user (dipakai admin-users.php)
     files.js → fungsi modal folder, upload, rename, move, delete, kelola file (dipakai files.php)
     Keduanya di-load global karena footer dipakai di semua halaman.
     Kalau nanti mau dipisah per halaman, pindahkan ke masing-masing file PHP-nya.
-->
<?php
$assetVersionApp   = @filemtime(APP_ROOT . '/assets/js/app.js') ?: time();
$assetVersionFiles = @filemtime(APP_ROOT . '/assets/js/files.js') ?: time();
?>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= $assetVersionApp ?>"></script>
<script src="<?= APP_URL ?>/assets/js/files.js?v=<?= $assetVersionFiles ?>" data-base="<?= APP_URL ?>"></script>

<script>
/* ── SESSION EXPIRED HANDLING ────────────────────────────────────────────────
   apiFetch() adalah pengganti fetch() biasa. Otomatis kirim header
   X-Requested-With supaya backend (middleware/auth.php) tahu ini request AJAX,
   dan otomatis munculin modal kalau backend balikin session_expired.
*/
function showSessionExpiredModal() {
  const modal = document.getElementById('sessionExpiredModal');
  if (modal) modal.classList.remove('hidden');
}

async function apiFetch(url, options = {}) {
  options.headers = { ...(options.headers || {}), 'X-Requested-With': 'XMLHttpRequest' };
  const res = await fetch(url, options);

  if (res.status === 401) {
    const clone = res.clone();
    const data = await clone.json().catch(() => ({}));
    if (data.status === 'session_expired') {
      showSessionExpiredModal();
    }
  }
  return res;
}

</script>

<script>
  
/* ── DARK MODE ───────────────────────────────────────────────────────────────
   Toggle dark/light mode, simpan preferensi ke localStorage.
   Dipanggil dari tombol di navbar.php (toggleDark).
   Ikon moon/sun di-sync saat load.
*/
function toggleDark() {
  const html   = document.documentElement;
  const isDark = html.classList.toggle('dark');
  localStorage.setItem('darkMode', isDark ? 'dark' : 'light');

  const moon = document.querySelector('.dark-icon-moon');
  const sun  = document.querySelector('.dark-icon-sun');
  if (moon) moon.classList.toggle('hidden', isDark);
  if (sun)  sun.classList.toggle('hidden', !isDark);
}



/* Sync ikon dark/light saat halaman pertama kali dibuka */
(function () {
  const isDark = document.documentElement.classList.contains('dark');
  const moon   = document.querySelector('.dark-icon-moon');
  const sun    = document.querySelector('.dark-icon-sun');
  if (moon) moon.classList.toggle('hidden', !isDark);
  if (sun)  sun.classList.toggle('hidden', isDark);
})();



/* ── SIDEBAR (mobile) ────────────────────────────────────────────────────────
   Toggle sidebar di layar kecil (hamburger menu).
   Dipanggil dari tombol di navbar.php (toggleSidebar / closeSidebar).
*/
function toggleSidebar() {
  const sb      = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebarOverlay');
  if (!sb) return;
  const open = !sb.classList.contains('-translate-x-full') &&
               !sb.classList.contains('lg:static');
  sb.classList.toggle('-translate-x-full', open);
  if (overlay) overlay.classList.toggle('hidden', open);
}
function closeSidebar() {
  const sb      = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebarOverlay');
  if (sb) sb.classList.add('-translate-x-full');
  if (overlay) overlay.classList.add('hidden');
}



/* ── USER DROPDOWN ───────────────────────────────────────────────────────────
   Toggle menu dropdown di pojok kanan atas navbar.php.
   Auto-tutup kalau klik di luar area dropdown.
*/
function toggleUserMenu() {
  const menu = document.getElementById('userMenu');
  if (menu) menu.classList.toggle('hidden');
}
document.addEventListener('click', function (e) {
  const dropdown = document.getElementById('userDropdown');
  const menu     = document.getElementById('userMenu');
  if (menu && dropdown && !dropdown.contains(e.target)) {
    menu.classList.add('hidden');
  }
});



/* ── QUICK SEARCH ────────────────────────────────────────────────────────────
   Input search di navbar.php — tekan Enter untuk redirect ke files.php?search=...
*/
function handleQuickSearch(e) {
  if (e.key !== 'Enter') return;
  const q = (document.getElementById('quickSearch')?.value || '').trim();
  if (q) window.location.href = '<?= APP_URL ?>/files.php?search=' + encodeURIComponent(q);
}



/* ── TOAST NOTIFICATION ──────────────────────────────────────────────────────
   Tampilkan notifikasi pop-up di pojok kanan bawah.
   Dipanggil dari semua JS (app.js, files.js) dan navbar.php (flash message).
   type: 'success' | 'error' | 'info'
*/
function showToast(message, type = 'info') {
  const container = document.getElementById('toast-container');
  if (!container) return;

  const icons = { success: '✓', error: '✕', info: 'ℹ' };
  const toast = document.createElement('div');
  toast.className = `toast toast-${type}`;
  toast.innerHTML = `<span>${icons[type] || icons.info}</span><span>${message}</span>`;
  container.appendChild(toast);

  setTimeout(() => {
    toast.style.animation = 'slideOut .3s ease forwards';
    setTimeout(() => toast.remove(), 300);
  }, 3500);
}



/* ── LUCIDE ICONS ────────────────────────────────────────────────────────────
   Inisialisasi semua ikon lucide (data-lucide="...") setelah DOM siap.
*/
document.addEventListener('DOMContentLoaded', function () {
  if (typeof lucide !== 'undefined') lucide.createIcons();
});

</script>

</body>
</html>