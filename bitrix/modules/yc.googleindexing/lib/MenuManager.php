<?php
/**
 * Menu Manager for module
 * Adds menu item to Bitrix admin sidebar
 * 
 * @package Yc\GoogleIndexing
 * @since 1.0.0
 */

namespace Yc\GoogleIndexing;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

class MenuManager
{
    const MODULE_ID = 'yc.googleindexing';
    
    /**
     * Add menu item to admin sidebar
     * 
     * @param array &$aGlobalMenu
     * @param array &$aModuleMenu
     * @return void
     */
    public static function onBuildGlobalMenu(array &$aGlobalMenu, array &$aModuleMenu)
    {
        // Check if module is installed
        if (!\Bitrix\Main\Loader::includeModule(self::MODULE_ID)) {
            return;
        }
        
        // Add to "Services" menu as separate section
        $aModuleMenu[] = [
            'parent_menu' => 'global_menu_services',
            'sort' => 400,
            'text' => GetMessage('YC_GOOGLEINDEXING_MENU_TEXT'),
            'title' => GetMessage('YC_GOOGLEINDEXING_MENU_TITLE'),
            'url' => 'yc_googleindexing_index.php?lang=' . LANG,
            'more_url' => [
                'yc_googleindexing_index.php'
            ],
            'icon' => 'svc_menu_icon',
            'page_icon' => 'default_page_icon',
            'items_id' => 'menu_yc_googleindexing',
            'section' => 'YcGoogleIndexing'
        ];
    }
}
