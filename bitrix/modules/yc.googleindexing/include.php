<?php
/**
 * Google Indexing API PRO - main include file
 * 
 * @package Yc\GoogleIndexing
 * @version 1.0.0
 */

use Bitrix\Main\Loader;
use Bitrix\Main\EventManager;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

\Bitrix\Main\Loader::registerAutoLoadClasses(
    'yc.googleindexing',
    [
        // Classes
        'Yc\GoogleIndexing\GoogleApiClient' => 'lib/GoogleApiClient.php',
        'Yc\GoogleIndexing\JwtHelper' => 'lib/JwtHelper.php',
        'Yc\GoogleIndexing\QueueTable' => 'lib/QueueTable.php',
        'Yc\GoogleIndexing\LogTable' => 'lib/LogTable.php',
        'Yc\GoogleIndexing\CredentialsTable' => 'lib/CredentialsTable.php',
        'Yc\GoogleIndexing\QuotaTable' => 'lib/QuotaTable.php',
        'Yc\GoogleIndexing\EventHandler' => 'lib/EventHandler.php',
        'Yc\GoogleIndexing\Agent' => 'lib/Agent.php',
        'Yc\GoogleIndexing\MenuManager' => 'lib/MenuManager.php',
        'Yc\GoogleIndexing\IblockSettings' => 'lib/IblockSettings.php',
    ]
);

// Register global menu handler
EventManager::getInstance()->addEventHandler(
    'main',
    'OnBuildGlobalMenu',
    ['\Yc\GoogleIndexing\MenuManager', 'onBuildGlobalMenu']
);
