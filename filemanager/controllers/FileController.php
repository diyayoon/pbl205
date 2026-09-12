<?php
require_once __DIR__ . '/../middleware/auth.php';
require_once APP_ROOT . '/models/FileModel.php';
require_once APP_ROOT . '/models/FolderModel.php';
require_once APP_ROOT . '/helpers/SambaHelper.php';
require_once APP_ROOT . '/helpers/DepartmentHelper.php';

requireLogin();
header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method tidak diizinkan.']);
    exit;
}

if (!csrfVerify($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token keamanan tidak valid.']);
    exit;
}

$fileModel   = new FileModel();
$folderModel = new FolderModel();
$user        = currentUser();

// Helper: kirim respons JSON gagal lalu stop eksekusi
function jsonFail(string $message, int $status = 200, array $extra = []): void {
    if ($status !== 200) http_response_code($status);
    echo json_encode(array_merge(['success' => false, 'message' => $message], $extra));
    exit;
}

// Helper: ambil target share+path Samba untuk sebuah folder, error kalau mapping tidak valid
function folderSambaTarget(FolderModel $folderModel, ?array $folder): array {
    if (!$folder) {
        throw new RuntimeException('Folder tidak ditemukan untuk mapping Samba.');
    }
    $target = $folderModel->getSambaTarget($folder);
    if (empty($target['share'])) {
        throw new RuntimeException('Mapping share Samba folder tidak valid.');
    }
    return $target;
}

// Sinkronkan rename file ke Samba (rename file fisik di network share)
function syncSambaRename(FolderModel $folderModel, array $file, string $newName): void {
    $folder = $folderModel->findById((int)($file['folder_id'] ?? 0));
    $target = folderSambaTarget($folderModel, $folder);
    SambaHelper::setShare($target['share']);
    SambaHelper::renameFile((string)$file['original_name'], $newName, (string)$target['path']);
}

// Sinkronkan pindah file ke Samba: kalau masih 1 share langsung move,
// kalau beda share (lintas departemen) download dulu lalu upload ke share baru
function syncSambaMove(FolderModel $folderModel, array $file, array $targetFolder): void {
    $oldFolder = $folderModel->findById((int)($file['folder_id'] ?? 0));
    $oldTarget = folderSambaTarget($folderModel, $oldFolder);
    $newTarget = folderSambaTarget($folderModel, $targetFolder);

    $remoteName = (string)$file['original_name'];

    // Kasus simpel: folder lama & baru masih dalam satu share yang sama
    if ($oldTarget['share'] === $newTarget['share']) {
        SambaHelper::setShare($oldTarget['share']);
        SambaHelper::moveFile($remoteName, $remoteName, (string)$oldTarget['path'], (string)$newTarget['path']);
        return;
    }

    // Move lintas share: download plaintext dari share lama, upload ke share baru, lalu hapus dari share lama.
    $tmp = tempnam(sys_get_temp_dir(), 'samba_move_');
    if ($tmp === false) {
        throw new RuntimeException('Gagal membuat file temporary untuk sinkronisasi Samba.');
    }

    try {
        // Ambil file dari share lama ke temp lokal
        SambaHelper::setShare($oldTarget['share']);
        SambaHelper::downloadFile($remoteName, (string)$oldTarget['path'], $tmp);

        // Push ke share baru
        SambaHelper::setShare($newTarget['share']);
        SambaHelper::pushFile($tmp, $remoteName, (string)$newTarget['path']);

        // Baru hapus versi lama setelah yakin sudah ada di share baru
        SambaHelper::setShare($oldTarget['share']);
        SambaHelper::deleteFile($remoteName, (string)$oldTarget['path'], false);
    } finally {
        // Selalu bersihkan file temp, sukses maupun gagal
        if (is_file($tmp)) {
            @unlink($tmp);
        }
    }
}

// Sinkronkan hapus file ke Samba (hapus file fisik di network share)
function syncSambaDelete(FolderModel $folderModel, array $file): void {
    $folder = $folderModel->findById((int)($file['folder_id'] ?? 0));
    $target = folderSambaTarget($folderModel, $folder);
    SambaHelper::setShare($target['share']);
    // Untuk delete, file yang sudah tidak ada di Samba dianggap aman agar DB bisa dibersihkan.
    SambaHelper::deleteFile((string)$file['original_name'], (string)$target['path'], true);
}

// ── Action: rename — ganti nama file (di DB & Samba) ───────────────────────
if ($action === 'rename') {
    if (!isGeneralAdmin()) {
        jsonFail('Hanya admin yang boleh rename file.', 403);
    }

    $id   = (int)($_POST['id'] ?? 0);
    $rawName = sanitizeFilename(trim($_POST['name'] ?? ''));

    if ($rawName === '') {
        jsonFail('Nama file wajib diisi.');
    }

    $file = $fileModel->findById($id);
    if (!$file) {
        jsonFail('File tidak ditemukan.', 404);
    }

    // Superadmin bebas akses semua folder, selain itu wajib punya hak kelola folder file ini
    if (!isSuperAdmin() && !$folderModel->userCanManage((int)($file['folder_id'] ?? 0), (int)$user['id'])) {
        jsonFail('Kamu hanya bisa rename file di folder departemenmu.', 403);
    }

    // Paksa ekstensi tetap sama dengan file asli, apapun yang diketik user,
    // supaya file tidak kehilangan asosiasi tipe (.pdf, .docx, dll) saat dibuka dari Samba/Explorer.
    $originalExt = fileExt((string)($file['original_name'] ?? ''));
    $newBaseName = pathinfo($rawName, PATHINFO_FILENAME);
    if ($newBaseName === '') {
        jsonFail('Nama file tidak valid.');
    }
    $name = $originalExt !== '' ? $newBaseName . '.' . $originalExt : $newBaseName;

    // Samba jadi source of truth: rename di Samba dulu, baru DB. Kalau Samba gagal, DB gak disentuh.
    try {
        syncSambaRename($folderModel, $file, $name);
        $fileModel->rename($id, $name);
    } catch (Throwable $e) {
        jsonFail('Rename di Samba gagal, jadi database website tidak diubah: ' . $e->getMessage(), 500);
    }

    echo json_encode(['success' => true, 'message' => 'File berhasil direname di website dan Samba.']);
    exit;
}

// ── Action: move — pindahkan file ke folder lain (di DB, fisik lokal, & Samba) ──
if ($action === 'move') {
    if (!isGeneralAdmin()) {
        jsonFail('Hanya admin yang boleh memindahkan file.', 403);
    }

    $id = (int)($_POST['id'] ?? 0);
    $targetFolderId = (int)($_POST['target_folder_id'] ?? 0);

    $file = $fileModel->findById($id);
    if (!$file) {
        jsonFail('File tidak ditemukan.', 404);
    }

    // Cek hak akses folder asal
    if (!isSuperAdmin() && !$folderModel->userCanManage((int)($file['folder_id'] ?? 0), (int)$user['id'])) {
        jsonFail('Kamu hanya bisa memindahkan file di folder departemenmu.', 403);
    }

    $targetFolder = $folderModel->findById($targetFolderId);
    if (!$targetFolder) {
        jsonFail('Folder tujuan tidak ditemukan.', 404);
    }

    // Cek hak akses folder tujuan juga (biar gak bisa lempar file ke folder departemen lain)
    if (!isSuperAdmin() && !$folderModel->userCanManage($targetFolderId, (int)$user['id'])) {
        jsonFail('Folder tujuan bukan folder departemenmu.', 403);
    }

    if ((int)($file['folder_id'] ?? 0) === $targetFolderId) {
        jsonFail('File sudah berada di folder tujuan.');
    }

    // Validasi path fisik file terenkripsi lokal, cegah path traversal keluar dari UPLOAD_PATH
    $oldPath = UPLOAD_PATH . '/' . $file['path'];
    $oldReal = realpath($oldPath);
    $uploadReal = realpath(UPLOAD_PATH);

    if (!$oldReal || !$uploadReal || !str_starts_with($oldReal, $uploadReal) || !is_file($oldReal)) {
        jsonFail('File fisik terenkripsi tidak ditemukan atau path tidak valid.');
    }

    // Siapkan folder tujuan lokal kalau belum ada, sekalian proteksi akses langsung via .htaccess
    $targetDir = UPLOAD_PATH . '/folders/' . $targetFolderId;
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0750, true);
        file_put_contents($targetDir . '/.htaccess', "Options -Indexes\nDeny from all\n");
    }

    // Kalau nama file sudah ada di folder tujuan, tambahkan timestamp biar gak collision
    $targetPath = $targetDir . '/' . $file['filename'];
    if (file_exists($targetPath)) {
        $ext = pathinfo($file['filename'], PATHINFO_EXTENSION);
        $base = pathinfo($file['filename'], PATHINFO_FILENAME);
        $fileName = $base . '-' . time() . ($ext ? '.' . $ext : '');
        $targetPath = $targetDir . '/' . $fileName;
        $newRelativePath = 'folders/' . $targetFolderId . '/' . $fileName;
    } else {
        $newRelativePath = 'folders/' . $targetFolderId . '/' . $file['filename'];
    }

    // Samba dulu, baru file lokal — biar konsisten sama pola action lain (Samba = source of truth)
    try {
        syncSambaMove($folderModel, $file, $targetFolder);
    } catch (Throwable $e) {
        jsonFail('Pindah file di Samba gagal, jadi website tidak diubah: ' . $e->getMessage(), 500);
    }

    if (!rename($oldReal, $targetPath)) {
        jsonFail('Samba sudah dipindahkan, tetapi file terenkripsi lokal gagal dipindahkan. Cek permission folder uploads.', 500);
    }

    chmod($targetPath, 0640);
    $fileModel->updateFolderAndPath($id, $targetFolderId, $newRelativePath);

    echo json_encode(['success' => true, 'message' => 'File berhasil dipindahkan di website dan Samba.']);
    exit;
}

