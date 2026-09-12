<?php
require_once __DIR__ . '/middleware/auth.php';
require_once APP_ROOT . '/models/UserModel.php';
requireGeneralAdmin();

$pageTitle    = 'Kelola User';
$userModel    = new UserModel();
$users        = $userModel->getAll();
$allDepts     = $userModel->getAllDepartments();
$me           = currentUser();
$isSuperAdmin = ($me['system_role'] ?? '') === 'superadmin';

/* Dept yang di-admin caller (untuk general_admin) */
$callerAdminDepts = $isSuperAdmin ? $allDepts : $userModel->getAdminDepartments((int)$me['id']);

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
      <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4 mb-7">
        <div>
          <h1 class="text-2xl font-bold text-white">Kelola User</h1>
          <p class="text-sm text-blue-100/60 mt-1">
            <?= $isSuperAdmin ? 'Tambah dan atur peran serta departemen anggota.' : 'Tambah Member dan atur departemen yang kamu kelola.' ?>
          </p>
        </div>
        <button type="button" onclick="openAdminCreateModal()" class="neon-button inline-flex items-center justify-center gap-2 px-4 py-3 rounded-2xl text-white font-bold">
          <i data-lucide="user-plus" class="w-4 h-4"></i>
          Tambah User
        </button>
      </div>

      <!-- Tabel daftar user -->
      <div class="rounded-2xl border border-white/10 bg-white/[0.045] backdrop-blur-xl shadow-xl overflow-hidden">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-blue-300 dark:border-white/10 bg-white/[0.04] text-blue-100/80">
              <th class="text-left p-4 font-semibold">Username</th>
              <th class="text-left p-4 font-semibold">NIM</th>
              <th class="text-left p-4 font-semibold">System Role</th>
              <th class="text-left p-4 font-semibold">Departemen</th>
              <th class="text-left p-4 font-semibold">Jobdesk</th>
              <th class="text-right p-4 font-semibold">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $u):
              $targetRole = $u['system_role'] ?? 'member';

              // Superadmin → bisa edit semua
              // Admin Divisi → hanya bisa edit member di departemen yang dia kelola
              if ($isSuperAdmin) {
                  $canEdit = true;
              } elseif ($targetRole === 'member') {
                  $targetDeptIds = array_column($userModel->getUserDepartments((int)$u['id']), 'id');
                  $canEdit = !empty(array_intersect(
                      array_column($callerAdminDepts, 'id'),
                      $targetDeptIds
                  ));
              } else {
                  $canEdit = false;
              }

              /* Parse dept string dari GROUP_CONCAT */
              $deptList = [];
              if (!empty($u['departments'])) {
                  foreach (explode(',', $u['departments']) as $pair) {
                      [$dname, $drole] = explode(':', $pair, 2);
                      $deptList[] = ['name' => $dname, 'role' => $drole];
                  }
              }

              /* Data untuk modal edit */
              $userDepts = $userModel->getUserDepartments((int)$u['id']);
            ?>
            <tr class="border-b border-blue-300 dark:border-white/10 last:border-b-0 text-blue-50 hover:bg-white/[0.055] transition-colors">
              <td class="p-4">
                <div class="flex items-center gap-3">
                  <div class="w-9 h-9 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-white text-sm font-bold shadow-lg">
                    <?= strtoupper(substr($u['username'], 0, 1)) ?>
                  </div>
                  <span class="font-semibold"><?= e($u['username']) ?></span>
                  <?php if ($targetRole === 'superadmin'): ?>
                    <i data-lucide="shield-check" class="w-4 h-4 superadmin-shield" title="Superadmin"></i>
                  <?php endif; ?>
                </div>
              </td>
              <td class="p-4 text-blue-100/70"><?= e($u['nim']) ?></td>
              <td class="p-4">
                <?php if ($targetRole === 'superadmin'): ?>
                  <span class="superadmin-badge inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold">Superadmin</span>
                <?php elseif ($targetRole === 'general_admin'): ?>
                  <span class="role-badge-admin inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold">Admin</span>
                <?php else: ?>
                  <span class="role-badge-member inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold">Member</span>
                <?php endif; ?>
              </td>
              <td class="p-4">
                <?php if ($targetRole === 'superadmin'): ?>
                  <span class="dept-badge-superadmin inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold">
                    Administrator
                  </span>
                <?php elseif (empty($deptList) && $targetRole === 'member'): ?>
                  <span class="dept-badge-member-only inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold">
                    Administrator
                  </span>
                <?php else: ?>
                  <div class="flex flex-wrap gap-1">
                    <?php foreach ($deptList as $d): ?>
                      <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold <?= $d['role'] === 'admin' ? 'dept-badge-admin' : 'dept-badge-member' ?>">
                        <?= e($d['name']) ?>
                        <span class="opacity-70">(<?= $d['role'] === 'admin' ? 'admin' : 'member' ?>)</span>
                      </span>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </td>
              <td class="p-4 text-blue-100/80"><?= e($u['jobdesk']) ?></td>
              <td class="p-4 text-right flex items-center justify-end gap-3">
                <?php if ($canEdit): ?>
                 <button onclick='openAdminEditModal(
                   <?= (int)$u["id"] ?>,
                   <?= json_encode($u["username"]) ?>,
                   <?= json_encode($u["system_role"]) ?>,
                   <?= json_encode($u["jobdesk"]) ?>,
                   <?= json_encode($userDepts) ?>,
                   <?= (($u['system_role'] ?? '') !== 'superadmin' && (int)$u['id'] !== (int)$me['id']) ? 'true' : 'false' ?>
                  )' class="text-purple-300 hover:text-purple-200 font-bold">Edit</button>
                <?php else: ?>
                  <span class="text-blue-100/25 text-xs italic">—</span>
                <?php endif; ?>
             </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($users)): ?>
              <tr><td colspan="6" class="p-6 text-center text-blue-100/50">Belum ada data user.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>
