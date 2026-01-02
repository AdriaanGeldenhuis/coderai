<?php
/**
 * CoderAI Cryptography
 * Handles encryption/decryption for sensitive data (API keys, etc.)
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

class Crypto
{
    private static $cipher = 'AES-256-GCM';
    private static $keyLength = 32;

    /**
     * Encrypt a string
     */
    public static function encrypt($plaintext)
    {
        $key = self::getKey();
        $iv = random_bytes(openssl_cipher_iv_length(self::$cipher));
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::$cipher,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($ciphertext === false) {
            throw new Exception('Encryption failed');
        }

        // Combine IV + tag + ciphertext and encode
        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt a string
     */
    public static function decrypt($encrypted)
    {
        $key = self::getKey();
        $data = base64_decode($encrypted);

        if ($data === false) {
            throw new Exception('Invalid encrypted data');
        }

        $ivLength = openssl_cipher_iv_length(self::$cipher);
        $tagLength = 16; // GCM tag length

        if (strlen($data) < $ivLength + $tagLength) {
            throw new Exception('Invalid encrypted data length');
        }

        $iv = substr($data, 0, $ivLength);
        $tag = substr($data, $ivLength, $tagLength);
        $ciphertext = substr($data, $ivLength + $tagLength);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::$cipher,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new Exception('Decryption failed');
        }

        return $plaintext;
    }

    /**
     * Get encryption key
     */
    private static function getKey()
    {
        $key = Bootstrap::getConfig('security')['encryption_key'] ?? '';

        if (empty($key) || $key === 'change-this-to-a-random-32-char-string') {
            throw new Exception('Encryption key not configured');
        }

        // Hash the key to ensure correct length
        return hash('sha256', $key, true);
    }

    /**
     * Generate a random token
     */
    public static function randomToken($length = 32)
    {
        return bin2hex(random_bytes($length));
    }

    /**
     * Generate a secure random string
     */
    public static function randomString($length = 16)
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $str = '';

        for ($i = 0; $i < $length; $i++) {
            $str .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $str;
    }

    /**
     * Hash a password
     */
    public static function hashPassword($password)
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
    }

    /**
     * Verify a password
     */
    public static function verifyPassword($password, $hash)
    {
        return password_verify($password, $hash);
    }

    /**
     * Generate HMAC signature
     */
    public static function sign($data)
    {
        $key = Bootstrap::getConfig('security')['encryption_key'] ?? '';
        return hash_hmac('sha256', $data, $key);
    }

    /**
     * Verify HMAC signature
     */
    public static function verifySignature($data, $signature)
    {
        return hash_equals(self::sign($data), $signature);
    }
}
