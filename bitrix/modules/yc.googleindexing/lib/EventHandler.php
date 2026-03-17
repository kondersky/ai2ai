<?php
/**
 * Event Handler for Iblock events
 * Handles element and section add/update/delete events
 * 
 * @package Yc\GoogleIndexing
 * @version 1.0.0
 */

namespace Yc\GoogleIndexing;

use Bitrix\Main\Loader;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Iblock\IblockTable;
use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\SectionTable;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

class EventHandler
{
    const MODULE_ID = 'yc.googleindexing';
    
    /**
     * Generate public URL for element
     * 
     * @param int $elementId
     * @param int $iblockId
     * @return string|null
     */
    private static function getElementUrl(int $elementId, int $iblockId): ?string
    {
        if (!Loader::includeModule('iblock')) {
            return null;
        }
        
        $element = ElementTable::getList([
            'filter' => ['ID' => $elementId, 'IBLOCK_ID' => $iblockId],
            'select' => ['ID', 'IBLOCK_ID', 'DETAIL_PAGE_URL', 'CODE', 'ACTIVE']
        ])->fetch();
        
        if (!$element) {
            return null;
        }
        
        // Skip inactive elements
        if ($element['ACTIVE'] !== 'Y') {
            return null;
        }
        
        // Try to use Bitrix URL Manager
        try {
            $url = UrlManager::getInstance()->getHostUrl() . \CIBlock::ReplaceDetailUrl(
                $element['DETAIL_PAGE_URL'],
                $element,
                false,
                'E'
            );
            
            if (!empty($url) && strpos($url, 'http') === 0) {
                return $url;
            }
        } catch (\Exception $e) {
            // Fallback to manual URL building
        }
        
        // Fallback: manual URL building
        $iblock = IblockTable::getById($iblockId)->fetch();
        if (!$iblock) {
            return null;
        }
        
        $siteId = null;
        $rsSites = \CIBlock::GetSite($iblockId);
        while ($site = $rsSites->Fetch()) {
            $siteId = $site['LID'];
            break;
        }
        
        if (!$siteId) {
            return null;
        }
        
        $url = $element['DETAIL_PAGE_URL'] ?: '';
        
        if (!empty($url)) {
            $url = str_replace(
                ['#SITE_DIR#', '#SERVER_NAME#', '#IBLOCK_ID#', '#ID#', '#CODE#'],
                ['/', '', $iblockId, $elementId, $element['CODE'] ?? ''],
                $url
            );
            
            $site = new \CSite();
            $siteData = $site->GetByID($siteId)->Fetch();
            
            if ($siteData) {
                $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $domain = $siteData['SERVER_NAME'] ?: ($_SERVER['SERVER_NAME'] ?? 'localhost');
                
                $url = $protocol . '://' . $domain . $url;
            }
        }
        
        return !empty($url) ? $url : null;
    }

    /**
     * Generate public URL for section
     * 
     * @param int $sectionId
     * @param int $iblockId
     * @return string|null
     */
    private static function getSectionUrl(int $sectionId, int $iblockId): ?string
    {
        if (!Loader::includeModule('iblock')) {
            return null;
        }
        
        $section = SectionTable::getList([
            'filter' => ['ID' => $sectionId, 'IBLOCK_ID' => $iblockId],
            'select' => ['ID', 'IBLOCK_ID', 'SECTION_PAGE_URL', 'CODE', 'ACTIVE']
        ])->fetch();
        
        if (!$section) {
            return null;
        }
        
        // Skip inactive sections
        if ($section['ACTIVE'] !== 'Y') {
            return null;
        }
        
        $url = $section['SECTION_PAGE_URL'] ?? '';
        
        if (!empty($url)) {
            $siteId = null;
            $sites = \CIBlock::GetSite($iblockId);
            $siteData = $sites->Fetch();
            
            if ($siteData) {
                $siteId = $siteData['LID'];
                $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $domain = $siteData['SERVER_NAME'] ?: ($_SERVER['SERVER_NAME'] ?? 'localhost');
                
                $url = str_replace(
                    ['#SITE_DIR#', '#SERVER_NAME#', '#IBLOCK_ID#', '#ID#', '#CODE#'],
                    ['/', '', $iblockId, $sectionId, $section['CODE'] ?? ''],
                    $url
                );
                
                $url = $protocol . '://' . $domain . $url;
            }
        }
        
        return !empty($url) ? $url : null;
    }

