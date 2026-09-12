<?php

require_once APP_ROOT . '/helpers/DepartmentHelper.php';

class FolderModel {
    private PDO $db;
    private array $columnCache = [];

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // Cek apakah tabel 'folders' sudah ada di database
    public function tableExists(): bool {
        try {
            $this->db->query("SELECT 1 FROM folders LIMIT 1");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    // Cek apakah suatu kolom ada di tabel folders (dengan cache supaya tidak query SHOW COLUMNS berulang)
    private function columnExists(string $column): bool {
        if (array_key_exists($column, $this->columnCache)) {
            return $this->columnCache[$column];
        }

        try {
            $col = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
            $stmt = $this->db->query("SHOW COLUMNS FROM folders LIKE '{$col}'");
            $this->columnCache[$column] = (bool)$stmt->fetch();
        } catch (Throwable $e) {
            $this->columnCache[$column] = false;
        }

        return $this->columnCache[$column];
    }

    // Cek apakah skema database sudah mendukung kolom mapping departemen/owner/Samba (versi migration baru)
    public function supportsDepartmentMapping(): bool {
        return $this->columnExists('department_id')
            && $this->columnExists('owner_user_id')
            && $this->columnExists('folder_type')
            && $this->columnExists('samba_share')
            && $this->columnExists('samba_path');
    }

    // Buat folder baru, kolom opsional (department_id, owner_user_id, dll) hanya diisi kalau kolomnya tersedia di DB
    public function create(string $name, int $createdBy, array $meta = []): int {
        $slug = $this->uniqueSlug($name);

        $columns = ['name', 'slug', 'created_by'];
        $params  = [':name', ':slug', ':created_by'];
        $values  = [
            ':name'       => $name,
            ':slug'       => $slug,
            ':created_by' => $createdBy,
        ];

        $optional = [
            'department_id' => ':department_id',
            'owner_user_id' => ':owner_user_id',
            'folder_type'   => ':folder_type',
            'samba_share'   => ':samba_share',
            'samba_path'    => ':samba_path',
        ];

        foreach ($optional as $column => $param) {
            if ($this->columnExists($column) && array_key_exists($column, $meta)) {
                $columns[] = $column;
                $params[]  = $param;
                $values[$param] = $meta[$column];
            }
        }

        $sql = "INSERT INTO folders (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $params) . ")";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);
        return (int)$this->db->lastInsertId();
    }

    // Pastikan folder pribadi member ada: kalau sudah ada pakai itu, kalau belum buat baru.
    // Akses folder ini otomatis lewat owner_user_id, tidak perlu setAccess manual lagi.
    public function ensureMemberFolder(int $userId, string $username, int $departmentId, string $departmentName, int $createdBy): int {
        $folderName = memberSambaFolderName($username);
        $share      = normalizeDepartmentShare($departmentName);
        $path       = $folderName;

        if ($this->supportsDepartmentMapping()) {
            $find = $this->db->prepare("\n                SELECT id FROM folders\n                WHERE folder_type = 'member'\n                  AND owner_user_id = ?\n                  AND department_id = ?\n                LIMIT 1\n            ");
            $find->execute([$userId, $departmentId]);
            $existingId = (int)($find->fetchColumn() ?: 0);
            if ($existingId > 0) {
                return $existingId;
            }

            return $this->create($folderName, $createdBy, [
                'department_id' => $departmentId,
                'owner_user_id' => $userId,
                'folder_type'   => 'member',
                'samba_share'   => $share,
                'samba_path'    => $path,
            ]);
        }

        // Fallback untuk database lama yang belum menjalankan migration.
        $find = $this->db->prepare("SELECT id FROM folders WHERE name = ? LIMIT 1");
        $find->execute([$folderName]);
        $existingId = (int)($find->fetchColumn() ?: 0);
        if ($existingId > 0) {
            return $existingId;
        }

        return $this->create($folderName, $createdBy);
    }

    // Ambil semua folder beserta jumlah file & total ukurannya (dipakai superadmin)
    public function getAll(): array {
        if (!$this->tableExists()) return [];
        $stmt = $this->db->query("\n            SELECT fo.*, u.username AS creator_name,\n                   COUNT(fi.id) AS file_count,\n                   COALESCE(SUM(fi.size), 0) AS total_size\n            FROM folders fo\n            LEFT JOIN users u ON u.id = fo.created_by\n            LEFT JOIN files fi ON fi.folder_id = fo.id\n            GROUP BY fo.id\n            ORDER BY fo.created_at DESC\n        ");
        return $stmt->fetchAll();
    }

    // Ambil daftar folder yang boleh dilihat user ini.
    // Admin: superadmin lihat semua, admin divisi lihat folder departemen yang dia kelola.
    // Member: hanya folder yang dia buat sendiri ATAU folder miliknya (owner_user_id) - tanpa tabel akses manual lagi.
    public function getAllForUser(int $userId, bool $isAdmin): array {
        if (!$this->tableExists()) return [];

        if ($isAdmin) {
            if ($this->isSuperAdminUser($userId)) {
                return $this->getAll();
            }
            return $this->getManagedFoldersForAdmin($userId);
        }

        $ownerCondition = $this->columnExists('owner_user_id') ? " OR fo.owner_user_id = :uid_owner" : "";
        $stmt = $this->db->prepare("\n            SELECT fo.*, u.username AS creator_name,\n                   (\n                     SELECT COUNT(*) FROM files f WHERE f.folder_id = fo.id\n                   ) AS file_count,\n                   (\n                     SELECT COALESCE(SUM(f2.size), 0) FROM files f2 WHERE f2.folder_id = fo.id\n                   ) AS total_size\n            FROM folders fo\n            LEFT JOIN users u ON u.id = fo.created_by\n            WHERE fo.created_by = :uid_creator\n               {$ownerCondition}\n            ORDER BY fo.created_at DESC\n        ");
        $stmt->bindValue(':uid_creator', $userId, PDO::PARAM_INT);
        if ($this->columnExists('owner_user_id')) {
            $stmt->bindValue(':uid_owner', $userId, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // Ambil folder-folder yang berada di departemen yang dikelola admin (general_admin)
    public function getManagedFoldersForAdmin(int $adminUserId): array {
        $adminDeptIds = $this->getAdminDepartmentIds($adminUserId);
        if (empty($adminDeptIds)) return [];

        if ($this->columnExists('department_id')) {
            $placeholders = implode(',', array_fill(0, count($adminDeptIds), '?'));
            $sql = "\n                SELECT fo.*, u.username AS creator_name,\n                       COUNT(fi.id) AS file_count,\n                       COALESCE(SUM(fi.size), 0) AS total_size\n                FROM folders fo\n                LEFT JOIN users u ON u.id = fo.created_by\n                LEFT JOIN files fi ON fi.folder_id = fo.id\n                WHERE fo.department_id IN ($placeholders)\n                   OR fo.created_by = ?\n                GROUP BY fo.id\n                ORDER BY fo.created_at DESC\n            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([...$adminDeptIds, $adminUserId]);
            return $stmt->fetchAll();
        }

        // Fallback database lama: folder departemen dikenali dari nama folder.
        $deptNames = $this->getAdminDepartmentNames($adminUserId);
        if (empty($deptNames)) return [];
        $placeholders = implode(',', array_fill(0, count($deptNames), '?'));
        $sql = "\n            SELECT fo.*, u.username AS creator_name,\n                   COUNT(fi.id) AS file_count,\n                   COALESCE(SUM(fi.size), 0) AS total_size\n            FROM folders fo\n            LEFT JOIN users u ON u.id = fo.created_by\n            LEFT JOIN files fi ON fi.folder_id = fo.id\n            WHERE LOWER(fo.name) IN ($placeholders)\n               OR fo.created_by = ?\n            GROUP BY fo.id\n            ORDER BY fo.created_at DESC\n        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([...array_map(fn($n) => strtolower(trim($n)), $deptNames), $adminUserId]);
        return $stmt->fetchAll();
    }

    // Ambil beberapa folder terbaru (dipakai misal di dashboard)
    public function getRecent(int $limit = 5): array {
        if (!$this->tableExists()) return [];
        $stmt = $this->db->prepare("\n            SELECT fo.*, u.username AS creator_name, COUNT(fi.id) AS file_count, COALESCE(SUM(fi.size), 0) AS total_size\n            FROM folders fo\n            LEFT JOIN users u ON u.id = fo.created_by\n            LEFT JOIN files fi ON fi.folder_id = fo.id\n            GROUP BY fo.id\n            ORDER BY fo.created_at DESC\n            LIMIT :limit\n        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // Hitung total semua folder di sistem
    public function countAll(): int {
        if (!$this->tableExists()) return 0;
        return (int)$this->db->query("SELECT COUNT(*) FROM folders")->fetchColumn();
    }

    public function findById(int $id): ?array {
        if (!$this->tableExists()) return null;
        $stmt = $this->db->prepare("SELECT * FROM folders WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    // Hitung jumlah file di dalam satu folder
    public function countFiles(int $folderId): int {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM files WHERE folder_id = ?");
        $stmt->execute([$folderId]);
        return (int)$stmt->fetchColumn();
    }

    // Hapus folder berdasarkan id (tabel akses manual sudah tidak ada, jadi langsung hapus row folder)
    public function delete(int $id): bool {
        $stmt = $this->db->prepare("DELETE FROM folders WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Hapus semua folder milik user (owner_user_id = userId), termasuk sinkronisasi
     * ke Samba (hapus folder fisik + isinya) dan folder lokal di uploads/folders/<id>.
     * Dipanggil saat user di-delete supaya foldernya ikut bersih di semua tempat,
     * bukan cuma di database.
     *
     * @return array{count:int, warnings:string[]} jumlah folder yang dihapus + pesan
     *         warning kalau ada folder yang gagal dihapus dari Samba/lokal (DB tetap
     *         dibersihkan walau warning muncul, supaya web tidak nyangkut data folder
     *         milik user yang sudah dihapus dari AD).
     */
    public function deleteByOwnerUser(int $userId): array {
        if ($this->columnExists('owner_user_id')) {
            $stmt = $this->db->prepare(
                "SELECT * FROM folders WHERE owner_user_id = ? OR (folder_type = 'member' AND created_by = ?)"
            );
            $stmt->execute([$userId, $userId]);
        } else {
            // Fallback DB lama: hapus folder yang dibuat oleh user ini
            $stmt = $this->db->prepare("SELECT * FROM folders WHERE created_by = ?");
            $stmt->execute([$userId]);
        }
        $folders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($folders)) return ['count' => 0, 'warnings' => []];

        $warnings = [];

        // Hapus folder fisik di Samba & lokal dulu, sebelum baris DB-nya dihapus.
        require_once APP_ROOT . '/helpers/SambaHelper.php';
        foreach ($folders as $folder) {
            try {
                $target = $this->getSambaTarget($folder);
                if (!empty($target['share']) && !empty($target['path'])) {
                    SambaHelper::setShare($target['share']);
                    SambaHelper::removeDirectoryRecursive($target['path'], true);
                }
            } catch (Throwable $e) {
                $warnings[] = "Folder Samba '{$folder['name']}' gagal dihapus: " . $e->getMessage();
            }

            // Hapus folder lokal di uploads/folders/<id> beserta isinya
            $localPath = UPLOAD_PATH . '/folders/' . $folder['id'];
            if (is_dir($localPath)) {
                $items = array_diff(scandir($localPath), ['.', '..']);
                foreach ($items as $item) {
                    @unlink($localPath . '/' . $item);
                }
                @rmdir($localPath);
            }
        }

        $folderIds = array_map(fn($f) => (int)$f['id'], $folders);
        $ph = implode(',', array_fill(0, count($folderIds), '?'));

        // Hapus signature file di dalam folder jika tabelnya ada
        try {
            $this->db->prepare(
                "DELETE fs FROM file_signatures fs
                 JOIN files f ON f.id = fs.file_id
                 WHERE f.folder_id IN ($ph)"
            )->execute($folderIds);
        } catch (Throwable $e) {}

        // Hapus files di dalam folder
        $this->db->prepare("DELETE FROM files WHERE folder_id IN ($ph)")->execute($folderIds);

        // Hapus foldernya sendiri
        $this->db->prepare("DELETE FROM folders WHERE id IN ($ph)")->execute($folderIds);

        return ['count' => count($folderIds), 'warnings' => $warnings];
    }


    // Cek apakah user boleh akses folder ini: superadmin selalu boleh, admin dicek lewat userCanManage,
    // member biasa dicek lewat kepemilikan (created_by / owner_user_id) saja
    public function userCanAccess(int $folderId, int $userId, bool $isAdmin = false): bool {
        if ($this->isSuperAdminUser($userId)) return true;
        if ($isAdmin) return $this->userCanManage($folderId, $userId);

        $ownerCondition = $this->columnExists('owner_user_id') ? " OR fo.owner_user_id = :uid_owner" : "";
        $stmt = $this->db->prepare("\n            SELECT COUNT(*)\n            FROM folders fo\n            WHERE fo.id = :folder_id\n              AND (\n                fo.created_by = :uid_creator\n                {$ownerCondition}\n              )\n        ");
        $params = [
            ':folder_id' => $folderId,
            ':uid_creator' => $userId,
        ];
        if ($this->columnExists('owner_user_id')) {
            $params[':uid_owner'] = $userId;
        }
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    // Cek apakah user (admin) boleh mengelola folder ini: superadmin selalu boleh,
    // pembuat folder boleh, atau admin divisi yang departemennya match dengan folder
    public function userCanManage(int $folderId, int $userId): bool {
        if ($this->isSuperAdminUser($userId)) return true;

        $folder = $this->findById($folderId);
        if (!$folder) return false;
        if ((int)($folder['created_by'] ?? 0) === $userId) return true;

        $adminDeptIds = $this->getAdminDepartmentIds($userId);
        if (empty($adminDeptIds)) return false;

        if ($this->columnExists('department_id') && !empty($folder['department_id'])) {
            return in_array((int)$folder['department_id'], $adminDeptIds, true);
        }

        $deptNames = array_map(fn($n) => strtolower(trim($n)), $this->getAdminDepartmentNames($userId));
        return in_array(strtolower(trim((string)($folder['name'] ?? ''))), $deptNames, true);
    }

    // Tentukan target share & path Samba untuk sebuah folder (untuk sinkronisasi rename/move/delete fisik)
    public function getSambaTarget(array $folder, ?array $fallbackDepartment = null): array {
        $share = '';
        $path  = '';

        if (!empty($folder['samba_share'])) {
            $share = normalizeDepartmentShare($folder['samba_share']);
        }
        if (!empty($folder['samba_path'])) {
            $path = sanitizeSambaPathSegment((string)$folder['samba_path']);
        }

        if ($share === '' && !empty($folder['department_id'])) {
            $deptName = $this->getDepartmentNameById((int)$folder['department_id']);
            $share = normalizeDepartmentShare($deptName);
        }

        if ($share === '' && $fallbackDepartment) {
            $share = normalizeDepartmentShare($fallbackDepartment['name'] ?? 'IT');
        }

        if ($share === '') {
            $share = normalizeDepartmentShare($folder['name'] ?? 'IT');
        }

        // Folder lama yang namanya sama seperti share departemen dikirim ke root share.
        // Folder member baru memakai samba_path=username.
        if ($path === '') {
            $folderNameAsShare = normalizeDepartmentShare($folder['name'] ?? '');
            $path = ($folderNameAsShare === $share) ? '' : sanitizeSambaPathSegment((string)($folder['name'] ?? ''));
        }

        return ['share' => $share, 'path' => $path];
    }

    // Cek apakah slug folder sudah dipakai (untuk memastikan slug unik)
    public function slugExists(string $slug): bool {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM folders WHERE slug = ?");
        $stmt->execute([$slug]);
        return (int)$stmt->fetchColumn() > 0;
    }

    // Generate slug unik dari nama folder (tambahkan -2, -3, dst kalau sudah dipakai)
    private function uniqueSlug(string $name): string {
        $base = strtolower(trim($name));
        $base = preg_replace('/[^a-z0-9]+/i', '-', $base);
        $base = trim($base, '-');
        if ($base === '') $base = 'folder';
        $base = substr($base, 0, 80);

        $slug = $base;
        $i = 2;
        while ($this->slugExists($slug)) {
            $slug = $base . '-' . $i;
            $i++;
        }
        return $slug;
    }

    // Cek apakah user ini superadmin
    private function isSuperAdminUser(int $userId): bool {
        $stmt = $this->db->prepare("SELECT system_role FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        return ($stmt->fetchColumn() ?: '') === 'superadmin';
    }

    // Ambil id-id departemen yang diadmin-i oleh user ini
    private function getAdminDepartmentIds(int $userId): array {
        $stmt = $this->db->prepare("\n            SELECT department_id\n            FROM user_departments\n            WHERE user_id = ? AND dept_role = 'admin'\n        ");
        $stmt->execute([$userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    // Ambil nama-nama departemen yang diadmin-i oleh user ini
    private function getAdminDepartmentNames(int $userId): array {
        $stmt = $this->db->prepare("\n            SELECT d.name\n            FROM user_departments ud\n            JOIN departments d ON d.id = ud.department_id\n            WHERE ud.user_id = ? AND ud.dept_role = 'admin'\n        ");
        $stmt->execute([$userId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    // Ambil nama departemen berdasarkan id-nya
    private function getDepartmentNameById(int $departmentId): string {
        $stmt = $this->db->prepare("SELECT name FROM departments WHERE id = ? LIMIT 1");
        $stmt->execute([$departmentId]);
        return (string)($stmt->fetchColumn() ?: 'IT');
    }
}