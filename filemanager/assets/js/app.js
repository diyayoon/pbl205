/* ──────────────────────────── helpers ──────────────────────────── */

//Generate checkbox departemen secara dinamis di container tertentu
function buildDeptCheckboxes(containerId, currentDepts) {

//Ambil container target, stop kalau tidak ditemukan
  const container = document.getElementById(containerId);
  if (!container) return;

//Tentukan daftar departemen & mapping departemen yang user saat ini sudah punya (dipakai untuk mode edit pada kelola user)
  const depts = IS_SUPERADMIN ? ALL_DEPTS : CALLER_DEPTS;
  const currentMap = {};
  (currentDepts || []).forEach(d => { currentMap[d.id] = d.dept_role; });

//Render HTML checkbox + dropdown role (member/admin) per departemen
  container.innerHTML = depts.length === 0
    ? '<p class="text-xs text-blue-100/45 px-1">Tidak ada departemen tersedia.</p>'
    : depts.map(d => {
        const isChecked = currentMap[d.id] !== undefined;
        const role      = currentMap[d.id] || 'member';
        return `
          <div class="flex items-center justify-between gap-3 py-1">
            <label class="flex items-center gap-2 cursor-pointer">
              <input type="checkbox" class="dept-cb w-4 h-4 accent-purple-500"
                     data-dept-id="${d.id}" ${isChecked ? 'checked' : ''}>
              <span class="text-sm text-blue-50">${d.name}</span>
            </label>
            <select class="dept-role-sel text-xs px-2 py-1 rounded-xl border border-white/10 bg-white/[0.06] text-blue-50"
                    data-dept-id="${d.id}" ${!isChecked ? 'disabled' : ''}>
              <option value="member" ${role === 'member' ? 'selected' : ''} class="bg-slate-900">Member</option>
              ${IS_SUPERADMIN ? `<option value="admin" ${role === 'admin' ? 'selected' : ''} class="bg-slate-900">Admin</option>` : ''}
            </select>
          </div>`;
      }).join('');


// Mencegah lebih dari 1 departemen tercentang sekaligus
 container.querySelectorAll('.dept-cb').forEach(cb => {
    cb.addEventListener('change', function () {
      if (this.checked) {
        container.querySelectorAll('.dept-cb').forEach(other => {
          if (other !== this && other.checked) {
            other.checked = false;
            const otherSel = container.querySelector(`.dept-role-sel[data-dept-id="${other.dataset.deptId}"]`);
            if (otherSel) otherSel.disabled = true;
          }
        });
      }
 
      const sel = container.querySelector(`.dept-role-sel[data-dept-id="${this.dataset.deptId}"]`);
      if (sel) sel.disabled = !this.checked;
    });
  });


//Jika ada dropdown system role di modal, maka ubah semua dropdown role departemen sesuai pilihan system role
  const roleSelectId = containerId === 'createDeptList' ? 'createSystemRole' : 'adminSystemRole';
  const roleSelect = document.getElementById(roleSelectId);
  if (roleSelect) {
    roleSelect.onchange = function () {
      const targetDeptRole = this.value === 'general_admin' ? 'admin' : 'member';
      container.querySelectorAll('.dept-role-sel').forEach(sel => {
        if ([...sel.options].some(o => o.value === targetDeptRole)) sel.value = targetDeptRole;
      });
    };
  }
}


//Fungsi ini cuma ngumpulin data dari checkbox+dropdown yang dicentang user (departemen mana aja yang dipilih beserta role-nya), terus dirapihin jadi format {ids, roles} yang siap dikirim ke server.
function collectDeptInput(containerId) {
  const container = document.getElementById(containerId);
  if (!container) return { ids: [], roles: [] };
  const ids = [], roles = [];
  container.querySelectorAll('.dept-cb:checked').forEach(cb => {

    const sel = container.querySelector(`.dept-role-sel[data-dept-id="${cb.dataset.deptId}"]`);
    ids.push(cb.dataset.deptId);
    roles.push(sel ? sel.value : 'member');
  });
  return { ids, roles };
}



/* ─────────────────── Modal Tambah User ─────────────────── */

function openAdminCreateModal() {
  
  // Ambil elemen modal & reset semua field input ke kosong
  const modal = document.getElementById('adminCreateModal');
  document.getElementById('createUsername').value = '';
  document.getElementById('createNim').value      = '';
  document.getElementById('createPassword').value = '';
  document.getElementById('createJobdesk').value  = '';

  // Reset dropdown system role ke default 'member'
  const sr = document.getElementById('createSystemRole');
  if (sr && sr.tagName === 'SELECT') sr.value = 'member';

  // Render ulang checkbox departemen dalam kondisi kosong
  buildDeptCheckboxes('createDeptList', []);

  // Tampilkan modal, lalu auto-focus ke field username
  if (modal) modal.classList.remove('hidden');
  setTimeout(() => document.getElementById('createUsername')?.focus(), 80);
}

// Tutup/sembunyikan modal tambah user
function closeAdminCreateModal() {
  document.getElementById('adminCreateModal')?.classList.add('hidden');
}


