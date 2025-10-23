<?php

namespace App;

use Exception;
use RuntimeException;

class LogWriter
{
    private string $tmpDir;
    private JsonToRawLogConverter $jsonToRawLogConverter;

    /**
     * @param string|null $tmpDir Directory to store temporary log files. Defaults to project-level ./tmp
     */
    public function __construct(?string $tmpDir = null)
    {
        // default to repository root tmp directory
        $this->tmpDir = $tmpDir ?? realpath(__DIR__ . '/../') . '/tmp';
        $this->jsonToRawLogConverter = new JsonToRawLogConverter();
    }

    /**
     * Write access logs to a JSON file.
     *
     * @param array $accessLogs The access logs array
     * @param string $domain The domain name (used in filename)
     * @param string $startDate The start date string (ISO format) used to timestamp the file
     * @return string The full path to the written file
     * @throws RuntimeException|Exception If directory creation or file write fails
     */
    public function write(array $accessLogs, string $domain, string $startDate): string
    {
        // ensure directory exists
        if (!is_dir($this->tmpDir)) {
            if (!mkdir($this->tmpDir, 0755, true) && !is_dir($this->tmpDir)) {
                throw new RuntimeException("Failed to create directory: {$this->tmpDir}");
            }
        }

        // parse start date to a safe timestamp portion
        try {
            $startDt = new \DateTimeImmutable($startDate);
            $timestamp = $startDt->format('Ymd');
        } catch (Exception $e) {
            // fallback to current date
            $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd');
        }

        $safeDomain = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $domain);
        $filename = "{$safeDomain}_{$timestamp}_access_logs.log";
        $filePath = rtrim($this->tmpDir, '/\\') . '/' . $filename;

        $raw = $this->jsonToRawLogConverter->convertToString($accessLogs);

        if (file_put_contents($filePath, $raw) === false) {
            throw new RuntimeException("Failed to write access logs to {$filePath}");
        }

        return $filePath;
    }

    /**
     * Clear all files in the temporary directory.
     *
     * @throws RuntimeException If directory cannot be read or files cannot be deleted
     */
    public function clearTmpDir(): void
    {
        if (!is_dir($this->tmpDir)) {
            return; // nothing to clear
        }

        $files = scandir($this->tmpDir);
        if ($files === false) {
            throw new RuntimeException("Failed to read directory: {$this->tmpDir}");
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $filePath = rtrim($this->tmpDir, '/\\') . '/' . $file;
            if (is_file($filePath) && !unlink($filePath)) {
                throw new RuntimeException("Failed to delete file: {$filePath}");
            }
        }
    }
}