<?php
/**
 * Samba Helper — Push decrypted file ke Windows Server Samba Share
 * via smbclient (Tailscale network)
 *
 * Requires: smbclient installed di container
 * Usage   : SambaHelper::pushFile($localPath, $remoteName, $subFolder)
 */

if (class_exists('SambaHelper')) return;

class SambaHelper
{
    // ── Konfigurasi Samba ─────────────────────────────────────────────────────
    private static string $host     = SAMBA_HOST;       // '100.107.192.115'
    private static string $share    = SAMBA_SHARE;      // 'IT'
    private static string $domain   = SAMBA_DOMAIN;     // 'polibatam'
    private static string $user     = SAMBA_USER;       // 'svc_filemanager'
    private static string $password = SAMBA_PASSWORD;   // dari config

    // ── Timeout (detik) ───────────────────────────────────────────────────────
    private static int $timeout = 30;

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC METHODS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Override share secara dinamis (untuk multi-divisi).
     */
    public static function setShare(string $share): void
    {
        self::$share = strtoupper(trim($share));
    }

    /**
     * Push satu file ke Samba share.
     *
     * @param string $localPath   Path file lokal (sudah didekripsi, file temp)
     * @param string $remoteName  Nama file di Samba (original_name)
     * @param string $subFolder   Sub-folder di dalam share, misal 'folders/1'
     *                            Kosongkan untuk simpan di root share.
     * @return bool
     * @throws RuntimeException jika push gagal
     */
    public static function pushFile(string $localPath, string $remoteName, string $subFolder = ''): bool
    {
        if (!file_exists($localPath)) {
            throw new RuntimeException("File lokal tidak ditemukan: {$localPath}");
        }

        // Sanitasi nama file & subfolder untuk smbclient
        $remoteName = self::sanitizeRemoteName($remoteName);
        $subFolder  = self::sanitizeSubFolder($subFolder);

        // Buat subfolder dulu kalau ada
        if ($subFolder !== '') {
            self::ensureRemoteDir($subFolder);
        }

        // Remote path relatif dari root share
        $remotePath = $subFolder !== ''
            ? $subFolder . '\\' . $remoteName
            : $remoteName;

        // Build smbclient command
        $command = self::buildCommand(
            sprintf('put "%s" "%s"', addslashes($localPath), $remotePath)
        );

        return self::exec($command, "Push file '{$remoteName}' ke Samba");
    }

    /**
     * Hapus file dari Samba share.
     *
     * @param string $remoteName  Nama file di Samba
     * @param string $subFolder   Sub-folder di dalam share
     */
    public static function deleteFile(string $remoteName, string $subFolder = '', bool $ignoreMissing = true): bool
    {
        $remoteName = self::sanitizeRemoteName($remoteName);
        $subFolder  = self::sanitizeSubFolder($subFolder);
        $remotePath = self::remotePath($remoteName, $subFolder);

        $command = self::buildCommand(sprintf('del "%s"', $remotePath));
        $output = self::execRaw($command);

        if ($ignoreMissing && self::isNotFoundOutput($output)) {
            return true;
        }

        self::assertNoSambaError($output, "Hapus file '{$remoteName}' dari Samba");
        return true;
    }



    /**
     * Rename file di share aktif.
     */
    public static function renameFile(string $oldRemoteName, string $newRemoteName, string $subFolder = ''): bool
    {
        $oldRemoteName = self::sanitizeRemoteName($oldRemoteName);
        $newRemoteName = self::sanitizeRemoteName($newRemoteName);
        $subFolder     = self::sanitizeSubFolder($subFolder);

        $oldPath = self::remotePath($oldRemoteName, $subFolder);
        $newPath = self::remotePath($newRemoteName, $subFolder);

        if ($oldPath === $newPath) {
            return true;
        }

        $command = self::buildCommand(sprintf('rename "%s" "%s"', $oldPath, $newPath));
        return self::exec($command, "Rename file '{$oldRemoteName}' menjadi '{$newRemoteName}' di Samba");
    }

    /**
     * Move/rename file di share aktif. Bisa dipakai untuk pindah folder dalam share yang sama.
     */
    public static function moveFile(string $oldRemoteName, string $newRemoteName, string $oldSubFolder = '', string $newSubFolder = ''): bool
    {
        $oldRemoteName = self::sanitizeRemoteName($oldRemoteName);
        $newRemoteName = self::sanitizeRemoteName($newRemoteName);
        $oldSubFolder  = self::sanitizeSubFolder($oldSubFolder);
        $newSubFolder  = self::sanitizeSubFolder($newSubFolder);

        if ($newSubFolder !== '') {
            self::ensureRemoteDir($newSubFolder);
        }

        $oldPath = self::remotePath($oldRemoteName, $oldSubFolder);
        $newPath = self::remotePath($newRemoteName, $newSubFolder);

        if ($oldPath === $newPath) {
            return true;
        }

        $command = self::buildCommand(sprintf('rename "%s" "%s"', $oldPath, $newPath));
        return self::exec($command, "Pindah file '{$oldRemoteName}' di Samba");
    }

