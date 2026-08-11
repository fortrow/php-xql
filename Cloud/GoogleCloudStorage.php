<?php

namespace XQL\Cloud;

use Google\Cloud\Storage\StorageClient;
use XQL\Core\Utils\Env;

class GoogleCloudStorage implements CloudDriver
{
    private string $bucketName;

    private StorageClient $client;

    public function __construct()
    {
        $this->bucketName = (string) Env::get("XQL_GCP_STORAGE_BUCKET");
        if($this->bucketName === "") {
            throw new \RuntimeException("XQL_GCP_STORAGE_BUCKET is required when using the gcs storage driver.");
        }

        $config = [];
        $projectId = Env::get("XQL_GCP_PROJECT_ID");
        $keyFilePath = Env::get("XQL_GCP_KEY_FILE_PATH") ?: Env::get("GOOGLE_APPLICATION_CREDENTIALS");

        if($projectId) {
            $config['projectId'] = $projectId;
        }

        if($keyFilePath) {
            $config['keyFilePath'] = $keyFilePath;
        }

        $this->client = new StorageClient($config);
    }

    public function put(string $key, string $content): void
    {
        $this->client->bucket($this->bucketName)->upload($content, [
            'name' => $this->normalizeKey($key),
        ]);
    }

    public function get(string $key): string
    {
        $object = $this->client->bucket($this->bucketName)->object($this->normalizeKey($key));
        return $object->downloadAsString();
    }

    private function normalizeKey(string $key): string
    {
        return ltrim(str_replace(["..", "\\"], ["", "/"], $key), "/");
    }
}
