<?php
/**
 * Helper integrasi website -> WinRM -> Active Directory.
 * Dipanggil dari AdminController saat create user.
 */

// Cek toggle env AD_SYNC_ENABLED, default false (AD sync mati)
function adSyncEnabled(): bool
{
    return strtolower((string)(getenv('AD_SYNC_ENABLED') ?: 'false')) === 'true';
}

// Panggil script Python (ad_create_user.py) buat provisioning user baru ke AD
function createAdUserViaWinRM(array $payload): array
{
    if (!adSyncEnabled()) {
        return [
            'success' => true,
            'skipped' => true,
            'message' => 'AD sync dilewati karena AD_SYNC_ENABLED bukan true.'
        ];
    }

    $helperPath = getenv('AD_HELPER_PATH') ?: (APP_ROOT . '/scripts/ad_create_user.py');
    $pythonBin  = getenv('PYTHON_BIN') ?: 'python3';

    if (!is_file($helperPath)) {
        return [
            'success' => false,
            'message' => 'File AD helper tidak ditemukan: ' . $helperPath
        ];
    }

    $cmd = escapeshellcmd($pythonBin) . ' ' . escapeshellarg($helperPath);

    // stdin buat kirim payload JSON, stdout/stderr buat baca hasil dari script Python
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($cmd, $descriptors, $pipes);

    if (!is_resource($process)) {
        return [
            'success' => false,
            'message' => 'Gagal menjalankan proses AD helper.'
        ];
    }

    // Kirim payload lewat stdin
    fwrite($pipes[0], json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    fclose($pipes[0]);

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $error = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    $result = json_decode(trim($output), true);

    // Kalau output bukan JSON valid, kembalikan raw output buat debugging
    if (!is_array($result)) {
        return [
            'success' => false,
            'message' => 'Output AD helper tidak valid.',
            'raw_output' => trim($output),
            'stderr' => trim($error),
            'exit_code' => $exitCode,
        ];
    }

    // Fallback: kalau exit code gagal tapi script gak set 'success', paksa false
    if ($exitCode !== 0 && !isset($result['success'])) {
        $result['success'] = false;
    }

    return $result;
}

// Sama seperti create, tapi panggil script ad_delete_user.py buat hapus user dari AD
function deleteAdUserViaWinRM(array $payload): array
{
    if (!adSyncEnabled()) {
        return [
            'success' => true,
            'skipped' => true,
            'message' => 'AD sync dilewati karena AD_SYNC_ENABLED bukan true.'
        ];
    }

    $helperPath = getenv('AD_DELETE_HELPER_PATH') ?: (APP_ROOT . '/scripts/ad_delete_user.py');
    $pythonBin  = getenv('PYTHON_BIN') ?: 'python3';

    if (!is_file($helperPath)) {
        return [
            'success' => false,
            'message' => 'File AD helper delete tidak ditemukan: ' . $helperPath
        ];
    }

    $cmd = escapeshellcmd($pythonBin) . ' ' . escapeshellarg($helperPath);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($cmd, $descriptors, $pipes);

    if (!is_resource($process)) {
        return [
            'success' => false,
            'message' => 'Gagal menjalankan proses AD helper delete.'
        ];
    }

    fwrite($pipes[0], json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    fclose($pipes[0]);

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $error = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    $result = json_decode(trim($output), true);

    if (!is_array($result)) {
        return [
            'success' => false,
            'message' => 'Output AD helper tidak valid.',
            'raw_output' => trim($output),
            'stderr' => trim($error),
            'exit_code' => $exitCode,
        ];
    }

    if ($exitCode !== 0 && !isset($result['success'])) {
        $result['success'] = false;
    }

    return $result;
}