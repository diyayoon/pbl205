/* ============================================================
   files.js — Modal & Upload logic untuk files.php
   ============================================================ */

// Ambil base path aplikasi dari atribut data-base di tag <script>, untuk prefix semua URL fetch
const BASE = (function () {
  const s = document.querySelector('script[data-base]');
  return s ? s.dataset.base.replace(/\/$/, '') : '';
})();

/* ── helpers ────────────────────────────────────────────── */

// Ambil value CSRF token dari elemen tertentu, fallback ke input csrf_token umum kalau tidak ditemukan
function getCsrf(id) {
  const el = document.getElementById(id) || document.querySelector('input[name="csrf_token"]');
  return el ? el.value : '';
}

// Tampilkan modal berdasarkan id elemen
function openModal(id)  { const m = document.getElementById(id); if (m) m.classList.remove('hidden'); }

// Sembunyikan modal berdasarkan id elemen
function closeModal(id) { const m = document.getElementById(id); if (m) m.classList.add('hidden'); }

/* ── Folder modal ───────────────────────────────────────── */

// Buka modal buat folder baru: reset input nama, lalu fokus ke input nama.
// Tidak ada lagi pemilihan akses user manual — akses folder sepenuhnya mengikuti AD group/departemen.
function openFolderModal() {
  const inp = document.getElementById('folderNameInput');
  if (inp) inp.value = '';
  openModal('folderModal');
  setTimeout(() => { if (inp) inp.focus(); }, 80);
}

// Tutup/sembunyikan modal buat folder
function closeFolderModal() { closeModal('folderModal'); }

// Validasi nama folder wajib diisi, lalu kirim request create folder ke server
function submitFolder() {
  const name  = (document.getElementById('folderNameInput')?.value || '').trim();
  const token = getCsrf('folderCsrfToken');
  if (!name) { showToast('Nama folder wajib diisi.', 'error'); return; }

  const body = new FormData();
  body.append('action', 'create');
  body.append('name', name);
  body.append('csrf_token', token);

  apiFetch(BASE + '/controllers/FolderController.php', { method: 'POST', body })
    .then(r => r.json())
    .then(res => {
      showToast(res.message, res.success ? 'success' : 'error');
      if (res.success) { closeFolderModal(); setTimeout(() => location.reload(), 800); }
    })
    .catch(() => showToast('Gagal membuat folder.', 'error'));
}

/* ── Folder delete modal ────────────────────────────────── */

// Buka modal konfirmasi hapus folder. Kalau folder masih berisi file, tampilkan peringatan & disable tombol konfirmasi
function confirmDeleteFolder(folderId, folderName, fileCount) {
  document.getElementById('deleteFolderId').value         = folderId;
  document.getElementById('deleteFolderName').textContent = `"${folderName}"`;

  const warn = document.getElementById('deleteFolderWarning');
  if (fileCount > 0) {
    warn.textContent = `Folder ini berisi ${fileCount} file. Hapus atau pindahkan file terlebih dahulu.`;
    warn.classList.remove('hidden');
    document.getElementById('deleteFolderConfirmBtn').disabled = true;
  } else {
    warn.classList.add('hidden');
    document.getElementById('deleteFolderConfirmBtn').disabled = false;
  }

  openModal('folderDeleteModal');
}

// Tutup/sembunyikan modal konfirmasi hapus folder
function closeDeleteFolderModal() { closeModal('folderDeleteModal'); }

// Kirim request hapus folder ke server
function submitDeleteFolder() {
  const id    = document.getElementById('deleteFolderId')?.value;
  const token = getCsrf('folderCsrfToken');

  const body = new FormData();
  body.append('action', 'delete');
  body.append('id', id);
  body.append('csrf_token', token);

  apiFetch(BASE + '/controllers/FolderController.php', { method: 'POST', body })
    .then(r => r.json())
    .then(res => {
      showToast(res.message, res.success ? 'success' : 'error');
      if (res.success) { closeDeleteFolderModal(); setTimeout(() => location.reload(), 800); }
    })
    .catch(() => showToast('Gagal menghapus folder.', 'error'));
}

/* ── Upload modal ───────────────────────────────────────── */

// Buka modal upload file: reset form, daftar file preview, dan progress bar.
// Tidak ada lagi pemilihan akses user manual — akses file mengikuti folder dept/pemiliknya secara otomatis.
function openUploadModal() {
  const form = document.getElementById('uploadForm');
  if (form) form.reset();
  const fileList = document.getElementById('fileList');
  if (fileList) fileList.innerHTML = '';
  const prog = document.getElementById('uploadProgress');
  if (prog) prog.classList.add('hidden');
  openModal('uploadModal');
}

