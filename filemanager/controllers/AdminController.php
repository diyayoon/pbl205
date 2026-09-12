<?php
require_once __DIR__ . '/../middleware/auth.php';
require_once APP_ROOT . '/models/UserModel.php';
require_once APP_ROOT . '/models/FolderModel.php';
require_once APP_ROOT . '/helpers/Crypto.php';
require_once APP_ROOT . '/helpers/AdWinrm.php';
require_once APP_ROOT . '/helpers/DepartmentHelper.php';

requireGeneralAdmin(); // hanya general_admin/superadmin yang boleh akses
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

$action             = $_POST['action'] ?? '';
$userModel          = new UserModel();
$current            = currentUser();
$callerIsSuperAdmin = ($current['system_role'] ?? '') === 'superadmin';

// Departemen yang di-admin caller (dipakai buat batasi akses general_admin)
$callerAdminDepts   = $callerIsSuperAdmin ? [] : $userModel->getAdminDepartments((int)$current['id']);
$callerAdminDeptIds = array_column($callerAdminDepts, 'id');

// Validasi & batasi system_role yang boleh dipilih sesuai hak akses caller
$normalizeSystemRole = function(string $role) use ($callerIsSuperAdmin): ?string {
    $role = trim($role);
    if ($callerIsSuperAdmin) {
        return in_array($role, ['superadmin','general_admin','member'], true) ? $role : null;
    }
    return $role === 'member' ? 'member' : null; // general_admin cuma boleh bikin/set member
};

// Parse dept_ids[] + dept_roles[] dari form, sekaligus batasi ke dept yang caller kelola
$parseDeptInput = function() use ($callerIsSuperAdmin, $callerAdminDeptIds): array {
    $ids   = array_map('intval', (array)($_POST['dept_ids']   ?? []));
    $roles = (array)($_POST['dept_roles'] ?? []);
    $result = [];
    foreach ($ids as $i => $deptId) {
        if ($deptId <= 0) continue;
        $role = $roles[$i] ?? 'member';
        if (!$callerIsSuperAdmin) {
            if (!in_array($deptId, $callerAdminDeptIds)) continue; // skip dept di luar wewenang caller
            $role = 'member'; // general_admin gak bisa assign role admin ke orang lain
        }
        $result[] = ['department_id' => $deptId, 'dept_role' => $role];
    }
    return $result;
};