    /**
     * Add URL to queue
     * 
     * @param string $url
     * @param string $type
     * @param int $iblockId
     * @param int|null $elementId
     * @param int|null $sectionId
     * @param string $priority
     * @return int|false
     */
    private static function addToQueue(
        string $url,
        string $type,
        int $iblockId,
        ?int $elementId = null,
        ?int $sectionId = null,
        string $priority = 'MEDIUM'
    ): int|false {
        // Validate URL
        if (empty($url) || strpos($url, 'http') !== 0) {
            return false;
        }
        
        // Check if URL already in queue (NEW status)
        if (QueueTable::exists($url, 'NEW')) {
            return false;
        }
        
        // Determine site ID
        $siteId = null;
        if (Loader::includeModule('iblock')) {
            $rsSites = \CIBlock::GetSite($iblockId);
            while ($site = $rsSites->Fetch()) {
                $siteId = $site['LID'];
                break;
            }
        }
        
        return QueueTable::add([
            'URL' => $url,
            'TYPE' => $type,
            'IBLOCK_ID' => $iblockId,
            'ELEMENT_ID' => $elementId,
            'SECTION_ID' => $sectionId,
            'SITE_ID' => $siteId,
            'PRIORITY' => $priority
        ]);
    }

    /**
     * After IBlock element add
     * 
     * @param array &$arFields
     * @return void
     */
    public static function onAfterIBlockElementAdd(array &$arFields): void
    {
        // Check if result is successful
        if (isset($arFields['RESULT']) && $arFields['RESULT'] === false) {
            return;
        }
        
        $iblockId = (int)($arFields['IBLOCK_ID'] ?? 0);
        $elementId = (int)($arFields['ID'] ?? 0);
        
        if ($iblockId <= 0 || $elementId <= 0) {
            return;
        }
        
        // Check if we should track this iblock
        if (!IblockSettings::isIblockTracked($iblockId)) {
            return;
        }
        
        $url = self::getElementUrl($elementId, $iblockId);
        
        if ($url) {
            $priority = IblockSettings::getIblockPriority($iblockId);
            $queueId = self::addToQueue($url, 'URL_UPDATED', $iblockId, $elementId, null, $priority);
            
            if ($queueId) {
                LogTable::agent("Element added: {$url} (IBLOCK: {$iblockId}, ELEMENT: {$elementId})");
            }
        }
    }

    /**
     * After IBlock element update
     * 
     * @param array &$arFields
     * @return void
     */
    public static function onAfterIBlockElementUpdate(array &$arFields): void
    {
        // Check if result is successful
        if (isset($arFields['RESULT']) && $arFields['RESULT'] === false) {
            return;
        }
        
        $iblockId = (int)($arFields['IBLOCK_ID'] ?? 0);
        $elementId = (int)($arFields['ID'] ?? 0);
        
        if ($iblockId <= 0 || $elementId <= 0) {
            return;
        }
        
        // Check if we should track this iblock
        if (!IblockSettings::isIblockTracked($iblockId)) {
            return;
        }
        
        $url = self::getElementUrl($elementId, $iblockId);
        
        if ($url) {
            $priority = IblockSettings::getIblockPriority($iblockId);
            $queueId = self::addToQueue($url, 'URL_UPDATED', $iblockId, $elementId, null, $priority);
            
            if ($queueId) {
                LogTable::agent("Element updated: {$url} (IBLOCK: {$iblockId}, ELEMENT: {$elementId})");
            }
        }
    }

