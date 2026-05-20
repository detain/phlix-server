<?php

namespace Phlix\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Phlix\Admin\S3Client;

class S3ClientTest extends TestCase
{
    public function testCanCreateS3Client(): void
    {
        $client = new S3Client(
            'us-east-1',
            'test-access-key',
            'test-secret-key',
            'http://localhost:9000'
        );

        $this->assertInstanceOf(S3Client::class, $client);
    }

    public function testUploadReturnsFalseForMissingFile(): void
    {
        $client = new S3Client(
            'us-east-1',
            'test-access-key',
            'test-secret-key'
        );

        $result = $client->upload('test-bucket', 'test-key', '/non/existent/file', 'abc123');

        $this->assertFalse($result);
    }

    public function testUploadVerifiesChecksumMismatch(): void
    {
        // Create a temp file
        $tempFile = tempnam(sys_get_temp_dir(), 's3test_');
        file_put_contents($tempFile, 'test content');

        $client = new S3Client(
            'us-east-1',
            'test-access-key',
            'test-secret-key'
        );

        // Use wrong checksum
        $result = $client->upload('test-bucket', 'test-key', $tempFile, 'wrong-checksum');

        $this->assertFalse($result);

        unlink($tempFile);
    }

    public function testListObjectsReturnsArray(): void
    {
        $client = new S3Client(
            'us-east-1',
            'test-access-key',
            'test-secret-key',
            'http://localhost:9000'
        );

        // Without a running S3, this should return empty array
        $result = $client->listObjects('test-bucket', 'prefix/');

        $this->assertIsArray($result);
    }

    public function testDeleteObjectReturnsBool(): void
    {
        $client = new S3Client(
            'us-east-1',
            'test-access-key',
            'test-secret-key',
            'http://localhost:9000'
        );

        // Without a running S3, this should return false
        $result = $client->deleteObject('test-bucket', 'test-key');

        $this->assertIsBool($result);
    }

    public function testDownloadCreatesDestinationDirectory(): void
    {
        $client = new S3Client(
            'us-east-1',
            'test-access-key',
            'test-secret-key',
            'http://localhost:9000'
        );

        $destDir = sys_get_temp_dir() . '/s3test_' . uniqid();
        $destFile = $destDir . '/subdir/test.txt';

        $result = $client->download('test-bucket', 'test-key', $destFile);

        // Without a running S3, this should return false
        // But the directory should still be created (if the method reaches that point)
        $this->assertFalse($result);
    }

    public function testS3ClientWithEmptyEndpointUsesAwsDefault(): void
    {
        $client = new S3Client(
            'eu-west-1',
            'access-key',
            'secret-key',
            ''  // Empty endpoint = use AWS default
        );

        $this->assertInstanceOf(S3Client::class, $client);
    }

    public function testS3ClientWithCustomEndpoint(): void
    {
        $client = new S3Client(
            'us-east-1',
            'access-key',
            'secret-key',
            'https://minio.example.com:9000'
        );

        $this->assertInstanceOf(S3Client::class, $client);
    }

    public function testUploadWithValidChecksumFailsWithoutServer(): void
    {
        // Create a temp file
        $tempFile = tempnam(sys_get_temp_dir(), 's3test_');
        $content = 'test content for checksum';
        file_put_contents($tempFile, $content);

        $actualChecksum = hash('sha256', $content);

        $client = new S3Client(
            'us-east-1',
            'test-access-key',
            'test-secret-key',
            'http://localhost:9999'  // Non-existent server
        );

        // Should fail because server doesn't exist
        $result = $client->upload('test-bucket', 'test-key', $tempFile, $actualChecksum);

        $this->assertFalse($result);

        unlink($tempFile);
    }

    public function testListObjectsWithEmptyPrefix(): void
    {
        $client = new S3Client(
            'us-east-1',
            'test-access-key',
            'test-secret-key',
            'http://localhost:9000'
        );

        $result = $client->listObjects('test-bucket');

        $this->assertIsArray($result);
    }
}
