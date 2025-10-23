<?php

namespace App;

use Exception;

class Api
{
    private string $baseUrl;
    private string $apiKey;
    private string $apiUser;

    // Rate limiting properties (in-memory, single-process)
    private array $requestTimestamps = [];
    private int $rateLimit;
    private int $rateWindow; // seconds
    private int $safetyMargin; // keep this many requests free from the reported limit
    private int $maxRetries; // number of retries for transient errors

    public function __construct(string $baseUrl, string $apiKey, string $apiUser, int $rateLimit = 10, int $rateWindow = 60, int $safetyMargin = 1, int $maxRetries = 3)
    {
        $this->baseUrl = $baseUrl;
        $this->apiKey = $apiKey;
        $this->apiUser = $apiUser;

        // Initialize rate limiting (default: 9 requests per 60 seconds)
        $this->rateLimit = $rateLimit;
        $this->rateWindow = $rateWindow;

        // Safety margin: keep N spare requests to avoid hitting the provider limit.
        $this->safetyMargin = max(0, $safetyMargin);

        // Retries for transient errors like 429 or 5xx
        $this->maxRetries = max(0, $maxRetries);
    }

    /**
     * Ensure we do not exceed the allowed number of requests per time window.
     * Single-process in-memory sliding-window limiter: records timestamps of
     * completed requests and delays the caller if the number of requests in the
     * last $rateWindow seconds would exceed the configured effective limit.
     */
    private function enforceRateLimit(): void
    {
        $effectiveLimit = max(1, $this->rateLimit - $this->safetyMargin);
        $slack = 0.05; // seconds small slack to avoid tight boundary races

        while (true) {
            $now = microtime(true);

            // Prune old timestamps outside the window
            $cutoff = $now - $this->rateWindow;
            $this->requestTimestamps = array_values(array_filter($this->requestTimestamps, function ($ts) use ($cutoff) {
                return $ts >= $cutoff;
            }));

            if (count($this->requestTimestamps) < $effectiveLimit) {
                // Reserve a slot immediately by appending our timestamp
                $this->requestTimestamps[] = $now;
                return;
            }

            // Otherwise compute how long to wait until the oldest timestamp exits window
            $oldest = $this->requestTimestamps[0] ?? $now;
            $waitSeconds = ($oldest + $this->rateWindow) - $now + $slack;
            if ($waitSeconds > 0) {
                usleep((int)ceil($waitSeconds * 1_000_000));
            } else {
                // tiny sleep to avoid busy loop
                usleep(100000);
            }
            // loop and try again
        }
    }

    /**
     * Perform an HTTP request using the provided stream context options and ensure
     * rate limiting. Returns the decoded JSON response as an array or throws an Exception.
     *
     * This method will retry on transient errors (HTTP 429 and 5xx) using exponential backoff
     * and will respect the configured rate limiting and safety margin so the process never
     * exceeds the provider limit for a single cron job process.
     *
     * @param string $url Full URL to request
     * @param array $options Stream context options (matches existing usage in this class)
     * @return array Decoded JSON response
     * @throws Exception If the request fails or JSON is invalid
     */
    private function doRequest(string $url, array $options): array
    {
        $attempt = 0;
        $maxAttempts = max(1, $this->maxRetries + 1);

        // We'll attempt request up to $maxAttempts times for transient failures
        while ($attempt < $maxAttempts) {
            // Respect rate limit before each attempt (this reserves a slot)
            $this->enforceRateLimit();

            // perform request
            $context = stream_context_create($options);
            $result = @file_get_contents($url, false, $context);

            // Inspect HTTP status code if available
            $statusCode = null;
            $retryAfterSeconds = null;
            if (isset($http_response_header) && is_array($http_response_header)) {
                foreach ($http_response_header as $hdr) {
                    if (preg_match('#HTTP/\d+\.\d+\s+(\d{3})#i', $hdr, $m)) {
                        $statusCode = (int)$m[1];
                    }
                    if (stripos($hdr, 'Retry-After:') === 0) {
                        $val = trim(substr($hdr, strlen('Retry-After:')));
                        if (is_numeric($val)) {
                            $retryAfterSeconds = (int)$val;
                        }
                    }
                }
            }

            // If file_get_contents failed entirely
            if ($result === FALSE) {
                $attempt++;
                $shouldRetry = true; // network-level failure: retry
                $lastException = new Exception("Unable to fetch data from API endpoint: {$url} with status code: " . ($statusCode ?? 'unknown'));
            } else {
                // If server returned an HTTP error code
                if ($statusCode !== null && $statusCode >= 400) {
                    $attempt++;
                    $shouldRetry = ($statusCode === 429) || ($statusCode >= 500 && $statusCode < 600);
                    $lastException = new Exception("HTTP {$statusCode} returned from API endpoint: {$url}");
                } else {
                    // success: parse JSON
                    $response = json_decode($result, true);
                    if (!is_array($response)) {
                        throw new Exception("Invalid JSON response from API for endpoint: {$url}");
                    }
                    return $response;
                }
            }

            // If we should not retry, or we've exhausted attempts, throw the last exception
            if (!$shouldRetry || $attempt >= $maxAttempts) {
                throw $lastException;
            }

            // Determine sleep time: prefer Retry-After when provided by server (in seconds), otherwise exponential backoff
            if ($retryAfterSeconds !== null && $retryAfterSeconds > 0) {
                $sleepSeconds = $retryAfterSeconds + 0.1; // tiny slack
            } else {
                // exponential backoff 1s,2s,4s... with small jitter
                $sleepSeconds = min(10, pow(2, max(0, $attempt - 1)));
                $sleepSeconds += mt_rand(0, 100) / 1000.0; // add 0-0.1s jitter
            }

            // Sleep before next attempt
            usleep((int)ceil($sleepSeconds * 1_000_000));

            // Loop will prune request timestamps via enforceRateLimit at the top of the next iteration
        }

        // If we get here, something unexpected happened
        throw new Exception("Failed to perform request to {$url}");
    }

