<?php

if (function_exists('cryptoGenerateDEK')) return;

//Generate DEK (Data Encryption Key)
function cryptoGenerateDEK(): string {
    return random_bytes(32); //bytes
}

//Enkripsi file dengan DEK (AES-256-CBC)

/**
 * @return array{ciphertext: string, iv: string}
 */

function cryptoEncryptFile(string $plaintext, string $dek): array {
    $iv = random_bytes(16);
    $ciphertext = openssl_encrypt(
        $plaintext,
        'AES-256-CBC',
        $dek,
        OPENSSL_RAW_DATA,
        $iv
    );
    if ($ciphertext === false) {
        throw new RuntimeException('Enkripsi file gagal: ' . openssl_error_string());
    }
    return [
        'ciphertext' => $ciphertext,
        'iv'         => $iv,
    ];
}


//Dekripsi file
function cryptoDecryptFile(string $ciphertext, string $dek, string $iv): string {
    $plaintext = openssl_decrypt(
        $ciphertext,
        'AES-256-CBC',
        $dek,
        OPENSSL_RAW_DATA,
        $iv
    );
    if ($plaintext === false) {
        throw new RuntimeException('Dekripsi file gagal: ' . openssl_error_string());
    }
    return $plaintext;
}

//Enkripsi DEK dengan RSA public key

/**
 * @param string $dek       Raw 32-byte DEK
 * @param string $publicKey PEM public key
 * @return string           Base64-encoded encrypted DEK
 */

function cryptoEncryptDEK(string $dek, string $publicKey): string {
    $encrypted = '';
    $ok = openssl_public_encrypt($dek, $encrypted, $publicKey, OPENSSL_PKCS1_OAEP_PADDING);
    if (!$ok) {
        throw new RuntimeException('Enkripsi DEK gagal: ' . openssl_error_string());
    }
    return base64_encode($encrypted);
}

//Dekripsi DEK dengan RSA private key

/**
 * @param string $encryptedDekB64 Base64 encrypted DEK dari DB
 * @param string $privateKey      PEM private key (decoded dari DB)
 * @return string                 Raw 32-byte DEK
 */

function cryptoDecryptDEK(string $encryptedDekB64, string $privateKey): string {
    $encrypted = base64_decode($encryptedDekB64);
    $dek = '';
    $ok = openssl_private_decrypt($encrypted, $dek, $privateKey, OPENSSL_PKCS1_OAEP_PADDING);
    if (!$ok) {
        throw new RuntimeException('Dekripsi DEK gagal: ' . openssl_error_string());
    }
    return $dek;
}

//Digital signature

/**
 * Sign data (hash string) dengan RSA private key
 * @return string Base64-encoded signature
 */

function cryptoSign(string $data, string $privateKey): string {
    $signature = '';
    $ok = openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    if (!$ok) {
        throw new RuntimeException('Signing gagal: ' . openssl_error_string());
    }
    return base64_encode($signature);
}

//Verifikasi signature

/**
 * @param string $signatureB64 Base64-encoded signature dari DB
 * @param string $publicKey    PEM public key
 */
function cryptoVerify(string $data, string $signatureB64, string $publicKey): bool {
    $signature = base64_decode($signatureB64);
    $result = openssl_verify($data, $signature, $publicKey, OPENSSL_ALGO_SHA256);
    return $result === 1;
}

//Hash helpers

function cryptoHashData(string $data): string {
    return hash('sha256', $data);
}

//Ambil private key user dari DB

/**
 * Ambil dan decode private key user dari tabel user_keys
 * @return string PEM private key string, atau null jika tidak ada
 */

function cryptoGetPrivateKey(int $userId): ?string {
    try {
        $db   = Database::getInstance();
        $stmt = $db->prepare("SELECT encrypted_private_key FROM user_keys WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $row  = $stmt->fetch();
        if (!$row) return null;

        // Saat ini private key di-store sebagai base64(PEM)
        return base64_decode($row['encrypted_private_key']);
    } catch (Throwable $e) {
        return null;
    }
}

//Ambil public key user dari DB
function cryptoGetPublicKey(int $userId): ?string {
    try {
        $db   = Database::getInstance();
        $stmt = $db->prepare("SELECT public_key FROM user_keys WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $row  = $stmt->fetch();
        return $row ? $row['public_key'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

//Generate RSA keypair dan simpan ke DB
function cryptoGenerateAndStoreKeypair(int $userId): bool {
    try {
        $db = Database::getInstance();

        // Cek user sudah punya key atau belum
        $check = $db->prepare("SELECT COUNT(*) FROM user_keys WHERE user_id = ?");
        $check->execute([$userId]);
        if ((int)$check->fetchColumn() > 0) return true; //Sudah ada, skip

        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        $res = openssl_pkey_new($config);
        if (!$res) {
            throw new RuntimeException('Gagal generate RSA key: ' . openssl_error_string());
        }

        openssl_pkey_export($res, $privateKey);
        $publicKey = openssl_pkey_get_details($res)['key'];

        $stmt = $db->prepare("
            INSERT INTO user_keys (user_id, public_key, encrypted_private_key)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            $publicKey,
            base64_encode($privateKey),
        ]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
