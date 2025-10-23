<?php

namespace App;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Exception;

class S3Storage
{
    private S3Client $client;

    public function __construct(
        string $endpoint,
        string $region,
        string $accessKey,
        string $secretKey,
        bool   $verifySSL = false
    )
    {
        $this->client = new S3Client([
            'version' => 'latest',
            'region' => $region,
            'endpoint' => $endpoint,
            // Force path-style so bucket is not prepended as subdomain to the endpoint
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => $accessKey,
                'secret' => $secretKey,
            ],
            'http' => [
                'verify' => $verifySSL,
            ]
        ]);
    }

    /**
     * Get the S3 client instance
     *
     * @return S3Client
     */
    public function getClient(): S3Client
    {
        return $this->client;
    }

    /**
     * Test the S3 connection by listing buckets
     *
     * @return array List of bucket names
     * @throws AwsException If there's an AWS-specific error
     * @throws \Exception For any other errors
     */
    public function testConnection(): array
    {
        $buckets = $this->listBuckets();

        foreach ($buckets as $bucket) {
            echo "Successfully connected to S3. Buckets found:\n";
            echo " - " . $bucket['Name'] . "\n";

            $buckets[] = $bucket['Name'];
        }

        return $buckets;
    }

    /**
     * List all buckets
     *
     * @return array Array of bucket information
     * @throws AwsException If there's an AWS-specific error
     */
    public function listBuckets(): array
    {
        $result = $this->client->listBuckets();
        return $result['Buckets'];
    }
}
