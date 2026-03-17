<?php
/**
 * Iblock Settings Management
 * 
 * @package Yc\GoogleIndexing
 */

namespace Yc\GoogleIndexing;

use Bitrix\Main\Config\Option;

class IblockSettings
{
    private static $tableName = 'b_yc_gindex_iblocks';
    private static $moduleId = 'yc.googleindexing';

    /**
     * Check if iblock should be tracked
     * 
     * @param int $iblockId
     * @return bool
     */
    public static function isIblockTracked(int $iblockId): bool
    {
        // Check global setting
        if (!Option::get(self::$moduleId, 'TRACK_ELEMENTS', 'Y') === 'Y') {
            return false;
        }
        
        // Check specific iblocks setting
        $selectedIblocks = Option::get(self::$moduleId, 'IBLOCKS', '');
        
        if (!empty($selectedIblocks)) {
            $iblockList = explode(',', $selectedIblocks);
            return in_array($iblockId, $iblockList);
        }
        
        return true;
    }

    /**
     * Check if sections should be tracked
     * 
     * @return bool
     */
    public static function isSectionTrackingEnabled(): bool
    {
        return Option::get(self::$moduleId, 'TRACK_SECTIONS', 'N') === 'Y';
    }

    /**
     * Get priority for iblock
     * 
     * @param int $iblockId
     * @return string
     */
    public static function getIblockPriority(int $iblockId): string
    {
        global $DB;
        
        $sql = "SELECT PRIORITY FROM " . self::$tableName . " 
            WHERE IBLOCK_ID = " . intval($iblockId) . " 
            AND ACTIVE = 'Y' 
            LIMIT 1";
        
        $result = $DB->Query($sql);
        
        if ($row = $result->Fetch()) {
            return $row['PRIORITY'];
        }
        
        return 'MEDIUM';
    }

    /**
     * Set iblock settings
     * 
     * @param int $iblockId
     * @param string $priority
     * @param bool $active
     * @return bool
     */
    public static function setIblockSettings(int $iblockId, string $priority = 'MEDIUM', bool $active = true): bool
    {
        global $DB;
        
        // Delete existing
        $DB->Query("DELETE FROM " . self::$tableName . " WHERE IBLOCK_ID = " . intval($iblockId));
        
        // Insert new
        $sql = "INSERT INTO " . self::$tableName . " 
            (IBLOCK_ID, PRIORITY, ACTIVE, DATE_CREATE) 
            VALUES (
                " . intval($iblockId) . ",
                '" . $DB->ForSQL($priority) . "',
                '" . ($active ? 'Y' : 'N') . "',
                '" . date('Y-m-d H:i:s') . "'
            )";
        
        return $DB->Query($sql);
    }

    /**
     * Get all tracked iblocks
     * 
     * @return array
     */
    public static function getTrackedIblocks(): array
    {
        global $DB;
        
        $sql = "SELECT * FROM " . self::$tableName . " WHERE ACTIVE = 'Y' ORDER BY IBLOCK_ID";
        
        $result = $DB->Query($sql);
        
        $items = [];
        while ($row = $result->Fetch()) {
            $items[] = [
                'id' => intval($row['IBLOCK_ID']),
                'priority' => $row['PRIORITY'],
                'active' => $row['ACTIVE'] === 'Y'
            ];
        }
        
        return $items;
    }

    /**
     * Get all available iblocks
     * 
     * @return array
     */
    public static function getAvailableIblocks(): array
    {
        if (!\Bitrix\Main\Loader::includeModule('iblock')) {
            return [];
        }
        
        $iblockTypes = \CIBlock::GetList(['SORT' => 'ASC']);
        
        $iblocks = [];
        while ($type = $iblockTypes->Fetch()) {
            $res = \CIBlock::GetList(
                ['SORT' => 'ASC'],
                ['TYPE' => $type['ID'], 'ACTIVE' => 'Y']
            );
            
            while ($block = $res->Fetch()) {
                $iblocks[] = [
                    'id' => intval($block['ID']),
                    'name' => $block['NAME'],
                    'type' => $type['ID']
                ];
            }
        }
        
        return $iblocks;
    }
}
