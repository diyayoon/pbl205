<?php

if (class_exists('ActivityModel')) return;

class ActivityModel {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // Cek tabel activity_logs ada apa belum (buat graceful degradation)
    private function tableExists(): bool {
        try {
            $this->db->query("SELECT 1 FROM activity_logs LIMIT 1");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Write one activity entry.
     * Silently skips if the table doesn't exist yet (graceful degradation).
     */
    public function log(int $userId, string $action, string $description): void {
        if (!$this->tableExists()) return;

        try {
            $stmt = $this->db->prepare("
                INSERT INTO activity_logs (user_id, action, description, ip_address, created_at)
                VALUES (:user_id, :action, :description, :ip, NOW())
            ");
            $stmt->execute([
                ':user_id'     => $userId,
                ':action'      => $action,
                ':description' => $description,
                ':ip'          => $this->clientIp(),
            ]);
        } catch (Throwable $e) {
            // Never let logging crash the app
        }
    }

    /**
     * Fetch the N most recent log entries, joined with the username.
     * Returns [] when the table doesn't exist.
     */
    public function getRecent(int $limit = 10): array {
        if (!$this->tableExists()) return [];

        try {
            $stmt = $this->db->prepare("
                SELECT al.*,
                       u.username AS username
                FROM activity_logs al
                LEFT JOIN users u ON u.id = al.user_id
                ORDER BY al.created_at DESC
                LIMIT :limit
            ");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Count total log entries (for pagination).
     */
    public function countAll(): int {
        if (!$this->tableExists()) return 0;

        try {
            return (int) $this->db->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Paginated log list for an admin log-viewer page.
     */
    public function getAll(int $offset = 0, int $limit = 20): array {
        if (!$this->tableExists()) return [];

        try {
            $stmt = $this->db->prepare("
                SELECT al.*,
                       u.username AS username
                FROM activity_logs al
                LEFT JOIN users u ON u.id = al.user_id
                ORDER BY al.created_at DESC
                LIMIT :limit OFFSET :offset
            ");
            $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    // Ambil IP client, cek header proxy dulu baru REMOTE_ADDR
    private function clientIp(): string {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', $_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return '0.0.0.0';
    }
}