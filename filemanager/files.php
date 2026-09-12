<?php
require_once __DIR__ . '/middleware/auth.php';
require_once APP_ROOT . '/models/FileModel.php';
require_once APP_ROOT . '/models/FolderModel.php';
require_once APP_ROOT . '/models/UserModel.php';
requireLogin();

$user        = currentUser();
$isAdmin     = isGeneralAdmin();

// User tanpa dept (misal Arga) = read-only, bisa lihat tapi tidak bisa aksi
$isReadOnly = false;
if (!$isAdmin) {
    try {
        require_once APP_ROOT . '/models/UserModel.php';
        $userModelCheck = new UserModel();
        $userDepts = $userModelCheck->getUserDepartments((int)$user['id']);
        $isReadOnly = empty($userDepts);
    } catch (Throwable $e) { $isReadOnly = false; }
}
$fileModel   = new FileModel();
$folderModel = new FolderModel();
$userModel   = new UserModel();
$pageTitle = $isAdmin ? 'Manajemen File' : 'File Sharing';
$folderId = (int)($_GET['folder_id'] ?? 0);
$search   = trim($_GET['search'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));

$folders = $folderModel->getAllForUser((int)$user['id'], $isAdmin);
$currentFolder = $folderId > 0 ? $folderModel->findById($folderId) : null;

// Kelompokkan folder berdasarkan departemen untuk tampilan dropdown sidebar.
// Folder "departemen" (folder_type != 'member' atau tidak punya folder_type) jadi header grup.
// Folder "member" (folder_type = 'member') masuk ke sub-list departemennya.
$folderGroups    = [];   // [ deptId => ['dept' => [...], 'dept_folder' => [...], 'members' => [...]] ]
$ungroupedFolders = [];  // folder tanpa department_id (fallback flat list)
$supportsDeptMapping = $folderModel->supportsDepartmentMapping();

if ($supportsDeptMapping) {
    // Ambil semua departemen untuk nama grup
    $allDepartments = $userModel->getAllDepartments();
    $deptMap = array_column($allDepartments, null, 'id');  // [id => dept_row]

    foreach ($folders as $folder) {
        $deptId     = (int)($folder['department_id'] ?? 0);
        $folderType = $folder['folder_type'] ?? '';

        if ($deptId > 0) {
            if (!isset($folderGroups[$deptId])) {
                $folderGroups[$deptId] = [
                    'dept'        => $deptMap[$deptId] ?? ['id' => $deptId, 'name' => 'Departemen'],
                    'dept_folder' => null,
                    'members'     => [],
                ];
            }
            if ($folderType === 'member') {
                $folderGroups[$deptId]['members'][] = $folder;
            } else {
                // Ini folder utama departemen
                $folderGroups[$deptId]['dept_folder'] = $folder;
            }
        } else {
            $ungroupedFolders[] = $folder;
        }
    }
    // Urutkan member folder tiap grup by name
    foreach ($folderGroups as &$grp) {
        usort($grp['members'], fn($a, $b) => strcmp($a['name'], $b['name']));
    }
    unset($grp);
} else {
    $ungroupedFolders = $folders;
}

// Folder yang boleh jadi tujuan upload:
// Superadmin → semua folder | Admin Divisi → folder anggota/departemen yang dia kelola.
if ($isAdmin) {
    $uploadFolders = $folders;
} else {
    $uploadFolders = [];
}

if ($folderId > 0 && (!$currentFolder || !$folderModel->userCanAccess($folderId, (int)$user['id'], $isAdmin))) {
    flash('error', 'Anda tidak memiliki akses ke folder tersebut.');
    redirect('/files.php');
}

$filters = [];
if ($folderId > 0) $filters['folder_id'] = $folderId;
if ($search) $filters['search'] = $search;
if (!$isAdmin) {
    $filters['current_user_id'] = (int)$user['id'];
    $filters['is_admin'] = false;
} elseif (!isSuperAdmin()) {
    $filters['managed_by_user_id'] = (int)$user['id'];
}

$total  = $fileModel->countFiles($filters);
$paging = paginate($total, $page);
$files  = $fileModel->getFiles($filters, $paging['offset'], $paging['limit']);
$users  = $userModel->getAll();

