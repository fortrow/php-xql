<?php

namespace XQL;

use XQL\Cloud\Cloud;
use XQL\Cloud\CloudDriver;
use XQL\Core\XQLField;
use XQL\Core\XQLModel;
use XQL\Core\XQLObject;
use XQL\Core\Utils\Env;
use XQL\Dev\CreateSchema;
use XQL\Dev\ProcessTrigger;
use XQL\Dev\Monitor\DaemonMonitor;
use XQL\DB\DBX;

class XQL
{
    public static function configure(array $config): void
    {
        Env::configure($config);
        Cloud::reset();
    }

    public static function useCloudDriver(CloudDriver $driver): void
    {
        Cloud::useDriver($driver);
    }

    public static function useConnections(?\PDO $xql = null, ?\PDO $data = null): void
    {
        DBX::useConnections($xql, $data);
    }

    public static function resetConnections(): void
    {
        DBX::resetConnections();
    }

    public static function validateConfig(): array
    {
        return DBX::validateConfig();
    }

    public static function fetch(string $modelClass, string $id): XQLModel
    {
        self::assertModelClass($modelClass);
        return $modelClass::fetch($id);
    }

    public static function metadata(string $modelClass, string $id): ?array
    {
        self::assertModelClass($modelClass);
        return DBX::instanceMetadata($modelClass, $id);
    }

    public static function list(string $modelClass, int $limit = 100, int $offset = 0): array
    {
        self::assertModelClass($modelClass);
        return DBX::listInstances($modelClass, $limit, $offset);
    }

    public static function search(string $modelClass, string $fieldName, mixed $value, int $limit = 100): array
    {
        self::assertModelClass($modelClass);
        return DBX::searchInstances($modelClass, $fieldName, $value, $limit);
    }

    public static function searchModels(string $modelClass, string $fieldName, mixed $value, int $limit = 100): array
    {
        return array_map(
            fn(array $row) => self::fetch($modelClass, $row['id']),
            self::search($modelClass, $fieldName, $value, $limit)
        );
    }

    public static function create(string $modelClass, array|\Closure $data, ?\Closure $callback = null): XQLModel
    {
        self::assertModelClass($modelClass);
        return $modelClass::create($data, $callback);
    }

    public static function value(XQLModel $model, string $xpath, mixed $default = null): mixed
    {
        return self::unwrap($model->get($xpath), $default);
    }

    public static function xml(XQLModel $model): string
    {
        return $model->toXml();
    }

    public static function array(XQLModel $model): array
    {
        return $model->toArray();
    }

    public static function syncModels(bool $createInstances = false): array
    {
        return CreateSchema::syncModelDefinitions($createInstances);
    }

    public static function rebuildDirty(int $limit = 100): array
    {
        return CreateSchema::rebuildDirtyInstances($limit);
    }

    public static function changedRow(string $table, string $eventType, array $before = [], array $after = [], array $metadata = []): array
    {
        return ProcessTrigger::changedRow($table, $eventType, $before, $after, $metadata);
    }

    public static function processJobs(int $limit = 100): array
    {
        return ProcessTrigger::processPendingJobs($limit);
    }

    public static function status(): array
    {
        return (new DaemonMonitor())->status();
    }

    private static function assertModelClass(string $modelClass): void
    {
        if(!class_exists($modelClass) || !is_subclass_of($modelClass, XQLModel::class)) {
            throw new \InvalidArgumentException($modelClass . " must be an XQLModel class.");
        }
    }

    private static function unwrap(mixed $value, mixed $default = null): mixed
    {
        if($value instanceof XQLField) {
            return $value->value() ?? $default;
        }

        if($value instanceof XQLObject) {
            return $value->toArray();
        }

        return $value ?? $default;
    }
}
