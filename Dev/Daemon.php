<?php

namespace XQL\Dev;

use Throwable;
use XQL\Dev\Binlog\MysqlBinlogAdapter;
use XQL\Dev\Monitor\DaemonMonitor;
use XQL\DB\DBX;

class Daemon
{
    private bool $running = true;

    public function run(array $options = []): array
    {
        CreateSchema::migrate();

        $intervalSeconds = max(1, (int) ($options['interval'] ?? 5));
        $limit = max(1, (int) ($options['limit'] ?? 100));
        $once = (bool) ($options['once'] ?? false);
        $withBinlog = (bool) ($options['binlog'] ?? false);
        $monitor = new DaemonMonitor();
        $healthLogInterval = max(0, (int) ($options['health_log_interval'] ?? 300));
        $lastHealthLog = 0;

        $this->installSignalHandlers();

        $summary = [
            'loops' => 0,
            'jobs' => 0,
            'rebuilt' => 0,
            'schema_rebuilt' => 0,
            'skipped' => 0,
            'errors' => [],
        ];
        $monitor->started([
            'binlog' => $withBinlog,
            'once' => $once,
            'interval_seconds' => $intervalSeconds,
            'limit' => $limit,
        ]);

        do {
            $summary['loops']++;

            try {
                if($withBinlog) {
                    $binlog = (new MysqlBinlogAdapter())->consume(
                        function (string $table, string $eventType, array $before, array $after, array $metadata) use (&$summary, $limit) {
                            ProcessTrigger::changedRow($table, $eventType, $before, $after, $metadata);
                            $jobs = ProcessTrigger::processPendingJobs($limit);
                            $schema = CreateSchema::rebuildDirtyInstances($limit);

                            $summary['jobs'] += $jobs['jobs'];
                            $summary['rebuilt'] += $jobs['rebuilt'];
                            $summary['schema_rebuilt'] += $schema['rebuilt'];
                            $summary['skipped'] += $jobs['skipped'] + $schema['skipped'];
                            $summary['errors'] = array_merge($summary['errors'], $jobs['errors'], $schema['errors']);
                        },
                        [
                            'stop_never' => !$once,
                            'binlog_file' => $options['binlog_file'] ?? null,
                            'binlog_position' => $options['binlog_position'] ?? null,
                        ]
                    );
                    $summary['binlog_events'] = ($summary['binlog_events'] ?? 0) + $binlog['events'];
                    $summary['binlog_rows'] = ($summary['binlog_rows'] ?? 0) + $binlog['rows'];
                    $summary['errors'] = array_merge($summary['errors'], $binlog['errors']);
                }

                $jobs = $withBinlog ? ['jobs' => 0, 'rebuilt' => 0, 'skipped' => 0, 'errors' => []] : ProcessTrigger::processPendingJobs($limit);
                $schema = $withBinlog ? ['rebuilt' => 0, 'skipped' => 0, 'errors' => []] : CreateSchema::rebuildDirtyInstances($limit);

                $summary['jobs'] += $jobs['jobs'];
                $summary['rebuilt'] += $jobs['rebuilt'];
                $summary['schema_rebuilt'] += $schema['rebuilt'];
                $summary['skipped'] += $jobs['skipped'] + $schema['skipped'];
                $summary['errors'] = array_merge($summary['errors'], $jobs['errors'], $schema['errors']);
            } catch(Throwable $e) {
                $summary['errors'][] = [
                    'loop' => $summary['loops'],
                    'error' => $e->getMessage(),
                ];
                $monitor->fault($e, [
                    'loop' => $summary['loops'],
                    'binlog' => $withBinlog,
                    'summary' => $summary,
                ]);
                if($withBinlog && !$once) {
                    throw $e;
                }
            }

            if($healthLogInterval > 0 && time() - $lastHealthLog >= $healthLogInterval) {
                $monitor->health($summary);
                $lastHealthLog = time();
            }

            if($once) break;

            $this->sleep($intervalSeconds);
        } while($this->running);

        $monitor->stopped($summary);
        return $summary;
    }

    public function checkpoint(): ?array
    {
        return DBX::binlogCheckpoint();
    }

    private function installSignalHandlers(): void
    {
        if(!function_exists('pcntl_signal')) return;

        pcntl_signal(SIGTERM, function () {
            $this->running = false;
        });
        pcntl_signal(SIGINT, function () {
            $this->running = false;
        });
    }

    private function sleep(int $seconds): void
    {
        for($i = 0; $i < $seconds && $this->running; $i++) {
            if(function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            sleep(1);
        }
    }
}