// Tutup/sembunyikan modal upload
function closeUploadModal() { closeModal('uploadModal'); }

// Tampilkan preview daftar file yang dipilih user di file input (nama file + ukuran dalam KB)
document.addEventListener('DOMContentLoaded', function () {
  const fileInput = document.getElementById('fileInput');
  if (fileInput) {
    fileInput.addEventListener('change', function () {
      const fileList = document.getElementById('fileList');
      if (!fileList) return;
      if (!this.files.length) { fileList.innerHTML = ''; return; }
      fileList.innerHTML = Array.from(this.files).map(f =>
        `<div class="flex items-center gap-2"><span class="text-blue-100/60">📄</span>${f.name} <span class="text-blue-100/40">(${(f.size/1024).toFixed(1)} KB)</span></div>`
      ).join('');
    });
  }
});

// Proses upload file pakai XMLHttpRequest (supaya bisa pantau progress upload secara real-time),
// kirim FormData berisi file ke server, lalu update UI tombol & progress bar sesuai hasil
function startUpload() {
  const form      = document.getElementById('uploadForm');
  const btn       = document.getElementById('uploadBtn');
  const progress  = document.getElementById('uploadProgress');
  const bar       = document.getElementById('progressBar');
  const pct       = document.getElementById('progressPct');
  const fileInput = document.getElementById('fileInput');
  const token     = form?.querySelector('input[name="csrf_token"]')?.value || getCsrf('folderCsrfToken');

  if (!fileInput?.files.length) { showToast('Pilih file terlebih dahulu.', 'error'); return; }

  const formData = new FormData(form);
  formData.set('csrf_token', token);

  btn.disabled    = true;
  btn.textContent = 'Mengunggah...';
  if (progress) progress.classList.remove('hidden');

  const xhr = new XMLHttpRequest();
  xhr.open('POST', BASE + '/controllers/UploadController.php');

  // Update progress bar sesuai persentase upload yang sudah terkirim
  xhr.upload.addEventListener('progress', function (e) {
    if (e.lengthComputable) {
      const p = Math.round((e.loaded / e.total) * 100);
      if (bar) bar.style.width = p + '%';
      if (pct) pct.textContent = p + '%';
    }
  });

  // Saat request selesai: parse respons JSON, tampilkan toast, reload halaman kalau sukses
  xhr.addEventListener('load', function () {
    try {
      const res = JSON.parse(xhr.responseText);
      showToast(res.message, res.success ? 'success' : 'error');
      if (res.success) { closeUploadModal(); setTimeout(() => location.reload(), 800); }
    } catch {
      showToast('Respons tidak valid dari server.', 'error');
    }
    btn.disabled    = false;
    btn.textContent = 'Upload';
  });

  // Tangani error jaringan saat upload (misal koneksi terputus)
  xhr.addEventListener('error', function () {
    showToast('Gagal mengunggah file.', 'error');
    btn.disabled    = false;
    btn.textContent = 'Upload';
  });

  xhr.send(formData);
}

/* ── Rename modal ───────────────────────────────────────── */

// Buka modal rename file, isi input dengan nama file saat ini, lalu fokus ke input
function openRenameModal(fileId, fileName) {
  document.getElementById('renameFileId').value = fileId;
  const inp = document.getElementById('renameInput');
  if (inp) inp.value = fileName;
  openModal('renameModal');
  setTimeout(() => { if (inp) inp.focus(); }, 80);
}

// Tutup/sembunyikan modal rename
function closeRenameModal() { closeModal('renameModal'); }

// Validasi nama baru tidak kosong, lalu kirim request rename ke server (rename juga sinkron ke Samba di backend)
function submitRename() {
  const id    = document.getElementById('renameFileId')?.value;
  const name  = (document.getElementById('renameInput')?.value || '').trim();
  const token = getCsrf('folderCsrfToken');
  if (!name) { showToast('Nama file wajib diisi.', 'error'); return; }

  const body = new FormData();
  body.append('action', 'rename');
  body.append('id', id);
  body.append('name', name);
  body.append('csrf_token', token);

  apiFetch(BASE + '/controllers/FileController.php', { method: 'POST', body })
    .then(r => r.json())
    .then(res => {
      showToast(res.message, res.success ? 'success' : 'error');
      if (res.success) { closeRenameModal(); setTimeout(() => location.reload(), 800); }
    })
    .catch(() => showToast('Gagal rename file.', 'error'));
}

