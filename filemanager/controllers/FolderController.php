<?php
require_once __DIR__ . '/../middleware/auth.php';
require_once APP_ROOT . '/models/FolderModel.php';
require_once APP_ROOT . '/models/UserModel.php';
require_once APP_ROOT . '/helpers/SambaHelper.php';
require_once APP_ROOT . '/helpers/DepartmentHelper.php';

requireGeneralAdmin();
header('Content-Type: application/json');

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

$action = $_POST['action'] ?? '';
$user = currentUser();
$folderModel = new FolderModel();

// Guard: pastikan tabel folders sudah di-migrate sebelum dipakai
if (!$folderModel->tableExists()) {
    echo json_encode(['success' => false, 'message' => 'Tabel folders belum ada. Jalankan sql/add_folders.sql dulu.']);
    exit;
}

// ── Action: create — bikin folder baru (khusus superadmin) ─────────────────
if ($action === 'create') {
    if (!isSuperAdmin()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Hanya Superadmin yang boleh membuat folder baru.']);
        exit;
    }
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        echo json_encode(['success' => false, 'message' => 'Nama folder wajib diisi.']);
        exit;
    }
    if (mb_strlen($name) > 100) {
        echo json_encode(['success' => false, 'message' => 'Nama folder maksimal 100 karakter.']);
        exit;
    }

    $id = $folderModel->create($name, (int)$user['id']);
    echo json_encode(['success' => true, 'message' => 'Folder berhasil dibuat.']);
    exit;
}

// ── Action: delete — hapus folder kosong, sinkron ke Samba dulu ────────────
if ($action === 'delete') {
    if (!isSuperAdmin()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Hanya Superadmin yang boleh menghapus folder.']);
        exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    $folder = $folderModel->findById($id);

    if (!$folder) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Folder tidak ditemukan.']);
        exit;
    }

    // Catatan: baris ini secara efektif tidak pernah true karena sudah dicek isSuperAdmin() di atas
    if (!isSuperAdmin() && !$folderModel->userCanManage($id, (int)$user['id'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Kamu hanya bisa menghapus folder di departemenmu.']);
        exit;
    }

    // Folder yang masih ada isinya gak boleh dihapus, harus dikosongin dulu
    $fileCount = $folderModel->countFiles($id);
    if ($fileCount > 0) {
        echo json_encode([
            'success' => false,
            'message' => "Folder masih berisi {$fileCount} file. Hapus atau pindahkan file terlebih dahulu."
        ]);
        exit;
    }

    // Sinkronkan penghapusan folder kosong ke Samba, kecuali folder root share departemen.
    try {
        $sambaTarget = $folderModel->getSambaTarget($folder);
        if (!empty($sambaTarget['share']) && !empty($sambaTarget['path'])) {
            SambaHelper::setShare($sambaTarget['share']);
            SambaHelper::removeDirectory($sambaTarget['path'], true);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Folder di Samba gagal dihapus, jadi database website tidak diubah: ' . $e->getMessage()
        ]);
        exit;
    }

    $folderModel->delete($id);

    // Bersihkan juga folder fisik lokal & .htaccess proteksinya
    $folderPath = UPLOAD_PATH . '/folders/' . $id;
    if (is_dir($folderPath)) {
        @unlink($folderPath . '/.htaccess');
        @rmdir($folderPath);
    }

    echo json_encode(['success' => true, 'message' => 'Folder berhasil dihapus.']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Action tidak valid.']);