// ─── CREATE USER: validasi input, provisioning ke AD, lalu simpan user lokal ──
if ($action === 'create_user') {
    $username   = trim($_POST['username'] ?? '');
    $nim        = trim($_POST['nim'] ?? '');
    $password   = (string)($_POST['password'] ?? '');
    $systemRole = $normalizeSystemRole($_POST['system_role'] ?? 'member');
    $teamRole   = trim($_POST['team_role'] ?? 'Anggota') ?: 'Anggota';
    $jobdesk    = trim($_POST['jobdesk'] ?? '-') ?: '-';

    // Validasi field wajib & role
    if ($username === '' || $nim === '' || $password === '') {
        echo json_encode(['success' => false, 'message' => 'Username, NIM, dan password wajib diisi.']);
        exit;
    }
    if (!$systemRole) {
        echo json_encode(['success' => false, 'message' => 'System role tidak valid atau tidak diizinkan.']);
        exit;
    }
    if ($systemRole === 'superadmin') {
        echo json_encode(['success' => false, 'message' => 'Pembuatan superadmin baru tidak diizinkan dari form ini.']);
        exit;
    }

    // Validasi format username, NIM, password
    if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
        echo json_encode(['success' => false, 'message' => 'Username minimal 3 karakter (huruf, angka, titik, underscore, strip).']);
        exit;
    }
    if (!preg_match('/^[0-9]{6,20}$/', $nim)) {
        echo json_encode(['success' => false, 'message' => 'NIM harus angka 6-20 digit.']);
        exit;
    }
    if (strlen($password) < 8) {
        echo json_encode(['success' => false, 'message' => 'Password minimal 8 karakter agar sesuai policy Active Directory.']);
        exit;
    }

    // Cek duplikat
    if ($userModel->usernameExists($username)) {
        echo json_encode(['success' => false, 'message' => 'Username sudah digunakan.']);
        exit;
    }
    if ($userModel->nimExists($nim)) {
        echo json_encode(['success' => false, 'message' => 'NIM sudah digunakan.']);
        exit;
    }

    $depts = $parseDeptInput();

    if (empty($depts)) {
        echo json_encode(['success' => false, 'message' => 'Pilih minimal satu departemen.']);
        exit;
    }

    // Integrasi AD/GPO versi ini cuma support 1 departemen utama per user
    if (count($depts) > 1) {
        echo json_encode(['success' => false, 'message' => 'Untuk integrasi Active Directory, pilih satu departemen utama saja.']);
        exit;
    }

    $selectedDeptId   = (int)$depts[0]['department_id'];
    $selectedDeptRole = $depts[0]['dept_role'] ?? 'member';

    // Cocokkan system_role dengan role di departemen (admin divisi harus role admin, dst)
    if ($systemRole === 'general_admin' && $selectedDeptRole !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Untuk membuat Admin Divisi, role pada departemen harus Admin.']);
        exit;
    }
    if ($systemRole === 'member' && $selectedDeptRole !== 'member') {
        echo json_encode(['success' => false, 'message' => 'Untuk membuat Anggota, role pada departemen harus Member/Anggota.']);
        exit;
    }

    // Ambil nama dept & validasi dept dipilih valid + diizinkan buat caller
    $availableDepts = $callerIsSuperAdmin ? $userModel->getAllDepartments() : $callerAdminDepts;
    $deptNameById = [];
    foreach ($availableDepts as $dept) {
        $deptNameById[(int)$dept['id']] = $dept['name'];
    }
    if (!isset($deptNameById[$selectedDeptId])) {
        echo json_encode(['success' => false, 'message' => 'Departemen tidak valid atau tidak diizinkan.']);
        exit;
    }

    // Siapkan payload buat provisioning ke Active Directory via WinRM
    $selectedDeptName = $deptNameById[$selectedDeptId];
    $adDivision = normalizeDepartmentShare($selectedDeptName);
    $adNewUserRole = ($selectedDeptRole === 'admin') ? 'admin_divisi' : 'anggota';
    $adRequesterRole = $callerIsSuperAdmin ? 'superadmin' : 'admin_divisi';
    $adRequesterDivision = $callerIsSuperAdmin ? '' : $adDivision;
    $fullName = $username . ' - ' . $nim;

    $adPayload = [
        'username' => $username,
        'full_name' => $fullName,
        'password' => $password,
        'new_user_role' => $adNewUserRole,
        'new_user_division' => $adDivision,
        'requester_role' => $adRequesterRole,
        'requester_division' => $adRequesterDivision,
        // Group dan share utama departemen dianggap sudah ada di DC/Samba.
        // Untuk anggota, PowerShell hanya membuat folder personal di dalam share existing.
        'provision_member_folder' => $adNewUserRole === 'anggota',
        'member_folder_name' => memberSambaFolderName($username),
        'division_share' => $adDivision,
    ];

    // User dibuat di AD dulu; kalau AD gagal, user lokal tidak dibuat
    $adResult = createAdUserViaWinRM($adPayload);

    $adSuccess = $adResult['success'] ?? false;
    $adSkipped = $adResult['skipped'] ?? false;

    // Toleransi kalau error-nya "user udah ada di AD" — dianggap bukan fatal error
    $adAlreadyExists = false;
    if (!$adSuccess && !$adSkipped) {
        $adMsg = strtolower($adResult['message'] ?? '');
        $adAlreadyExists = str_contains($adMsg, 'already exists')
            || str_contains($adMsg, 'sudah ada')
            || str_contains($adMsg, 'account restriction');
    }

    if (!$adSuccess && !$adSkipped && !$adAlreadyExists) {
        echo json_encode([
            'success' => false,
            'message' => 'User gagal dibuat di Active Directory: ' . ($adResult['message'] ?? 'Unknown error'),
            'ad_error' => $adResult,
        ]);
        exit;
    }

    // AD berhasil (atau di-skip/sudah ada) → lanjut simpan ke database lokal
    try {
        $newId = $userModel->create([
            'username'    => $username,
            'nim'         => $nim,
            'password'    => password_hash($password, PASSWORD_DEFAULT),
            'system_role' => $systemRole,
            'team_role'   => $teamRole,
            'jobdesk'     => $jobdesk,
        ]);

        $userModel->setUserDepartments($newId, $depts);

        // Kalau member, siapkan folder personal-nya di tabel folder lokal
        $memberFolderId = null;
        if ($systemRole === 'member') {
            $folderModel = new FolderModel();
            if ($folderModel->tableExists()) {
                $memberFolderId = $folderModel->ensureMemberFolder(
                    $newId,
                    $username,
                    $selectedDeptId,
                    $selectedDeptName,
                    (int)$current['id']
                );
            }
        }

        // Generate RSA keypair untuk user baru
        cryptoGenerateAndStoreKeypair($newId);

        $message = ($adResult['skipped'] ?? false)
            ? 'User baru berhasil ditambahkan. AD sync masih nonaktif; folder anggota dicatat di database lokal.'
            : 'User baru berhasil ditambahkan, dibuat di Active Directory, dan folder anggota diproses.';

        echo json_encode([
            'success' => true,
            'message' => $message,
            'id' => $newId,
            'member_folder_id' => $memberFolderId,
            'ad' => $adResult,
        ]);
    } catch (Throwable $e) {
        // AD sudah terlanjur dibuat tapi DB lokal gagal — perlu manual sync/cleanup
        echo json_encode([
            'success' => false,
            'message' => 'User berhasil dibuat di AD, tetapi gagal disimpan ke database lokal.',
            'detail' => $e->getMessage(),
        ]);
    }
    exit;
}