/* ── Move modal ─────────────────────────────────────────── */

// Buka modal pindah file, isi id & nama file, reset pilihan folder tujuan
function openMoveModal(fileId, fileName) {
  document.getElementById('moveFileId').value         = fileId;
  document.getElementById('moveFileName').textContent = fileName;
  const sel = document.getElementById('moveTargetFolder');
  if (sel) sel.value = '';
  openModal('moveModal');
}

// Tutup/sembunyikan modal pindah file
function closeMoveModal() { closeModal('moveModal'); }

// Validasi folder tujuan sudah dipilih, lalu kirim request pindah file ke server (sinkron ke Samba di backend)
function submitMove() {
  const id             = document.getElementById('moveFileId')?.value;
  const targetFolderId = document.getElementById('moveTargetFolder')?.value;
  const token          = getCsrf('folderCsrfToken');
  if (!targetFolderId) { showToast('Pilih folder tujuan.', 'error'); return; }

  const body = new FormData();
  body.append('action', 'move');
  body.append('id', id);
  body.append('target_folder_id', targetFolderId);
  body.append('csrf_token', token);

  apiFetch(BASE + '/controllers/FileController.php', { method: 'POST', body })
    .then(r => r.json())
    .then(res => {
      showToast(res.message, res.success ? 'success' : 'error');
      if (res.success) { closeMoveModal(); setTimeout(() => location.reload(), 800); }
    })
    .catch(() => showToast('Gagal memindahkan file.', 'error'));
}

/* ── Delete file modal ──────────────────────────────────── */

// Buka modal konfirmasi hapus file, isi id & nama file yang akan dihapus
function confirmDelete(fileId, fileName) {
  document.getElementById('deleteFileId').value         = fileId;
  document.getElementById('deleteFileName').textContent = `"${fileName}"`;
  openModal('deleteModal');
}

// Tutup/sembunyikan modal konfirmasi hapus file
function closeDeleteModal() { closeModal('deleteModal'); }

// Kirim request hapus file ke server (file fisik + entri Samba ikut dihapus di backend)
function submitDelete() {
  const id    = document.getElementById('deleteFileId')?.value;
  const token = getCsrf('folderCsrfToken');

  const body = new FormData();
  body.append('action', 'delete');
  body.append('id', id);
  body.append('csrf_token', token);

  apiFetch(BASE + '/controllers/FileController.php', { method: 'POST', body })
    .then(r => r.json())
    .then(res => {
      showToast(res.message, res.success ? 'success' : 'error');
      if (res.success) { closeDeleteModal(); setTimeout(() => location.reload(), 800); }
    })
    .catch(() => showToast('Gagal menghapus file.', 'error'));
}

/* ── Unified File Manage Modal ──────────────────────────── */

// State sementara: nama asli file yang sedang dikelola di modal gabungan (dipakai untuk cek apakah nama berubah)
let _fileManageOriginalName = '';

// Helper: set value sebuah input berdasarkan id elemen
function setValue(id, value) {
  const el = document.getElementById(id);
  if (el) el.value = value;
}

// Helper: set textContent sebuah elemen berdasarkan id
function setText(id, value) {
  const el = document.getElementById(id);
  if (el) el.textContent = value;
}

// Buka modal kelola file (gabungan rename + pindah folder) dan isi semua field sesuai data file yang dipilih
// (dipanggil lewat tombol "Kelola" per file). Tidak ada lagi pengaturan akses user manual di modal ini.
function openFileManageModal(fileId, fileName, currentFolderId) {
  const modal = document.getElementById('fileManageModal');
  if (!modal) {
    console.error('Modal #fileManageModal tidak ditemukan di halaman.');
    if (typeof showToast === 'function') showToast('Modal kelola file tidak ditemukan.', 'error');
    return;
  }

  _fileManageOriginalName = fileName || '';

  setValue('fileManageId', fileId || '');
  setValue('fileManageFolderId', currentFolderId || 0);
  setText('fileManageName', _fileManageOriginalName);

  // Rename — pre-fill nama file. Rename hanya dikirim kalau isinya berubah.
  const renameInp = document.getElementById('fileManageRenameInput');
  if (renameInp) renameInp.value = _fileManageOriginalName;

  // Pindah — kosong artinya tetap di folder saat ini.
  const moveSel = document.getElementById('fileManageMoveTarget');
  if (moveSel) moveSel.value = '';

  openModal('fileManageModal');
  setTimeout(() => { if (renameInp) renameInp.focus(); }, 80);
}

