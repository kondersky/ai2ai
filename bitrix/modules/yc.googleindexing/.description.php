<?php
/**
 * @deprecated deprecated since version 1.0.0
 * Module description.
 */
$moduleTitle = 'Google Indexing API PRO';
$moduleDescription = <<<EOD
Модуль для автоматической отправки URL страниц сайта в Google Indexing API при создании, обновлении или удалении элементов инфоблоков.

Возможности модуля:
- Автоматическая постановка URL в очередь при изменении элементов инфоблоков
- Фоновая отправка URL с учётом дневного лимита Google (по умолчанию 200 запросов/сутки)
- Удобная админ-панель для управления настройками и мониторинга
- Поддержка JobPosting и BroadcastEvent страниц для Google
- Логирование всех операций и обработка ошибок
EOD;
