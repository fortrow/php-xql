<?php

namespace XQL\Cloud;

use XQL\Core\Utils\Env;

class Cloud
{
    private static ?CloudDriver $driver = null;

    public static function put(string $key, string $content) {
        self::driver()->put($key, $content);
    }

    public static function get(string $key)
    {
        return self::driver()->get($key);
    }

    private static function driver()
    {
        if(isset(self::$driver)) return self::$driver;

        $driver = strtolower((string) (Env::get("XQL_CLOUD_DRIVER") ?: "s3"));
        self::$driver = match($driver) {
            "local", "disk", "filesystem" => new LocalDisk(),
            "s3", "aws" => new S3(),
            "azure", "azure-blob", "azure_blob", "blob" => new AzureBlob(),
            "gcs", "google", "google-cloud", "google_cloud", "google-cloud-storage" => new GoogleCloudStorage(),
            default => throw new \RuntimeException("Unsupported XQL cloud driver: " . $driver),
        };

        return self::$driver;
    }

    public static function useDriver(CloudDriver $driver): void
    {
        self::$driver = $driver;
    }

    public static function reset(): void
    {
        self::$driver = null;
    }
}