</div>

<!-- ────────────────── DATA DEPT UNTUK JS ──────────────────── -->
<script>
const ALL_DEPTS   = <?= json_encode($allDepts) ?>;
const CALLER_DEPTS = <?= json_encode($callerAdminDepts) ?>;
const IS_SUPERADMIN = <?= $isSuperAdmin ? 'true' : 'false' ?>;
const ADMIN_CTRL_URL = '<?= APP_URL ?>/controllers/AdminController.php';
</script>

<!-- ─────────────────────────────────────── MODAL TAMBAH USER ──────────────────────────────────────── -->
<div id="adminCreateModal" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" onclick="closeAdminCreateModal()"></div>
  <div class="relative flex items-center justify-center min-h-full p-4 pointer-events-none">
    <div class="cyber-panel rounded-3xl w-full max-w-md pointer-events-auto flex flex-col max-h-[90vh]">
      <div class="p-6 overflow-y-auto flex-1">
      <div class="flex items-center justify-between mb-4">
        <div>
          <h3 class="font-extrabold text-white text-lg">Tambah User</h3>
          <p class="text-sm text-blue-100/60">
            <?= $isSuperAdmin ? 'Atur peran dan departemen anggota.' : 'User baru akan ditambahkan sebagai Member.' ?>
          </p>
        </div>
        <button onclick="closeAdminCreateModal()" class="text-blue-100/50 hover:text-white text-xl">×</button>
      </div>

      <input type="hidden" id="adminCreateCsrf" value="<?= csrfGenerate() ?>">

      <label class="block text-xs font-bold text-blue-200/60 mb-2">Username</label>
      <input type="text" id="createUsername" placeholder="Contoh: Arga"
        class="w-full px-4 py-3 border border-white/10 rounded-2xl mb-4 bg-white/[0.06] text-blue-50 placeholder:text-blue-100/35 focus:outline-none focus:ring-2 focus:ring-purple-500/40">

      <label class="block text-xs font-bold text-blue-200/60 mb-2">NIM</label>
      <input type="text" id="createNim" placeholder="Contoh: 4332501030"
        class="w-full px-4 py-3 border border-white/10 rounded-2xl mb-4 bg-white/[0.06] text-blue-50 placeholder:text-blue-100/35 focus:outline-none focus:ring-2 focus:ring-purple-500/40">

      <label class="block text-xs font-bold text-blue-200/60 mb-2">Password</label>
      <input type="password" id="createPassword" placeholder="Kata sandi"
        class="w-full px-4 py-3 border border-white/10 rounded-2xl mb-4 bg-white/[0.06] text-blue-50 placeholder:text-blue-100/35 focus:outline-none focus:ring-2 focus:ring-purple-500/40">

      <label class="block text-xs font-bold text-blue-200/60 mb-2">Jobdesk</label>
      <input type="text" id="createJobdesk" placeholder="Contoh: Web Developer"
        class="w-full px-4 py-3 border border-white/10 rounded-2xl mb-4 bg-white/[0.06] text-blue-50 placeholder:text-blue-100/35 focus:outline-none focus:ring-2 focus:ring-purple-500/40">

      <?php if ($isSuperAdmin): ?>
      <!-- General admin gak bisa pilih system_role, otomatis dianggap member -->
      <label class="block text-xs font-bold text-blue-200/60 mb-2">System Role</label>
      <select id="createSystemRole" class="w-full px-4 py-3 border border-white/10 rounded-2xl mb-4 bg-white/[0.06] text-blue-50 focus:outline-none focus:ring-2 focus:ring-purple-500/40">
        <option value="member"        class="bg-slate-900">Member</option>
        <option value="general_admin" class="bg-slate-900">Admin</option>
      </select>
      <?php else: ?>
      <input type="hidden" id="createSystemRole" value="member">
      <?php endif; ?>

      <label class="block text-xs font-bold text-blue-200/60 mb-2">Departemen</label>
      <div id="createDeptList" class="space-y-2 border border-white/10 rounded-2xl p-3 bg-white/[0.03] mb-1"></div>

      <button onclick="submitAdminCreate()" class="neon-button mt-5 w-full px-4 py-3 rounded-2xl text-white font-bold">Simpan</button>
      </div><!-- /scrollable -->
    </div>
  </div>