// Tutup/sembunyikan modal kelola file
function closeFileManageModal() { closeModal('fileManageModal'); }

// Proses simpan perubahan dari modal kelola file: rename (kalau nama berubah) dan pindah folder
// (kalau folder tujuan dipilih). Tiap aksi dikirim terpisah secara berurutan (await), status sukses/gagal
// tiap aksi dilacak supaya toast akhir bisa menunjukkan hasil gabungan
// (semua berhasil / sebagian gagal dengan pesan error spesifik per aksi)
async function submitFileManage() {
  const id       = document.getElementById('fileManageId')?.value;
  const token    = getCsrf('folderCsrfToken');
  const newName  = (document.getElementById('fileManageRenameInput')?.value || '').trim();
  const moveTo   = document.getElementById('fileManageMoveTarget')?.value || '';

  if (!id) { showToast('ID file tidak ditemukan.', 'error'); return; }
  if (!token) { showToast('Token keamanan tidak ditemukan. Reload halaman lalu coba lagi.', 'error'); return; }
  if (!newName) { showToast('Nama file tidak boleh kosong.', 'error'); return; }

  let anySuccess = false;
  let hasError = false;
  let anyChangeAttempted = false;

  // 1. Rename hanya jika nama benar-benar berubah.
  if (newName !== _fileManageOriginalName) {
    anyChangeAttempted = true;
    const body = new FormData();
    body.append('action', 'rename');
    body.append('id', id);
    body.append('name', newName);
    body.append('csrf_token', token);
    try {
      const res = await apiFetch(BASE + '/controllers/FileController.php', { method: 'POST', body }).then(r => r.json());
      if (res.success) {
        anySuccess = true;
        _fileManageOriginalName = newName;
      } else {
        hasError = true;
        showToast('Rename: ' + res.message, 'error');
      }
    } catch (e) {
      hasError = true;
      showToast('Gagal rename file.', 'error');
    }
  }

  // 2. Pindah jika folder tujuan dipilih.
  if (moveTo) {
    anyChangeAttempted = true;
    const body = new FormData();
    body.append('action', 'move');
    body.append('id', id);
    body.append('target_folder_id', moveTo);
    body.append('csrf_token', token);
    try {
      const res = await apiFetch(BASE + '/controllers/FileController.php', { method: 'POST', body }).then(r => r.json());
      if (res.success) anySuccess = true;
      else {
        hasError = true;
        showToast('Pindah: ' + res.message, 'error');
      }
    } catch (e) {
      hasError = true;
      showToast('Gagal memindahkan file.', 'error');
    }
  }

  // Tampilkan hasil akhir gabungan: sukses penuh, atau sebagian gagal
  if (anySuccess && !hasError) {
    showToast('Perubahan berhasil disimpan.', 'success');
    closeFileManageModal();
    setTimeout(() => location.reload(), 800);
  } else if (anySuccess) {
    showToast('Sebagian perubahan tersimpan. Cek pesan error yang muncul.', 'info');
  }else if (!hasError && !anyChangeAttempted) {
    showToast('Tidak ada perubahan untuk disimpan.', 'info');
  }
}

// Tutup modal kelola, lalu langsung buka modal konfirmasi hapus dengan data file yang sama
function openDeleteFromManage() {
  const id   = document.getElementById('fileManageId')?.value;
  const name = document.getElementById('fileManageName')?.textContent || 'file ini';
  closeFileManageModal();
  confirmDelete(id, name);
}

// Binding tombol Kelola pakai event delegation supaya tetap jalan meskipun onclick inline gagal/ter-cache.
(function bindFileManageButton() {
  if (window.__fileManageButtonBound) return;
  window.__fileManageButtonBound = true;

  document.addEventListener('click', function (event) {
    const btn = event.target.closest('.js-file-manage-btn');
    if (!btn) return;

    event.preventDefault();
    event.stopPropagation();

    openFileManageModal(
      parseInt(btn.dataset.fileId || '0', 10),
      btn.dataset.fileName || '',
      parseInt(btn.dataset.folderId || '0', 10)
    );
  });
})();

// Ekspos fungsi-fungsi ini ke window supaya tetap bisa dipanggil lewat atribut onclick lama di HTML
window.openFileManageModal  = openFileManageModal;
window.closeFileManageModal = closeFileManageModal;
window.submitFileManage     = submitFileManage;
window.openDeleteFromManage = openDeleteFromManage;