<?php

namespace XQL\Dev\Binlog;

use XQL\Core\Utils\Env;
use XQL\DB\DBX;

class MysqlBinlogAdapter implements BinlogAdapter
{
    private array $columnsByTable = [];
    private array $primaryKeyByTable = [];

    public function consume(callable $onChangedRow, array $options = []): array
    {
        $this->assertAvailable();

        [$command, $environment, $source] = $this->command($options);
        $process = proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, $environment);

        if(!is_resource($process)) {
            throw new \RuntimeException("Unable to start mysqlbinlog process.");
        }

        $summary = [
            'events' => 0,
            'rows' => 0,
            'errors' => [],
        ];

        $event = null;
        while(($line = fgets($pipes[1])) !== false) {
            $parsed = $this->parseLine($line, $event, $source);
            if(!$parsed) continue;

            try {
                $onChangedRow(
                    $parsed['table'],
                    $parsed['event_type'],
                    $parsed['before'],
                    $parsed['after'],
                    $parsed['metadata']
                );
                $summary['rows']++;
            } catch(\Throwable $e) {
                $summary['errors'][] = [
                    'table' => $parsed['table'],
                    'event_type' => $parsed['event_type'],
                    'error' => $e->getMessage(),
                ];
            }

            $summary['events']++;
        }

