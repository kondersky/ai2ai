<?php
/**
 * Log Table Management
 * 
 * @package Yc\GoogleIndexing
 */

namespace Yc\GoogleIndexing;

class LogTable
{
    private static $tableName = 'b_yc_gindex_log';

    const EVENT_SUCCESS = 'SUCCESS';
    const EVENT_ERROR = 'ERROR';
    const EVENT_OAUTH_ERROR = 'OAUTH_ERROR';
    const EVENT_QUOTA = 'QUOTA';
    const EVENT_AGENT = 'AGENT';
    const EVENT_CREDENTIALS = 'CREDENTIALS';

    /**
     * Add log entry
     * 
     * @param string $eventType
     * @param string $message
     * @param int|null $queueId
     * @return int|false
     */
    public static function add(string $eventType, string $message, ?int $queueId = null): int|false
    {
        global $DB;
        
        $sql = "INSERT INTO " . self::$tableName . " 
            (EVENT_TYPE, MESSAGE, QUEUE_ID, DATE_CREATE) 
            VALUES (
                '" . $DB->ForSQL($eventType) . "',
                '" . $DB->ForSQL($message) . "',
                " . ($queueId ? intval($queueId) : 'NULL') . ",
                '" . date('Y-m-d H:i:s') . "'
            )";
        
        $DB->Query($sql);
        
        return $DB->LastID();
    }

    /**
     * Add success log
     * 
     * @param string $message
     * @param int|null $queueId
     */
    public static function success(string $message, ?int $queueId = null)
    {
        return self::add(self::EVENT_SUCCESS, $message, $queueId);
    }

    /**
     * Add error log
     * 
     * @param string $message
     * @param int|null $queueId
     */
    public static function error(string $message, ?int $queueId = null)
    {
        return self::add(self::EVENT_ERROR, $message, $queueId);
    }

    /**
     * Add OAuth error log
     * 
     * @param string $message
     */
    public static function oauthError(string $message)
    {
        return self::add(self::EVENT_OAUTH_ERROR, $message);
    }

    /**
     * Add quota log
     * 
     * @param string $message
     */
    public static function quota(string $message)
    {
        return self::add(self::EVENT_QUOTA, $message);
    }

    /**
     * Add agent log
     * 
     * @param string $message
     */
    public static function agent(string $message)
    {
        return self::add(self::EVENT_AGENT, $message);
    }

    /**
     * Get list of log entries
     * 
     * @param int $limit
     * @param string $order
     * @return array
     */
    public static function getList(int $limit = 100, string $order = 'DATE_CREATE DESC'): array
    {
        global $DB;
        
        $sql = "SELECT * FROM " . self::$tableName . " ORDER BY " . $order . " LIMIT " . intval($limit);
        
        $result = $DB->Query($sql);
        
        $items = [];
        while ($row = $result->Fetch()) {
            $items[] = $row;
        }
        
        return $items;
    }

    /**
     * Cleanup old logs
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
     * Get error count for today
     * 
     * @return int
     */
    public static function getErrorCountToday(): int
    {
        global $DB;
        
        $today = date('Y-m-d');
        
        $sql = "SELECT COUNT(*) as CNT FROM " . self::$tableName . " 
            WHERE EVENT_TYPE IN ('ERROR', 'OAUTH_ERROR') 
            AND DATE_CREATE >= '" . $DB->ForSQL($today) . " 00:00:00'";
        
        $result = $DB->Query($sql);
        $row = $result->Fetch();
        
        return intval($row['CNT'] ?? 0);
    }

    /**
     * Get logs by type
     * 
     * @param string $eventType
     * @param int $limit
     * @return array
     */
    public static function getByType(string $eventType, int $limit = 100): array
    {
        global $DB;
        
        $sql = "SELECT * FROM " . self::$tableName . " 
            WHERE EVENT_TYPE = '" . $DB->ForSQL($eventType) . "' 
            ORDER BY DATE_CREATE DESC 
            LIMIT " . intval($limit);
        
        $result = $DB->Query($sql);
        
        $items = [];
        while ($row = $result->Fetch()) {
            $items[] = $row;
        }
        
        return $items;
    }
}