    /**
     * After IBlock element delete
     * 
     * @param array $arFields
     * @return void
     */
    public static function onAfterIBlockElementDelete(array $arFields): void
    {
        $iblockId = (int)($arFields['IBLOCK_ID'] ?? 0);
        $elementId = (int)($arFields['ID'] ?? 0);
        
        if ($iblockId <= 0 || $elementId <= 0) {
            return;
        }
        
        // Check if we should track this iblock
        if (!IblockSettings::isIblockTracked($iblockId)) {
            return;
        }
        
        $url = self::getElementUrl($elementId, $iblockId);
        
        if ($url) {
            $priority = IblockSettings::getIblockPriority($iblockId);
            $queueId = self::addToQueue($url, 'URL_DELETED', $iblockId, $elementId, null, $priority);
            
            if ($queueId) {
                LogTable::agent("Element deleted: {$url} (IBLOCK: {$iblockId}, ELEMENT: {$elementId})");
            }
        }
    }

    /**
     * After IBlock section add
     * 
     * @param array &$arFields
     * @return void
     */
    public static function onAfterIBlockSectionAdd(array &$arFields): void
    {
        if (!IblockSettings::isSectionTrackingEnabled()) {
            return;
        }
        
        // Check if result is successful
        if (isset($arFields['RESULT']) && $arFields['RESULT'] === false) {
            return;
        }
        
        $iblockId = (int)($arFields['IBLOCK_ID'] ?? 0);
        $sectionId = (int)($arFields['ID'] ?? 0);
        
        if ($iblockId <= 0 || $sectionId <= 0) {
            return;
        }
        
        $url = self::getSectionUrl($sectionId, $iblockId);
        
        if ($url) {
            $priority = IblockSettings::getIblockPriority($iblockId);
            $queueId = self::addToQueue($url, 'URL_UPDATED', $iblockId, null, $sectionId, $priority);
            
            if ($queueId) {
                LogTable::agent("Section added: {$url} (IBLOCK: {$iblockId}, SECTION: {$sectionId})");
            }
        }
    }

    /**
     * After IBlock section update
     * 
     * @param array &$arFields
     * @return void
     */
    public static function onAfterIBlockSectionUpdate(array &$arFields): void
    {
        if (!IblockSettings::isSectionTrackingEnabled()) {
            return;
        }
        
        // Check if result is successful
        if (isset($arFields['RESULT']) && $arFields['RESULT'] === false) {
            return;
        }
        
        $iblockId = (int)($arFields['IBLOCK_ID'] ?? 0);
        $sectionId = (int)($arFields['ID'] ?? 0);
        
        if ($iblockId <= 0 || $sectionId <= 0) {
            return;
        }
        
        $url = self::getSectionUrl($sectionId, $iblockId);
        
        if ($url) {
            $priority = IblockSettings::getIblockPriority($iblockId);
            $queueId = self::addToQueue($url, 'URL_UPDATED', $iblockId, null, $sectionId, $priority);
            
            if ($queueId) {
                LogTable::agent("Section updated: {$url} (IBLOCK: {$iblockId}, SECTION: {$sectionId})");
            }
        }
    }

    /**
     * After IBlock section delete
     * 
     * @param array $arFields
     * @return void
     */
    public static function onAfterIBlockSectionDelete(array $arFields): void
    {
        if (!IblockSettings::isSectionTrackingEnabled()) {
            return;
        }
        
        $iblockId = (int)($arFields['IBLOCK_ID'] ?? 0);
        $sectionId = (int)($arFields['ID'] ?? 0);
        
        if ($iblockId <= 0 || $sectionId <= 0) {
            return;
        }
        
        $url = self::getSectionUrl($sectionId, $iblockId);
        
        if ($url) {
            $priority = IblockSettings::getIblockPriority($iblockId);
            $queueId = self::addToQueue($url, 'URL_DELETED', $iblockId, null, $sectionId, $priority);
            
            if ($queueId) {
                LogTable::agent("Section deleted: {$url} (IBLOCK: {$iblockId}, SECTION: {$sectionId})");
            }
        }
    }
}
