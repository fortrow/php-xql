<?php

namespace XQL\Dev\Monitor;

use XQL\Core\Utils\Env;

class XQLLogger
{
    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?: (Env::get("XQL_DAEMON_LOG_PATH") ?: $this->defaultPath());
        $dir = dirname($this->path);
        if(!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    public function info(string $message, array $context = []): void
    {
        $this->write("info", $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write("warning", $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write("error", $message, $context);
    }

    public function path(): string
    {
        return $this->path;
    }

    private function write(string $level, string $message, array $context = []): void
    {
        $record = [
            'time' => gmdate('c'),
            'level' => $level,
            'message' => $message,
            'context' => $this->sanitize($context),
        ];

        file_put_contents($this->path, json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function sanitize(array $context): array
    {
        foreach($context as $key => $value) {
            if(preg_match('/password|secret|token|key/i', (string) $key)) {
                $context[$key] = '[redacted]';
            } else if(is_array($value)) {
                $context[$key] = $this->sanitize($value);
            }
        }

        return $context;
    }

    private function defaultPath(): string
    {
        return getcwd() . "/storage/logs/xql/windsor.log";
    }
}
