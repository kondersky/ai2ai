<?php
/**
 * Quota Table Management
 * 
 * @package Yc\GoogleIndexing
 */

namespace Yc\GoogleIndexing;

use Bitrix\Main\Config\Option;

class QuotaTable
{
    private static $tableName = 'b_yc_gindex_quota';
    private static $moduleId = 'yc.googleindexing';

    /**
     * Get quota status for today
     * 
     * @return array
     */
    public static function getStatus(): array
    {
        global $DB;
        
        $today = date('Y-m-d');
        
        $sql = "SELECT * FROM " . self::$tableName . " WHERE DATE_DAY = '" . $DB->ForSQL($today) . "'";
        
        $result = $DB->Query($sql);
        
        if ($row = $result->Fetch()) {
            return [
                'sent' => intval($row['SENT_COUNT']),
                'limit' => intval($row['LIMIT_DAY']),
                'date' => $row['DATE_DAY'],
                'remaining' => intval($row['LIMIT_DAY']) - intval($row['SENT_COUNT'])
            ];
        }
        
        // No record for today, return default
        $defaultLimit = Option::get(self::$moduleId, 'DAILY_LIMIT', 200);
        
        return [
            'sent' => 0,
            'limit' => $defaultLimit,
            'date' => $today,
            'remaining' => $defaultLimit
        ];
    }

    /**
     * Check if quota is exceeded
     * 
     * @return bool
     */
    public static function isQuotaExceeded(): bool
    {
        $status = self::getStatus();
        return $status['sent'] >= $status['limit'];
    }

    /**
     * Get remaining quota
     * 
     * @return int
     */
    public static function getRemaining(): int
    {
        $status = self::getStatus();
        return max(0, $status['remaining']);
    }

    /**
     * Increment quota counter
     * 
     * @return bool
     */
    public static function increment(): bool
    {
        global $DB;
        
        $today = date('Y-m-d');
        
        // Try to update existing record
        $sql = "UPDATE " . self::$tableName . " 
            SET SENT_COUNT = SENT_COUNT + 1 
            WHERE DATE_DAY = '" . $DB->ForSQL($today) . "'";
        
        $DB->Query($sql);
        
        // If no row was updated, insert new record
        if ($DB->AffectedRowsCount() == 0) {
            $defaultLimit = Option::get(self::$moduleId, 'DAILY_LIMIT', 200);
            
            $sql = "INSERT INTO " . self::$tableName . " 
                (DATE_DAY, SENT_COUNT, LIMIT_DAY, DATE_CREATE) 
                VALUES (
                    '" . $DB->ForSQL($today) . "',
                    1,
                    " . intval($defaultLimit) . ",
                    '" . date('Y-m-d H:i:s') . "'
                )";
            
            return $DB->Query($sql);
        }
        
        return true;
    }

    /**
     * Reset quota for today (for testing)
     * 
     * @return bool
     */
    public static function resetToday(): bool
    {
        global $DB;
        
        $today = date('Y-m-d');
        
        $sql = "UPDATE " . self::$tableName . " 
            SET SENT_COUNT = 0 
            WHERE DATE_DAY = '" . $DB->ForSQL($today) . "'";
        
        return $DB->Query($sql);
    }

    /**
     * Set daily limit
     * 
     * @param int $limit
     * @return bool
     */
    public static function setLimit(int $limit): bool
    {
        global $DB;
        
        $today = date('Y-m-d');
        
        // Try to update existing record
        $sql = "UPDATE " . self::$tableName . " 
            SET LIMIT_DAY = " . intval($limit) . " 
            WHERE DATE_DAY = '" . $DB->ForSQL($today) . "'";
        
        $DB->Query($sql);
        
        // If no row was updated, insert new record
        if ($DB->AffectedRowsCount() == 0) {
            $sql = "INSERT INTO " . self::$tableName . " 
                (DATE_DAY, SENT_COUNT, LIMIT_DAY, DATE_CREATE) 
                VALUES (
                    '" . $DB->ForSQL($today) . "',
                    0,
                    " . intval($limit) . ",
                    '" . date('Y-m-d H:i:s') . "'
                )";
            
            return $DB->Query($sql);
        }
        
        return true;
    }

    /**
     * Get quota history
     * 
     * @param int $days
     * @return array
     */
    public static function getHistory(int $days = 30): array
    {
        global $DB;
        
        $date = date('Y-m-d', strtotime('- ' . $days . ' days'));
        
        $sql = "SELECT * FROM " . self::$tableName . " 
            WHERE DATE_DAY >= '" . $DB->ForSQL($date) . "' 
            ORDER BY DATE_DAY DESC";
        
        $result = $DB->Query($sql);
        
        $items = [];
        while ($row = $result->Fetch()) {
            $items[] = [
                'date' => $row['DATE_DAY'],
                'sent' => intval($row['SENT_COUNT']),
                'limit' => intval($row['LIMIT_DAY']),
                'percentage' => intval($row['LIMIT_DAY']) > 0 
                    ? round(intval($row['SENT_COUNT']) / intval($row['LIMIT_DAY']) * 100) 
                    : 0
            ];
        }
        
        return $items;
    }
}
