<?php
$user        = currentUser();
$currentPage = basename($_SERVER['PHP_SELF']);

// Menu navigasi utama, "Kelola User" cuma muncul kalau admin
$navItems = [
    ['href' => 'dashboard.php', 'icon' => 'layout-dashboard', 'label' => 'Beranda'],
    ['href' => 'profile.php',   'icon' => 'user-circle',      'label' => 'Profil Saya'],
    ['href' => 'files.php',     'icon' => 'folder-open',      'label' => isGeneralAdmin() ? 'Manajemen File' : 'File Sharing'],
];

if (isGeneralAdmin()) {
    $navItems[] = ['href' => 'admin-users.php', 'icon' => 'users',       'label' => 'Kelola User'];
}

// Susun daftar folder buat sidebar: dikelompokkan per departemen (dept folder + member folder di bawahnya)
// kalau skema DB baru, atau flat list kalau masih skema lama.
$sidebarFolders  = [];
$sidebarGroups   = [];
$sidebarUngrouped = [];
try {
    require_once APP_ROOT . '/models/FolderModel.php';
    $folderModelSidebar = new FolderModel();
    $sidebarUser    = currentUser();
    $sidebarIsAdmin = isGeneralAdmin();
    $allSidebarFolders = $folderModelSidebar->getAllForUser((int)$sidebarUser['id'], $sidebarIsAdmin);

    if ($folderModelSidebar->supportsDepartmentMapping()) {
        require_once APP_ROOT . '/config/database.php';
        $db = Database::getInstance();
        $depts = $db->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

        // Inisialisasi grup per departemen dulu, baru diisi folder dept & member-nya
        foreach ($depts as $dept) {
            $sidebarGroups[$dept['id']] = ['dept' => $dept, 'dept_folder' => null, 'members' => []];
        }
        foreach ($allSidebarFolders as $folder) {
            $did = (int)($folder['department_id'] ?? 0);
            if ($did && isset($sidebarGroups[$did])) {
                if (($folder['folder_type'] ?? '') === 'dept') {
                    $sidebarGroups[$did]['dept_folder'] = $folder;
                } elseif (($folder['folder_type'] ?? '') === 'member') {
                    $sidebarGroups[$did]['members'][] = $folder;
                }
            } else {
                $sidebarUngrouped[] = $folder;
            }
        }
        // Hapus grup yang kosong
        foreach ($sidebarGroups as $did => $grp) {
            if (!$grp['dept_folder'] && empty($grp['members'])) {
                unset($sidebarGroups[$did]);
            }
        }
    } else {
        // Fallback DB lama: tampilkan flat list, dibatasi 8 folder
        $sidebarUngrouped = array_slice($allSidebarFolders, 0, 8);
    }
} catch (Throwable $e) {
    $sidebarUngrouped = [];
}
?>

<aside class="w-72 flex-shrink-0 bg-slate-950/80 backdrop-blur-2xl flex flex-col h-full fixed inset-y-0 left-0 z-30 transform transition-transform duration-300 lg:static lg:translate-x-0 -translate-x-full border-r border-indigo-300/15 shadow-2xl shadow-blue-950/40 overflow-hidden" id="sidebar">
  <!-- Dekorasi background blur -->
  <div class="absolute inset-0 pointer-events-none opacity-80">
    <div class="absolute -top-24 -left-24 w-52 h-52 bg-purple-600/25 rounded-full blur-3xl"></div>
    <div class="absolute bottom-28 -right-24 w-64 h-64 bg-blue-500/18 rounded-full blur-3xl"></div>
    <div class="absolute inset-0 bg-[radial-gradient(circle_at_30%_20%,rgba(255,255,255,.08)_0_1px,transparent_2px)] bg-[length:95px_95px]"></div>
  </div>

  <!-- Logo & nama aplikasi -->
  <div class="relative px-5 py-5 border-b border-white/10">
    <div class="flex items-center gap-3">
      <div class="w-12 h-12 crystal-logo rounded-2xl flex items-center justify-center backdrop-blur-sm flex-shrink-0">
        <i data-lucide="files" class="w-6 h-6 text-white"></i>
      </div>
      <div>
        <p class="text-white font-extrabold text-base leading-tight tracking-tight"><?= APP_NAME ?></p>
        <p class="text-blue-200/70 text-xs">Secure File Sharing</p>
      </div>
    </div>
  </div>

  <!-- Info user login (avatar, username, role) -->
  <div class="relative px-4 py-4 border-b border-white/10">
    <div class="flex items-center gap-3">
      <div class="w-12 h-12 rounded-full crystal-logo flex items-center justify-center flex-shrink-0 overflow-hidden ring-2 ring-white/10">
        <?php if (!empty($user['avatar'])): ?>
          <img src="<?= e(APP_URL . '/uploads/avatars/' . $user['avatar']) ?>" alt="Avatar" class="w-full h-full object-cover">
        <?php else: ?>
          <span class="text-white font-extrabold text-base"><?= strtoupper(substr($user['username'], 0, 1)) ?></span>
        <?php endif; ?>
      </div>
      <div class="min-w-0">
        <p class="text-white font-bold text-sm truncate"><?= e($user['username']) ?></p>
        <p class="text-purple-200/75 text-xs"><?= e($user['team_role'] ?? ($user['system_role'] ?? '-')) ?></p>
      </div>
    </div>
  </div>

  <nav class="relative flex-1 px-3 py-4 overflow-y-auto space-y-1">
    <!-- Menu navigasi utama -->
    <?php foreach ($navItems as $item): ?>
    <a href="<?= APP_URL . '/' . $item['href'] ?>" class="sidebar-link <?= $currentPage === $item['href'] ? 'active' : '' ?>">
      <i data-lucide="<?= $item['icon'] ?>" class="w-4 h-4 flex-shrink-0"></i>
      <?= e($item['label']) ?>
    </a>
    <?php endforeach; ?>

    <!-- Daftar folder: dikelompokkan per departemen (collapsible) atau flat list -->
    <div class="mt-5 pt-4 border-t border-white/10">
      <div class="px-3 mb-2">
        <p class="text-blue-200/45 text-xs uppercase tracking-[0.22em] font-bold">Folder</p>
      </div>

      <?php if (empty($sidebarGroups) && empty($sidebarUngrouped)): ?>
        <p class="px-3 text-xs text-blue-100/35">Belum ada folder.</p>
      <?php else: ?>
        <?php foreach ($sidebarGroups as $grp):
            $dept       = $grp['dept'];
            $deptFolder = $grp['dept_folder'];
            $members    = $grp['members'];
        ?>
        <div class="mb-1">
          <!-- Dept folder: klik toggle expand/collapse member di bawahnya -->
          <div class="flex items-center gap-1 group/dept cursor-pointer sidebar-dept-toggle"
            onclick="toggleSidebarDept(this)">
            <i data-lucide="chevron-down" class="w-3 h-3 text-blue-200/40 flex-shrink-0 transition-transform duration-200"></i>
            <a href="<?= APP_URL ?>/files.php<?= $deptFolder ? '?folder_id=' . (int)$deptFolder['id'] : '' ?>"
              onclick="event.stopPropagation()"
              class="sidebar-link flex-1 font-bold uppercase text-xs tracking-wide text-blue-200/70 !py-1.5">
              <i data-lucide="folder" class="w-4 h-4 flex-shrink-0"></i>
              <?= e($dept['name']) ?>
            </a>
          </div>
          <!-- Member folders di bawah dept-nya -->
          <div class="sidebar-dept-members ml-4">
            <?php foreach ($members as $mf): ?>
            <a href="<?= APP_URL ?>/files.php?folder_id=<?= (int)$mf['id'] ?>"
              class="sidebar-link pl-4 text-blue-100/60">
              <i data-lucide="user" class="w-3.5 h-3.5 flex-shrink-0"></i>
              <?= e($mf['name']) ?>
            </a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>

        <?php foreach ($sidebarUngrouped as $folder): ?>
        <a href="<?= APP_URL ?>/files.php?folder_id=<?= (int)$folder['id'] ?>" class="sidebar-link">
          <i data-lucide="folder" class="w-4 h-4 flex-shrink-0"></i>
          <?= e($folder['name']) ?>
        </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </nav>

  <div class="relative px-4 pb-4">
    <div class="border-t border-white/10 pt-3">
      <a href="<?= APP_URL ?>/logout.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-blue-100/75 hover:bg-red-500/15 hover:text-red-300 transition-all duration-150 text-sm font-bold">
        <i data-lucide="log-out" class="w-4 h-4"></i>
        Keluar
      </a>
    </div>
  </div>
