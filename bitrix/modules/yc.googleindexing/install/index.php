<?php
/**
 * Модуль Google Indexing API PRO
 * Установка и удаление модуля
 * 
 * @package Yc\GoogleIndexing
 */

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Application;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

class yc_googleindexing extends CModule
{
    public $MODULE_ID = 'yc.googleindexing';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;
    public $MODULE_CSS;
    public $MODULE_GROUP_RIGHTS = 'Y';

    private $errors = [];

    public function __construct()
    {
        $arModuleVersion = [];
        include(__DIR__ . '/version.php');
        $this->MODULE_VERSION = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
        $this->MODULE_NAME = 'Google Indexing API PRO';
        $this->MODULE_DESCRIPTION = 'Модуль для автоматической отправки URL в Google Indexing API';
    }

    /**
     * Установка модуля
     */
    public function DoInstall()
    {
        global $APPLICATION, $step;

        $step = intval($step);
        if ($step < 1) {
            $this->ShowInstallStep1();
        } elseif ($step == 1) {
            $this->InstallDB();
            $this->InstallEvents();
            $this->InstallFiles();
            $this->InstallAgent();
            $this->ShowInstallStep2();
        }
    }

    /**
     * Удаление модуля
     */
    public function DoUnInstall($arParams = [])
    {
        global $APPLICATION, $step;

        $step = intval($step);
        if ($step < 1) {
            $this->ShowUnInstallStep1();
        } elseif ($step == 1) {
            $saveData = isset($arParams['save_tables']) && $arParams['save_tables'] === 'Y';
            
            $this->UnInstallEvents();
            $this->UnInstallFiles();
            $this->UnInstallAgent();
            
            if (!$saveData) {
                $this->UnInstallDB();
            }
            
            $this->ShowUnInstallStep2();
        }
    }

    /**
     * Шаг 1 установки
     */
    protected function ShowInstallStep1()
    {
        global $APPLICATION;
        ?>
        <form action="<?php echo $APPLICATION->GetCurPage(); ?>" method="post">
            <?php echo bitrix_sessid_post(); ?>
            <input type="hidden" name="lang" value="<?php echo LANG; ?>">
            <input type="hidden" name="id" value="<?php echo $this->MODULE_ID; ?>">
            <input type="hidden" name="install" value="Y">
            <input type="hidden" name="step" value="1">
            
            <p>Модуль "Google Indexing API PRO" позволяет автоматически отправлять URL страниц сайта в Google Indexing API.</p>
            
            <p><b>Возможности модуля:</b></p>
            <ul>
                <li>Автоматическая постановка URL в очередь при изменении элементов инфоблоков</li>
                <li>Фоновая отправка URL с учётом дневного лимита Google</li>
                <li>Удобная админ-панель для управления настройками</li>
                <li>Подробное логирование всех операций</li>
            </ul>
            
            <p><input type="checkbox" name="save_tables" value="Y" checked> Сохранить данные при удалении модуля</p>
            
            <input type="submit" name="inst" value="Установить модуль">
        </form>
        <?php
    }

    /**
     * Шаг 2 установки (успех)
     */
    protected function ShowInstallStep2()
    {
        global $APPLICATION;
        ?>
        <p>Модуль успешно установлен!</p>
        <p>Перейдите в <a href="/bitrix/admin/settings.php?lang=ru&module_id=<?php echo $this->MODULE_ID; ?>">настройки модуля</a> для загрузки JSON ключа сервис-аккаунта Google.</p>
        <p>Также доступна <a href="/bitrix/admin/yc_googleindexing_index.php">страница управления модулем</a>.</p>
        <?php
    }

    /**
     * Шаг 1 удаления
     */
    protected function ShowUnInstallStep1()
    {
        global $APPLICATION;
        ?>
        <form action="<?php echo $APPLICATION->GetCurPage(); ?>" method="post">
            <?php echo bitrix_sessid_post(); ?>
            <input type="hidden" name="lang" value="<?php echo LANG; ?>">
            <input type="hidden" name="id" value="<?php echo $this->MODULE_ID; ?>">
            <input type="hidden" name="uninstall" value="Y">
            <input type="hidden" name="step" value="1">
            
            <p>Вы уверены, что хотите удалить модуль "Google Indexing API PRO"?</p>
            
            <p><input type="checkbox" name="save_tables" value="Y" checked> Сохранить таблицы и данные модуля</p>
            <p>Если флаг не установлен, все данные (очередь, логи, настройки) будут удалены.</p>
            
            <input type="submit" name="inst" value="Удалить модуль">
        </form>
        <?php
    }

