<?php
require_once __DIR__ . '/middleware/auth.php';
require_once APP_ROOT . '/models/FileModel.php';
require_once APP_ROOT . '/models/UserModel.php';
require_once APP_ROOT . '/models/FolderModel.php';
requireLogin();

$fileModel     = new FileModel();
$userModel     = new UserModel();
$folderModel   = new FolderModel();
$user          = currentUser();
$pageTitle     = 'Dashboard';

$isAdmin = isGeneralAdmin();
$fileFilters = [];
if (!$isAdmin) {
    // Member biasa cuma lihat file miliknya sendiri
    $fileFilters['current_user_id'] = (int)$user['id'];
    $fileFilters['is_admin'] = false;
} elseif (!isSuperAdmin()) {
    // Admin Divisi hanya melihat file dari departemen yang dia kelola.
    $fileFilters['managed_by_user_id'] = (int)$user['id'];
}

// Kumpulkan data statistik dashboard (folder, file, storage, user)
$totalFiles     = $fileModel->countFiles($fileFilters);
$totalStorage   = $fileModel->getTotalStorage((int)$user['id'], $isAdmin);
$recentFiles    = $fileModel->getFiles($fileFilters, 0, 5);
$totalUsers     = $isAdmin ? $userModel->countAll() : 0;
$visibleFolders = $folderModel->getAllForUser((int)$user['id'], $isAdmin);
$totalFolders   = count($visibleFolders);
$recentFolders  = array_slice($visibleFolders, 0, 5);
$storageStats   = $isAdmin ? $fileModel->getStorageByFolder(isSuperAdmin() ? null : (int)$user['id']) : [];

require_once APP_ROOT . '/views/partials/header.php';
require_once APP_ROOT . '/views/partials/sidebar.php';
?>