// ── Action: delete — hapus file (di Samba, fisik lokal, & DB) ──────────────
if ($action === 'delete') {
    if (!isGeneralAdmin()) {
        jsonFail('Hanya admin yang boleh hapus file.', 403);
    }

    $id = (int)($_POST['id'] ?? 0);

    $file = $fileModel->findById($id);
    if (!$file) {
        jsonFail('File tidak ditemukan.', 404);
    }

    if (!isSuperAdmin() && !$folderModel->userCanManage((int)($file['folder_id'] ?? 0), (int)$user['id'])) {
        jsonFail('Kamu hanya bisa menghapus file di folder departemenmu.', 403);
    }

    // Hapus di Samba dulu; kalau gagal, DB & file lokal tidak disentuh
    try {
        syncSambaDelete($folderModel, $file);
    } catch (Throwable $e) {
        jsonFail('Hapus file di Samba gagal, jadi database website tidak diubah: ' . $e->getMessage(), 500);
    }

    // Hapus file fisik lokal, dengan validasi path biar gak kehapus file di luar UPLOAD_PATH
    $realPath = realpath(UPLOAD_PATH . '/' . $file['path']);
    $uploadReal = realpath(UPLOAD_PATH);
    if ($realPath && $uploadReal && str_starts_with($realPath, $uploadReal) && file_exists($realPath)) {
        unlink($realPath);
    }

    $fileModel->delete($id);
    echo json_encode(['success' => true, 'message' => 'File berhasil dihapus dari website dan Samba.']);
    exit;
}

jsonFail('Action tidak valid.');