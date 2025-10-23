<?php

use App\Api;
use App\S3Storage;
use App\LogWriter;
use Aws\Exception\AwsException;

if (!is_file(__DIR__ . '/vendor/autoload.php')) {
    echo "Error: Composer autoload not found at " . __DIR__ . "/vendor/autoload.php\n";
    exit(1);
}
require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// API Configuration
$baseUrl = "https://api.planethoster.net/v3/";
$apiKey = $_ENV['PH_API_KEY'] ?: '';
$apiUser = $_ENV['PH_API_USER'] ?: '';
$hostingUsername = $_ENV['HOSTING_USERNAME'] ?: '';
$s3Endpoint = $_ENV['S3_ENDPOINT'] ?: '';
$s3Region = $_ENV['S3_REGION'] ?: '';

if ($apiKey === '' || $apiUser === '' || $hostingUsername === '' || $s3Endpoint === '' || $s3Region === '') {
    echo "Error: Missing required environment variables. Please set PH_API_KEY, PH_API_USER, HOSTING_USERNAME, S3_ENDPOINT and S3_REGION in .env or the environment.\n";
    exit(1);
}

// Initialize API client
$api = new Api($baseUrl, $apiKey, $apiUser);

// Test API connection
try {
    $response = $api->testConnection();
    print_r($response);
    echo "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

// Find the specific hosting account
try {
    $hostingAccount = $api->findHostingByUsername($hostingUsername);

    if ($hostingAccount === null) {
        echo "Error: hosting account {$hostingUsername} not found\n";
        exit(1);
    }

    print_r($hostingAccount);
    echo "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

// Fetch domains for hosting account
try {
    $domains = $api->getDomainsForHosting($hostingAccount['id'])['data'];
    print_r($domains);
    echo "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

// Initialize the log writer (stores files under ./tmp by default)
$logWriter = new LogWriter();
$logWriter->clearTmpDir();

// Get N0C Storage credentials
try {
    $n0cStorageCredentials = $api->getN0CStorageCredentials($hostingAccount['id'])['data'];
    print_r($n0cStorageCredentials);
    echo "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

// Get access logs for each domain

// Initialize 24-hour window in UTC (previous calendar day, midnight-to-midnight).
$utc = new DateTimeZone('UTC');
try {
    $startDate = (new DateTimeImmutable('yesterday', $utc))->setTime(0, 0, 0)->format('Y-m-d\TH:i:s.000\Z');
    $endDate = (new DateTimeImmutable('today', $utc))->setTime(0, 0, 0)->format('Y-m-d\TH:i:s.000\Z');
} catch (Exception $e) {
    echo "Error creating date range: " . $e->getMessage() . "\n";
    exit(1);
}

foreach ($domains as $domain) {
    $is_empty_response = false;
    $accessLogs = [];
    try {
        while (!$is_empty_response) {
            $fetchedLogs = $api->getAccessLogsForDomain($hostingAccount['id'], $domain['domain'], $startDate, $endDate, 100, count($accessLogs))['data'];
            if (is_array($fetchedLogs) && count($fetchedLogs) === 0) {
                $is_empty_response = true;
            } else {
                $accessLogs = array_merge($accessLogs, $fetchedLogs);
            }
        }

        echo "Access logs for domain {$domain['domain']}:\n";
        print_r($accessLogs);
        echo "\n";

        // if accessLogs is an empty array, set flag
        if (is_array($accessLogs) && count($accessLogs) === 0) {
            $is_empty_response = true;
            echo "No access logs found for domain {$domain['domain']} in the specified date range.\n";
            continue;
        }

        // write logs to file via LogWriter
        $filePath = $logWriter->write($accessLogs, $domain['domain'], $startDate);
        echo "Wrote access logs to `{$filePath}`\n";
    } catch (Exception $e) {
        echo "Error fetching access logs for domain {$domain['domain']}: " . $e->getMessage() . "\n";
    }

    // sleep 5 seconds to avoid rate limiting
    sleep(5);
}

// S3 Configuration
$s3Storage = new S3Storage(
    endpoint: 'https://ht2-storage.n0c.com:5443',
    region: 'ht2-storage',
    accessKey: $n0cStorageCredentials['accessKey'],
    secretKey: $n0cStorageCredentials['secretKey'],
    verifySSL: false
);

$s3BasePath = 'private/access_logs/';

// Test the S3 connection
try {
    $buckets = $s3Storage->testConnection();

    // Upload each log file in the tmp directory to S3
    $tmpDir = __DIR__ . '/tmp';
    $files = scandir($tmpDir);
    if ($files === false) {
        throw new Exception("Failed to read directory: {$tmpDir}");
    }

    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        $filePath = rtrim($tmpDir, '/\\') . '/' . $file;
        if (is_file($filePath)) {
            // Build S3 key using folder structure: private/access_logs/{domain}/{year}/{month}/{YYYYMMDD}.log
            // Expect filenames like: {domain}_{YYYY}{MM}{DD}_access_logs.log
            $matches = [];
            $destinationPath = null;
            if (preg_match('/^(?P<domain>.+?)_(?P<year>\\d{4})(?P<month>\\d{2})(?P<day>\\d{2})_access_logs\\.log$/', $file, $matches)) {
                $domain = $matches['domain'];
                $year = $matches['year'];
                $month = $matches['month'];
                $day = $matches['day'];
                // Sanitize domain just in case (should already be safe from LogWriter)
                $domainFolder = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $domain);
                $finalName = $year . $month . $day . '.log';
                $destinationPath = $s3BasePath . $domainFolder . '/' . $year . '/' . $month . '/' . $finalName;
            } else {
                // Fallback: place under base path if pattern doesn't match
                $destinationPath = $s3BasePath . $file;
            }

            // Upload file
            $result = $s3Storage->getClient()->putObject([
                'Bucket' => $n0cStorageCredentials['name'],
                'Key' => $destinationPath,
                'SourceFile' => $filePath,
            ]);

            echo "Uploaded {$filePath} to S3 at {$destinationPath}\n";
        }
    }
} catch (AwsException $e) {
    // Catch any AWS-specific errors
    echo "Error connecting to S3: " . $e->getMessage() . "\n";
    echo "AWS Error Code: " . $e->getAwsErrorCode() . "\n";
    exit(1);
} catch (Exception $e) {
    // Catch any other general exceptions
    echo "An unexpected error occurred: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Script completed successfully.\n";
exit(0);