// ─── UPDATE USER: cek hak akses caller atas target, lalu update profil & dept ─
if ($action === 'update_user') {
    $id     = (int)($_POST['id'] ?? 0);
    $target = $userModel->findById($id);

    if (!$target) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User tidak ditemukan.']);
        exit;
    }

    $targetRole = $target['system_role'] ?? 'member';

    // Batasi siapa yang boleh edit siapa
    if ($targetRole === 'superadmin' && !$callerIsSuperAdmin) {
        echo json_encode(['success' => false, 'message' => 'Tidak bisa mengubah akun Superadmin.']);
        exit;
    }
    if ($targetRole === 'general_admin' && !$callerIsSuperAdmin) {
        echo json_encode(['success' => false, 'message' => 'Tidak bisa mengubah akun Admin lain.']);
        exit;
    }

    // General admin hanya boleh edit member yang berada di departemennya sendiri
    if (!$callerIsSuperAdmin && $targetRole === 'member') {
        $targetDepts = $userModel->getUserDepartments($id);
        $targetDeptIds = array_column($targetDepts, 'id');
        $overlap = array_intersect(array_map('intval', $targetDeptIds), $callerAdminDeptIds);
        if (empty($overlap)) {
            echo json_encode(['success' => false, 'message' => 'Kamu hanya bisa mengedit member yang berada di departemenmu.']);
            exit;
        }
    }

    $systemRole = $normalizeSystemRole($_POST['system_role'] ?? 'member');
    $teamRole   = trim($_POST['team_role'] ?? 'Anggota') ?: 'Anggota';
    $jobdesk    = trim($_POST['jobdesk'] ?? '-') ?: '-';

    if (!$systemRole) {
        echo json_encode(['success' => false, 'message' => 'System role tidak valid.']);
        exit;
    }

    // Cegah superadmin terakhir diturunkan rolenya
    if ($targetRole === 'superadmin' && $systemRole !== 'superadmin') {
        $stmt = Database::getInstance()->query("SELECT COUNT(*) FROM users WHERE system_role = 'superadmin'");
        if ((int)$stmt->fetchColumn() <= 1) {
            echo json_encode(['success' => false, 'message' => 'Tidak bisa menurunkan Superadmin terakhir.']);
            exit;
        }
    }

    $userModel->updateProfileFields($id, $systemRole, $teamRole, $jobdesk);

    $depts = $parseDeptInput();
    $userModel->setUserDepartments($id, $depts);

    // Pastikan user yang diupdate juga punya keypair
    cryptoGenerateAndStoreKeypair($id);

    // Kalau caller update dirinya sendiri, sync juga data di session
    if ((int)$current['id'] === $id) {
        $_SESSION['user']['system_role'] = $systemRole;
        $_SESSION['user']['team_role']   = $teamRole;
        $_SESSION['user']['jobdesk']     = $jobdesk;
    }

    echo json_encode(['success' => true, 'message' => 'Data user berhasil diperbarui.']);
    exit;
}

