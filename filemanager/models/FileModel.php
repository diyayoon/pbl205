<?php

if (class_exists('FileModel')) return;

class FileModel {
    private PDO $db;
    private array $columnCache = [];

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // Cek apakah tabel 'files' sudah ada di database
    public function tableExists(): bool {
        try {
            $this->db->query("SELECT 1 FROM files LIMIT 1");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Insert a new file record.
     * Expected keys: filename, original_name, path, mime_type, size,
     *                folder_id, division, uploaded_by,
     *                encrypted_dek, enc_iv, plaintext_hash,
     *                ciphertext_hash, encryption_version, is_encrypted
     */
    public function create(array $data): int {
        $sql = "
            INSERT INTO files (
                filename,
                original_name,
                path,
                mime_type,
                size,
                folder_id,
                division,
                uploaded_by,
                encrypted_dek,
                enc_iv,
                plaintext_hash,
                ciphertext_hash,
                encryption_version,
                is_encrypted
            ) VALUES (
                :filename,
                :original_name,
                :path,
                :mime_type,
                :size,
                :folder_id,
                :division,
                :uploaded_by,
                :encrypted_dek,
                :enc_iv,
                :plaintext_hash,
                :ciphertext_hash,
                :encryption_version,
                :is_encrypted
            )
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':filename'           => $data['filename'],
            ':original_name'      => $data['original_name'],
            ':path'               => $data['path'],
            ':mime_type'          => $data['mime_type'],
            ':size'               => (int)$data['size'],
            ':folder_id'          => (int)$data['folder_id'],
            ':division'           => $data['division'] ?? 'general',
            ':uploaded_by'        => (int)$data['uploaded_by'],
            ':encrypted_dek'      => $data['encrypted_dek'] ?? null,
            ':enc_iv'             => $data['enc_iv'] ?? null,
            ':plaintext_hash'     => $data['plaintext_hash'] ?? null,
            ':ciphertext_hash'    => $data['ciphertext_hash'] ?? null,
            ':encryption_version' => $data['encryption_version'] ?? 'AES-256-CBC',
            ':is_encrypted'       => (int)($data['is_encrypted'] ?? 0),
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Find a single file by ID, joining folder name and uploader username.
     */
    public function findById(int $id): ?array {
        if (!$this->tableExists()) return null;
        $stmt = $this->db->prepare("
            SELECT f.*,
                   fo.name     AS folder_name,
                   u.username  AS uploader_name
            FROM files f
            LEFT JOIN folders fo ON fo.id = f.folder_id
            LEFT JOIN users   u  ON u.id  = f.uploaded_by
            WHERE f.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get a paginated list of files with optional filters.
     */
    public function getFiles(array $filters = [], int $offset = 0, int $limit = PER_PAGE): array {
        if (!$this->tableExists()) return [];

        [$where, $params] = $this->buildWhere($filters);

        $stmt = $this->db->prepare("
            SELECT f.*,
                   fo.name    AS folder_name,
                   u.username AS uploader_name
            FROM files f
            LEFT JOIN folders fo ON fo.id = f.folder_id
            LEFT JOIN users   u  ON u.id  = f.uploaded_by
            {$where}
            ORDER BY f.created_at DESC
            LIMIT :limit OFFSET :offset
        ");

        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Count files matching the same filters as getFiles().
     */
    public function countFiles(array $filters = []): int {
        if (!$this->tableExists()) return 0;

        [$where, $params] = $this->buildWhere($filters);

        $stmt = $this->db->prepare("
            SELECT COUNT(DISTINCT f.id)
            FROM files f
            LEFT JOIN folders fo ON fo.id = f.folder_id
            LEFT JOIN users   u  ON u.id  = f.uploaded_by
            {$where}
        ");

        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * Total storage used.
     */
    // Hitung total storage terpakai: superadmin lihat semua, admin lihat folder yang dia kelola, member lihat punyanya sendiri
    public function getTotalStorage(int $userId, bool $isAdmin): int {
        if (!$this->tableExists()) return 0;

        if ($isAdmin) {
            if ($this->isSuperAdminUser($userId)) {
                return (int) $this->db->query("SELECT COALESCE(SUM(size), 0) FROM files")->fetchColumn();
            }

            [$where, $params] = $this->buildWhere(['managed_by_user_id' => $userId]);
            $stmt = $this->db->prepare("
                SELECT COALESCE(SUM(f.size), 0)
                FROM files f
                LEFT JOIN folders fo ON fo.id = f.folder_id
                {$where}
            ");
            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->execute();
            return (int)$stmt->fetchColumn();
        }

        $stmt = $this->db->prepare("SELECT COALESCE(SUM(size), 0) FROM files WHERE uploaded_by = ?");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Storage breakdown per folder (admin only).
     */
    public function getStorageByFolder(?int $managedByUserId = null): array {
        if (!$this->tableExists()) return [];

        $where = '';
        $params = [];
        if ($managedByUserId !== null && $managedByUserId > 0 && !$this->isSuperAdminUser($managedByUserId)) {
            [$where, $params] = $this->buildWhere(['managed_by_user_id' => $managedByUserId]);
        }

        $stmt = $this->db->prepare("
            SELECT fo.id,
                   fo.name,
                   COUNT(f.id)              AS file_count,
                   COALESCE(SUM(f.size), 0) AS total_size
            FROM folders fo
            LEFT JOIN files f ON f.folder_id = fo.id
            {$where}
            GROUP BY fo.id, fo.name
            ORDER BY total_size DESC
        ");
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Rename a file's original_name display label.
     */
    public function rename(int $id, string $newName): bool {
        $stmt = $this->db->prepare("UPDATE files SET original_name = ? WHERE id = ?");
        return $stmt->execute([$newName, $id]);
    }

    /**
     * Move a file to a different folder.
     */
    public function updateFolderAndPath(int $id, int $folderId, string $newPath): bool {
        $stmt = $this->db->prepare("UPDATE files SET folder_id = ?, path = ? WHERE id = ?");
        return $stmt->execute([$folderId, $newPath, $id]);
    }

    /**
     * Delete a file record.
     */
    // Hapus data file dari DB beserta signature-nya (tabel file_access sudah tidak ada, jadi tidak dibersihkan lagi)
    public function delete(int $id): bool {
        $this->db->beginTransaction();
        try {
            try {
                $stmt = $this->db->prepare("DELETE FROM file_signatures WHERE file_id = ?");
                $stmt->execute([$id]);
            } catch (Throwable $e) {
                // Tabel relasi mungkin belum ada di database lama.
            }

            $stmt = $this->db->prepare("DELETE FROM files WHERE id = ?");
            $ok = $stmt->execute([$id]);
            $this->db->commit();
            return $ok;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    // Cek apakah suatu kolom ada di tabel folders (dengan cache)
    private function folderColumnExists(string $column): bool {
        $cacheKey = 'folders.' . $column;
        if (array_key_exists($cacheKey, $this->columnCache)) {
            return $this->columnCache[$cacheKey];
        }

        try {
            $stmt = $this->db->prepare("SHOW COLUMNS FROM folders LIKE ?");
            $stmt->execute([$column]);
            $this->columnCache[$cacheKey] = (bool)$stmt->fetch();
        } catch (Throwable $e) {
            $this->columnCache[$cacheKey] = false;
        }

        return $this->columnCache[$cacheKey];
    }

    // Cek apakah user ini superadmin
    private function isSuperAdminUser(int $userId): bool {
        try {
            $stmt = $this->db->prepare("SELECT system_role FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            return ($stmt->fetchColumn() ?: '') === 'superadmin';
        } catch (Throwable $e) {
            return false;
        }
    }

    // Bangun klausa WHERE dinamis untuk query file berdasarkan filter yang diberikan:
    // folder_id, search (nama file), visibilitas member (uploaded_by/owner folder), dan scope admin divisi
    private function buildWhere(array $filters): array {
        $conditions = [];
        $params     = [];

        if (!empty($filters['folder_id'])) {
            $conditions[] = "f.folder_id = :folder_id";
            $params[':folder_id'] = (int) $filters['folder_id'];
        }

        if (!empty($filters['search'])) {
            $conditions[] = "f.original_name LIKE :search";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        if (isset($filters['is_admin']) && $filters['is_admin'] === false
            && !empty($filters['current_user_id'])
            && empty($filters['folder_id'])
        ) {
            $uid = (int) $filters['current_user_id'];
            $ownerCondition = $this->folderColumnExists('owner_user_id')
                ? " OR fo.owner_user_id = :vis_uid2"
                : "";
            $conditions[] = "
                (
                    f.uploaded_by = :vis_uid
                    {$ownerCondition}
                )
            ";
            $params[':vis_uid']  = $uid;
            if ($this->folderColumnExists('owner_user_id')) {
                $params[':vis_uid2'] = $uid;
            }
        }

        // Admin Divisi hanya boleh melihat file di folder departemen yang dia kelola.
        if (!empty($filters['managed_by_user_id'])) {
            $managedUid = (int)$filters['managed_by_user_id'];
            if ($this->folderColumnExists('department_id')) {
                $conditions[] = "
                    (
                        fo.created_by = :managed_uid_created
                        OR EXISTS (
                            SELECT 1
                            FROM user_departments mud
                            WHERE mud.user_id = :managed_uid_dept
                              AND mud.dept_role = 'admin'
                              AND mud.department_id = fo.department_id
                        )
                    )
                ";
                $params[':managed_uid_created'] = $managedUid;
                $params[':managed_uid_dept'] = $managedUid;
            } else {
                $conditions[] = "
                    (
                        fo.created_by = :managed_uid_created
                        OR LOWER(fo.name) IN (
                            SELECT LOWER(d.name)
                            FROM user_departments mud
                            JOIN departments d ON d.id = mud.department_id
                            WHERE mud.user_id = :managed_uid_dept
                              AND mud.dept_role = 'admin'
                        )
                    )
                ";
                $params[':managed_uid_created'] = $managedUid;
                $params[':managed_uid_dept'] = $managedUid;
            }
        }

        $where = $conditions ? ("WHERE " . implode(" AND ", $conditions)) : "";
        return [$where, $params];
    }
}