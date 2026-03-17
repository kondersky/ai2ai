<?php
/**
 * Google Indexing API PRO - Admin Page
 * 
 * @package Yc\GoogleIndexing
 */

require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin.php");

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Yc\GoogleIndexing\GoogleApiClient;
use Yc\GoogleIndexing\QueueTable;
use Yc\GoogleIndexing\LogTable;
use Yc\GoogleIndexing\CredentialsTable;
use Yc\GoogleIndexing\QuotaTable;
use Yc\GoogleIndexing\Agent;

$moduleId = 'yc.googleindexing';

// Check module is installed
if (!Loader::includeModule($moduleId)) {
    require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_admin.php");
    die();
}

$action = $_REQUEST['action'] ?? '';
$tabActive = $_REQUEST['tab'] ?? 'main';

// Handle AJAX actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action) {
    header('Content-Type: application/json');
    
    try {
        switch ($action) {
            case 'save_credentials':
                $jsonKey = $_POST['json_key'] ?? '';
                
                if (empty($jsonKey)) {
                    echo json_encode(['success' => false, 'error' => 'JSON key is required']);
                    break;
                }
                
                // Validate JSON
                $data = json_decode($jsonKey, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    echo json_encode(['success' => false, 'error' => 'Invalid JSON: ' . json_last_error_msg()]);
                    break;
                }
                
                if (empty($data['client_email']) || empty($data['private_key'])) {
                    echo json_encode(['success' => false, 'error' => 'Missing required fields: client_email or private_key']);
                    break;
                }
                
                $result = CredentialsTable::saveCredentials($jsonKey);
                
                if ($result) {
                    LogTable::credentials('Credentials saved');
                    echo json_encode(['success' => true, 'message' => 'Credentials saved successfully']);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Failed to save credentials']);
                }
                break;
                
            case 'check_auth':
                $credentials = CredentialsTable::getCredentials();
                
                if (!$credentials || empty($credentials['json_key'])) {
                    echo json_encode(['success' => false, 'error' => 'No credentials found']);
                    break;
                }
                
                try {
                    $client = new GoogleApiClient($credentials['json_key']);
                    $result = $client->checkAuthorization();
                    
                    if ($result['success']) {
                        // Also get quota status
                        $quota = QuotaTable::getStatus();
                        $result['quota'] = $quota;
                    }
                    
                    echo json_encode($result);
                } catch (\Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;
                
            case 'test_publish':
                $testUrl = $_POST['test_url'] ?? '';
                $credentials = CredentialsTable::getCredentials();
                
                if (!$credentials || empty($credentials['json_key'])) {
                    echo json_encode(['success' => false, 'error' => 'No credentials found']);
                    break;
                }
                
                if (empty($testUrl)) {
                    echo json_encode(['success' => false, 'error' => 'Test URL is required']);
                    break;
                }
                
                try {
                    $client = new GoogleApiClient($credentials['json_key']);
                    $result = $client->testPublish($testUrl);
                    
                    if ($result['success']) {
                        QuotaTable::increment();
                        LogTable::success('Test publish successful: ' . $testUrl);
                    } else {
                        LogTable::error('Test publish failed: ' . ($result['error'] ?? 'Unknown error'));
                    }
                    
                    echo json_encode($result);
                } catch (\Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;
                
            case 'run_agent':
                $limit = intval($_POST['limit'] ?? 10);
                $result = Agent::runManual($limit);
                echo json_encode($result);
                break;
                
            case 'cleanup_logs':
                $days = intval($_POST['days'] ?? 30);
                $count = LogTable::cleanup($days);
                echo json_encode(['success' => true, 'deleted' => $count]);
                break;
                
            case 'get_queue':
                $status = $_POST['status'] ?? '';
                $limit = intval($_POST['limit'] ?? 50);
                
                $filter = [];
                if (!empty($status)) {
                    $filter['STATUS'] = $status;
                }
                
                $items = QueueTable::getList($filter, $limit);
                echo json_encode(['items' => $items]);
                break;
                
            case 'get_logs':
                $limit = intval($_POST['limit'] ?? 100);
                $items = LogTable::getList($limit);
                echo json_encode(['items' => $items]);
                break;
                
            case 'export_csv':
                $items = QueueTable::getList([], 10000);
                
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename=google_indexing_queue_' . date('Y-m-d') . '.csv');
                
                // BOM for UTF-8
                echo "\xEF\xBB\xBF";
                
                // Header
                echo '"ID","IBLOCK_ID","ELEMENT_ID","URL","TYPE","STATUS","PRIORITY","HTTP_CODE","DATE_CREATE","DATE_SENT"' . "\n";
                
                foreach ($items as $item) {
                    echo '"' . $item['ID'] . '",';
                    echo '"' . ($item['IBLOCK_ID'] ?? '') . '",';
                    echo '"' . ($item['ELEMENT_ID'] ?? '') . '",';
                    echo '"' . str_replace('"', '""', $item['URL']) . '",';
                    echo '"' . $item['TYPE'] . '",';
                    echo '"' . $item['STATUS'] . '",';
                    echo '"' . $item['PRIORITY'] . '",';
                    echo '"' . ($item['HTTP_CODE'] ?? '') . '",';
                    echo '"' . $item['DATE_CREATE'] . '",';
                    echo '"' . ($item['DATE_SENT'] ?? '') . '"';
                    echo "\n";
                }
                break;
                
            default:
                echo json_encode(['success' => false, 'error' => 'Unknown action']);
        }
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    
    require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_admin.php");
    die();
}

// Get statistics
$queueStats = QueueTable::getStats();
$quota = QuotaTable::getStatus();
$errorCountToday = LogTable::getErrorCountToday();
$credentials = CredentialsTable::getCredentials();
?>
<style>
.yc-gindex-wrap {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    padding: 20px;
    max-width: 1200px;
    margin: 0 auto;
}

.yc-gindex-header {
    margin-bottom: 20px;
}

.yc-gindex-header h1 {
    margin: 0;
    color: #333;
}

.yc-gindex-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.yc-gindex-stat-card {
    background: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    padding: 15px;
}

.yc-gindex-stat-card .label {
    font-size: 12px;
    color: #666;
    margin-bottom: 5px;
}

.yc-gindex-stat-card .value {
    font-size: 24px;
    font-weight: bold;
    color: #333;
}

.yc-gindex-stat-card.warning .value {
    color: #f57c00;
}

.yc-gindex-stat-card.success .value {
    color: #388e3c;
}

.yc-gindex-stat-card.error .value {
    color: #d32f2f;
}

/* Tabs */
.yc-gindex-tabs {
    display: flex;
    border-bottom: 2px solid #e0e0e0;
    margin-bottom: 20px;
}

.yc-gindex-tab {
    padding: 10px 20px;
    cursor: pointer;
    border: none;
    background: none;
    font-size: 14px;
    color: #666;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    transition: all 0.2s;
}

.yc-gindex-tab:hover {
    color: #333;
}

.yc-gindex-tab.active {
    color: #1e87f0;
    border-bottom-color: #1e87f0;
}

/* Tab content */
.yc-gindex-tab-content {
    display: none;
}

.yc-gindex-tab-content.active {
    display: block;
}

/* Forms */
.yc-gindex-form-group {
    margin-bottom: 15px;
}

.yc-gindex-form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
    color: #333;
}

.yc-gindex-form-group textarea {
    width: 100%;
    min-height: 200px;
    padding: 10px;
    border: 1px solid #ccc;
    border-radius: 4px;
    font-family: monospace;
    font-size: 12px;
}

.yc-gindex-form-group input[type="text"],
.yc-gindex-form-group input[type="number"] {
    width: 100%;
    padding: 8px;
    border: 1px solid #ccc;
    border-radius: 4px;
}

.yc-gindex-btn {
    padding: 8px 16px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.2s;
}

.yc-gindex-btn-primary {
    background: #1e87f0;
    color: white;
}

.yc-gindex-btn-primary:hover {
    background: #0b7ae6;
}

.yc-gindex-btn-secondary {
    background: #f5f5f5;
    color: #333;
    border: 1px solid #ddd;
}

.yc-gindex-btn-secondary:hover {
    background: #e8e8e8;
}

.yc-gindex-btn-success {
    background: #4caf50;
    color: white;
}

.yc-gindex-btn-danger {
    background: #f44336;
    color: white;
}

/* Tables */
.yc-gindex-table {
    width: 100%;
    border-collapse: collapse;
    background: #fff;
    font-size: 13px;
}

.yc-gindex-table th,
.yc-gindex-table td {
    padding: 10px;
    text-align: left;
    border-bottom: 1px solid #eee;
}

.yc-gindex-table th {
    background: #f8f9fa;
    font-weight: 600;
    color: #333;
}

.yc-gindex-table tr:hover {
    background: #f8f9fa;
}

.status-new { color: #2196f3; }
.status-sent { color: #4caf50; }
.status-error { color: #f44336; }

.type-updated { color: #2196f3; }
.type-deleted { color: #ff9800; }

/* Result blocks */
.yc-gindex-result {
    padding: 15px;
    border-radius: 4px;
    margin-top: 15px;
    display: none;
}

.yc-gindex-result.success {
    background: #e8f5e9;
    border: 1px solid #c8e6c9;
    color: #2e7d32;
    display: block;
}

.yc-gindex-result.error {
    background: #ffebee;
    border: 1px solid #ffcdd2;
    color: #c62828;
    display: block;
}

.yc-gindex-result.loading {
    background: #e3f2fd;
    border: 1px solid #bbdefb;
    color: #1565c0;
    display: block;
}
</style>

<div class="yc-gindex-wrap">
    <div class="yc-gindex-header">
        <h1>Google Indexing API PRO</h1>
    </div>

    <!-- Statistics -->
    <div class="yc-gindex-stats">
        <div class="yc-gindex-stat-card">
            <div class="label">Отправлено сегодня</div>
            <div class="value"><?php echo $quota['sent']; ?> / <?php echo $quota['limit']; ?></div>
        </div>
        <div class="yc-gindex-stat-card <?php echo $quota['remaining'] < 50 ? 'warning' : 'success'; ?>">
            <div class="label">Осталось</div>
            <div class="value"><?php echo $quota['remaining']; ?></div>
        </div>
        <div class="yc-gindex-stat-card">
            <div class="label">В очереди (NEW)</div>
            <div class="value"><?php echo $queueStats['NEW'] ?? 0; ?></div>
        </div>
        <div class="yc-gindex-stat-card <?php echo $errorCountToday > 0 ? 'error' : ''; ?>">
            <div class="label">Ошибок сегодня</div>
            <div class="value"><?php echo $errorCountToday; ?></div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="yc-gindex-tabs">
        <button class="yc-gindex-tab <?php echo $tabActive === 'main' ? 'active' : ''; ?>" data-tab="main">Главная</button>
        <button class="yc-gindex-tab <?php echo $tabActive === 'settings' ? 'active' : ''; ?>" data-tab="settings">Настройки</button>
        <button class="yc-gindex-tab <?php echo $tabActive === 'queue' ? 'active' : ''; ?>" data-tab="queue">Очередь</button>
        <button class="yc-gindex-tab <?php echo $tabActive === 'logs' ? 'active' : ''; ?>" data-tab="logs">Логи</button>
    </div>

    <!-- Main Tab -->
    <div id="tab-main" class="yc-gindex-tab-content <?php echo $tabActive === 'main' ? 'active' : ''; ?>">
        <h2>Статистика</h2>
        
        <div class="yc-gindex-stats">
            <div class="yc-gindex-stat-card">
                <div class="label">Всего в очереди</div>
                <div class="value"><?php echo $queueStats['total'] ?? 0; ?></div>
            </div>
            <div class="yc-gindex-stat-card success">
                <div class="label">Отправлено</div>
                <div class="value"><?php echo $queueStats['SENT'] ?? 0; ?></div>
            </div>
            <div class="yc-gindex-stat-card error">
                <div class="label">Ошибок</div>
                <div class="value"><?php echo $queueStats['ERROR'] ?? 0; ?></div>
            </div>
        </div>
        
        <h3>Квота Google</h3>
        <table class="yc-gindex-table">
            <tr>
                <th>Дата</th>
                <th>Отправлено</th>
                <th>Лимит</th>
                <th>Процент</th>
            </tr>
            <?php
            $history = QuotaTable::getHistory(7);
            foreach ($history as $day): ?>
            <tr>
                <td><?php echo $day['date']; ?></td>
                <td><?php echo $day['sent']; ?></td>
                <td><?php echo $day['limit']; ?></td>
                <td><?php echo $day['percentage']; ?>%</td>
            </tr>
            <?php endforeach; ?>
        </table>
        
        <h3>Последние логи</h3>
        <table class="yc-gindex-table">
            <tr>
                <th>Время</th>
                <th>Тип</th>
                <th>Сообщение</th>
            </tr>
            <?php
            $logs = LogTable::getList(10);
            foreach ($logs as $log): ?>
            <tr>
                <td><?php echo $log['DATE_CREATE']; ?></td>
                <td><?php echo $log['EVENT_TYPE']; ?></td>
                <td><?php echo htmlspecialchars($log['MESSAGE']); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <!-- Settings Tab -->
    <div id="tab-settings" class="yc-gindex-tab-content <?php echo $tabActive === 'settings' ? 'active' : ''; ?>">
        <h2>Настройки подключения</h2>
        
        <?php if ($credentials): ?>
        <div class="yc-gindex-result success" style="display: block;">
            <strong>Подключено:</strong> <?php echo htmlspecialchars($credentials['service_email']); ?>
        </div>
        <?php endif; ?>
        
        <form id="credentials-form">
            <div class="yc-gindex-form-group">
                <label>JSON ключ сервис-аккаунта Google:</label>
                <textarea name="json_key" id="json_key" placeholder='{
  "type": "service_account",
  "project_id": "...",
  "private_key_id": "...",
  "private_key": "-----BEGIN PRIVATE KEY-----\n...",
  "client_email": "...",
  "client_id": "...",
  "auth_uri": "https://accounts.google.com/o/oauth2/auth",
  "token_uri": "https://oauth2.googleapis.com/token",
  "auth_provider_x509_cert_url": "https://www.googleapis.com/oauth2/v1/certs",
  "client_x509_cert_url": "..."
}'><?php echo htmlspecialchars($credentials['json_key'] ?? ''); ?></textarea>
            </div>
            
            <button type="submit" class="yc-gindex-btn yc-gindex-btn-primary">Сохранить</button>
            <button type="button" id="check_auth_btn" class="yc-gindex-btn yc-gindex-btn-success">Проверить авторизацию</button>
        </form>
        
        <div id="auth_result" class="yc-gindex-result"></div>
        
        <hr style="margin: 30px 0;">
        
        <h2>Тестовая отправка</h2>
        <form id="test-form">
            <div class="yc-gindex-form-group">
                <label>URL для теста:</label>
                <input type="text" name="test_url" id="test_url" placeholder="https://example.com/page">
            </div>
            <button type="submit" class="yc-gindex-btn yc-gindex-btn-primary">Отправить тестовый URL</button>
        </form>
        
        <div id="test_result" class="yc-gindex-result"></div>
        
        <hr style="margin: 30px 0;">
        
        <h2>Ручной запуск</h2>
        <p>Запустить обработку очереди вручную (max 10 URL):</p>
        <button type="button" id="run_agent_btn" class="yc-gindex-btn yc-gindex-btn-secondary">Запустить агент</button>
        <div id="agent_result" class="yc-gindex-result"></div>
    </div>

    <!-- Queue Tab -->
    <div id="tab-queue" class="yc-gindex-tab-content <?php echo $tabActive === 'queue' ? 'active' : ''; ?>">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h2>Очередь URL</h2>
            <button type="button" id="export_csv_btn" class="yc-gindex-btn yc-gindex-btn-secondary">Экспорт CSV</button>
        </div>
        
        <table class="yc-gindex-table" id="queue-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>URL</th>
                    <th>Тип</th>
                    <th>Статус</th>
                    <th>Приоритет</th>
                    <th>HTTP</th>
                    <th>Создано</th>
                    <th>Отправлено</th>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="8">Загрузка...</td></tr>
            </tbody>
        </table>
    </div>

    <!-- Logs Tab -->
    <div id="tab-logs" class="yc-gindex-tab-content <?php echo $tabActive === 'logs' ? 'active' : ''; ?>">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h2>Логи</h2>
            <button type="button" id="cleanup_logs_btn" class="yc-gindex-btn yc-gindex-btn-danger">Очистить логи старше 30 дней</button>
        </div>
        
        <table class="yc-gindex-table" id="logs-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Время</th>
                    <th>Тип</th>
                    <th>Сообщение</th>
                    <th>Queue ID</th>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="5">Загрузка...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Tab switching
    document.querySelectorAll('.yc-gindex-tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            var tabId = this.dataset.tab;
            
            document.querySelectorAll('.yc-gindex-tab').forEach(function(t) { t.classList.remove('active'); });
            document.querySelectorAll('.yc-gindex-tab-content').forEach(function(c) { c.classList.remove('active'); });
            
            this.classList.add('active');
            document.getElementById('tab-' + tabId).classList.add('active');
            
            // Load data for tab
            if (tabId === 'queue') loadQueue();
            if (tabId === 'logs') loadLogs();
        });
    });
    
    // Credentials form
    document.getElementById('credentials-form').addEventListener('submit', function(e) {
        e.preventDefault();
        var jsonKey = document.getElementById('json_key').value;
        
        var formData = new FormData();
        formData.append('action', 'save_credentials');
        formData.append('json_key', jsonKey);
        
        var result = document.getElementById('auth_result');
        result.className = 'yc-gindex-result loading';
        result.textContent = 'Сохранение...';
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                result.className = 'yc-gindex-result success';
                result.textContent = data.message;
            } else {
                result.className = 'yc-gindex-result error';
                result.textContent = data.error;
            }
        })
        .catch(err => {
            result.className = 'yc-gindex-result error';
            result.textContent = 'Ошибка: ' + err.message;
        });
    });
    
    // Check auth
    document.getElementById('check_auth_btn').addEventListener('click', function() {
        var result = document.getElementById('auth_result');
        result.className = 'yc-gindex-result loading';
        result.textContent = 'Проверка авторизации...';
        
        var formData = new FormData();
        formData.append('action', 'check_auth');
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                result.className = 'yc-gindex-result success';
                result.innerHTML = '<strong>Авторизация успешна!</strong><br>' +
                    'Email: ' + data.email + '<br>' +
                    'Квота: ' + (data.quota?.sent || 0) + ' / ' + (data.quota?.limit || 200);
            } else {
                result.className = 'yc-gindex-result error';
                result.textContent = data.error;
            }
        })
        .catch(err => {
            result.className = 'yc-gindex-result error';
            result.textContent = 'Ошибка: ' + err.message;
        });
    });
    
    // Test publish
    document.getElementById('test-form').addEventListener('submit', function(e) {
        e.preventDefault();
        var testUrl = document.getElementById('test_url').value;
        
        var result = document.getElementById('test_result');
        result.className = 'yc-gindex-result loading';
        result.textContent = 'Отправка...';
        
        var formData = new FormData();
        formData.append('action', 'test_publish');
        formData.append('test_url', testUrl);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                result.className = 'yc-gindex-result success';
                result.textContent = 'URL успешно отправлен в Google!';
            } else {
                result.className = 'yc-gindex-result error';
                result.textContent = data.error;
            }
        })
        .catch(err => {
            result.className = 'yc-gindex-result error';
            result.textContent = 'Ошибка: ' + err.message;
        });
    });
    
    // Run agent
    document.getElementById('run_agent_btn').addEventListener('click', function() {
        var result = document.getElementById('agent_result');
        result.className = 'yc-gindex-result loading';
        result.textContent = 'Запуск...';
        
        var formData = new FormData();
        formData.append('action', 'run_agent');
        formData.append('limit', 10);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                result.className = 'yc-gindex-result error';
                result.textContent = data.error;
            } else {
                result.className = 'yc-gindex-result success';
                result.textContent = 'Отправлено: ' + data.success + ', Ошибок: ' + data.error;
                // Reload queue
                loadQueue();
            }
        })
        .catch(err => {
            result.className = 'yc-gindex-result error';
            result.textContent = 'Ошибка: ' + err.message;
        });
    });
    
    // Cleanup logs
    document.getElementById('cleanup_logs_btn').addEventListener('click', function() {
        if (!confirm('Удалить логи старше 30 дней?')) return;
        
        var formData = new FormData();
        formData.append('action', 'cleanup_logs');
        formData.append('days', 30);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('Удалено ' + data.deleted + ' записей');
                loadLogs();
            } else {
                alert('Ошибка: ' + data.error);
            }
        });
    });
    
    // Export CSV
    document.getElementById('export_csv_btn').addEventListener('click', function() {
        var formData = new FormData();
        formData.append('action', 'export_csv');
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(r => r.blob())
        .then(blob => {
            var url = window.URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = 'google_indexing_queue.csv';
            a.click();
            window.URL.revokeObjectURL(url);
        });
    });
    
    // Load queue
    function loadQueue() {
        var formData = new FormData();
        formData.append('action', 'get_queue');
        formData.append('limit', 50);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            var tbody = document.querySelector('#queue-table tbody');
            if (data.items && data.items.length > 0) {
                tbody.innerHTML = data.items.map(function(item) {
                    var statusClass = 'status-' + item.STATUS.toLowerCase();
                    var typeClass = 'type-' + (item.TYPE === 'URL_UPDATED' ? 'updated' : 'deleted');
                    return '<tr>' +
                        '<td>' + item.ID + '</td>' +
                        '<td title="' + item.URL + '">' + (item.URL.length > 50 ? item.URL.substring(0, 50) + '...' : item.URL) + '</td>' +
                        '<td class="' + typeClass + '">' + item.TYPE + '</td>' +
                        '<td class="' + statusClass + '">' + item.STATUS + '</td>' +
                        '<td>' + item.PRIORITY + '</td>' +
                        '<td>' + (item.HTTP_CODE || '-') + '</td>' +
                        '<td>' + item.DATE_CREATE + '</td>' +
                        '<td>' + (item.DATE_SENT || '-') + '</td>' +
                        '</tr>';
                }).join('');
            } else {
                tbody.innerHTML = '<tr><td colspan="8">Нет данных</td></tr>';
            }
        });
    }
    
    // Load logs
    function loadLogs() {
        var formData = new FormData();
        formData.append('action', 'get_logs');
        formData.append('limit', 50);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            var tbody = document.querySelector('#logs-table tbody');
            if (data.items && data.items.length > 0) {
                tbody.innerHTML = data.items.map(function(item) {
                    return '<tr>' +
                        '<td>' + item.ID + '</td>' +
                        '<td>' + item.DATE_CREATE + '</td>' +
                        '<td>' + item.EVENT_TYPE + '</td>' +
                        '<td>' + (item.MESSAGE ? item.MESSAGE.substring(0, 100) : '') + '</td>' +
                        '<td>' + (item.QUEUE_ID || '-') + '</td>' +
                        '</tr>';
                }).join('');
            } else {
                tbody.innerHTML = '<tr><td colspan="5">Нет данных</td></tr>';
            }
        });
    }
});
</script>

<?php
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_admin.php");