    /**
     * Test the API connection
     *
     * @return array The decoded JSON response
     * @throws Exception If the API request fails
     */
    public function testConnection(): array
    {
        $options = array(
            "http" => array(
                "header" => array(
                    "X-API-KEY: " . $this->apiKey,
                    "X-API-USER: " . $this->apiUser
                ),
                "method" => "GET"
            )
        );

        return $this->doRequest($this->baseUrl . 'hello', $options);
    }

    /**
     * Fetch all hosting accounts from the API
     *
     * @return array The decoded JSON response
     * @throws Exception If the API request fails
     */
    public function getHostings(): array
    {
        $options = array(
            "http" => array(
                "header" => array(
                    "X-API-KEY: " . $this->apiKey,
                    "X-API-USER: " . $this->apiUser
                ),
                "method" => "GET"
            )
        );

        return $this->doRequest($this->baseUrl . 'hostings', $options);
    }

    /**
     * Get all domains for a specific hosting account
     *
     * @param int $hosting_id The hosting account ID
     * @return array The decoded JSON response
     * @throws Exception If the API request fails
     */
    public function getDomainsForHosting(int $hosting_id): array
    {
        $options = array(
            "http" => array(
                "header" => array(
                    "Content-Type: application/json",
                    "X-API-KEY: " . $this->apiKey,
                    "X-API-USER: " . $this->apiUser
                ),
                "method" => "GET",
                "content" => json_encode(['id' => $hosting_id])
            )
        );

        return $this->doRequest($this->baseUrl . 'hosting/domains', $options);
    }

    /**
     * Get access logs for a specific domain under a hosting account
     *
     * @param int $hosting_id The hosting account ID
     * @param string $domain_name The domain name
     * @return array The decoded JSON response
     * @throws Exception If the API request fails
     */
    public function getAccessLogsForDomain(int $hosting_id, string $domain_name, string $startDate, string $endDate, int $size = 100, int $from = 0): array
    {
        $options = array(
            "http" => array(
                "header" => array(
                    "Content-Type: application/json",
                    "X-API-KEY: " . $this->apiKey,
                    "X-API-USER: " . $this->apiUser
                ),
                "method" => "GET",
                "content" => json_encode(['id' => $hosting_id, 'domain' => $domain_name, 'size' => $size, 'from' => $from, 'after' => $startDate, 'before' => $endDate])
            )
        );

        return $this->doRequest($this->baseUrl . 'hosting/domain/access-logs', $options);
    }


    /**
     * Get N0C Storage credentials
     *
     * @param int $hosting_id The hosting account ID
     * @return array The decoded JSON response
     * @throws Exception If the API request fails
     */
    public function getN0CStorageCredentials(int $hosting_id): array
    {
        $options = array(
            "http" => array(
                "header" => array(
                    "Content-Type: application/json",
                    "X-API-KEY: " . $this->apiKey,
                    "X-API-USER: " . $this->apiUser
                ),
                "method" => "GET",
                "content" => json_encode(['id' => $hosting_id])
            )
        );

        return $this->doRequest($this->baseUrl . 'hosting/n0c-storage', $options);
    }

    /**
     * Find a hosting account by username
     *
     * @param string $username The username to search for
     * @return array|null The hosting account data or null if not found
     * @throws Exception If the API request fails
     */
    public function findHostingByUsername(string $username): ?array
    {
        $response = $this->getHostings();

        if (!isset($response['hosting_accounts']) || !is_array($response['hosting_accounts'])) {
            throw new Exception("Invalid hostings response: missing hosting_accounts");
        }

        foreach ($response['hosting_accounts'] as $hosting) {
            if (isset($hosting['username']) && $hosting['username'] === $username) {
                return $hosting;
            }
        }

        return null;
    }

    /**
     * Return current rate limit configuration and recent timestamps (in-memory).
     * Useful for monitoring and testing.
     */
    public function getRateLimitState(): array
    {
        $now = microtime(true);
        $effectiveLimit = max(1, $this->rateLimit - $this->safetyMargin);

        // prune to only recent ones
        $cutoff = $now - $this->rateWindow;
        $timestamps = array_values(array_filter($this->requestTimestamps, function ($ts) use ($cutoff) {
            return $ts >= $cutoff;
        }));

        return [
            'rateLimit' => $this->rateLimit,
            'rateWindow' => $this->rateWindow,
            'safetyMargin' => $this->safetyMargin,
            'effectiveLimit' => $effectiveLimit,
            'recentTimestamps' => $timestamps,
        ];
    }
}

