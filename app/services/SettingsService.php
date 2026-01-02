<?php
/**
 * CoderAI Settings Service
 * Central place for all app configuration
 */

if (!defined('CODERAI')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../core/Crypto.php';

class SettingsService
{
    private static $cache = [];

    /**
     * Get a setting value
     */
    public static function get($key, $default = null)
    {
        // Check cache first
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $db = Bootstrap::getDB();
        $stmt = $db->prepare("SELECT value_json, is_encrypted FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        if (!$row) {
            return $default;
        }

        $value = $row['value_json'];

        // If value is empty, return default
        if (empty($value) || $value === 'null') {
            return $default;
        }

        // Try to decrypt if marked as encrypted
        if ($row['is_encrypted']) {
            try {
                $decrypted = Crypto::decrypt($value);
                if (!empty($decrypted)) {
                    $value = $decrypted;
                }
            } catch (Exception $e) {
                error_log("Decryption failed for {$key}, trying raw value");
            }
        }

        // Try JSON decode
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && $decoded !== null) {
            $value = $decoded;
        }

        // Cache it
        self::$cache[$key] = $value;

        return $value;
    }

    /**
     * Set a setting value
     */
    public static function set($key, $value, $encrypted = false)
    {
        $db = Bootstrap::getDB();

        // Check if setting exists
        $stmt = $db->prepare("SELECT id, is_encrypted FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $existing = $stmt->fetch();

        // Prepare value
        if ($encrypted) {
            $storedValue = Crypto::encrypt($value);
        } else {
            $storedValue = is_array($value) ? json_encode($value) : json_encode($value);
        }

        if ($existing) {
            // Update
            $stmt = $db->prepare("UPDATE settings SET value_json = ?, is_encrypted = ? WHERE setting_key = ?");
            $stmt->execute([$storedValue, $encrypted ? 1 : 0, $key]);
        } else {
            // Insert
            $stmt = $db->prepare("INSERT INTO settings (setting_key, value_json, is_encrypted) VALUES (?, ?, ?)");
            $stmt->execute([$key, $storedValue, $encrypted ? 1 : 0]);
        }

        // Update cache
        self::$cache[$key] = $value;

        return true;
    }

    /**
     * Get all settings (for admin panel)
     */
    public static function getAll()
    {
        $db = Bootstrap::getDB();
        $stmt = $db->query("SELECT setting_key, value_json, is_encrypted, description FROM settings ORDER BY setting_key");
        $rows = $stmt->fetchAll();

        $settings = [];
        foreach ($rows as $row) {
            $value = $row['value_json'];

            // Don't decrypt encrypted values for display - just show placeholder
            if ($row['is_encrypted']) {
                $value = !empty($value) ? '••••••••' : null;
            } else {
                $decoded = json_decode($value, true);
                $value = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $value;
            }

            $settings[$row['setting_key']] = [
                'value' => $value,
                'encrypted' => (bool) $row['is_encrypted'],
                'description' => $row['description']
            ];
        }

        return $settings;
    }

    /**
     * Update multiple settings at once
     */
    public static function updateBulk($settings)
    {
        $db = Bootstrap::getDB();

        // Get encrypted keys
        $stmt = $db->query("SELECT setting_key FROM settings WHERE is_encrypted = 1");
        $encryptedKeys = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($settings as $key => $value) {
            // Skip empty encrypted values (don't overwrite)
            if (in_array($key, $encryptedKeys) && empty($value)) {
                continue;
            }

            $encrypted = in_array($key, $encryptedKeys);
            self::set($key, $value, $encrypted);
        }

        return true;
    }

    /**
     * ✅ NEW: Get model for workspace with complexity
     */
    public static function getModelForWorkspace($workspace, $complexity = 'cheap')
    {
        $routing = self::get('model_routing', []);

        if (is_string($routing)) {
            $routing = json_decode($routing, true) ?? [];
        }

        // Build key: normal_cheap, church, coder_plan, etc
        if ($workspace === 'normal') {
            $key = 'normal_' . $complexity;
        } elseif ($workspace === 'church') {
            $key = 'church';
        } elseif ($workspace === 'coder') {
            $key = 'coder_' . $complexity; // plan, code, review
        } else {
            $key = $workspace;
        }

        return $routing[$key] ?? 'gpt-4o-mini';
    }

    /**
     * Check if maintenance mode is enabled
     */
    public static function isMaintenanceMode()
    {
        $value = self::get('maintenance_mode', 'false');
        return $value === true || $value === 'true' || $value === '1';
    }

    /**
     * Get API key for provider
     */
    public static function getApiKey($provider)
    {
        $key = $provider . '_api_key';
        return self::get($key);
    }

    /**
     * Clear settings cache
     */
    public static function clearCache()
    {
        self::$cache = [];
    }
}