// Helper: Superadmin boleh semua. Admin Divisi boleh kelola folder/file dalam departemennya.
function canManageItem(array $item, array $user, string $ownerKey = 'created_by'): bool {
    if (isSuperAdmin()) return true;

    $folderModel = new FolderModel();
    $folderId = (int)($item['folder_id'] ?? $item['id'] ?? 0);
    if ($folderId > 0 && $folderModel->userCanManage($folderId, (int)$user['id'])) {
        return true;
    }

    return (int)($item[$ownerKey] ?? -1) === (int)$user['id'];
}

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
      <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4 mb-7">
        <div>
          <h1 class="text-3xl font-extrabold text-white tracking-tight">
            <?= $currentFolder ? e($currentFolder['name']) : ($isAdmin ? 'Manajemen File' : 'File Sharing') ?>
          </h1>
          <p class="text-sm text-blue-100/60 mt-2 max-w-2xl">
            <?= $currentFolder ? 'Menampilkan file dalam folder ini sesuai hak akses.' : ($isAdmin ? 'Kelola folder anggota, file, dan akses anggota divisi.' : 'Lihat dan unduh file yang telah dibagikan ke kamu.') ?>
          </p>
        </div>

        <?php if ($isAdmin): ?>
        <div class="flex flex-col sm:flex-row gap-2 w-full lg:w-auto">
          <?php if (isSuperAdmin()): ?>
          <button onclick="openFolderModal()" class="inline-flex items-center justify-center gap-2 bg-white/[0.07] hover:bg-white/[0.11] border border-white/10 text-blue-50 px-4 py-3 rounded-2xl text-sm font-bold transition-all">
            <i data-lucide="folder-plus" class="w-4 h-4"></i>
            + Folder
          </button>
          <?php endif; ?>
          <button onclick="openUploadModal()" class="neon-button inline-flex items-center justify-center gap-2 px-4 py-3 rounded-2xl text-white text-sm font-bold">
            <i data-lucide="upload-cloud" class="w-4 h-4"></i>
            Unggah File
          </button>
        </div>
        <?php endif; ?>
      </div>

      <div class="grid grid-cols-1 xl:grid-cols-4 gap-5 mb-6">
        <div class="xl:col-span-1 cyber-panel rounded-3xl p-5">
          <div class="flex items-center justify-between mb-4">
            <h2 class="font-extrabold text-white">Folder</h2>
          </div>

          <div class="space-y-1 folder-scroll-area">
            <!-- Semua File -->
            <a href="files.php" class="flex items-center gap-3 px-3 py-3 rounded-2xl <?= $folderId === 0 ? 'bg-gradient-to-r from-blue-600/30 to-purple-600/30 text-white' : 'text-blue-100/70 hover:bg-white/[0.06]' ?> transition-colors">
              <i data-lucide="folder-open" class="w-4 h-4"></i>
              <span class="font-bold text-sm">Semua File</span>
            </a>

            <?php if ($supportsDeptMapping && !empty($folderGroups)): ?>
              <?php foreach ($folderGroups as $deptId => $grp):
                  $dept        = $grp['dept'];
                  $deptFolder  = $grp['dept_folder'];
                  $memberFolders = $grp['members'];
                  $deptName    = e($dept['name']);
                  $groupId     = 'deptgroup-' . (int)$deptId;

                  // Cek apakah folder aktif ada di grup ini
                  $grpActive   = ($deptFolder && $folderId === (int)$deptFolder['id'])
                              || !empty(array_filter($memberFolders, fn($mf) => $folderId === (int)$mf['id']));
              ?>
              <div class="folder-group" data-group="<?= $groupId ?>">

                <!-- Header Grup Departemen (bisa klik ke folder utama atau collapse) -->
                <div class="flex items-center gap-1 rounded-2xl <?= $grpActive ? 'text-white' : 'text-blue-100/80' ?> transition-colors">
                  <!-- Toggle collapse -->
                  <button type="button"
                    onclick="toggleFolderGroup('<?= $groupId ?>')"
                    class="flex-shrink-0 p-2 rounded-xl hover:bg-white/[0.07] transition-colors"
                    title="Expand/collapse">
                    <i data-lucide="chevron-down" class="w-3.5 h-3.5 group-toggle-icon transition-transform <?= $grpActive ? '' : '-rotate-90' ?>" id="icon-<?= $groupId ?>"></i>
                  </button>

                  <?php if ($deptFolder): ?>
                  <!-- Link ke folder utama departemen -->
                  <a href="files.php?folder_id=<?= (int)$deptFolder['id'] ?>"
                    class="flex flex-1 items-center justify-between gap-2 px-2 py-2.5 rounded-xl <?= $folderId === (int)$deptFolder['id'] ? 'bg-gradient-to-r from-blue-600/30 to-purple-600/30' : 'hover:bg-white/[0.06]' ?> transition-colors min-w-0">
                    <span class="flex items-center gap-2 min-w-0">
                      <i data-lucide="folder" class="w-4 h-4 flex-shrink-0 text-purple-300"></i>
                      <span class="font-extrabold text-sm truncate uppercase tracking-wide"><?= $deptName ?></span>
                    </span>
                    <span class="text-xs text-blue-100/45"><?= (int)($deptFolder['file_count'] ?? 0) ?></span>
                  </a>

                  <?php else: ?>
                  <!-- Grup tanpa folder utama (hanya label) -->
                  <span class="flex flex-1 items-center gap-2 px-2 py-2.5 min-w-0">
                    <i data-lucide="folder" class="w-4 h-4 flex-shrink-0 text-purple-300"></i>
                    <span class="font-extrabold text-sm truncate uppercase tracking-wide"><?= $deptName ?></span>
                  </span>
                  <?php endif; ?>
                </div>

                <!-- Sub-list Member Folders (collapsible) -->
                <div id="<?= $groupId ?>" class="member-folder-list pl-6 space-y-0.5 mt-0.5 <?= $grpActive ? '' : 'hidden' ?>">
                  <?php if (empty($memberFolders)): ?>
                    <p class="text-xs text-blue-100/35 px-3 py-1.5 italic">Belum ada folder member</p>
                  <?php else: ?>
                    <?php foreach ($memberFolders as $mFolder):
                        $canManageMF   = canManageItem($mFolder, $user, 'created_by');
                    ?>
                    <div class="group flex items-center gap-1 rounded-xl <?= $folderId === (int)$mFolder['id'] ? 'bg-gradient-to-r from-blue-600/25 to-purple-600/25 text-white' : 'text-blue-100/65 hover:bg-white/[0.055]' ?> transition-colors">
                      <a href="files.php?folder_id=<?= (int)$mFolder['id'] ?>"
                        class="flex flex-1 items-center justify-between gap-2 px-3 py-2 min-w-0">
                        <span class="flex items-center gap-2 min-w-0">
                          <i data-lucide="user" class="w-3.5 h-3.5 flex-shrink-0 text-blue-300/70"></i>
                          <span class="text-xs font-semibold truncate"><?= e($mFolder['name']) ?></span>
                        </span>
                        <span class="text-xs text-blue-100/35"><?= (int)$mFolder['file_count'] ?></span>
                      </a>
                      <?php if ($isAdmin && $canManageMF): ?>
                        <button onclick="confirmDeleteFolder(<?= (int)$mFolder['id'] ?>, '<?= e(addslashes($mFolder['name'])) ?>', <?= (int)$mFolder['file_count'] ?>)"
                          class="mr-1.5 p-1.5 rounded-lg text-red-300/50 hover:text-red-200 hover:bg-red-500/10 opacity-0 group-hover:opacity-100 transition-all" title="Hapus folder">
                          <i data-lucide="trash-2" class="w-3 h-3"></i>
                        </button>
                      <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>
            <?php endif; ?>

            <?php /* Folder tanpa department mapping (flat fallback atau folder lama) */ ?>
            <?php foreach ($ungroupedFolders as $folder):
                $canManageFolder = canManageItem($folder, $user, 'created_by');
            ?>
            <div class="group flex items-center gap-2 rounded-2xl <?= $folderId === (int)$folder['id'] ? 'bg-gradient-to-r from-blue-600/30 to-purple-600/30 text-white' : 'text-blue-100/70 hover:bg-white/[0.06]' ?> transition-colors">
              <a href="files.php?folder_id=<?= (int)$folder['id'] ?>" class="flex flex-1 items-center justify-between gap-3 px-3 py-3 min-w-0">
                <span class="flex items-center gap-3 min-w-0">
                  <i data-lucide="folder" class="w-4 h-4 flex-shrink-0"></i>
                  <span class="font-bold text-sm truncate"><?= e($folder['name']) ?></span>
                </span>
                <span class="text-xs text-blue-100/45"><?= (int)$folder['file_count'] ?></span>
              </a>
              <?php if ($isAdmin && $canManageFolder): ?>
                <button onclick="confirmDeleteFolder(<?= (int)$folder['id'] ?>, '<?= e(addslashes($folder['name'])) ?>', <?= (int)$folder['file_count'] ?>)" class="mr-2 p-2 rounded-xl text-red-300/60 hover:text-red-200 hover:bg-red-500/10 opacity-80 group-hover:opacity-100 transition-all" title="Hapus folder">
                  <i data-lucide="trash-2" class="w-4 h-4"></i>
                </button>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>

          <?php if (empty($folders)): ?>
          <p class="text-xs text-blue-100/45 mt-4"><?= $isAdmin ? 'Belum ada folder.' : 'Belum ada folder yang dibagikan.' ?></p>
          <?php endif; ?>
        </div>

        <div class="xl:col-span-3 space-y-5">
          <div class="cyber-panel rounded-3xl p-4">
            <form method="GET" action="" class="flex flex-wrap gap-3 items-end">
              <input type="hidden" name="folder_id" value="<?= $folderId > 0 ? (int)$folderId : '' ?>">
              <div class="flex-1 min-w-[220px]">
                <label class="block text-xs font-bold text-blue-200/60 mb-1">Cari File</label>
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Nama file" class="w-full px-4 py-3 text-sm border border-white/10 rounded-2xl bg-white/[0.06] text-blue-50 placeholder:text-blue-100/35 focus:outline-none focus:ring-2 focus:ring-purple-500/40">
              </div>
              <button type="submit" class="search-btn px-5 py-3 bg-white/[0.06] dark:bg-white/[0.08] border border-white/10 text-gray-800 dark:text-blue-50 text-sm rounded-2xl font-bold transition-colors">
              Cari
              </button>
            </form>
          </div>

          <div class="cyber-panel rounded-3xl overflow-hidden">
            <?php if (empty($files)): ?>
            <div class="flex flex-col items-center justify-center py-16 text-center">
              <div class="w-16 h-16 rounded-3xl icon-orb text-blue-200 mb-4">
                <i data-lucide="folder-open" class="w-8 h-8"></i>
              </div>
              <h3 class="font-extrabold text-white mb-1"><?= $search ? 'Tidak ada file yang cocok' : 'Belum ada file' ?></h3>
              <p class="text-sm text-blue-100/50 max-w-xs"><?= $search ? 'Coba ubah kata kunci pencarian.' : ($isAdmin ? 'Buat folder dan upload file untuk mulai berbagi.' : 'Belum ada file yang dibagikan.') ?></p>
            </div>
            <?php else: ?>
            <div class="file-table-scroll overflow-x-auto">
              <table class="w-full text-sm">
                <thead>
                  <tr class="border-b border-white/10 bg-white/[0.04] text-blue-100/80 sticky top-0 z-10 backdrop-blur-xl">
                    <th class="text-left py-4 px-4 font-bold">File</th>
                    <th class="text-left py-4 px-4 font-bold">Folder</th>
                    <th class="text-left py-4 px-4 font-bold">Ukuran</th>
                    <th class="text-left py-4 px-4 font-bold">Diunggah oleh</th>
                    <th class="text-left py-4 px-4 font-bold">Aksi</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($files as $file): ?>
                  <?php 
                      $canManageFile  = canManageItem($file, $user, 'uploaded_by');
                  ?>
                  <tr class="border-b border-white/10 last:border-b-0 text-blue-50 hover:bg-white/[0.055] transition-colors">
                    <td class="py-4 px-4">
                      <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-2xl bg-gradient-to-br from-blue-500/70 to-purple-600/70 flex items-center justify-center text-white shadow-lg shadow-purple-500/20">
                          <i data-lucide="file" class="w-4 h-4"></i>
                        </div>
                        <span class="font-bold"><?= e($file['original_name']) ?></span>
                      </div>
                    </td>
                    <td class="py-4 px-4">
                      <span class="inline-flex items-center rounded-full border border-blue-300/20 bg-blue-500/10 px-3 py-1 text-xs font-bold text-blue-200">
                        <?= e($file['folder_name'] ?? '-') ?>
                      </span>
                    </td>
                    <td class="py-4 px-4 text-blue-100/70"><?= formatSize((int)$file['size']) ?></td>
                    <td class="py-4 px-4 text-blue-100/70"><?= e($file['uploader_name'] ?? '-') ?></td>
                    <td class="py-4 px-4 text-right whitespace-nowrap">
                      <?php if ($isAdmin && $canManageFile): ?>
                      <button type="button"
                        class="file-action file-action-rename ml-2 js-file-manage-btn"
                        data-file-id="<?= (int)$file['id'] ?>"
                        data-file-name="<?= e($file['original_name']) ?>"
                        data-folder-id="<?= (int)($file['folder_id'] ?? 0) ?>">
                        Kelola
                      </button>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </main>
