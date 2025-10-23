<?php

namespace App;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

class JsonToRawLogConverter
{
    /**
     * Convert an array of JSON-decoded records (matching tmp/..._access_logs.json format)
     * into Apache "combined" log format lines.
     *
     * Contract:
     * - Input: array<int, array> where each element has keys: '@timestamp', 'access' (array)
     * - Required under 'access': clientip, ident, auth, verb, request, httpversion, response
     * - Optional under 'access': bytes, referrer, user_agent (object)
     * - Output: array<int, string> where each string is a single combined log line
     * - Error: throws InvalidArgumentException when a required field is missing/invalid
     *
     * @param array $records
     * @return array
     */
    public function convert(array $records): array
    {

        $lines = [];
        foreach ($records as $idx => $record) {
            $lines[] = $this->convertOne($record, $idx);
        }

        return $lines;
    }

    /**
     * Convert and join lines with a given separator (default: PHP_EOL).
     *
     * @param array $records
     * @param string $separator
     * @return string
     */
    public function convertToString(array $records, string $separator = PHP_EOL): string
    {
        return implode($separator, $this->convert($records));
    }

    /**
     * Convert a single record to an Apache combined log line.
     *
     * Format: %h %l %u %t "%r" %>s %b "%{Referer}i" "%{User-Agent}i"
     * Example: 127.0.0.1 - frank [10/Oct/2000:13:55:36 +0000] "GET /apache_pb.gif HTTP/1.0" 200 2326 "-" "Mozilla/5.0"
     *
     * @param array $record
     * @param int|string|null $index Used only for error context
     * @return string
     */
    private function convertOne(array $record, int|string|null $index = null): string
    {
        $ctx = $index !== null ? " at index {$index}" : '';

        // Validate top-level keys
        if (!isset($record['@timestamp'])) {
            throw new InvalidArgumentException("Missing '@timestamp' in record{$ctx}");
        }
        if (!isset($record['access']) || !is_array($record['access'])) {
            throw new InvalidArgumentException("Missing or invalid 'access' object in record{$ctx}");
        }
        $a = $record['access'];

        // Required fields under access (bytes is optional)
        $required = ['clientip', 'ident', 'auth', 'verb', 'request', 'httpversion', 'response'];
        foreach ($required as $k) {
            if (!array_key_exists($k, $a)) {
                throw new InvalidArgumentException("Missing required field 'access.{$k}' in record{$ctx}");
            }
        }

        $clientIp = (string)$a['clientip'];
        $ident = (string)$a['ident'];
        $authUser = (string)$a['auth'];
        $verb = (string)$a['verb'];
        $requestUri = (string)$a['request'];
        $httpVersion = (string)$a['httpversion'];
        $statusCode = $this->toInt($a['response'], "access.response{$ctx}");

        // bytes may be missing or non-numeric; use '-' per CLF when unknown
        $bytesSentStr = $this->normalizeBytesField($a['bytes'] ?? null);

        // Optional fields
        $referrerRaw = $a['referrer'] ?? '-';
        $uaRaw = $a['user_agent'] ?? null;

        // Build request line
        $requestLine = sprintf('%s %s HTTP/%s', $verb, $requestUri, $httpVersion);

        // Parse timestamp (@timestamp is ISO8601 in UTC e.g., 2025-10-23T12:20:06.000Z)
        $apacheTime = $this->formatApacheTime($record['@timestamp']);

        // Normalize referrer: sample JSON sometimes includes quotes like "\"-\""; strip if present
        $referrer = $this->normalizeQuotedField($referrerRaw, '-');

        // Build a user-agent string from the structured object when available
        $userAgent = $this->buildUserAgentString($uaRaw);

        // Escape quotes in referrer and UA, and ensure they are wrapped in quotes in the final line
        $referrerEsc = $this->escapeForQuotedField($referrer);
        $uaEsc = $this->escapeForQuotedField($userAgent);

        // Compose combined log line
        // %h %l %u %t "%r" %>s %b "%{Referer}i" "%{User-Agent}i"
        $line = sprintf(
            '%s %s %s [%s] "%s" %d %s "%s" "%s"',
            $clientIp,
            $ident,
            $authUser,
            $apacheTime,
            $requestLine,
            $statusCode,
            $bytesSentStr,
            $referrerEsc,
            $uaEsc
        );

        return $line;
    }

