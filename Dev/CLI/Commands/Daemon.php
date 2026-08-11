<?php

namespace XQL\Dev\CLI\Commands;

use XQL\Dev\CLI\Command;
use XQL\Dev\Daemon as XQLDaemon;
use XQL\Dev\Monitor\DaemonMonitor;

class Daemon extends Command
{
    protected function handle(array $args, array $params, array $flags): bool
    {
        if($this->hasFlag($flags, "status")) {
            $monitor = new DaemonMonitor();
            $status = $monitor->status();
            $status['checkpoint'] = (new XQLDaemon())->checkpoint();
            $this->success(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return true;
        }

        if($this->hasFlag($flags, "logs")) {
            $monitor = new DaemonMonitor();
            $lines = isset($params['lines']) ? (int) $params['lines'] : 50;
            $this->out($this->tail($monitor->logger()->path(), $lines));
            return true;
        }

        if($this->hasFlag($flags, "checkpoint")) {
            $checkpoint = (new XQLDaemon())->checkpoint();
            $this->success($checkpoint ? json_encode($checkpoint) : "No XQL binlog checkpoint has been saved.");
            return true;
        }

        $daemon = new XQLDaemon();
        $result = $daemon->run([
            'once' => $this->hasFlag($flags, "once"),
            'binlog' => $this->hasFlag($flags, "binlog"),
            'interval' => isset($params['interval']) ? (int) $params['interval'] : 5,
            'limit' => isset($params['limit']) ? (int) $params['limit'] : 100,
            'binlog_file' => $params['binlog-file'] ?? null,
            'binlog_position' => $params['binlog-position'] ?? null,
            'binlog_provider' => $params['binlog-provider'] ?? null,
            'binlog_host' => $params['binlog-host'] ?? null,
            'binlog_port' => $params['binlog-port'] ?? null,
            'binlog_database' => $params['binlog-database'] ?? null,
            'binlog_username' => $params['binlog-username'] ?? null,
            'binlog_password' => $params['binlog-password'] ?? null,
            'binlog_server_id' => $params['binlog-server-id'] ?? null,
            'binlog_socket' => $params['binlog-socket'] ?? null,
            'binlog_ssl_mode' => $params['binlog-ssl-mode'] ?? null,
            'binlog_ssl_ca' => $params['binlog-ssl-ca'] ?? null,
            'binlog_ssl_cert' => $params['binlog-ssl-cert'] ?? null,
            'binlog_ssl_key' => $params['binlog-ssl-key'] ?? null,
            'health_log_interval' => isset($params['health-log-interval']) ? (int) $params['health-log-interval'] : 300,
        ]);

        $this->success("XQL daemon stopped. Loops: " . $result['loops'] . ", jobs: " . $result['jobs'] . ", rebuilt: " . $result['rebuilt'] . ", schema rebuilt: " . $result['schema_rebuilt'] . ", skipped: " . $result['skipped'] . ", errors: " . count($result['errors']) . ".");

        return true;
    }

    private function hasFlag(array $flags, string $name): bool
    {
        return in_array($name, $flags, true) || array_key_exists($name, $flags) || in_array("--" . $name, $flags, true) || array_key_exists("--" . $name, $flags);
    }

    private function tail(string $path, int $lines): string
    {
        if(!is_file($path)) return "No XQL daemon log exists at " . $path . ".";

        $contents = file($path, FILE_IGNORE_NEW_LINES);
        if(!$contents) return "";
        return implode(PHP_EOL, array_slice($contents, -1 * max(1, $lines)));
    }
}
