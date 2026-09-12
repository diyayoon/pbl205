<?php
class UserModel {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function findByUsernameOrNim(string $identifier): ?array {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE username = :u OR nim = :n LIMIT 1");
        $stmt->execute(['u' => $identifier, 'n' => $identifier]);
        return $stmt->fetch() ?: null;
    }

    public function findByUsernameOrEmail(string $identifier): ?array {
        return $this->findByUsernameOrNim($identifier);
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function countAll(): int {
        return (int)$this->db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    }

    public function countAdmins(): int {
        return (int)$this->db->query("SELECT COUNT(*) FROM users WHERE system_role IN ('superadmin','general_admin')")->fetchColumn();
    }

    public function isSuperAdmin(int $id): bool {
        $stmt = $this->db->prepare("SELECT system_role FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row && $row['system_role'] === 'superadmin';
    }

    /* Ambil semua user + dept mereka (digabung jadi string) */
    public function getAll(): array {
        $stmt = $this->db->query("
            SELECT u.id, u.username, u.nim, u.system_role, u.team_role, u.jobdesk, u.avatar, u.created_at,
                   GROUP_CONCAT(
                       CONCAT(d.name, ':', ud.dept_role)
                       ORDER BY d.name SEPARATOR ','
                   ) AS departments
            FROM users u
            LEFT JOIN user_departments ud ON ud.user_id = u.id
            LEFT JOIN departments d ON d.id = ud.department_id
            GROUP BY u.id
            ORDER BY u.id ASC
        ");
        return $stmt->fetchAll();
    }

    public function usernameExists(string $username, ?int $exceptId = null): bool {
        if ($exceptId) {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND id <> ?");
            $stmt->execute([$username, $exceptId]);
        } else {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $stmt->execute([$username]);
        }
        return (int)$stmt->fetchColumn() > 0;
    }

    public function nimExists(string $nim, ?int $exceptId = null): bool {
        if ($exceptId) {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE nim = ? AND id <> ?");
            $stmt->execute([$nim, $exceptId]);
        } else {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE nim = ?");
            $stmt->execute([$nim]);
        }
        return (int)$stmt->fetchColumn() > 0;
    }

    public function create(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO users (username, nim, password, system_role, team_role, jobdesk, avatar)
            VALUES (:username, :nim, :password, :system_role, :team_role, :jobdesk, :avatar)
        ");
        $stmt->execute([
            'username'    => $data['username'],
            'nim'         => $data['nim'],
            'password'    => $data['password'],
            'system_role' => $data['system_role'] ?? 'member',
            'team_role'   => $data['team_role']   ?? 'Anggota',
            'jobdesk'     => $data['jobdesk']      ?? '-',
            'avatar'      => $data['avatar']       ?? null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function updateProfileFields(int $id, string $systemRole, string $teamRole, string $jobdesk): bool {
        $stmt = $this->db->prepare("
            UPDATE users SET system_role=:sr, team_role=:tr, jobdesk=:jd WHERE id=:id
        ");
        return $stmt->execute([':sr' => $systemRole, ':tr' => $teamRole, ':jd' => $jobdesk, ':id' => $id]);
    }

    public function delete(int $id): bool {
        $stmt = $this->db->prepare("DELETE FROM users WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /* ── Department methods ── */

    public function getAllDepartments(): array {
        return $this->db->query("SELECT * FROM departments ORDER BY name ASC")->fetchAll();
    }

    /* Ambil dept user: [{id, name, slug, dept_role}] */
    public function getUserDepartments(int $userId): array {
        $stmt = $this->db->prepare("
            SELECT d.id, d.name, d.slug, ud.dept_role
            FROM user_departments ud
            JOIN departments d ON d.id = ud.department_id
            WHERE ud.user_id = ?
            ORDER BY d.name ASC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /* Set dept user — hapus lama, insert baru */
    public function setUserDepartments(int $userId, array $depts): void {
        $del = $this->db->prepare("DELETE FROM user_departments WHERE user_id = ?");
        $del->execute([$userId]);

        $ins = $this->db->prepare("
            INSERT IGNORE INTO user_departments (user_id, department_id, dept_role)
            VALUES (?, ?, ?)
        ");
        foreach ($depts as $d) {
            $deptId   = (int)($d['department_id'] ?? 0);
            $deptRole = in_array($d['dept_role'] ?? '', ['admin','member']) ? $d['dept_role'] : 'member';
            if ($deptId > 0) $ins->execute([$userId, $deptId, $deptRole]);
        }
    }

    /* Ambil members yang relevan untuk folder tertentu (berdasarkan dept folder) */
    public function getMembersByFolder(int $folderId): array {
        // Cari user yang system_role = member dan punya akses ke folder ini
        // ATAU semua member jika folder tidak terhubung ke dept tertentu
        $stmt = $this->db->prepare("
            SELECT DISTINCT u.id, u.username, u.nim
            FROM users u
            WHERE u.system_role = 'member'
            ORDER BY u.username ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /* Dept mana yang di-admin oleh user ini */
    public function getAdminDepartments(int $userId): array {
        $stmt = $this->db->prepare("
            SELECT d.id, d.name, d.slug
            FROM user_departments ud
            JOIN departments d ON d.id = ud.department_id
            WHERE ud.user_id = ? AND ud.dept_role = 'admin'
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /* Ambil member yang berada di departemen tertentu (array of dept IDs) */
    public function getMembersByDepartmentIds(array $deptIds): array {
        if (empty($deptIds)) return [];
        $placeholders = implode(',', array_fill(0, count($deptIds), '?'));
        $stmt = $this->db->prepare("
            SELECT DISTINCT u.id, u.username, u.nim
            FROM users u
            JOIN user_departments ud ON ud.user_id = u.id
            WHERE ud.department_id IN ($placeholders)
              AND u.system_role = 'member'
            ORDER BY u.username ASC
        ");
        $stmt->execute($deptIds);
        return $stmt->fetchAll();
    }
}