<?php
/**
 * Helper mapping departemen website -> share Samba existing.
 * Folder/share utama departemen dianggap sudah dibuat manual di DC/Samba.
 */

// Normalisasi nama departemen (bahasa apa pun / typo spasi) ke kode share Samba baku
if (!function_exists('normalizeDepartmentShare')) {
    function normalizeDepartmentShare(?string $departmentName): string
    {
        $name = strtoupper(trim((string)$departmentName));
        $name = preg_replace('/\s+/', ' ', $name);

        // Mapping alias nama departemen -> nama share resmi
        $map = [
            'FINANCE' => 'FINANCE',
            'KEUANGAN' => 'FINANCE',
            'HUMAN RESOURCE' => 'HR',
            'HUMAN RESOURCES' => 'HR',
            'HR' => 'HR',
            'IT' => 'IT',
            'INFORMATION TECHNOLOGY' => 'IT',
            'TEKNOLOGI INFORMASI' => 'IT',
        ];

        // Kalau gak ada di map, bersihkan karakter aneh & fallback ke IT
        return $map[$name] ?? preg_replace('/[^A-Z0-9_-]/', '_', $name ?: 'IT');
    }
}

// Bersihkan karakter yang gak valid buat nama file/folder Windows (buat path Samba)
if (!function_exists('sanitizeSambaPathSegment')) {
    function sanitizeSambaPathSegment(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/[<>:"\\/|?*\x00-\x1F]/', '_', $value);
        $value = trim($value, ". \t\n\r\0\x0B");
        return $value !== '' ? $value : 'folder';
    }
}

// Nama folder personal member di Samba, sama dengan username yang sudah disanitasi
if (!function_exists('memberSambaFolderName')) {
    function memberSambaFolderName(string $username): string
    {
        return sanitizeSambaPathSegment($username);
    }
}