<?php
/**
 * Agent for processing queue
 * Background agent for sending URLs to Google Indexing API
 * 
 * @package Yc\GoogleIndexing
 * @version 1.0.0
 */

namespace Yc\GoogleIndexing;

use Bitrix\Main\Config\Option;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

class Agent
{
    private const MODULE_ID = 'yc.googleindexing';
    
    /**
     * Process queue - called by Bitrix agent
     * 
     * @return string Agent string for next run
     */
    public static function sendQueue(): string
    {
        try {
            // Check if module is installed
            if (!\Bitrix\Main\Loader::includeModule('yc.googleindexing')) {
                LogTable::error('Module not installed');
                return "\\Yc\\GoogleIndexing\\Agent::sendQueue();";
            }

            // Check quota
            $quota = QuotaTable::getStatus();
            
            if ($quota['sent'] >= $quota['limit']) {
                LogTable::quota('Daily quota exceeded: ' . $quota['sent'] . '/' . $quota['limit']);
                return "\\Yc\\GoogleIndexing\\Agent::sendQueue();";
            }

            // Get credentials
            $credentials = CredentialsTable::getCredentials();
            
            if (!$credentials || empty($credentials['json_key'])) {
                LogTable::oauthError('No credentials found');
                return "\\Yc\\GoogleIndexing\\Agent::sendQueue();";
            }

            // Get batch size
            $batchSize = Option::get(self::MODULE_ID, 'BATCH_SIZE', 50);
            
            // Calculate how many we can send
            $canSend = min($batchSize, $quota['remaining']);
            
            if ($canSend <= 0) {
                LogTable::quota('No remaining quota');
                return "\\Yc\\GoogleIndexing\\Agent::sendQueue();";
            }

            // Create Google API client
            $client = new GoogleApiClient($credentials['json_key']);

            // Get pending items
            $items = QueueTable::getPending($canSend);
            
            if (empty($items)) {
                LogTable::agent('No pending items in queue');
                return "\\Yc\\GoogleIndexing\\Agent::sendQueue();";
            }

            $successCount = 0;
            $errorCount = 0;

            foreach ($items as $item) {
                // Double check quota
                if (QuotaTable::isQuotaExceeded()) {
                    LogTable::quota('Quota exceeded during processing');
                    break;
                }

                try {
                    // Check if should retry
                    if ($item['RETRY_AFTER']) {
                        $retryTime = strtotime($item['RETRY_AFTER']);
                        if ($retryTime > time()) {
                            continue;
                        }
                    }

                    // Publish URL
                    $result = $client->publish($item['URL'], $item['TYPE']);

                    if ($result['success']) {
                        // Update status to SENT
                        QueueTable::updateStatus($item['ID'], 'SENT', '200', null);
                        
                        // Increment quota
                        QuotaTable::increment();
                        
                        // Log success if enabled
                        $logSuccess = Option::get(self::MODULE_ID, 'LOG_SUCCESS', 'N');
                        if ($logSuccess === 'Y') {
                            LogTable::success('URL sent: ' . $item['URL'], $item['ID']);
                        }
                        
                        $successCount++;
                    } else {
                        // Update status to ERROR
                        QueueTable::updateStatus(
                            $item['ID'],
                            'ERROR',
                            $result['http_code'] ?? '0',
                            $result['error'] ?? 'Unknown error'
                        );
                        
                        // Log error
                        LogTable::error(
                            'Failed to send URL: ' . $item['URL'] . ' - ' . ($result['error'] ?? 'HTTP ' . ($result['http_code'] ?? '0')),
                            $item['ID']
                        );
                        
                        $errorCount++;
                    }

                } catch (\Exception $e) {
                    QueueTable::updateStatus($item['ID'], 'ERROR', '0', $e->getMessage());
                    LogTable::error('Exception: ' . $e->getMessage(), $item['ID']);
                    $errorCount++;
                }
            }

            LogTable::agent("Queue processed: {$successCount} sent, {$errorCount} errors, " . ($quota['remaining'] - $successCount) . " remaining");

        } catch (\Exception $e) {
            LogTable::error('Agent error: ' . $e->getMessage());
        }

        // Return agent string for next run
        return "\\Yc\\GoogleIndexing\\Agent::sendQueue();";
    }

    /**
     * Manual run queue (for testing)
     * 
     * @param int $limit
     * @return array
     */
    public static function runManual(int $limit = 10): array
    {
        $result = [
            'success' => 0,
            'error' => 0,
            'total' => 0
        ];

        try {
            if (!\Bitrix\Main\Loader::includeModule('yc.googleindexing')) {
                return ['error' => 'Module not installed'];
            }

            $credentials = CredentialsTable::getCredentials();
            
            if (!$credentials || empty($credentials['json_key'])) {
                return ['error' => 'No credentials'];
            }

            $client = new GoogleApiClient($credentials['json_key']);
            $items = QueueTable::getPending($limit);

            foreach ($items as $item) {
                $res = $client->publish($item['URL'], $item['TYPE']);
                
                if ($res['success']) {
                    QueueTable::updateStatus($item['ID'], 'SENT', '200', null);
                    QuotaTable::increment();
                    $result['success']++;
                } else {
                    QueueTable::updateStatus($item['ID'], 'ERROR', $res['http_code'] ?? '0', $res['error'] ?? '');
                    $result['error']++;
                }
                
                $result['total']++;
            }

        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }

        return $result;
    }

    /**
     * Clean up old data
     * 
     * @return void
     */
    public static function cleanup(): void
    {
        if (!\Bitrix\Main\Loader::includeModule('yc.googleindexing')) {
            return;
        }

        $logRetention = Option::get(self::MODULE_ID, 'LOG_RETENTION_DAYS', 30);
        
        LogTable::cleanup($logRetention);
        QueueTable::cleanup($logRetention);
        
        LogTable::agent('Cleanup completed: logs and queue older than ' . $logRetention . ' days');
    }
}