        if($event && (count($event['before']) > 0 || count($event['after']) > 0)) {
            $parsed = $this->finalizeEvent($event, $source);
            try {
                $onChangedRow(
                    $parsed['table'],
                    $parsed['event_type'],
                    $parsed['before'],
                    $parsed['after'],
                    $parsed['metadata']
                );
                $summary['rows']++;
                $summary['events']++;
            } catch(\Throwable $e) {
                $summary['errors'][] = [
                    'table' => $parsed['table'],
                    'event_type' => $parsed['event_type'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if($exitCode !== 0) {
            throw new \RuntimeException("mysqlbinlog exited with code " . $exitCode . ": " . trim($stderr));
        }

        return $summary;
    }

    private function assertAvailable(): void
    {
        $binary = Env::get("XQL_BINLOG_MYSQLBINLOG") ?: "mysqlbinlog";
        $path = trim((string) shell_exec("command -v " . escapeshellarg($binary)));
        if($path === "") {
            throw new \RuntimeException("mysqlbinlog was not found. Install MariaDB/MySQL client tools and set XQL_BINLOG_MYSQLBINLOG if needed.");
        }
    }

    private function command(array $options): array
    {
        $binary = Env::get("XQL_BINLOG_MYSQLBINLOG") ?: "mysqlbinlog";
        $provider = $this->provider($options);
        $host = $options['binlog_host'] ?? Env::get("XQL_BINLOG_HOST") ?: Env::get("XQL_BINDED_DB_HOST");
        $port = $options['binlog_port'] ?? Env::get("XQL_BINLOG_PORT") ?: Env::get("XQL_BINDED_DB_PORT") ?: "3306";
        $user = $options['binlog_username'] ?? Env::get("XQL_BINLOG_USERNAME") ?: Env::get("XQL_BINDED_DB_USERNAME");
        $password = $options['binlog_password'] ?? Env::get("XQL_BINLOG_PASSWORD") ?: Env::get("XQL_BINDED_DB_PASSWORD");
        $database = $options['binlog_database'] ?? Env::get("XQL_BINLOG_DATABASE") ?: Env::get("XQL_BINDED_DB_DATABASE");
        $checkpoint = DBX::binlogCheckpoint($host, $database);

        $this->validateConnectionConfig($provider, $host, $user, $database);

        $file = $options['binlog_file'] ?? $checkpoint['binlog_file'] ?? Env::get("XQL_BINLOG_FILE");
        if(!$file) {
            throw new \RuntimeException("No binlog file configured. Set XQL_BINLOG_FILE or save a checkpoint first.");
        }

        $position = $options['binlog_position'] ?? $checkpoint['binlog_position'] ?? Env::get("XQL_BINLOG_POSITION");

        $parts = [
            escapeshellarg($binary),
            "--read-from-remote-server",
            "--base64-output=DECODE-ROWS",
            "--verbose",
            "--host=" . escapeshellarg($host),
            "--port=" . escapeshellarg((string) $port),
            "--user=" . escapeshellarg($user),
            "--database=" . escapeshellarg($database),
        ];

        $parts = array_merge($parts, $this->providerOptions($provider, $options));

        if($position) {
            $parts[] = "--start-position=" . escapeshellarg((string) $position);
        }

        if(!empty($options['stop_never'])) {
            $parts[] = "--stop-never";
        }

        $parts[] = escapeshellarg($file);

        return [implode(" ", $parts), array_merge($_ENV, [
            'MYSQL_PWD' => $password,
        ]), [
            'source_host' => $host,
            'source_database' => $database,
            'source_provider' => $provider,
            'binlog_file' => $file,
            'start_position' => $position,
        ]];
    }

    private function provider(array $options = []): string
    {
        $provider = strtolower((string) ($options['binlog_provider'] ?? Env::get("XQL_BINLOG_PROVIDER") ?: Env::get("XQL_DB_PROVIDER") ?: "self-hosted"));
        return match($provider) {
            "self", "self-hosted", "self_hosted", "vps", "mysql", "mariadb" => "self-hosted",
            "aws", "rds", "aurora", "amazon" => "aws",
            "azure", "azure-mysql", "azure_mysql", "flexible-server", "flexible_server" => "azure",
            "gcp", "google", "cloud-sql", "cloud_sql", "google-cloud-sql" => "gcp",
            default => throw new \RuntimeException("Unsupported XQL binlog provider: " . $provider),
        };
    }

    private function validateConnectionConfig(string $provider, ?string $host, ?string $user, ?string $database): void
    {
        $missing = [];
        if(!$host) $missing[] = "XQL_BINLOG_HOST or XQL_BINDED_DB_HOST";
        if(!$user) $missing[] = "XQL_BINLOG_USERNAME or XQL_BINDED_DB_USERNAME";
        if(!$database) $missing[] = "XQL_BINLOG_DATABASE or XQL_BINDED_DB_DATABASE";

        if(count($missing) > 0) {
            throw new \RuntimeException("Missing XQL " . $provider . " binlog configuration: " . implode(", ", $missing) . ".");
        }
    }

    private function providerOptions(string $provider, array $options = []): array
    {
        $parts = [];

        $serverId = $options['binlog_server_id'] ?? Env::get("XQL_BINLOG_SERVER_ID");
        if($serverId) {
            $parts[] = "--connection-server-id=" . escapeshellarg((string) $serverId);
        }

        $socket = $options['binlog_socket'] ?? Env::get("XQL_BINLOG_SOCKET");
        if($provider === "self-hosted" && $socket) {
            $parts[] = "--socket=" . escapeshellarg($socket);
        }

        foreach($this->sslOptions($provider, $options) as $option => $value) {
            if($value === null || $value === "") continue;
            $parts[] = $option . "=" . escapeshellarg((string) $value);
        }

        return $parts;
    }

    private function sslOptions(string $provider, array $options = []): array
    {
        $mode = $options['binlog_ssl_mode'] ?? Env::get("XQL_BINLOG_SSL_MODE");
        if(!$mode && in_array($provider, ["azure", "gcp"], true)) {
            $mode = "REQUIRED";
        }

        return [
            "--ssl-mode" => $mode,
            "--ssl-ca" => $options['binlog_ssl_ca'] ?? Env::get("XQL_BINLOG_SSL_CA"),
            "--ssl-cert" => $options['binlog_ssl_cert'] ?? Env::get("XQL_BINLOG_SSL_CERT"),
            "--ssl-key" => $options['binlog_ssl_key'] ?? Env::get("XQL_BINLOG_SSL_KEY"),
        ];
    }

    private function parseLine(string $line, ?array &$event, array $source): ?array
    {
        $line = rtrim($line, "\r\n");

        if(preg_match('/^# at (\d+)/', $line, $match)) {
            $event['binlog_position'] = (int) $match[1];
            return null;
        }

        if(preg_match('/^#\d+\s+\d+:\d+:\d+\s+server id .* end_log_pos\s+(\d+)/', $line, $match)) {
            $event['next_binlog_position'] = (int) $match[1];
            return null;
        }

        if(preg_match('/^### (INSERT INTO|UPDATE|DELETE FROM) `[^`]+`\.`([^`]+)`/', $line, $match)) {
            $completed = null;
            if($event && (count($event['before']) > 0 || count($event['after']) > 0)) {
                $completed = $this->finalizeEvent($event, $source);
            }

            $event = [
                'event_type' => match($match[1]) {
                    'INSERT INTO' => 'insert',
                    'UPDATE' => 'update',
                    'DELETE FROM' => 'delete',
                },
                'table' => $match[2],
                'before' => [],
                'after' => [],
                'section' => null,
                'binlog_position' => $event['binlog_position'] ?? null,
                'next_binlog_position' => $event['next_binlog_position'] ?? null,
                'binlog_file' => $source['binlog_file'],
                'source_host' => $source['source_host'],
                'source_database' => $source['source_database'],
            ];

            return $completed;
        }

        if(!$event) return null;

        if($line === "### SET") {
            $event['section'] = 'after';
            return null;
        }

        if($line === "### WHERE") {
            $event['section'] = 'before';
            return null;
        }

        if(preg_match('/^###\s+@(\d+)=(.*?)(?: \/\*.*)?$/', $line, $match)) {
            $column = $this->columnName($event['table'], (int) $match[1]);
            $value = $this->parseValue($match[2]);
            $section = $event['section'] ?: ($event['event_type'] === 'delete' ? 'before' : 'after');
            $event[$section][$column] = $value;
            return null;
        }

        if(str_starts_with($line, "###") || !isset($event['event_type'])) {
            return null;
        }

        if(count($event['before']) === 0 && count($event['after']) === 0) {
            return null;
        }

        $parsed = $this->finalizeEvent($event, $source);

        $event = null;
        return $parsed;
    }

    private function finalizeEvent(array $event, array $source): array
    {
        $table = $event['table'];
        $primaryKeyColumn = $this->primaryKeyColumn($table);
        $primaryKey = $event['after'][$primaryKeyColumn] ?? $event['before'][$primaryKeyColumn] ?? null;

        return [
            'table' => $table,
            'event_type' => $event['event_type'],
            'before' => $event['before'],
            'after' => $event['after'],
            'metadata' => [
                'primary_key' => $primaryKey,
                'source_host' => $event['source_host'] ?? $source['source_host'],
                'source_database' => $event['source_database'] ?? $source['source_database'],
                'binlog_file' => $event['binlog_file'] ?? $source['binlog_file'],
                'binlog_position' => $event['next_binlog_position'] ?? $event['binlog_position'] ?? null,
            ],
        ];
    }

    private function columnName(string $table, int $ordinal): string
    {
        if(!isset($this->columnsByTable[$table])) {
            $this->columnsByTable[$table] = DBX::dataTableColumns($table);
        }

        return $this->columnsByTable[$table][$ordinal - 1] ?? "column_" . $ordinal;
    }

    private function primaryKeyColumn(string $table): string
    {
        if(!isset($this->primaryKeyByTable[$table])) {
            $this->primaryKeyByTable[$table] = DBX::dataTablePrimaryKey($table) ?: "id";
        }

        return $this->primaryKeyByTable[$table];
    }

    private function parseValue(string $raw): mixed
    {
        $raw = trim($raw);
        if($raw === "NULL") return null;
        if(preg_match("/^'(.*)'$/s", $raw, $match)) {
            return stripcslashes($match[1]);
        }
        if(is_numeric($raw)) {
            return str_contains($raw, ".") ? (float) $raw : (int) $raw;
        }
        return $raw;
    }
}
