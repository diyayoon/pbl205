<?php

// Singleton buat koneksi database (biar cuma 1 koneksi PDO yang dipakai bareng)
class Database {
    private static ?PDO $pdo = null;

    // Ambil koneksi PDO, bikin baru kalau belum ada
    public static function getInstance(): PDO {
        if (self::$pdo === null) {
            try {
                $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT
                     . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
                self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // lempar exception kalau error
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // hasil query jadi array asosiatif
                    PDO::ATTR_EMULATE_PREPARES   => false,                 // prepared statement asli, aman dari SQL injection
                ]);
            } catch (PDOException $e) {
                die('DB Error: ' . $e->getMessage());
            }
        }
        return self::$pdo;
    }

    // Cegah clone instance
    private function __clone() {}

    // Cegah unserialize instance
    public function __wakeup() {
        throw new Exception('Cannot unserialize singleton');
    }
}