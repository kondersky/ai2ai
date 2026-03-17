<?php
/**
 * Credentials Table Management
 * 
 * @package Yc\GoogleIndexing
 */

namespace Yc\GoogleIndexing;

class CredentialsTable
{
    private static $tableName = 'b_yc_gindex_credentials';

    /**
     * Save credentials
     * 
     * @param string $jsonKey
     * @return bool
     */
    public static function saveCredentials(string $jsonKey): bool
    {
        global $DB;
        
        $data = json_decode($jsonKey, true);
        
        if (json_last_error() !== JSON_ERROR_NONE || empty($data['client_email'])) {
            return false;
        }
        
        // Clear existing credentials
        $DB->Query("DELETE FROM " . self::$tableName);
        
        $serviceEmail = $DB->ForSQL($data['client_email']);
        $jsonKeySql = $DB->ForSQL($jsonKey);
        
        $sql = "INSERT INTO " . self::$tableName . " 
            (JSON_KEY, SERVICE_EMAIL, DATE_CREATE) 
            VALUES ('" . $jsonKeySql . "', '" . $serviceEmail . "', '" . date('Y-m-d H:i:s') . "')";
        
        return $DB->Query($sql);
    }

    /**
     * Get credentials
     * 
     * @return array|null
     */
    public static function getCredentials(): ?array
    {
        global $DB;
        
        $sql = "SELECT * FROM " . self::$tableName . " LIMIT 1";
        
        $result = $DB->Query($sql);
        
        if ($row = $result->Fetch()) {
            return [
                'json_key' => $row['JSON_KEY'],
                'service_email' => $row['SERVICE_EMAIL'],
                'token_expires' => $row['TOKEN_EXPIRES'],
                'date_create' => $row['DATE_CREATE']
            ];
        }
        
        return null;
    }

    /**
     * Get JSON key content
     * 
     * @return string|null
     */
    public static function getJsonKey(): ?string
    {
        $creds = self::getCredentials();
        return $creds['json_key'] ?? null;
    }

    /**
     * Check if credentials exist
     * 
     * @return bool
     */
    public static function hasCredentials(): bool
    {
        return self::getCredentials() !== null;
    }

    /**
     * Delete credentials
     * 
     * @return bool
     */
    public static function deleteCredentials(): bool
    {
        global $DB;
        
        $sql = "DELETE FROM " . self::$tableName;
        
        return $DB->Query($sql);
    }

    /**
     * Get service email
     * 
     * @return string|null
     */
    public static function getServiceEmail(): ?string
    {
        $creds = self::getCredentials();
        return $creds['service_email'] ?? null;
    }
}