// ─── DELETE USER: cek hak akses, hapus dari AD, sinkron folder, lalu DB lokal ─
if ($action === 'delete_user') {
    $id     = (int)($_POST['id'] ?? 0);
    $target = $userModel->findById($id);

    if (!$target) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User tidak ditemukan.']);
        exit;
    }

    $targetRole = $target['system_role'] ?? 'member';

    if ($targetRole === 'superadmin') {
        echo json_encode(['success' => false, 'message' => 'Akun Superadmin tidak dapat dihapus.']);
        exit;
    }
    if (!$callerIsSuperAdmin && $targetRole !== 'member') {
        echo json_encode(['success' => false, 'message' => 'Kamu tidak memiliki izin untuk menghapus akun ini.']);
        exit;
    }
    if (!$callerIsSuperAdmin && $targetRole === 'member') {
        $targetDepts = $userModel->getUserDepartments($id);
        $targetDeptIds = array_column($targetDepts, 'id');
        $overlap = array_intersect(array_map('intval', $targetDeptIds), $callerAdminDeptIds);
        if (empty($overlap)) {
            echo json_encode(['success' => false, 'message' => 'Kamu hanya bisa menghapus member yang berada di departemenmu.']);
            exit;
        }
    }
    if ((int)$current['id'] === $id) {
        echo json_encode(['success' => false, 'message' => 'Tidak bisa menghapus akun sendiri.']);
        exit;
    }

    // Hapus dari AD dulu sebelum data lokal
    $adPayload = [
        'username'       => $target['username'],
        'requester_role' => $callerIsSuperAdmin ? 'superadmin' : 'admin_divisi',
    ];
    $adResult = deleteAdUserViaWinRM($adPayload);

    if (!($adResult['success'] ?? false)) {
        echo json_encode([
            'success'  => false,
            'message'  => 'Gagal menghapus user dari Active Directory: ' . ($adResult['message'] ?? 'Unknown error'),
            'ad_error' => $adResult,
        ]);
        exit;
    }

    // Hapus folder user DULU sebelum user-nya (biar gak ada folder yatim)
    require_once APP_ROOT . '/models/FolderModel.php';
    $folderModel = new FolderModel();
    $cleanup = $folderModel->deleteByOwnerUser($id);

    $userModel->delete($id);

    $message = ($adResult['skipped'] ?? false)
        ? 'User berhasil dihapus. AD sync masih nonaktif.'
        : 'User berhasil dihapus dari sistem dan Active Directory.';

    if (!empty($cleanup['warnings'])) {
        $message .= ' Catatan: ' . implode(' ', $cleanup['warnings']);
    }

    echo json_encode([
        'success' => true,
        'message' => $message,
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Action tidak valid.']);