    /**
     * Шаг 2 удаления
     */
    protected function ShowUnInstallStep2()
    {
        ?>
        <p>Модуль успешно удалён!</p>
        <?php
    }

    /**
     * Установка базы данных
     */
    public function InstallDB()
    {
        global $DB, $APPLICATION;

        $this->errors = [];
        
        try {
            // Queue table
            $DB->Query("
                CREATE TABLE IF NOT EXISTS b_yc_gindex_queue (
                    ID int(11) NOT NULL AUTO_INCREMENT,
                    IBLOCK_ID int(11) DEFAULT NULL,
                    ELEMENT_ID int(11) DEFAULT NULL,
                    SECTION_ID int(11) DEFAULT NULL,
                    SITE_ID char(2) DEFAULT NULL,
                    URL varchar(512) NOT NULL,
                    TYPE enum('URL_UPDATED','URL_DELETED') DEFAULT 'URL_UPDATED',
                    STATUS enum('NEW','SENT','ERROR') DEFAULT 'NEW',
                    PRIORITY enum('LOW','MEDIUM','HIGH') DEFAULT 'MEDIUM',
                    HTTP_CODE varchar(10) DEFAULT NULL,
                    ATTEMPTS int(11) DEFAULT 0,
                    RETRY_AFTER datetime DEFAULT NULL,
                    LAST_ERROR text,
                    DATE_CREATE datetime NOT NULL,
                    DATE_SENT datetime DEFAULT NULL,
                    PRIMARY KEY (ID),
                    INDEX IX_STATUS (STATUS),
                    INDEX IX_PRIORITY (PRIORITY),
                    INDEX IX_DATE_CREATE (DATE_CREATE),
                    UNIQUE KEY UX_URL_STATUS (URL(255), STATUS)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Quota table
            $DB->Query("
                CREATE TABLE IF NOT EXISTS b_yc_gindex_quota (
                    ID int(11) NOT NULL AUTO_INCREMENT,
                    DATE_DAY date NOT NULL,
                    SENT_COUNT int(11) DEFAULT 0,
                    LIMIT_DAY int(11) DEFAULT 200,
                    DATE_CREATE datetime NOT NULL,
                    PRIMARY KEY (ID),
                    UNIQUE KEY UX_DATE_DAY (DATE_DAY)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Log table
            $DB->Query("
                CREATE TABLE IF NOT EXISTS b_yc_gindex_log (
                    ID int(11) NOT NULL AUTO_INCREMENT,
                    EVENT_TYPE varchar(50) NOT NULL,
                    MESSAGE text,
                    QUEUE_ID int(11) DEFAULT NULL,
                    DATE_CREATE datetime NOT NULL,
                    PRIMARY KEY (ID),
                    INDEX IX_DATE_CREATE (DATE_CREATE)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Credentials table
            $DB->Query("
                CREATE TABLE IF NOT EXISTS b_yc_gindex_credentials (
                    ID int(11) NOT NULL AUTO_INCREMENT,
                    JSON_KEY longtext NOT NULL,
                    SERVICE_EMAIL varchar(255) DEFAULT NULL,
                    TOKEN_EXPIRES int(11) DEFAULT NULL,
                    DATE_CREATE datetime NOT NULL,
                    PRIMARY KEY (ID)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Iblocks settings table
            $DB->Query("
                CREATE TABLE IF NOT EXISTS b_yc_gindex_iblocks (
                    ID int(11) NOT NULL AUTO_INCREMENT,
                    IBLOCK_ID int(11) NOT NULL,
                    PRIORITY enum('LOW','MEDIUM','HIGH') DEFAULT 'MEDIUM',
                    ACTIVE char(1) DEFAULT 'Y',
                    DATE_CREATE datetime NOT NULL,
                    PRIMARY KEY (ID),
                    UNIQUE KEY UX_IBLOCK_ID (IBLOCK_ID)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Register module in system
            RegisterModule($this->MODULE_ID);

        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            $APPLICATION->ThrowException($e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * Удаление базы данных
     */
    public function UnInstallDB()
    {
        global $DB;

        $DB->Query("DROP TABLE IF EXISTS b_yc_gindex_queue");
        $DB->Query("DROP TABLE IF EXISTS b_yc_gindex_quota");
        $DB->Query("DROP TABLE IF EXISTS b_yc_gindex_log");
        $DB->Query("DROP TABLE IF EXISTS b_yc_gindex_credentials");
        $DB->Query("DROP TABLE IF EXISTS b_yc_gindex_iblocks");

        UnRegisterModule($this->MODULE_ID);

        return true;
    }

    /**
     * Установка событий
     */
    public function InstallEvents()
    {
        $eventManager = \Bitrix\Main\EventManager::getInstance();
        
        // Register iblock event handlers
        $eventManager->registerEventHandler(
            'iblock',
            'OnAfterIBlockElementAdd',
            $this->MODULE_ID,
            'Yc\GoogleIndexing\EventHandler',
            'onAfterIBlockElementAdd'
        );
        
        $eventManager->registerEventHandler(
            'iblock',
            'OnAfterIBlockElementUpdate',
            $this->MODULE_ID,
            'Yc\GoogleIndexing\EventHandler',
            'onAfterIBlockElementUpdate'
        );
        
        $eventManager->registerEventHandler(
            'iblock',
            'OnAfterIBlockElementDelete',
            $this->MODULE_ID,
            'Yc\GoogleIndexing\EventHandler',
            'onAfterIBlockElementDelete'
        );

        $eventManager->registerEventHandler(
            'iblock',
            'OnAfterIBlockSectionAdd',
            $this->MODULE_ID,
            'Yc\GoogleIndexing\EventHandler',
            'onAfterIBlockSectionAdd'
        );

        $eventManager->registerEventHandler(
            'iblock',
            'OnAfterIBlockSectionUpdate',
            $this->MODULE_ID,
            'Yc\GoogleIndexing\EventHandler',
            'onAfterIBlockSectionUpdate'
        );

        $eventManager->registerEventHandler(
            'iblock',
            'OnAfterIBlockSectionDelete',
            $this->MODULE_ID,
            'Yc\GoogleIndexing\EventHandler',
            'onAfterIBlockSectionDelete'
        );

        return true;
    }

    /**
     * Удаление событий
     */
    public function UnInstallEvents()
    {
        $eventManager = \Bitrix\Main\EventManager::getInstance();
        
        $eventManager->unRegisterEventHandler(
            'main',
            'OnBuildGlobalMenu',
            $this->MODULE_ID,
            'Yc\GoogleIndexing\MenuManager',
            'onBuildGlobalMenu'
        );
        
        $eventManager->unRegisterEventHandler(
            'iblock',
            'OnAfterIBlockElementAdd',
            $this->MODULE_ID,
            'Yc\GoogleIndexing\EventHandler',
            'onAfterIBlockElementAdd'
        );
        
        $eventManager->unRegisterEventHandler(
            'iblock',
            'OnAfterIBlockElementUpdate',
            $this->MODULE_ID,
            'Yc\GoogleIndexing\EventHandler',
            'onAfterIBlockElementUpdate'
        );
        
        $eventManager->unRegisterEventHandler(
            'iblock',
            'OnAfterIBlockElementDelete',
            $this->MODULE_ID,
            'Yc\GoogleIndexing\EventHandler',
            'onAfterIBlockElementDelete'
        );

        $eventManager->unRegisterEventHandler(
            'iblock',
            'OnAfterIBlockSectionAdd',
            $this->MODULE_ID,
            'Yc\GoogleIndexing\EventHandler',
            'onAfterIBlockSectionAdd'
        );

        $eventManager->unRegisterEventHandler(
            'iblock',
            'OnAfterIBlockSectionUpdate',
            $this->MODULE_ID,
            'Yc\GoogleIndexing\EventHandler',
            'onAfterIBlockSectionUpdate'
        );

        $eventManager->unRegisterEventHandler(
            'iblock',
            'OnAfterIBlockSectionDelete',
            $this->MODULE_ID,
            'Yc\GoogleIndexing\EventHandler',
            'onAfterIBlockSectionDelete'
        );

        return true;
    }

    /**
     * Установка файлов
     */
    public function InstallFiles()
    {
        // Copy admin file to bitrix/admin/
        CopyDirFiles(
            __DIR__ . '/admin/',
            $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin/',
            true,
            true
        );

        return true;
    }

    /**
     * Удаление файлов
     */
    public function UnInstallFiles()
    {
        // Delete admin file from bitrix/admin/
        DeleteFile($_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin/yc_googleindexing_index.php');

        return true;
    }

    /**
     * Установка агента
     */
    public function InstallAgent()
    {
        // Agent is registered via CAgent
        // Interval in seconds (default 5 minutes = 300 seconds)
        $interval = Option::get($this->MODULE_ID, 'AGENT_INTERVAL', 300);
        
        CAgent::AddAgent(
            "\\Yc\\GoogleIndexing\\Agent::sendQueue();",
            $this->MODULE_ID,
            "N",
            $interval,
            "",
            "Y",
            "",
            100
        );

        return true;
    }

    /**
     * Удаление агента
     */
    public function UnInstallAgent()
    {
        CAgent::RemoveAgent("\\Yc\\GoogleIndexing\\Agent::sendQueue();");

        return true;
    }
}
