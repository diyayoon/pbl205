<?php
/**
 * Upload Controller — AES-256-CBC + RSA + Digital Signature + Samba Push
 */

ob_start(); // buffer output biar warning PHP gak masuk ke JSON

require_once __DIR__ . '/../middleware/auth.php';
require_once APP_ROOT . '/models/FileModel.php';
require_once APP_ROOT . '/models/FolderModel.php';
require_once APP_ROOT . '/models/ActivityModel.php';
require_once APP_ROOT . '/helpers/Crypto.php';
require_once APP_ROOT . '/helpers/SambaHelper.php';
require_once APP_ROOT . '/helpers/DepartmentHelper.php';
require_once APP_ROOT . '/helpers/AuditLogger.php';
require_once APP_ROOT . '/models/UserModel.php';
requireLogin();

header('Content-Type: application/json');

// Validasi CSRF & method
$token = $_POST['csrf_token'] ?? '';
if (!csrfVerify($token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token keamanan tidak valid.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method tidak diizinkan.']);
    exit;
}

$user = currentUser();
$uploaderId = (int)$user['id'];
if (!isGeneralAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Hanya admin yang boleh upload file.']);
    exit;
}

// Validasi folder tujuan
$folderId    = (int)($_POST['folder_id'] ?? 0);
$folderModel = new FolderModel();

if (!$folderModel->tableExists()) {
    echo json_encode(['success' => false, 'message' => 'Tabel folders belum ada.']);
    exit;
}

$folder = $folderModel->findById($folderId);
if (!$folder) {
    echo json_encode(['success' => false, 'message' => 'Folder tidak valid. Buat atau pilih folder dulu.']);
    exit;
}

// Batasi general admin cuma bisa upload ke folder departemennya sendiri
if (!isSuperAdmin() && !$folderModel->userCanManage($folderId, $uploaderId)) {
    echo json_encode(['success' => false, 'message' => 'Kamu hanya bisa mengupload ke folder anggota/departemen yang kamu kelola.']);
    exit;
}

// Ambil/generate RSA keypair uploader
$privateKey = cryptoGetPrivateKey($uploaderId);
$publicKey  = cryptoGetPublicKey($uploaderId);

if (!$privateKey || !$publicKey) {
    cryptoGenerateAndStoreKeypair($uploaderId);
    $privateKey = cryptoGetPrivateKey($uploaderId);
    $publicKey  = cryptoGetPublicKey($uploaderId);
}

// Siapkan folder fisik tujuan
$uploadDir = UPLOAD_PATH . '/folders/' . $folder['id'];
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0750, true);
    file_put_contents($uploadDir . '/.htaccess', "Options -Indexes\nDeny from all\n");
}

if (empty($_FILES['files']['name'][0])) {
    echo json_encode(['success' => false, 'message' => 'Tidak ada file yang dipilih.']);
    exit;
}

$fileModel     = new FileModel();
$activityModel = new ActivityModel();
$results       = [];
$hasError      = false;

// Mapping folder web ke share/folder Samba
$userModel = new UserModel();
$userDepts = $userModel->getUserDepartments($uploaderId);
$fallbackDept = $userDepts[0] ?? ['name' => 'IT'];
$sambaTarget = $folderModel->getSambaTarget($folder, $fallbackDept);
$sambaShare = $sambaTarget['share'];
$sambaSubFolder = $sambaTarget['path'];
SambaHelper::setShare($sambaShare);

$files = $_FILES['files'];
$count = count($files['name']);