    /**
     * Ambil file plaintext dari Samba ke file lokal sementara.
     * Dipakai untuk move lintas share, misalnya HR -> FINANCE.
     */
    public static function downloadFile(string $remoteName, string $subFolder, string $localPath): bool
    {
        $remoteName = self::sanitizeRemoteName($remoteName);
        $subFolder  = self::sanitizeSubFolder($subFolder);
        $remotePath = self::remotePath($remoteName, $subFolder);

        $command = self::buildCommand(sprintf('get "%s" "%s"', $remotePath, addslashes($localPath)));
        return self::exec($command, "Download file '{$remoteName}' dari Samba");
    }

    /**
     * Hapus folder kosong dari share aktif. Aman untuk dipakai saat folder web dihapus.
     */
    public static function removeDirectory(string $subFolder, bool $ignoreMissing = true): bool
    {
        $subFolder = self::sanitizeSubFolder($subFolder);
        if ($subFolder === '') {
            throw new RuntimeException('Tidak boleh menghapus root share Samba.');
        }

        $command = self::buildCommand(sprintf('rmdir "%s"', $subFolder));
        $output = self::execRaw($command);

        if ($ignoreMissing && self::isNotFoundOutput($output)) {
            return true;
        }

        self::assertNoSambaError($output, "Hapus folder '{$subFolder}' dari Samba");
        return true;
    }

    /**
     * Hapus folder beserta SEMUA isinya (file di dalamnya) dari share aktif.
     * Dipakai saat folder member dihapus (misal user-nya dihapus), karena
     * removeDirectory() biasa cuma bisa hapus folder yang sudah kosong.
     * Tidak melempar error kalau foldernya memang sudah tidak ada di Samba.
     */
    public static function removeDirectoryRecursive(string $subFolder, bool $ignoreMissing = true): bool
    {
        $subFolder = self::sanitizeSubFolder($subFolder);
        if ($subFolder === '') {
            throw new RuntimeException('Tidak boleh menghapus root share Samba.');
        }

        // Hapus semua file di dalam folder dulu, baru foldernya sendiri.
        $entries = self::listDir($subFolder);
        foreach ($entries as $entry) {
            if (($entry['name'] ?? '') === '' || in_array($entry['name'], ['.', '..'], true)) {
                continue;
            }
            if (($entry['type'] ?? 'file') === 'dir') {
                // Folder member normalnya flat (tanpa subfolder), tapi jaga-jaga kalau ada nested folder.
                self::removeDirectoryRecursive($subFolder . '\\' . $entry['name'], true);
            } else {
                self::deleteFile($entry['name'], $subFolder, true);
            }
        }

        return self::removeDirectory($subFolder, $ignoreMissing);
    }