</div>

<!-- ─────────────────────────────────────── MODAL EDIT USER ──────────────────────────────────────── -->
<div id="adminEditModal" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" onclick="closeAdminEditModal()"></div>
  <div class="relative flex items-center justify-center min-h-full p-4 pointer-events-none">
    <div class="cyber-panel rounded-3xl w-full max-w-md pointer-events-auto flex flex-col max-h-[90vh]">
      <div class="p-6 overflow-y-auto flex-1">
      <div class="flex items-center justify-between mb-4">
        <div>
          <h3 class="font-extrabold text-white text-lg">Edit User</h3>
          <p id="adminEditName" class="text-sm text-blue-100/60"></p>
        </div>
        <button onclick="closeAdminEditModal()" class="text-blue-100/50 hover:text-white text-xl">×</button>
      </div>

      <input type="hidden" id="adminEditId">
      <input type="hidden" id="adminEditCanDelete" value="false">
      <input type="hidden" id="adminEditCsrf" value="<?= csrfGenerate() ?>">

      <?php if ($isSuperAdmin): ?>
      <label class="block text-xs font-bold text-blue-200/60 mb-2">System Role</label>
      <select id="adminSystemRole" class="w-full px-4 py-3 border border-white/10 rounded-2xl mb-4 bg-white/[0.06] text-blue-50 focus:outline-none focus:ring-2 focus:ring-purple-500/40">
        <option value="member"        class="bg-slate-900">Member</option>
        <option value="general_admin" class="bg-slate-900">Admin</option>
        <option value="superadmin"    class="bg-slate-900">Superadmin</option>
      </select>
      <?php else: ?>
      <input type="hidden" id="adminSystemRole" value="member">
      <?php endif; ?>

      <label class="block text-xs font-bold text-blue-200/60 mb-2">Jobdesk</label>
      <input type="text" id="adminJobdesk" placeholder="Jobdesk..."
        class="w-full px-4 py-3 border border-white/10 rounded-2xl mb-4 bg-white/[0.06] text-blue-50 placeholder:text-blue-100/35 focus:outline-none focus:ring-2 focus:ring-purple-500/40">

      <label class="block text-xs font-bold text-blue-200/60 mb-2">Departemen</label>
      <div id="editDeptList" class="space-y-2 border border-white/10 rounded-2xl p-3 bg-white/[0.03] mb-1"></div>

      <button onclick="submitAdminEdit()" class="neon-button mt-5 w-full px-4 py-3 rounded-2xl text-white font-bold">Simpan Perubahan</button>

      <button id="adminEditDeleteBtn" onclick="openDeleteFromEdit()"
        class="mt-3 w-full px-4 py-3 rounded-2xl bg-red-600 hover:bg-red-500 text-white font-bold transition-colors">
        Hapus Akun
      </button>
      </div><!-- /scrollable -->
    </div>
  </div>
