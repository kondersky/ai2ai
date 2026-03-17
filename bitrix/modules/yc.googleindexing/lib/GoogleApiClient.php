<?php
/**
 * Google API Client for Indexing API
 * 
 * @package Yc\GoogleIndexing
 */

namespace Yc\GoogleIndexing;

use Exception;

class GoogleApiClient
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const INDEXING_API_URL = 'https://indexing.googleapis.com/v3/urlNotifications:publish';
    
    /** @var array|null */
    private $serviceAccount;
    
    /** @var string|null */
    private $accessToken;
    
    /** @var int|null */
    private $tokenExpires;
    
    /** @var string|null */
    private $tempFile;

    /**
     * Constructor
     * 
     * @param string|null $jsonKey JSON key content or null to load from DB
     */
    public function __construct(?string $jsonKey = null)
    {
        if ($jsonKey !== null) {
            $this->loadFromJson($jsonKey);
        }
    }

    /**
     * Load service account from JSON content
     * 
     * @param string $jsonKey
     * @return self
     * @throws Exception
     */
    public function loadFromJson(string $jsonKey): self
    {
        $data = json_decode($jsonKey, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON: ' . json_last_error_msg());
        }
        
        if (empty($data['client_email']) || empty($data['private_key'])) {
            throw new Exception('Invalid service account format');
        }
        
        $this->serviceAccount = $data;
        return $this;
    }

    /**
     * Get access token using JWT
     * 
     * @return string
     * @throws Exception
     */
    public function getAccessToken(): string
    {
        // Check if we have valid cached token
        if ($this->accessToken !== null && $this->tokenExpires !== null && time() < $this->tokenExpires) {
            return $this->accessToken;
        }

        if ($this->serviceAccount === null) {
            throw new Exception('Service account not loaded');
        }

        // Create JWT
        $jwt = JwtHelper::create($this->serviceAccount);

        // Exchange JWT for access token
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => self::TOKEN_URL,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded'
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200) {
            $errorMsg = 'Failed to get access token';
            if ($response) {
                $respData = json_decode($response, true);
                if (isset($respData['error_description'])) {
                    $errorMsg .= ': ' . $respData['error_description'];
                } elseif (isset($respData['error'])) {
                    $errorMsg .= ': ' . $respData['error'];
                }
            }
            throw new Exception($errorMsg . ' (HTTP ' . $httpCode . ')');
        }

        $tokenData = json_decode($response, true);
        
        if (empty($tokenData['access_token'])) {
            throw new Exception('No access token in response');
        }

        $this->accessToken = $tokenData['access_token'];
        $this->tokenExpires = time() + ($tokenData['expires_in'] ?? 3600) - 60; // 60 seconds buffer

        return $this->accessToken;
    }

    /**
     * Publish URL to Google Indexing API
     * 
     * @param string $url URL to publish
     * @param string $type URL_UPDATED or URL_DELETED
     * @return array
     * @throws Exception
     */
    public function publish(string $url, string $type = 'URL_UPDATED'): array
    {
        $token = $this->getAccessToken();

        $postData = json_encode([
            'url' => $url,
            'type' => $type
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => self::INDEXING_API_URL,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json'
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $result = [
            'success' => false,
            'http_code' => $httpCode,
            'url' => $url,
            'type' => $type,
            'response' => ''
        ];

        if ($httpCode === 200) {
            $result['success'] = true;
            $result['response'] = 'URL successfully submitted to Google';
        } else {
            $errorMsg = 'HTTP ' . $httpCode;
            if ($response) {
                $respData = json_decode($response, true);
                if (isset($respData['error'])) {
                    $errorMsg = $respData['error']['message'] ?? $respData['error'];
                }
                $result['response'] = substr($response, 0, 1000);
            }
            $result['error'] = $errorMsg;
        }

        return $result;
    }

    /**
     * Check authorization
     * 
     * @return array
     */
    public function checkAuthorization(): array
    {
        try {
            $token = $this->getAccessToken();
            
            return [
                'success' => true,
                'email' => $this->serviceAccount['client_email'] ?? '',
                'token_expires' => $this->tokenExpires,
                'message' => 'Authorization successful'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Test publish with a URL
     * 
     * @param string $url
     * @return array
     */
    public function testPublish(string $url): array
    {
        try {
            return $this->publish($url, 'URL_UPDATED');
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'url' => $url
            ];
        }
    }

    /**
     * Get current quota status
     * 
     * @return array
     */
    public function getQuotaStatus(): array
    {
        return QuotaTable::getStatus();
    }

    /**
     * Clean up temporary files
     */
    public function __destruct()
    {
        if ($this->tempFile && file_exists($this->tempFile)) {
            @unlink($this->tempFile);
        }
    }

    /**
     * Create temporary file with JSON key
     * 
     * @return string
     * @throws Exception
     */
    public function createTempFile(): string
    {
        if ($this->serviceAccount === null) {
            throw new Exception('Service account not loaded');
        }

        $tempDir = sys_get_temp_dir();
        $this->tempFile = $tempDir . '/google_indexing_' . uniqid() . '.json';
        
        $result = file_put_contents($this->tempFile, json_encode($this->serviceAccount));
        
        if ($result === false) {
            throw new Exception('Failed to create temporary file');
        }
        
        chmod($this->tempFile, 0600);
        
        return $this->tempFile;
    }
}