<div class="flex-1 flex flex-col min-w-0">
  <?php require_once APP_ROOT . '/views/partials/navbar.php'; ?>

  <main class="flex-1 p-4 md:p-6 overflow-auto relative">
    <div class="pointer-events-none absolute inset-0 opacity-80">
      <div class="absolute top-0 right-0 w-[620px] h-[620px] bg-purple-600/14 blur-3xl rounded-full"></div>
      <div class="absolute bottom-20 left-20 w-[460px] h-[460px] bg-blue-600/12 blur-3xl rounded-full"></div>
      <div class="absolute top-32 left-1/2 w-[320px] h-[320px] bg-fuchsia-500/8 blur-3xl rounded-full"></div>
    </div>

    <div class="relative z-10 max-w-[1600px] mx-auto">
      <!-- Header sambutan -->
      <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4 mb-7">
        <div>
          <p class="text-xs uppercase tracking-[0.34em] text-blue-200/50 font-extrabold mb-2">Beranda</p>
          <h1 class="text-3xl font-extrabold text-white tracking-tight">Selamat datang, <span class="gradient-text"><?= e($user['username']) ?></span></h1>
          <p class="text-sm text-blue-100/60 mt-2 max-w-2xl">
            <?= $isAdmin
              ? 'Kelola folder dan distribusikan file secara aman dan terorganisir.'
              : 'Temukan dan akses file yang Anda butuhkan dengan mudah.' ?>
          </p>
        </div>

        <?php if ($isAdmin): ?>
        
        <?php endif; ?>
      </div>

      <!-- Stat card: folder, file, storage, (khusus admin: total user) -->
      <div class="grid grid-cols-1 sm:grid-cols-2 <?= $isAdmin ? 'xl:grid-cols-4' : 'xl:grid-cols-3' ?> gap-4 mb-7">
        <div class="cyber-card rounded-3xl p-5 transition-all duration-200">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-3xl font-extrabold text-white"><?= number_format($totalFolders) ?></p>
              <p class="text-sm text-blue-100/60 mt-1"><?= $isAdmin ? 'Total Folder' : 'Folder yang bisa diakses' ?></p>
            </div>
            <div class="w-12 h-12 icon-orb text-blue-200"><i data-lucide="folder" class="w-5 h-5"></i></div>
          </div>
        </div>

        <div class="cyber-card rounded-3xl p-5 transition-all duration-200">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-3xl font-extrabold text-white"><?= number_format($totalFiles) ?></p>
              <p class="text-sm text-blue-100/60 mt-1"><?= $isAdmin ? 'Total File' : 'File yang bisa diakses' ?></p>
            </div>
            <div class="w-12 h-12 icon-orb text-purple-200"><i data-lucide="files" class="w-5 h-5"></i></div>
          </div>
        </div>

        <div class="cyber-card rounded-3xl p-5 transition-all duration-200">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-3xl font-extrabold text-white"><?= formatSize($totalStorage) ?></p>
              <p class="text-sm text-blue-100/60 mt-1"><?= $isAdmin ? 'Storage Digunakan' : 'Storage saya' ?></p>
            </div>
            <div class="w-12 h-12 icon-orb text-fuchsia-200"><i data-lucide="hard-drive" class="w-5 h-5"></i></div>
          </div>
        </div>

        <?php if ($isAdmin): ?>
        <div class="cyber-card rounded-3xl p-5 transition-all duration-200">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-3xl font-extrabold text-white"><?= number_format($totalUsers) ?></p>
              <p class="text-sm text-blue-100/60 mt-1">Total Pengguna</p>
            </div>
            <div class="w-12 h-12 icon-orb text-indigo-200"><i data-lucide="users" class="w-5 h-5"></i></div>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Panel folder terbaru + file terbaru -->
      <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">
        <div class="xl:col-span-1 cyber-panel rounded-3xl p-6 member-access-panel">
          <div class="flex items-center justify-between mb-5">
            <h2 class="font-extrabold text-white text-lg"><?= $isAdmin ? 'Folder Terbaru' : 'Folder Tersedia' ?></h2>
            <?php if ($isAdmin): ?>
            
            <?php endif; ?>
          </div>

          <?php if (empty($recentFolders)): ?>
            <p class="text-sm text-blue-100/50"><?= $isAdmin ? 'Belum ada folder.' : 'Belum ada folder yang tersedia.' ?></p>
          <?php else: ?>
            <div class="space-y-3 folder-scroll-area max-h-[360px]">
              <?php foreach ($recentFolders as $folder): ?>
              <a href="files.php?folder_id=<?= (int)$folder['id'] ?>" class="flex items-center justify-between rounded-2xl border border-white/10 bg-white/[0.04] hover:bg-white/[0.07] px-4 py-3 transition-colors">
                <div class="flex items-center gap-3 min-w-0">
                  <div class="w-10 h-10 rounded-xl bg-blue-500/10 border border-blue-300/15 flex items-center justify-center text-blue-200">
                    <i data-lucide="folder" class="w-5 h-5"></i>
                  </div>
                  <div class="min-w-0">
                    <p class="text-white font-bold truncate"><?= e($folder['name']) ?></p>
                    <p class="text-xs text-blue-100/45 muted-text"><?= (int)$folder['file_count'] ?> file · <?= formatSize((int)$folder['total_size']) ?></p>
                  </div>
                </div>
                <i data-lucide="chevron-right" class="w-4 h-4 text-blue-100/40"></i>
              </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="xl:col-span-2 cyber-panel rounded-3xl p-6 member-access-panel">
          <div class="flex items-center justify-between mb-5">
            <h2 class="font-extrabold text-white text-lg"><?= $isAdmin ? 'File Terbaru' : 'File Tersedia' ?></h2>
            <a href="files.php" class="text-sm text-purple-200 hover:text-white font-bold access-link">Lihat semua</a>
          </div>

          <?php if (empty($recentFiles)): ?>
            <p class="text-sm text-blue-100/50"><?= $isAdmin ? 'Belum ada file diunggah.' : 'Belum ada file yang tersedia.' ?></p>
          <?php else: ?>
            <div class="file-table-scroll max-h-[360px] overflow-auto">
              <table class="w-full text-sm">
                <tbody>
                <?php foreach ($recentFiles as $file): ?>
                  <tr class="border-b border-white/10 last:border-b-0 text-blue-100/75">
                    <td class="py-3 text-white font-semibold"><?= e($file['original_name']) ?></td>
                    <td class="py-3"><?= e($file['folder_name'] ?? '-') ?></td>
                    <td class="py-3"><?= formatSize((int)$file['size']) ?></td>
                    <td class="py-3 text-blue-100/45 muted-text"><?= timeAgo($file['created_at']) ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Breakdown storage per folder, khusus admin -->
      <?php if ($isAdmin): ?>
      <div class="grid grid-cols-1 gap-6">
        <div class="cyber-panel rounded-3xl p-6">
          <h2 class="font-extrabold text-white text-lg mb-5">Storage per Folder</h2>
          <?php if (empty($storageStats)): ?>
            <p class="text-sm text-blue-100/50">Belum ada file di folder ini.</p>
          <?php else: ?>
            <?php foreach ($storageStats as $stat): ?>
              <?php $pct = $totalStorage > 0 ? round(((int)$stat['total_size'] / $totalStorage) * 100) : 0; ?>
              <div class="mb-4">
                <div class="flex justify-between text-sm mb-1.5">
                  <span class="font-bold text-blue-100/80"><?= e($stat['name']) ?></span>
                  <span class="text-blue-100/50"><?= formatSize((int)$stat['total_size']) ?></span>
                </div>
                <div class="w-full bg-white/10 rounded-full h-2 overflow-hidden">
                  <div class="bg-gradient-to-r from-blue-500 to-purple-500 h-2 rounded-full shadow-lg shadow-purple-500/30" style="width: <?= $pct ?>%"></div>
                </div>
                <p class="text-xs text-blue-100/40 mt-1"><?= (int)$stat['file_count'] ?> file · <?= $pct ?>%</p>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <?php endif; ?>
    </div>
  </main>
</div>

<?php require_once APP_ROOT . '/views/partials/footer.php'; ?>