</div>

<!-- ─── MODAL KONFIRMASI HAPUS USER ─── -->
<div id="adminDeleteModal" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" onclick="closeAdminDeleteModal()"></div>
  <div class="relative flex items-center justify-center min-h-full p-4 pointer-events-none">
    <div class="cyber-panel rounded-3xl w-full max-w-sm pointer-events-auto p-6">
      <h3 class="font-extrabold text-white text-lg mb-2">Hapus User</h3>
      <p class="text-blue-100/70 text-sm mb-1">Yakin ingin menghapus user:</p>
      <p id="adminDeleteUserName" class="text-red-300 font-bold text-base mb-5"></p>
      <p class="text-yellow-300/70 text-xs mb-5">⚠️ Tindakan ini tidak bisa dibatalkan.</p>

      <input type="hidden" id="adminDeleteUserId">
      <input type="hidden" id="adminDeleteUserCsrf" value="<?= csrfGenerate() ?>">

      <div class="flex gap-3">
        <button onclick="closeAdminDeleteModal()"
          class="flex-1 px-4 py-3 rounded-2xl border border-white/10 text-blue-100/70 hover:text-white font-bold">
          Batal
        </button>
        <button onclick="submitAdminDelete()"
          class="flex-1 px-4 py-3 rounded-2xl bg-red-600 hover:bg-red-500 text-white font-bold">
          Hapus
        </button>
      </div>
    </div>
  </div>
</div>

<script>
// Buka/tutup modal konfirmasi hapus user
function openAdminDeleteModal(id, username) {
  document.getElementById('adminDeleteUserId').value = id;
  document.getElementById('adminDeleteUserName').textContent = username;
  document.getElementById('adminDeleteModal').classList.remove('hidden');
}

function closeAdminDeleteModal() {
  document.getElementById('adminDeleteModal').classList.add('hidden');
}

// Kirim request hapus user ke AdminController, reload halaman kalau sukses
async function submitAdminDelete() {
  const id        = document.getElementById('adminDeleteUserId').value;
  const csrfToken = document.getElementById('adminDeleteUserCsrf').value;

  const body = new URLSearchParams({
    action:     'delete_user',
    id:         id,
    csrf_token: csrfToken,
  });

  try {
    const res  = await fetch('<?= APP_URL ?>/controllers/AdminController.php', { method: 'POST', body });
    const data = await res.json();

    if (data.success) {
      closeAdminDeleteModal();
      location.reload();
    } else {
      alert('Gagal: ' + data.message);
    }
  } catch (e) {
    alert('Error: ' + e.message);
  }
}
</script>

<?php require_once APP_ROOT . '/views/partials/footer.php'; ?>