// Loop tiap file: validasi lalu enkripsi + simpan + sync Samba
for ($i = 0; $i < $count; $i++) {
    $originalName = $files['name'][$i];
    $tmpPath      = $files['tmp_name'][$i];
    $fileSize     = $files['size'][$i];
    $uploadError  = $files['error'][$i];

    if ($uploadError !== UPLOAD_ERR_OK) {
        $results[] = ['file' => $originalName, 'success' => false, 'message' => 'Gagal upload (error code: ' . $uploadError . ')'];
        $hasError  = true;
        continue;
    }

    if ($fileSize > UPLOAD_MAX_SIZE) {
        $results[] = ['file' => $originalName, 'success' => false, 'message' => 'File terlalu besar (maks. 100MB)'];
        $hasError  = true;
        continue;
    }

    // Blokir ekstensi berbahaya
    $ext = fileExt($originalName);
    if (isDangerous($ext)) {
        $results[] = ['file' => $originalName, 'success' => false, 'message' => 'Tipe file tidak diizinkan'];
        $hasError  = true;
        continue;
    }

    // Cek MIME asli, bukan cuma ekstensi
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($tmpPath);
    if (!in_array($mimeType, ALLOWED_MIME_TYPES, true)) {
        $results[] = ['file' => $originalName, 'success' => false, 'message' => 'MIME type tidak diizinkan: ' . $mimeType];
        $hasError  = true;
        continue;
    }

    // Cek tag PHP tersembunyi di image/text (anti file berbahaya)
    if (str_starts_with($mimeType, 'image/') || $mimeType === 'text/plain') {
        $content = file_get_contents($tmpPath, false, null, 0, 512);
        if (preg_match('/<\?php|<\?=/i', $content)) {
            $results[] = ['file' => $originalName, 'success' => false, 'message' => 'File mengandung konten berbahaya'];
            $hasError  = true;
            continue;
        }
    }

    $safeOriginal = sanitizeFilename($originalName);
    $uuid         = generateUuid();
    $storedName   = $uuid . ($ext ? '.' . $ext : ''); // nama file di disk pakai UUID
    $destPath     = $uploadDir . '/' . $storedName;
    $tempPlainPath = sys_get_temp_dir() . '/samba_' . $uuid; // temp plaintext sebelum push ke Samba
    $fileId = 0;

    try {
        // 1. Baca plaintext
        $plaintext     = file_get_contents($tmpPath);
        $plaintextHash = cryptoHashData($plaintext);

        // 2. Generate DEK & enkripsi file
        $dek            = cryptoGenerateDEK();
        $encrypted      = cryptoEncryptFile($plaintext, $dek);
        $ciphertext     = $encrypted['ciphertext'];
        $iv             = $encrypted['iv'];
        $ciphertextHash = cryptoHashData($ciphertext);

        // 3. Enkripsi DEK pakai RSA public key uploader
        $encryptedDek = cryptoEncryptDEK($dek, $publicKey);

        // 4. Simpan ciphertext ke disk lokal
        file_put_contents($destPath, $ciphertext);
        chmod($destPath, 0640);

        // 5. Simpan record ke DB
        $fileId = $fileModel->create([
            'filename'           => $storedName,
            'original_name'      => $safeOriginal,
            'path'               => 'folders/' . $folder['id'] . '/' . $storedName,
            'mime_type'          => $mimeType,
            'size'               => $fileSize,
            'folder_id'          => $folder['id'],
            'division'           => $sambaShare,
            'uploaded_by'        => $uploaderId,
            'encrypted_dek'      => $encryptedDek,
            'enc_iv'             => base64_encode($iv),
            'plaintext_hash'     => $plaintextHash,
            'ciphertext_hash'    => $ciphertextHash,
            'encryption_version' => 'AES-256-CBC',
            'is_encrypted'       => 1,
        ]);

        // 6. Digital signature (bukti keaslian pengunggah)
        $signature = cryptoSign($ciphertextHash, $privateKey);
        $db        = Database::getInstance();
        $sigStmt   = $db->prepare("
            INSERT INTO file_signatures (file_id, signer_user_id, signature, signed_hash)
            VALUES (?, ?, ?, ?)
        ");
        $sigStmt->execute([$fileId, $uploaderId, $signature, $ciphertextHash]);

        // 7. Dekripsi ulang buat push plaintext ke Samba
        $decryptedDek  = cryptoDecryptDEK($encryptedDek, $privateKey);
        $ivRaw         = base64_decode(base64_encode($iv));
        $plainDecrypted = cryptoDecryptFile($ciphertext, $decryptedDek, $ivRaw);

        // Verifikasi integritas sebelum push
        if (cryptoHashData($plainDecrypted) !== $plaintextHash) {
            throw new RuntimeException('Verifikasi integritas gagal — file tidak dikirim ke Samba.');
        }

        file_put_contents($tempPlainPath, $plainDecrypted);
        chmod($tempPlainPath, 0600);

        // Push ke Samba pakai nama asli
        SambaHelper::pushFile($tempPlainPath, $safeOriginal, $sambaSubFolder);

        // 8. Hapus temp plaintext
        if (file_exists($tempPlainPath)) {
            unlink($tempPlainPath);
        }

        // 9. Audit log
        $activityModel->log(
            $uploaderId,
            'upload',
            $user['username'] . ' mengupload file "' . $safeOriginal . '" ke folder "' . $folder['name'] . '"'
        );

        AuditLogger::log(
            "UPLOAD_FILE",
            "SUCCESS",
            [
                "filename" => $safeOriginal,
                "folder" => $folder['name'],
                "department" => $sambaShare,
                "size" => $fileSize
            ]
        );

        $results[] = [
            'file'    => $safeOriginal,
            'success' => true,
            'size'    => formatSize($fileSize),
            'id'      => $fileId,
        ];

    } catch (Throwable $e) {
        // Rollback: bersihkan file lokal, temp, dan record DB kalau ada error
        if (file_exists($destPath)) unlink($destPath);
        if (file_exists($tempPlainPath)) unlink($tempPlainPath);
        if (!empty($fileId)) {
            try { $fileModel->delete((int)$fileId); } catch (Throwable $cleanupError) {}
        }

        AuditLogger::log(
            "UPLOAD_FILE",
            "FAILED",
            [
                "filename" => $originalName,
                "reason" => $e->getMessage()
            ]
        );

        $results[] = [
            'file'    => $originalName,
            'success' => false,
            'message' => 'Gagal memproses file: ' . $e->getMessage(),
        ];
        $hasError = true;
    }
}

// Ringkasan hasil upload
ob_end_clean();
echo json_encode([
    'success'  => !$hasError || count(array_filter($results, fn($r) => $r['success'])) > 0,
    'results'  => $results,
    'uploaded' => count(array_filter($results, fn($r) => $r['success'])),
    'failed'   => count(array_filter($results, fn($r) => !$r['success'])),
    'message'  => count(array_filter($results, fn($r) => $r['success'])) . ' file berhasil diunggah.',
]);