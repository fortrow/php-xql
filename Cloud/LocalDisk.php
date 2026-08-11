<?php

namespace XQL\Cloud;

use XQL\Core\Utils\Env;

class LocalDisk implements CloudDriver
{
    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root ?: (Env::get("XQL_LOCAL_STORAGE_PATH") ?: getcwd() . "/storage/xql"), "/");
    }

    public function put(string $key, string $content): void
    {
        $path = $this->path($key);
        $dir = dirname($path);
        if(!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, $content, LOCK_EX);
    }

    public function get(string $key): string
    {
        $path = $this->path($key);
        if(!is_file($path)) {
            throw new \RuntimeException("XQL local object not found: " . $path);
        }
        return (string) file_get_contents($path);
    }

    private function path(string $key): string
    {
        $key = ltrim(str_replace(["..", "\\"], ["", "/"], $key), "/");
        return $this->root . "/" . $key;
    }
}
