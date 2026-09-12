<?php

class AuditLogger
{
    /**
     * Default log file. Can be overridden by AUDIT_LOG_FILE env variable.
     * Do not let audit logging break page redirects/UI rendering.
     */
    private static string $defaultLogFile = '/var/log/filemanager/audit.log';

    // Entry point utama buat catat event audit (login, upload, delete, dll)
    public static function log(
        string $event,
        string $status = "SUCCESS",
        array $extra = []
    ): void {
        try {
            if (session_status() === PHP_SESSION_NONE) {
                @session_start();
            }

            $log = [
                "timestamp" => date('c'),
                "event" => strtoupper($event),
                "status" => strtoupper($status),
                "user" => $_SESSION['username'] ?? "guest",
                "role" => $_SESSION['role'] ?? "Unknown",
                "department" => $_SESSION['department'] ?? "Unknown",
                "ip" => self::getClientIP(),
                "method" => $_SERVER['REQUEST_METHOD'] ?? "-",
                "uri" => $_SERVER['REQUEST_URI'] ?? "-",
                "user_agent" => $_SERVER['HTTP_USER_AGENT'] ?? "-",
                "hostname" => gethostname(),
                "request_id" => self::generateRequestID()
            ];

            if (!empty($extra)) {
                $log = array_merge($log, $extra);
            }

            self::writeLog($log);
        } catch (Throwable $e) {
            // Audit log gak boleh nampilin error ke browser, cukup catat ke error_log
            error_log("[AuditLogger] " . $e->getMessage());
        }
    }

    // Tulis log ke file pertama yang berhasil (coba beberapa kandidat lokasi)
    private static function writeLog(array $log): void
    {
        $line = json_encode(
            $log,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) . PHP_EOL;

        foreach (self::candidateLogFiles() as $logFile) {
            if (self::ensureWritableLogFile($logFile)) {
                $written = @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
                if ($written !== false) {
                    return;
                }
            }
        }

        // Semua kandidat gagal → fallback terakhir ke error_log
        error_log('[AuditLogger] Failed writing audit log: ' . trim($line));
    }

    // Urutan prioritas lokasi log: env var > default > storage lokal > temp dir
    private static function candidateLogFiles(): array
    {
        $envLogFile = getenv('AUDIT_LOG_FILE');

        return array_values(array_unique(array_filter([
            is_string($envLogFile) && trim($envLogFile) !== '' ? trim($envLogFile) : null,
            self::$defaultLogFile,
            __DIR__ . '/../storage/logs/audit.log',
            sys_get_temp_dir() . '/filemanager-audit.log',
        ])));
    }

    // Pastikan folder & file log ada dan bisa ditulis, buat kalau belum ada
    private static function ensureWritableLogFile(string $logFile): bool
    {
        $dir = dirname($logFile);

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        if (!is_dir($dir) || !is_writable($dir)) {
            return false;
        }

        if (!file_exists($logFile)) {
            @touch($logFile);
            @chmod($logFile, 0664);
        }

        return is_file($logFile) && is_writable($logFile);
    }

    // Ambil IP client, prioritaskan X-Forwarded-For (kalau di belakang proxy)
    private static function getClientIP(): string
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }

        if (!empty($_SERVER['REMOTE_ADDR'])) {
            return $_SERVER['REMOTE_ADDR'];
        }

        return "Unknown";
    }

    // Bikin ID unik per request buat nge-trace log
    private static function generateRequestID(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (Exception $e) {
            return uniqid('', true);
        }
    }
}