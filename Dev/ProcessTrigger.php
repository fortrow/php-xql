<?php

namespace XQL\Dev;

use Throwable;
use XQL\Core\XQLModel;
use XQL\DB\DBX;

class ProcessTrigger
{
    public static function changedRow(string $table, string $eventType, array $before = [], array $after = [], array $metadata = []): array
    {
        CreateSchema::migrate();

        $primaryKey = self::primaryKey($before, $after, $metadata);
        $eventUid = self::eventUid($table, $eventType, $primaryKey, $before, $after, $metadata);
        $affected = DBX::affectedInstancesForChangedRow($table, $primaryKey);

        $jobId = DBX::enqueueJob($eventUid, $table, $primaryKey, $eventType, [
            'table' => $table,
            'event_type' => $eventType,
            'primary_key' => $primaryKey,
            'before' => $before,
            'after' => $after,
            'metadata' => $metadata,
            'affected_instances' => $affected,
        ]);

        if(isset($metadata['binlog_file']) || isset($metadata['binlog_position']) || isset($metadata['gtid'])) {
            DBX::saveBinlogCheckpoint($metadata);
        }

        return [
            'job_id' => $jobId,
            'event_uid' => $eventUid,
            'affected_instances' => count($affected),
            'primary_key' => $primaryKey,
        ];
    }

    public static function processPendingJobs(int $limit = 100): array
    {
        CreateSchema::migrate();

        $result = [
            'jobs' => 0,
            'rebuilt' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        foreach(DBX::pendingJobs($limit) as $job) {
            $result['jobs']++;
            DBX::markJobProcessing((int) $job['id']);

            try {
                $payload = json_decode($job['payload_json'] ?? '{}', true) ?: [];
                $affected = $payload['affected_instances'] ?? DBX::affectedInstancesForChangedRow(
                    $job['source_table'],
                    $job['source_pk']
                );

                foreach($affected as $instance) {
                    $rebuilt = self::rebuildAffectedInstance($instance);
                    if($rebuilt) $result['rebuilt']++;
                    else $result['skipped']++;
                }

                DBX::markJobComplete((int) $job['id']);
            } catch(Throwable $e) {
                DBX::markJobError((int) $job['id'], $e->getMessage());
                $result['errors'][] = [
                    'job_id' => (int) $job['id'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    private static function rebuildAffectedInstance(array $instance): bool
    {
        $class = $instance['model_class'] ?? null;
        $id = $instance['id'] ?? null;

        if(!$class || !$id || !class_exists($class)) {
            return false;
        }

        try {
            /** @var XQLModel $model */
            $model = new $class();
            if($model->isFinal()) {
                throw new \Exception("Final model instances cannot be rebuilt.");
            }
            if(!$model->canAutoCreateFromRootBinding()) {
                throw new \Exception("Model requires external payload or computed context and cannot be rebuilt from root DB binding only.");
            }

            $primary = $model->primaryBindingInfo();
            $class::create([
                $primary['binding'] => [
                    $primary['field'] => $id,
                ],
            ]);
            return true;
        } catch(Throwable $e) {
            DBX::markInstanceRebuildError($class, (string) $id, $e->getMessage());
            return false;
        }
    }

    private static function primaryKey(array $before, array $after, array $metadata): ?string
    {
        $key = $metadata['primary_key'] ?? $metadata['id'] ?? null;
        if($key !== null) return (string) $key;

        foreach(['id', 'uuid'] as $field) {
            if(array_key_exists($field, $after)) return (string) $after[$field];
            if(array_key_exists($field, $before)) return (string) $before[$field];
        }

        return null;
    }

    private static function eventUid(string $table, string $eventType, ?string $primaryKey, array $before, array $after, array $metadata): string
    {
        if(!empty($metadata['event_uid'])) return (string) $metadata['event_uid'];

        $source = [
            'binlog_file' => $metadata['binlog_file'] ?? null,
            'binlog_position' => $metadata['binlog_position'] ?? null,
            'gtid' => $metadata['gtid'] ?? null,
            'table' => $table,
            'event_type' => $eventType,
            'primary_key' => $primaryKey,
            'before' => $before,
            'after' => $after,
        ];

        return hash('sha256', json_encode($source));
    }

}
