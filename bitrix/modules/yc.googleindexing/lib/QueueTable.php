<?php
/**
 * Queue Table Management
 * 
 * @package Yc\GoogleIndexing
 */

namespace Yc\GoogleIndexing;

use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlQueryException;

class QueueTable
{
    private static $tableName = 'b_yc_gindex_queue';

    /**
     * Add URL to queue
     * 
     * @param array $data
     * @return int|false
     */
    public static function add(array $data): int|false
    {
        global $DB;
        
        $defaults = [
            'STATUS' => 'NEW',
            'PRIORITY' => 'MEDIUM',
            'ATTEMPTS' => 0,
            'DATE_CREATE' => date('Y-m-d H:i:s')
        ];
        
        $data = array_merge($defaults, $data);
        
        $fields = [];
        $values = [];
        
        foreach ($data as $key => $value) {
            $fields[] = $DB->ForSQL($key);
            $values[] = is_null($value) ? 'NULL' : "'" . $DB->ForSQL($value) . "'";
        }
        
        $sql = "INSERT INTO " . self::$tableName . " (" . implode(', ', array_keys($fields)) . ") VALUES (" . implode(', ', $values) . ")";
        
        $DB->Query($sql);
        
        return $DB->LastID();
    }

    /**
     * Get list of queue items
     * 
     * @param array $filter
     * @param int $limit
     * @param string $order
     * @return array
     */
    public static function getList(array $filter = [], int $limit = 50, string $order = 'DATE_CREATE ASC'): array
    {
        global $DB;
        
        $where = [];
        
        foreach ($filter as $key => $value) {
            $where[] = $DB->ForSQL($key) . " = '" . $DB->ForSQL($value) . "'";
        }
        
        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $sql = "SELECT * FROM " . self::$tableName . " " . $whereSql . " ORDER BY 
            CASE PRIORITY 
                WHEN 'HIGH' THEN 1 
                WHEN 'MEDIUM' THEN 2 
                WHEN 'LOW' THEN 3 
            END, " . $order . " 
            LIMIT " . intval($limit);
        
        $result = $DB->Query($sql);
        
        $items = [];
        while ($row = $result->Fetch()) {
            $items[] = $row;
        }
        
        return $items;
    }

    /**
     * Get items by status
     * 
     * @param string $status
     * @param int $limit
     * @return array
     */
    public static function getByStatus(string $status, int $limit = 50): array
    {
        return self::getList(['STATUS' => $status], $limit);
    }

    /**
     * Get pending items (NEW status)
     * 
     * @param int $limit
     * @return array
     */
    public static function getPending(int $limit = 50): array
    {
        return self::getByStatus('NEW', $limit);
    }

    /**
     * Update status
     * 
     * @param int $id
     * @param string $status
     * @param string|null $httpCode
     * @param string|null $errorText
     * @return bool
     */
    public static function updateStatus(int $id, string $status, ?string $httpCode = null, ?string $errorText = null): bool
    {
        global $DB;
        
        $updates = [
            'STATUS' => "'" . $DB->ForSQL($status) . "'"
        ];
        
        if ($status === 'SENT') {
            $updates['DATE_SENT'] = "'" . date('Y-m-d H:i:s') . "'";
        }
        
        if ($httpCode !== null) {
            $updates['HTTP_CODE'] = "'" . $DB->ForSQL($httpCode) . "'";
        }
        
        if ($errorText !== null) {
            $updates['LAST_ERROR'] = "'" . $DB->ForSQL($errorText) . "'";
        }
        
        $updates['ATTEMPTS'] = 'ATTEMPTS + 1';
        
        $sql = "UPDATE " . self::$tableName . " SET " . implode(', ', array_map(
            fn($k, $v) => "$k = $v",
            array_keys($updates),
            array_values($updates)
        )) . " WHERE ID = " . intval($id);
        
        return $DB->Query($sql);
    }

    /**
     * Get sent count for today
     * 
     * @return int
     */
    public static function getSentCountForToday(): int
    {
        global $DB;
        
        $today = date('Y-m-d');
        
        $sql = "SELECT COUNT(*) as CNT FROM " . self::$tableName . " 
            WHERE STATUS = 'SENT' 
            AND DATE_SENT >= '" . $DB->ForSQL($today) . " 00:00:00'";
        
        $result = $DB->Query($sql);
        $row = $result->Fetch();
        
        return intval($row['CNT'] ?? 0);
    }

    /**
     * Get queue statistics
     * 
     * @return array
     */
    public static function getStats(): array
    {
        global $DB;
        
        $stats = [
            'total' => 0,
            'new' => 0,
            'sent' => 0,
            'error' => 0
        ];
        
        $sql = "SELECT STATUS, COUNT(*) as CNT FROM " . self::$tableName . " GROUP BY STATUS";
        $result = $DB->Query($sql);
        
        while ($row = $result->Fetch()) {
            $stats[$row['STATUS']] = intval($row['CNT']);
            $stats['total'] += intval($row['CNT']);
        }
        
        return $stats;
    }

    /**
     * Delete old queue items
     * 
     * @param int $days
     * @return int
     */
    public static function cleanup(int $days = 30): int
    {
        global $DB;
        
        $date = date('Y-m-d H:i:s', strtotime('- ' . $days . ' days'));
        
        $sql = "DELETE FROM " . self::$tableName . " WHERE DATE_CREATE < '" . $DB->ForSQL($date) . "'";
        
        $DB->Query($sql);
        
        return $DB->LastID();
    }

    /**
     * Check if URL already in queue
     * 
     * @param string $url
     * @param string $status
     * @return bool
     */
    public static function exists(string $url, string $status = 'NEW'): bool
    {
        global $DB;
        
        $sql = "SELECT ID FROM " . self::$tableName . " 
            WHERE URL = '" . $DB->ForSQL($url) . "' 
            AND STATUS = '" . $DB->ForSQL($status) . "' 
            LIMIT 1";
        
        $result = $DB->Query($sql);
        
        return $result->SelectedRowsCount() > 0;
    }
}