</div>

<?php if ($isAdmin): ?>
<div id="uploadModal" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" onclick="closeUploadModal()"></div>
  <div class="relative flex items-center justify-center min-h-full p-4">
    <div class="cyber-panel rounded-3xl w-full max-w-lg overflow-hidden">
      <div class="flex items-center justify-between px-6 py-4 border-b border-white/10">
        <h3 class="font-extrabold text-white">Unggah File</h3>
        <button onclick="closeUploadModal()" class="text-blue-100/60 hover:text-white">×</button>
      </div>
      <form id="uploadForm" enctype="multipart/form-data" class="p-6">
        <input type="hidden" name="csrf_token" value="<?= csrfGenerate() ?>">
        <label class="block text-xs font-bold text-blue-200/60 mb-2">Pilih Folder</label>
        <select name="folder_id" required class="w-full px-4 py-3 border border-white/10 rounded-2xl mb-4 bg-white/[0.06] text-blue-50 focus:outline-none focus:ring-2 focus:ring-purple-500/40">
          <option value="" class="bg-slate-900">-- Pilih Folder --</option>
          <?php if ($supportsDeptMapping && !empty($folderGroups)):
              foreach ($folderGroups as $deptId => $grp):
                  $dept = $grp['dept'];
                  $deptFolder = $grp['dept_folder'];
                  $memberFolders = $grp['members'];
                  $deptLabel = strtoupper($dept['name']);
          ?>
            <?php if ($deptFolder): ?>
            <option value="<?= (int)$deptFolder['id'] ?>" class="bg-slate-900 font-bold" <?= $folderId === (int)$deptFolder['id'] ? 'selected' : '' ?>><?= e($deptLabel) ?>/</option>
            <?php endif; ?>
            <?php foreach ($memberFolders as $mf): ?>
            <option value="<?= (int)$mf['id'] ?>" class="bg-slate-900" <?= $folderId === (int)$mf['id'] ? 'selected' : '' ?>>&nbsp;&nbsp;<?= e($deptLabel) ?>/<?= e($mf['name']) ?></option>
            <?php endforeach; ?>
          <?php endforeach; endif; ?>
          <?php foreach ($ungroupedFolders as $folder): ?>
          <option value="<?= (int)$folder['id'] ?>" class="bg-slate-900" <?= $folderId === (int)$folder['id'] ? 'selected' : '' ?>><?= e($folder['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="file" name="files[]" id="fileInput" multiple class="file-picker-input w-full border border-white/10 rounded-2xl p-3 bg-white/[0.06] text-blue-50">
        <div id="fileList" class="mt-4 text-sm text-blue-100/70"></div>
        <div id="uploadProgress" class="hidden mt-4"><div id="progressPct" class="text-sm text-blue-100/70 mb-1">0%</div><div class="w-full bg-white/10 rounded-full h-2 overflow-hidden"><div id="progressBar" style="width:0%" class="bg-gradient-to-r from-blue-500 to-purple-500 h-2"></div></div></div>
        <button type="button" onclick="startUpload()" id="uploadBtn" class="neon-button mt-5 w-full px-4 py-3 rounded-2xl text-white font-bold">Unggah</button>
      </form>
    </div>
  </div>
</div>

<div id="folderModal" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" onclick="closeFolderModal()"></div>
  <div class="relative flex items-center justify-center min-h-full p-4">
    <div class="cyber-panel rounded-3xl w-full max-w-sm p-6">
      <h3 class="font-extrabold text-white mb-4">Buat Folder Manual</h3>
      <input type="hidden" id="folderCsrfToken" name="csrf_token" value="<?= csrfGenerate() ?>">
      <input type="text" id="folderNameInput" class="w-full px-4 py-3 border border-white/10 rounded-2xl bg-white/[0.06] text-blue-50 placeholder:text-blue-100/35 focus:outline-none focus:ring-2 focus:ring-purple-500/40" placeholder="Nama folder manual...">
      <button onclick="submitFolder()" class="neon-button mt-4 w-full px-4 py-3 rounded-2xl text-white font-bold">Buat Folder</button>
    </div>
  </div>
</div>

<div id="renameModal" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" onclick="closeRenameModal()"></div>
  <div class="relative flex items-center justify-center min-h-full p-4"><div class="cyber-panel rounded-3xl w-full max-w-sm p-6"><h3 class="font-extrabold text-white mb-4">Rename File</h3><input type="hidden" id="renameFileId"><input type="text" id="renameInput" class="w-full px-4 py-3 border border-white/10 rounded-2xl bg-white/[0.06] text-blue-50 placeholder:text-blue-100/35 focus:outline-none focus:ring-2 focus:ring-purple-500/40" placeholder="Nama file baru..."><button onclick="submitRename()" class="neon-button mt-4 w-full px-4 py-3 rounded-2xl text-white font-bold">Simpan</button></div></div>
</div>

<div id="moveModal" class="fixed inset-0 z-50 hidden bg-black/70 backdrop-blur-sm">
  <div class="absolute inset-0 modal-overlay pointer-events-none"></div>

  <div class="relative min-h-full flex items-center justify-center p-4 pointer-events-none">
    <div class="cyber-panel rounded-3xl w-full max-w-sm p-6 pointer-events-auto">
      <div class="flex items-center justify-between mb-2">
        <h3 class="font-extrabold text-white">Pindah File</h3>
        <button type="button" onclick="closeMoveModal()" class="text-blue-100/50 hover:text-white text-xl leading-none">
          ×
        </button>
      </div>

      <p id="moveFileName" class="text-sm font-bold text-blue-100/70 mb-4"></p>
      <input type="hidden" id="moveFileId">

      <label class="block text-xs font-bold text-blue-200/60 mb-2">Folder Tujuan</label>
      <select id="moveTargetFolder" class="w-full px-4 py-3 border border-white/10 rounded-2xl bg-white/[0.06] text-blue-50 focus:outline-none focus:ring-2 focus:ring-purple-500/40">
        <option value="" class="bg-slate-900">-- Pilih Folder Tujuan --</option>
        <?php if ($supportsDeptMapping && !empty($folderGroups)):
            foreach ($folderGroups as $deptId => $grp):
                $dept = $grp['dept'];
                $deptFolder = $grp['dept_folder'];
                $memberFolders = $grp['members'];
                $deptLabel = strtoupper($dept['name']);
        ?>
          <?php if ($deptFolder): ?>
          <option value="<?= (int)$deptFolder['id'] ?>" class="bg-slate-900 font-bold"><?= e($deptLabel) ?>/</option>
          <?php endif; ?>
          <?php foreach ($memberFolders as $mf): ?>
          <option value="<?= (int)$mf['id'] ?>" class="bg-slate-900">&nbsp;&nbsp;<?= e($deptLabel) ?>/<?= e($mf['name']) ?></option>
          <?php endforeach; ?>
        <?php endforeach; endif; ?>
        <?php foreach ($ungroupedFolders as $folder): ?>
          <option value="<?= (int)$folder['id'] ?>" class="bg-slate-900"><?= e($folder['name']) ?></option>
        <?php endforeach; ?>
      </select>

      <button type="button" onclick="submitMove()" class="neon-button mt-4 w-full px-4 py-3 rounded-2xl text-white font-bold">
        Pindahkan
      </button>
    </div>
  </div>
</div>

<div id="folderDeleteModal" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" onclick="closeDeleteFolderModal()"></div>
  <div class="relative flex items-center justify-center min-h-full p-4"><div class="cyber-panel rounded-3xl w-full max-w-sm p-6 text-center"><div class="w-12 h-12 rounded-2xl bg-red-500/10 border border-red-300/15 flex items-center justify-center text-red-300 mx-auto mb-4"><i data-lucide="folder-x" class="w-6 h-6"></i></div><h3 class="font-extrabold text-white text-lg mb-2">Hapus Folder?</h3><p id="deleteFolderName" class="text-sm font-bold text-blue-100/70 mb-3"></p><p id="deleteFolderWarning" class="text-xs text-yellow-200/80 mb-5 hidden"></p><input type="hidden" id="deleteFolderId"><div class="flex justify-center gap-2"><button onclick="closeDeleteFolderModal()" class="px-4 py-2 border border-white/10 rounded-xl text-blue-100/80 hover:bg-white/[0.06] transition-colors">Batal</button><button id="deleteFolderConfirmBtn" onclick="submitDeleteFolder()" class="px-4 py-2 bg-red-500 hover:bg-red-400 text-white rounded-xl font-bold transition-colors">Ya, Hapus</button></div></div></div>
</div>

<div id="deleteModal" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/70 backdrop-blur-sm"></div>
  <div class="relative flex items-center justify-center min-h-full p-4"><div class="cyber-panel rounded-3xl w-full max-w-sm p-6 text-center"><div class="w-12 h-12 rounded-2xl bg-red-500/10 border border-red-300/15 flex items-center justify-center text-red-300 mx-auto mb-4"><i data-lucide="trash-2" class="w-6 h-6"></i></div><h3 class="font-extrabold text-white text-lg mb-2">Hapus File?</h3><p id="deleteFileName" class="text-sm font-bold text-blue-100/70 mb-5"></p><input type="hidden" id="deleteFileId"><div class="flex justify-center gap-2"><button onclick="closeDeleteModal()" class="px-4 py-2 border border-white/10 rounded-xl text-blue-100/80 hover:bg-white/[0.06] transition-colors">Batal</button><button onclick="submitDelete()" class="px-4 py-2 bg-red-500 hover:bg-red-400 text-white rounded-xl font-bold transition-colors">Ya, Hapus</button></div></div></div>
</div>

<!-- ─── MODAL KELOLA FILE (unified: rename + pindah + akses + hapus) ─── -->
<div id="fileManageModal" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" onclick="closeFileManageModal()"></div>
  <div class="relative flex items-center justify-center min-h-full p-4 pointer-events-none">
    <div class="cyber-panel rounded-3xl w-full max-w-md pointer-events-auto flex flex-col max-h-[90vh]">
      <div class="p-6 overflow-y-auto flex-1">

        <div class="flex items-center justify-between mb-4">
          <div>
            <h3 class="font-extrabold text-white text-lg">Kelola File</h3>
            <p id="fileManageName" class="text-sm text-blue-100/60 truncate max-w-xs"></p>
          </div>
          <button onclick="closeFileManageModal()" class="text-blue-100/50 hover:text-white text-xl leading-none">×</button>
        </div>

        <input type="hidden" id="fileManageId">
        <input type="hidden" id="fileManageFolderId">

        <!-- Rename -->
        <label class="block text-xs font-bold text-blue-200/60 mb-2">Rename File</label>
        <input type="text" id="fileManageRenameInput"
          class="w-full px-4 py-3 border border-white/10 rounded-2xl mb-4 bg-white/[0.06] text-blue-50 placeholder:text-blue-100/35 focus:outline-none focus:ring-2 focus:ring-purple-500/40"
          placeholder="Nama file baru...">

        <!-- Pindah Folder -->
        <label class="block text-xs font-bold text-blue-200/60 mb-2">Pindah ke Folder</label>
        <select id="fileManageMoveTarget"
          class="w-full px-4 py-3 border border-white/10 rounded-2xl mb-4 bg-white/[0.06] text-blue-50 focus:outline-none focus:ring-2 focus:ring-purple-500/40">
          <option value="" class="bg-slate-900">-- Tetap di folder saat ini --</option>
          <?php if (isset($supportsDeptMapping) && $supportsDeptMapping && !empty($folderGroups)):
              foreach ($folderGroups as $deptId => $grp):
                  $dept = $grp['dept'];
                  $deptFolder = $grp['dept_folder'];
                  $memberFolders = $grp['members'];
                  $deptLabel = strtoupper($dept['name']);
          ?>
            <?php if ($deptFolder): ?>
            <option value="<?= (int)$deptFolder['id'] ?>" class="bg-slate-900 font-bold"><?= e($deptLabel) ?>/</option>
            <?php endif; ?>
            <?php foreach ($memberFolders as $mf): ?>
            <option value="<?= (int)$mf['id'] ?>" class="bg-slate-900">&nbsp;&nbsp;<?= e($deptLabel) ?>/<?= e($mf['name']) ?></option>
            <?php endforeach; ?>
          <?php endforeach; endif; ?>
          <?php if (isset($ungroupedFolders)): foreach ($ungroupedFolders as $folder): ?>
            <option value="<?= (int)$folder['id'] ?>" class="bg-slate-900"><?= e($folder['name']) ?></option>
          <?php endforeach; endif; ?>
        </select>

        <!-- Simpan Perubahan -->
        <button onclick="submitFileManage()" class="neon-button mt-5 w-full px-4 py-3 rounded-2xl text-white font-bold">
          Simpan Perubahan
        </button>

        <!-- Hapus File -->
        <button onclick="openDeleteFromManage()"
          class="mt-3 w-full px-4 py-3 rounded-2xl font-bold text-white transition-colors"
          style="background: linear-gradient(135deg, #dc2626, #b91c1c);">
          Hapus File
        </button>

      </div>
    </div>
  </div>
</div>

<?php endif; ?>

<?php require_once APP_ROOT . '/views/partials/footer.php'; ?>

<script>
// Toggle collapse grup folder departemen
function toggleFolderGroup(groupId) {
    const list = document.getElementById(groupId);
    const icon = document.getElementById('icon-' + groupId);
    if (!list) return;
    const isHidden = list.classList.contains('hidden');
    list.classList.toggle('hidden', !isHidden);
    if (icon) {
        icon.classList.toggle('-rotate-90', isHidden);
    }
    // Persist state di sessionStorage supaya tidak collapse setiap kali page reload
    try {
        const states = JSON.parse(sessionStorage.getItem('folderGroupStates') || '{}');
        states[groupId] = !isHidden ? 'closed' : 'open';
        sessionStorage.setItem('folderGroupStates', JSON.stringify(states));
    } catch(e) {}
}

// Restore collapse state dari sessionStorage saat halaman dimuat
document.addEventListener('DOMContentLoaded', function() {
    try {
        const states = JSON.parse(sessionStorage.getItem('folderGroupStates') || '{}');
        Object.entries(states).forEach(([groupId, state]) => {
            const list = document.getElementById(groupId);
            const icon = document.getElementById('icon-' + groupId);
            if (!list) return;
            if (state === 'closed') {
                list.classList.add('hidden');
                if (icon) icon.classList.add('-rotate-90');
            } else {
                list.classList.remove('hidden');
                if (icon) icon.classList.remove('-rotate-90');
            }
        });
    } catch(e) {}
});
</script>