    /**
     * Cek apakah koneksi ke Samba share berhasil.
     */
    public static function testConnection(): bool
    {
        $command = self::buildCommand('ls');
        try {
            return self::exec($command, 'Test koneksi Samba');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * List isi direktori di Samba share.
     *
     * @param string $subFolder Sub-folder (kosong = root)
     * @return array
     */
    public static function listDir(string $subFolder = ''): array
    {
        $subFolder = self::sanitizeSubFolder($subFolder);
        $lsCmd     = $subFolder !== '' ? "ls \"{$subFolder}\\*\"" : 'ls';
        $command   = self::buildCommand($lsCmd);

        $output = self::execRaw($command);
        return self::parseListOutput($output);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE METHODS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Susun path remote relatif dari root share.
     */
    private static function remotePath(string $remoteName, string $subFolder = ''): string
    {
        $remoteName = self::sanitizeRemoteName($remoteName);
        $subFolder  = self::sanitizeSubFolder($subFolder);
        return $subFolder !== '' ? $subFolder . '\\' . $remoteName : $remoteName;
    }

    private static function isNotFoundOutput(string $output): bool
    {
        return str_contains($output, 'NT_STATUS_NO_SUCH_FILE')
            || str_contains($output, 'NT_STATUS_OBJECT_NAME_NOT_FOUND')
            || str_contains($output, 'ERRbadfile')
            || str_contains($output, 'No such file')
            || str_contains($output, 'does not exist');
    }

    /**
     * Pastikan subfolder ada di Samba, buat jika belum ada.
     * Mendukung nested folder, misal 'folders/1' → mkdir folders, mkdir folders\1
     */
    private static function ensureRemoteDir(string $subFolder): void
    {
        // Split path ke segmen-segmen
        $segments = explode('\\', str_replace('/', '\\', $subFolder));
        $current  = '';

        foreach ($segments as $segment) {
            $current = $current !== '' ? $current . '\\' . $segment : $segment;
            $mkdirCmd = sprintf('mkdir "%s"', $current);
            $command  = self::buildCommand($mkdirCmd);

            // Ignore error — folder mungkin sudah ada
            try {
                self::execRaw($command);
            } catch (Throwable) {
                // folder sudah ada, lanjut
            }
        }
    }

    /**
     * Build smbclient command yang aman.
     * Credential di-pass via environment variable PASSWD, tidak lewat args.
     */
    private static function buildCommand(string $smbCommand): string
    {
        $host     = escapeshellarg('//' . self::$host . '/' . self::$share);
        $user     = escapeshellarg(self::$domain . '\\' . self::$user);
        $domain   = escapeshellarg(self::$domain);
        $timeout  = (int) self::$timeout;

        // Gunakan PASSWD env var agar password tidak muncul di process list
        return sprintf(
            'PASSWD=%s smbclient %s -U %s -W %s --timeout=%d -c %s 2>&1',
            escapeshellarg(self::$password),
            $host,
            $user,
            $domain,
            $timeout,
            escapeshellarg($smbCommand)
        );
    }

    /**
     * Jalankan command dan throw jika ada error Samba.
     */
    private static function exec(string $command, string $context = ''): bool
    {
        $output = self::execRaw($command);
        self::assertNoSambaError($output, $context);
        return true;
    }

    /**
     * Jalankan command dan return raw output.
     */
    private static function execRaw(string $command): string
    {
        $output     = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);
        return implode("\n", $output);
    }

    /**
     * Cek apakah output mengandung error Samba yang dikenal.
     */
    private static function assertNoSambaError(string $output, string $context = ''): void
    {
        $errors = [
            'NT_STATUS_BAD_NETWORK_NAME'  => 'Share tidak ditemukan',
            'NT_STATUS_VOLUME_DISMOUNTED' => 'Volume tidak ter-mount di server',
            'NT_STATUS_ACCESS_DENIED'     => 'Akses ditolak — cek permission Samba/NTFS',
            'NT_STATUS_LOGON_FAILURE'     => 'Credential salah — cek username/password',
            'NT_STATUS_NO_SUCH_FILE'      => 'File tidak ditemukan di Samba',
            'NT_STATUS_OBJECT_NAME_NOT_FOUND' => 'Path tidak ditemukan di Samba',
            'NT_STATUS_OBJECT_NAME_COLLISION' => 'File/folder tujuan sudah ada di Samba',
            'NT_STATUS_SHARING_VIOLATION' => 'File sedang dipakai/terkunci di Samba',
            'NT_STATUS_DIRECTORY_NOT_EMPTY' => 'Folder Samba masih berisi file',
            'ERRbadfile' => 'File/folder tidak ditemukan di Samba',
            'tree connect failed'         => 'Gagal connect ke share',
            'Connection refused'          => 'Samba server tidak dapat dijangkau',
            'Name or service not known'   => 'Host Samba tidak dapat diresolve',
        ];

        foreach ($errors as $pattern => $description) {
            if (str_contains($output, $pattern)) {
                $prefix = $context ? "[{$context}] " : '';
                throw new RuntimeException(
                    "{$prefix}Samba error: {$description} ({$pattern})\nOutput: {$output}"
                );
            }
        }
    }

    /**
     * Parse output `ls` smbclient menjadi array file.
     */
    private static function parseListOutput(string $output): array
    {
        $files = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            // Skip baris kosong, header, footer
            if ($line === '' || str_starts_with($line, 'blocks')) continue;
            // Match: nama  [D]  size  date
            if (preg_match('/^(.+?)\s+(D|A|-)\s+(\d+)\s+(.+)$/', $line, $m)) {
                $name = trim($m[1]);
                if ($name === '.' || $name === '..') continue;
                $files[] = [
                    'name' => $name,
                    'type' => $m[2] === 'D' ? 'dir' : 'file',
                    'size' => (int) $m[3],
                    'date' => trim($m[4]),
                ];
            }
        }
        return $files;
    }

    /**
     * Sanitasi nama file untuk smbclient (tidak boleh ada karakter berbahaya).
     */
    private static function sanitizeRemoteName(string $name): string
    {
        // Hapus karakter yang tidak aman di Windows filename
        $name = preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]/', '_', $name);
        return trim($name, '. ') ?: 'file';
    }

    /**
     * Sanitasi subfolder path, konversi slash ke backslash.
     */
    private static function sanitizeSubFolder(string $path): string
    {
        $path = str_replace(['/', '\\'], '\\', $path);
        $path = preg_replace('/[<>:"|?*\x00-\x1F]/', '_', $path);
        return trim($path, '\\ ');
    }
}