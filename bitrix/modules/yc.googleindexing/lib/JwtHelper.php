<?php
/**
 * JWT Helper for Google Service Account
 * 
 * @package Yc\GoogleIndexing
 */

namespace Yc\GoogleIndexing;

class JwtHelper
{
    /**
     * Create JWT token for Google Service Account
     * 
     * @param array $serviceAccount Service account data from JSON key
     * @return string JWT token
     * @throws \Exception
     */
    public static function create(array $serviceAccount): string
    {
        if (empty($serviceAccount['client_email'])) {
            throw new \Exception('Missing client_email in service account');
        }
        
        if (empty($serviceAccount['private_key'])) {
            throw new \Exception('Missing private_key in service account');
        }

        $now = time();
        $expire = $now + 3600; // 1 hour

        // Header
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT'
        ];

        // Payload (Claims)
        $payload = [
            'iss' => $serviceAccount['client_email'],
            'scope' => 'https://www.googleapis.com/auth/indexing',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $expire
        ];

        // Base64url encode header and payload
        $headerEncoded = self::base64UrlEncode(json_encode($header));
        $payloadEncoded = self::base64UrlEncode(json_encode($payload));

        // Create signature input
        $signatureInput = $headerEncoded . '.' . $payloadEncoded;

        // Sign with private key
        $privateKey = openssl_pkey_get_private($serviceAccount['private_key']);
        
        if (!$privateKey) {
            throw new \Exception('Invalid private key');
        }

        $signature = '';
        $signResult = openssl_sign($signatureInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        
        if (!$signResult) {
            throw new \Exception('Failed to sign JWT');
        }

        $signatureEncoded = self::base64UrlEncode($signature);

        return $signatureInput . '.' . $signatureEncoded;
    }

    /**
     * Base64url encoding (RFC 7515)
     * 
     * @param string $data
     * @return string
     */
    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Validate JSON key format
     * 
     * @param string $jsonContent
     * @return array|false Parsed JSON or false on error
     */
    public static function validateJsonKey(string $jsonContent)
    {
        $data = json_decode($jsonContent, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        $requiredFields = ['client_email', 'private_key'];
        
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                return false;
            }
        }

        // Validate private key format
        if (strpos($data['private_key'], '-----BEGIN PRIVATE KEY-----') === false) {
            return false;
        }

        return $data;
    }
}