    /**
     * Convert ISO8601/Z timestamps to Apache time format [d/M/Y:H:i:s O] in UTC offset
     * Example input: 2025-10-23T12:20:06.000Z -> 23/Oct/2025:12:20:06 +0000
     */
    private function formatApacheTime(string $iso8601): string
    {
        try {
            $dt = new DateTimeImmutable($iso8601);
        } catch (\Exception $e) {
            throw new InvalidArgumentException("Invalid '@timestamp': {$iso8601}");
        }
        // Ensure timezone information is kept; if none, assume UTC
        if ($dt->getTimezone() === false) {
            $dt = $dt->setTimezone(new DateTimeZone('UTC'));
        }
        return $dt->format('d/M/Y:H:i:s O');
    }

    /**
     * Normalize a value that might already include surrounding quotes in the JSON string.
     * If the value is null/empty, returns the provided default (e.g., '-')
     */
    private function normalizeQuotedField(mixed $value, string $default): string
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $str = (string)$value;
        // If string starts and ends with a quote, strip outer quotes
        if (strlen($str) >= 2 && $str[0] === '"' && substr($str, -1) === '"') {
            $str = substr($str, 1, -1);
        }
        if ($str === '') {
            return $default;
        }
        return $str;
    }

    /**
     * Build a reasonable User-Agent string from structured fields if provided.
     * Falls back to '-' when nothing useful is available.
     * Accepts either a string (already a UA), an array with keys like name/version,
     * or null.
     */
    private function buildUserAgentString(mixed $ua): string
    {
        if ($ua === null) {
            return '-';
        }
        if (is_string($ua)) {
            // Might already include quotes; we'll escape later
            $val = trim($ua);
            return $val !== '' ? $val : '-';
        }
        if (!is_array($ua)) {
            return '-';
        }

        // Prefer name and version if available
        $name = isset($ua['name']) && $ua['name'] !== '' ? (string)$ua['name'] : null;
        $version = isset($ua['version']) && $ua['version'] !== '' ? (string)$ua['version'] : null;

        if ($name !== null && $version !== null) {
            return $name . '/' . $version;
        }
        if ($name !== null) {
            // Try to compose of major/minor/patch
            $parts = [];
            foreach (['major', 'minor', 'patch'] as $k) {
                if (isset($ua[$k]) && $ua[$k] !== '') {
                    $parts[] = (string)$ua[$k];
                }
            }
            if (!empty($parts)) {
                return $name . '/' . implode('.', $parts);
            }
            return $name;
        }

        // Try OS-based fallback
        $os = isset($ua['os_full']) && $ua['os_full'] !== '' ? (string)$ua['os_full'] : ((isset($ua['os_name']) && $ua['os_name'] !== '') ? (string)$ua['os_name'] : null);
        if ($os !== null) {
            return $os;
        }

        return '-';
    }

    /**
     * Escape a string so it can be safely placed inside double quotes in a log line.
     * We escape existing backslashes and double quotes.
     */
    private function escapeForQuotedField(string $s): string
    {
        $s = str_replace(['\\', '"'], ['\\\\', '\\"'], $s);
        return $s;
    }

    /**
     * Convert a scalar to int with validation; throws if not numeric.
     */
    private function toInt(mixed $value, string $fieldName): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int)$value;
        }
        if (is_float($value)) {
            return (int)$value;
        }
        throw new InvalidArgumentException("Field '{$fieldName}' must be numeric");
    }

    /**
     * Normalize the bytes field to a string appropriate for %b in CLF.
     * - Numeric values -> integer string
     * - '-' or null/empty/non-numeric -> '-'
     */
    private function normalizeBytesField(mixed $value): string
    {
        if ($value === null) {
            return '-';
        }
        if (is_int($value)) {
            return (string)$value;
        }
        if (is_float($value)) {
            return (string)((int)$value);
        }
        if (is_string($value)) {
            $trim = trim($value);
            if ($trim === '-') {
                return '-';
            }
            if (is_numeric($trim)) {
                return (string)((int)$trim);
            }
        }
        return '-';
    }
}