</aside>

<script>
// Expand/collapse grup departemen di sidebar, state disimpan di localStorage biar persist
function toggleSidebarDept(el) {
  const members = el.parentElement.querySelector('.sidebar-dept-members');
  const chevron = el.querySelector('[data-lucide="chevron-down"]');
  const deptName = el.querySelector('a')?.textContent?.trim() || '';
  if (!members) return;
  const isOpen = !members.classList.contains('hidden');
  if (isOpen) {
    members.classList.add('hidden');
    chevron.style.transform = 'rotate(-90deg)';
    localStorage.setItem('sidebar_dept_' + deptName, 'closed');
  } else {
    members.classList.remove('hidden');
    chevron.style.transform = 'rotate(0deg)';
    localStorage.setItem('sidebar_dept_' + deptName, 'open');
  }
}

// Restore state on load
document.querySelectorAll('.sidebar-dept-toggle').forEach(el => {
  const members = el.parentElement.querySelector('.sidebar-dept-members');
  const chevron = el.querySelector('[data-lucide="chevron-down"]');
  const deptName = el.querySelector('a')?.textContent?.trim() || '';
  const state = localStorage.getItem('sidebar_dept_' + deptName);
  if (state === 'closed') {
    members?.classList.add('hidden');
    if (chevron) chevron.style.transform = 'rotate(-90deg)';
  }
});
</script>

<!-- Modal buat folder baru, cuma muncul buat admin -->
<?php if (isGeneralAdmin()): ?>
<div id="folderModal" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" onclick="closeFolderModal()"></div>
  <div class="relative flex items-center justify-center min-h-full p-4">
    <div class="cyber-panel rounded-3xl w-full max-w-md p-6">
      <div class="flex items-center justify-between mb-5">
        <h3 class="font-extrabold text-white text-lg">Buat Folder Baru</h3>
        <button onclick="closeFolderModal()" class="text-blue-100/60 hover:text-white">×</button>
      </div>
      <input type="hidden" id="folderCsrfToken" value="<?= csrfGenerate() ?>">
      <label class="block text-xs font-bold text-blue-200/60 mb-2">Nama Folder</label>
      <input type="text" id="folderNameInput" class="w-full px-4 py-3 border border-white/10 rounded-2xl bg-white/[0.06] text-blue-50 placeholder:text-blue-100/35 focus:outline-none focus:ring-2 focus:ring-purple-500/40" placeholder="Contoh: Laporan Mingguan">
      <button onclick="submitFolder()" class="neon-button mt-5 w-full px-4 py-3 rounded-2xl text-white font-bold">
        Simpan Folder
      </button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Overlay gelap saat sidebar terbuka di mobile -->
<div class="fixed inset-0 bg-black/50 z-20 lg:hidden hidden" id="sidebarOverlay" onclick="closeSidebar()"></div>