function submitAdminCreate() {
  // Ambil semua nilai input dari form
  const username   = (document.getElementById('createUsername')?.value || '').trim();
  const nim        = (document.getElementById('createNim')?.value || '').trim();
  const password   = document.getElementById('createPassword')?.value || '';
  const systemRole = document.getElementById('createSystemRole')?.value || 'member';
  const jobdesk    = (document.getElementById('createJobdesk')?.value || '').trim();
  const token      = document.getElementById('adminCreateCsrf')?.value || '';

  // Validasi: username, NIM, password wajib diisi
  if (!username || !nim || !password) {
    showToast('Username, NIM, dan password wajib diisi.', 'error');
    return;
  }


  // Kumpulkan data departemen, susun jadi FormData untuk dikirim
  const { ids, roles } = collectDeptInput('createDeptList');
  const body = new FormData();
  body.append('action',      'create_user');
  body.append('csrf_token',  token);
  body.append('username',    username);
  body.append('nim',         nim);
  body.append('password',    password);
  body.append('system_role', systemRole);
  body.append('jobdesk',     jobdesk);
  ids.forEach((id, i) => { body.append('dept_ids[]', id); body.append('dept_roles[]', roles[i]); });


  // Kirim AJAX (POST) ke AdminController.php dengan action create_user
  apiFetch(typeof ADMIN_CTRL_URL !== 'undefined' ? ADMIN_CTRL_URL : 'controllers/AdminController.php', { method: 'POST', body })
    .then(r => r.json())
    .then(res => {

      // Tampilkan toast hasil; kalau sukses, tutup modal & reload halaman
      showToast(res.message, res.success ? 'success' : 'error');
      if (res.success) { closeAdminCreateModal(); setTimeout(() => location.reload(), 900); }
    })
    .catch(() => showToast('Gagal menambahkan user.', 'error'));
}



/* ─────────────────────────────────── Modal Edit User ────────────────────────────────────────── */
function openAdminEditModal(id, username, systemRole, jobdesk, currentDepts, canDelete = false) {
  // Ambil elemen modal & isi semua field input dengan data user yang mau diedit
  const modal = document.getElementById('adminEditModal');
  document.getElementById('adminEditId').value         = id;
  document.getElementById('adminEditCanDelete').value  = canDelete;
  document.getElementById('adminEditName').textContent = username;
  document.getElementById('adminJobdesk').value        = jobdesk;

  // Set dropdown system role & render checkbox departemen sesuai data user yang diedit
  const sr = document.getElementById('adminSystemRole');
  if (sr && sr.tagName === 'SELECT') sr.value = systemRole;
  buildDeptCheckboxes('editDeptList', currentDepts || []);

  // Tampilkan/sembunyikan tombol hapus berdasarkan canDelete, lalu tampilkan modal
  const deleteBtn = document.getElementById('adminEditDeleteBtn');
  if (deleteBtn) deleteBtn.classList.toggle('hidden', !canDelete);
  if (modal) modal.classList.remove('hidden');
}

// Tutup/sembunyikan modal edit user
function closeAdminEditModal() {
  document.getElementById('adminEditModal')?.classList.add('hidden');
}

// Tutup modal edit, lalu buka pop-up konfirmasi hapus user (bawa data id+username)
function openDeleteFromEdit() {
  const id       = document.getElementById('adminEditId').value;
  const username = document.getElementById('adminEditName').textContent;
  closeAdminEditModal();
  openAdminDeleteModal(id, username);
}

// Ambil nilai input dari form edit (id, system role, jobdesk, csrf token)
function submitAdminEdit() {
  const id         = document.getElementById('adminEditId')?.value;
  const systemRole = document.getElementById('adminSystemRole')?.value;
  const jobdesk    = (document.getElementById('adminJobdesk')?.value || '').trim();
  const token      = document.getElementById('adminEditCsrf')?.value || '';

// Kumpulkan departemen yang dicentang beserta role-nya dari form edit
  const { ids, roles } = collectDeptInput('editDeptList');

// Siapkan FormData untuk dikirim via AJAX ke AdminController.php
  const body = new FormData();
  body.append('action',      'update_user');
  body.append('csrf_token',  token);
  body.append('id',          id);
  body.append('system_role', systemRole);
  body.append('jobdesk',     jobdesk);

// Tambahkan tiap departemen yang dicentang beserta role-nya ke FormData
  ids.forEach((id, i) => { body.append('dept_ids[]', id); body.append('dept_roles[]', roles[i]); });

// Kirim request POST ke AdminController.php
  apiFetch(typeof ADMIN_CTRL_URL !== 'undefined' ? ADMIN_CTRL_URL : 'controllers/AdminController.php', { method: 'POST', body })
    .then(r => r.json())
    .then(res => {
      // Tampilkan notifikasi sesuai hasil dari server (sukses/gagal)
      showToast(res.message, res.success ? 'success' : 'error');
      if (res.success) { closeAdminEditModal(); setTimeout(() => location.reload(), 900); }
    })

    // Tangani error jaringan/request gagal (misal koneksi putus, server down)
    .catch(() => showToast('Gagal menyimpan data user.', 'error'));
}