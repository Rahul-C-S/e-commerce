<?php

namespace Opencart\Catalog\Controller\Mobile;

use Opencart\System\Engine\Controller;
use InvalidArgumentException;
use Exception;
use JsonException;

class ApiController extends Controller
{
    /**
     * CORS and Response Configuration Constants
     */
    private const CORS_ALLOWED_METHODS = 'GET, POST, DELETE, PUT, OPTIONS, TRACE, PATCH, CONNECT';
    private const CORS_DEFAULT_HEADERS = 'Origin, Content-Type, X-Auth-Token, Accept, Authorization, X-Request-With, Access-Control-Request-Method, Access-Control-Request-Headers';
    private const DEFAULT_CHARSET = 'utf-8';
    private const DEFAULT_COMPRESSION = 9;

    /**
     * Send JSONP or JSON response with CORS headers
     * 
     * @param mixed $json Response data
     * @param bool $encode Whether to JSON encode the response
     * @return string
     */
    protected function jsonp($json, bool $encode = false): string
    {
        $this->configureResponseHeaders();
        $this->addCorsHeaders();
        $this->response->setCompression(self::DEFAULT_COMPRESSION);

        return $this->processJsonpResponse($json, $encode);
    }

    /**
     * Configure base response headers
     */
    private function configureResponseHeaders(): void
    {
        $this->response->addHeader("Content-Type: application/x-www-form-urlencoded; charset=" . self::DEFAULT_CHARSET);
        $this->response->addHeader("Accept: application/json, text/plain");
        $this->response->addHeader("cache-control: no-cache");
        $this->response->addHeader('Content-Type: application/json');
    }

    /**
     * Add dynamic CORS headers
     */
    private function addCorsHeaders(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
        $this->response->addHeader("Access-Control-Allow-Origin: {$origin}");
        $this->response->addHeader('Access-Control-Allow-Methods: ' . self::CORS_ALLOWED_METHODS);

        $requestHeaders = $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? self::CORS_DEFAULT_HEADERS;
        $this->response->addHeader("Access-Control-Allow-Headers: {$requestHeaders}");
        $this->response->addHeader('Access-Control-Allow-Credentials: true');
    }

    /**
     * Process JSONP or JSON response
     * 
     * @param mixed $json Response data
     * @param bool $encode Whether to JSON encode the response
     * @return string
     */
    private function processJsonpResponse($json, bool $encode): string
    {
        $callback = $this->request->get['callback'] ?? null;

        if ($callback) {
            $processedJson = $encode ? json_encode($json) : $json;
            return "{$callback}({$processedJson})";
        }

        return $encode ? json_encode($json) : $json;
    }

    /**
     * Set JSON output
     * 
     * @param array $data Response data
     */
    protected function returnArray(array $data): void
    {
        try {
            $this->response->setOutput(json_encode($data, JSON_THROW_ON_ERROR));
        } catch (JsonException $e) {
            // Log error in production
            $this->response->setOutput(json_encode(['error' => 'JSON encoding failed']));
        }
    }

    /**
     * Encode a key with enhanced security
     * 
     * @param string|int $key Original key
     * @return string Encoded key token
     * @throws InvalidArgumentException
     */
    protected function encodeKey($key): string
    {
        if (empty($key)) {
            throw new InvalidArgumentException('Key cannot be empty');
        }

        $tokenData = [
            'key' => $key,
            'salt' => bin2hex(random_bytes(8)),
            'site' => $this->generateSiteHexCode(),
            'timestamp' => time()
        ];

        return base64_encode(json_encode($tokenData));
    }

    /**
     * Decode a key token with robust validation
     * 
     * @param string $keyToken Encoded key token
     * @param int $tokenLifetime Token validity duration in seconds
     * @return string|false Decoded key or false
     */
    protected function decodeKey(string $keyToken, int $tokenLifetime = 3600)
    {
        try {
            $tokenData = $this->validateKeyToken($keyToken, $tokenLifetime);
            return $tokenData['key'] ?? false;
        } catch (Exception $e) {
            // Log error in production
            return false;
        }
    }

    /**
     * Validate key token integrity and freshness
     * 
     * @param string $keyToken Encoded key token
     * @param int $tokenLifetime Token validity duration
     * @return array Token data
     * @throws Exception
     */
    private function validateKeyToken(string $keyToken, int $tokenLifetime): array
    {
        $decodedJson = base64_decode($keyToken, true);
        $tokenData = json_decode($decodedJson, true);

        if (!$this->isValidTokenStructure($tokenData)) {
            throw new Exception('Invalid token structure');
        }

        if (!$this->isValidSiteHexCode($tokenData)) {
            throw new Exception('Site hex code mismatch');
        }

        if (!$this->isTokenFresh($tokenData, $tokenLifetime)) {
            throw new Exception('Token has expired');
        }

        return $tokenData;
    }

    /**
     * Check token structure
     */
    private function isValidTokenStructure(?array $tokenData): bool
    {
        return $tokenData &&
            isset($tokenData['key'], $tokenData['salt'], $tokenData['site'], $tokenData['timestamp']);
    }

    /**
     * Verify site hex code
     */
    private function isValidSiteHexCode(array $tokenData): bool
    {
        return $tokenData['site'] === $this->generateSiteHexCode();
    }

    /**
     * Check token freshness
     */
    private function isTokenFresh(array $tokenData, int $tokenLifetime): bool
    {
        return (time() - $tokenData['timestamp']) <= $tokenLifetime;
    }

    /**
     * Generate site-specific hex code
     * 
     * @return string Sanitized hex representation of the site
     */
    protected function generateSiteHexCode(): string
    {
        $host = parse_url(HTTP_SERVER, PHP_URL_HOST) ?: '';
        $sanitizedHost = preg_replace('/[^a-zA-Z0-9\-]/', '', $host);
        return hash('sha256', $sanitizedHost);
    }
}
