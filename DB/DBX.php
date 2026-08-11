<?php

namespace XQL\DB;

use XQL\Core\Utils\DynamicValue;
use XQL\Core\Utils\Env;
use XQL\Core\Types\XQLBindingType;
use XQL\Core\XQLBinding;
use XQL\Core\XQLBindingClause;
use XQL\Core\XQLField;
use XQL\Core\XQLHook;
use XQL\Core\XQLModel;
use XQL\Core\XQLObject;
use Exception;
use PDO;

class DBX
{
    protected static PDO $data;
    protected static PDO $xql;

    protected static array $searchables = [];

    public static function useConnections(?PDO $xql = null, ?PDO $data = null): void
    {
        if($xql) {
            self::$xql = $xql;
            self::$xql->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }

        if($data) {
            self::$data = $data;
            self::$data->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
    }

    public static function resetConnections(): void
    {
        unset(self::$xql, self::$data);
    }

    public static function validateConfig(): array
    {
        $errors = [];
        foreach([
            'XQL_DB_DRIVER',
            'XQL_DB_HOST',
            'XQL_DB_PORT',
            'XQL_DB_DATABASE',
            'XQL_DB_USERNAME',
            'XQL_BINDED_DB_DRIVER',
            'XQL_BINDED_DB_HOST',
            'XQL_BINDED_DB_PORT',
            'XQL_BINDED_DB_DATABASE',
            'XQL_BINDED_DB_USERNAME',
        ] as $key) {
            if(!Env::get($key)) {
                $errors[] = $key . " is required when PDO connections are not injected.";
            }
        }

        return [
            'ok' => count($errors) === 0,
            'errors' => $errors,
        ];
    }

    public static function instanceCreated(XQLModel $instance)
    {
        self::connect();
        self::modelDefined($instance);
        self::insertInstance($instance);
        self::insertInstanceBindings($instance);
    }

    public static function modelDefined(XQLModel $instance): void
    {
        self::connect();
        self::insertModel($instance);
        self::insertHooks($instance);
        self::insertBindings($instance, false);
        self::insertSchemaMigrations($instance);
        self::createHookTriggers($instance);
        self::createBindingTriggers($instance);
    }

    protected static function insertInstance(XQLModel $instance) {
        $con = self::$xql;
        $path = $instance->path();
        $primary = $instance->primaryBindingInfo();
        $signature = $instance->schemaSignature();

        $query = "INSERT INTO instances(
            `id`,
            `model_class`,
            `model_key`,
            `type`,
            `path`,
            `root_table`,
            `root_pk`,
            `status`,
            `version`,
            `checksum`,
            `schema_signature`,
            `schema_dirty`,
            `last_modified`,
            `created_at`
        ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,now(),now())
        ON DUPLICATE KEY UPDATE
            `path`=VALUES(`path`),
            `root_table`=VALUES(`root_table`),
            `root_pk`=VALUES(`root_pk`),
            `status`='active',
            `version`=`version` + 1,
            `schema_signature`=VALUES(`schema_signature`),
            `schema_dirty`=0,
            `schema_error`=null,
            `last_modified`=now()";
        $stmt = $con->prepare($query);
        $stmt->bindValue(1, $instance->id());
        $stmt->bindValue(2, get_class($instance));
        $stmt->bindValue(3, $instance->modelKey());
        $stmt->bindValue(4, $instance->modelKey(true));
        $stmt->bindValue(5, $path);
        $stmt->bindValue(6, $primary['table']);
        $stmt->bindValue(7, $primary['value']);
        $stmt->bindValue(8, 'active');
        $stmt->bindValue(9, 1);
        $stmt->bindValue(10, null);
        $stmt->bindValue(11, $signature);
        $stmt->bindValue(12, 0, PDO::PARAM_INT);

        $stmt->execute();
    }

    protected static function insertModel(XQLModel $instance): void
    {
        $signature = $instance->schemaSignature();
        $exists = self::$xql->prepare("SELECT id FROM models WHERE model_class=? AND model_key=? LIMIT 1");
        $exists->bindValue(1, get_class($instance));
        $exists->bindValue(2, $instance->modelKey());
        $exists->execute();
        $existingId = $exists->fetchColumn();
        if($existingId) {
            $current = self::$xql->prepare("SELECT schema_signature FROM models WHERE id=? LIMIT 1");
            $current->bindValue(1, $existingId, PDO::PARAM_INT);
            $current->execute();
            $currentSignature = $current->fetchColumn();

            $primary = $instance->primaryBindingInfo();
            $update = self::$xql->prepare("UPDATE models SET
                `root_table`=?,
                `root_binding_name`=?,
                `is_static`=?,
                `is_final`=?,
                `schema_signature`=?,
                `updated_at`=now()
                WHERE id=?");
            $update->bindValue(1, $primary['table']);
            $update->bindValue(2, $primary['binding']);
            $update->bindValue(3, $instance->isStatic() ? 1 : 0, PDO::PARAM_INT);
            $update->bindValue(4, $instance->isFinal() ? 1 : 0, PDO::PARAM_INT);
            $update->bindValue(5, $signature);
            $update->bindValue(6, $existingId, PDO::PARAM_INT);
            $update->execute();

            if($currentSignature !== $signature) {
                $dirty = self::$xql->prepare("UPDATE instances SET schema_dirty=1 WHERE model_class=? AND model_key=?");
                $dirty->bindValue(1, get_class($instance));
                $dirty->bindValue(2, $instance->modelKey());
                $dirty->execute();
            }
            return;
        }

        $primary = $instance->primaryBindingInfo();
        $query = "INSERT INTO models(
            `model_class`,
            `model_key`,
            `root_table`,
            `root_binding_name`,
            `is_static`,
            `is_final`,
            `schema_signature`,
            `created_at`,
            `updated_at`
        ) VALUES(?,?,?,?,?,?,?,now(),now())";
        $stmt = self::$xql->prepare($query);
        $stmt->bindValue(1, get_class($instance));
        $stmt->bindValue(2, $instance->modelKey());
        $stmt->bindValue(3, $primary['table']);
        $stmt->bindValue(4, $primary['binding']);
        $stmt->bindValue(5, $instance->isStatic() ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(6, $instance->isFinal() ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(7, $signature);
        $stmt->execute();
    }

    protected static function insertHooks(XQLModel $instance) {
        foreach($instance->hooks() as $hook) {
            if(!$hook instanceof XQLHook) continue;

            $existing = self::$xql->prepare("SELECT id FROM hooks WHERE source_table=? AND event_type=? AND model_class=? LIMIT 1");
            $existing->bindValue(1, $hook->table());
            $existing->bindValue(2, strtolower($hook->type()->name));
            $existing->bindValue(3, get_class($instance));
            $existing->execute();
            if($existing->fetchColumn()) continue;

            $query = "INSERT INTO hooks(
                `source_table`,
                `event_type`,
                `source_columns_json`,
                `model_class`,
                `binding_id`,
                `handler_class`,
                `handler_method`,
                `enabled`,
                `created_at`
            ) VALUES(?,?,?,?,?,?,?,?,now())";
            $stmt = self::$xql->prepare($query);
            $stmt->bindValue(1, $hook->table());
            $stmt->bindValue(2, strtolower($hook->type()->name));
            $stmt->bindValue(3, json_encode($hook->columns()));
            $stmt->bindValue(4, get_class($instance));
            $stmt->bindValue(5, null);
            $stmt->bindValue(6, null);
            $stmt->bindValue(7, null);
            $stmt->bindValue(8, 1, PDO::PARAM_INT);
            $stmt->execute();
        }
    }

    protected static function insertBindings(XQLModel $instance, bool $includeInstanceBindings = true) {
        self::insertBindingsForObject($instance, $instance, $instance->modelKey(), $includeInstanceBindings);
    }

    protected static function insertSchemaMigrations(XQLModel $instance): void
    {
        $delete = self::$xql->prepare("DELETE FROM model_schema_migrations WHERE model_class=? AND model_key=?");
        $delete->bindValue(1, get_class($instance));
        $delete->bindValue(2, $instance->modelKey());
        $delete->execute();

        foreach($instance->schemaMigrations() as $migration) {
            $definition = $migration;
            unset($definition['callback']);

            $query = "INSERT INTO model_schema_migrations(
                `model_class`,
                `model_key`,
                `migration_name`,
                `migration_type`,
                `from_signature`,
                `definition_json`,
                `created_at`
            ) VALUES(?,?,?,?,?,?,now())";
            $stmt = self::$xql->prepare($query);
            $stmt->bindValue(1, get_class($instance));
            $stmt->bindValue(2, $instance->modelKey());
            $stmt->bindValue(3, $migration['name'] ?? null);
            $stmt->bindValue(4, $migration['type'] ?? 'custom');
            $stmt->bindValue(5, $migration['from_signature'] ?? null);
            $stmt->bindValue(6, json_encode($definition));
            $stmt->execute();
        }
    }

    protected static function insertInstanceBindings(XQLModel $instance): void
    {
        self::insertBindings($instance, true);
    }

    protected static function createHookTriggers(XQLModel $instance) {

    }

    protected static function createBindingTriggers(XQLModel $instance) {

    }

    protected static function insertBindingsForObject(XQLModel $instance, XQLObject $object, string $path, bool $includeInstanceBindings = true): void
    {
        foreach ($object->children() as $child) {
            if (is_array($child) && count($child) === 1) {
                $child = array_values($child)[0];
            }

            if ($child instanceof XQLBinding) {
                $bindingId = self::insertBinding($instance, $child, $path);
                self::insertBindingReferences($bindingId, $child);
                if($includeInstanceBindings) {
                    self::insertInstanceBinding($instance, $bindingId, $child, $path);
                }
            }

            if ($child instanceof XQLObject) {
                $childPath = $child->xpath() ?: $path . "/" . $child->fieldName();
                self::insertBindingsForObject($instance, $child, $childPath, $includeInstanceBindings);
            }
        }
    }

    protected static function insertBinding(XQLModel $instance, XQLBinding $binding, string $path): int
    {
        $sourceTable = is_string($binding->bindFrom()) && $binding->getBindType() === XQLBindingType::DB_TO_FILE
            ? $binding->bindFrom()
            : null;

        $sourceModelClass = is_string($binding->bindFrom()) && $binding->getBindType() !== XQLBindingType::DB_TO_FILE
            ? $binding->bindFrom()
            : null;

        $existing = self::$xql->prepare("SELECT id FROM models_with_bindings WHERE model_name=? AND bind_name=? AND type=? AND xml_path=? LIMIT 1");
        $existing->bindValue(1, $instance->modelKey());
        $existing->bindValue(2, $binding->name());
        $existing->bindValue(3, $binding->getBindType()->value, PDO::PARAM_INT);
        $existing->bindValue(4, $path . "/" . $binding->fieldName());
        $existing->execute();
        $existingId = $existing->fetchColumn();
        if($existingId) return (int) $existingId;

        $query = "INSERT INTO models_with_bindings(
            `model_name`,
            `bind_name`,
            `reference_name`,
            `model_field_name`,
            `type`,
            `source_table`,
            `source_model_class`,
            `xml_path`,
            `where_json`,
            `created_at`
        ) VALUES(?,?,?,?,?,?,?,?,?,now())";

        $stmt = self::$xql->prepare($query);
        $stmt->bindValue(1, $instance->modelKey());
        $stmt->bindValue(2, $binding->name());
        $stmt->bindValue(3, implode(",", $binding->references()));
        $stmt->bindValue(4, $binding->fieldName());
        $stmt->bindValue(5, $binding->getBindType()->value, PDO::PARAM_INT);
        $stmt->bindValue(6, $sourceTable);
        $stmt->bindValue(7, $sourceModelClass);
        $stmt->bindValue(8, $path . "/" . $binding->fieldName());
        $stmt->bindValue(9, json_encode($binding->whereConditions()));
        $stmt->execute();

        return (int) self::$xql->lastInsertId();
    }

    protected static function insertBindingReferences(int $bindingId, XQLBinding $binding): void
    {
        $sourceTable = is_string($binding->bindFrom()) && $binding->getBindType() === XQLBindingType::DB_TO_FILE
            ? $binding->bindFrom()
            : null;

        foreach ($binding->references() as $reference) {
            $existing = self::$xql->prepare("SELECT id FROM binding_columns WHERE binding_id=? AND source_column=? AND reference_key=? LIMIT 1");
            $existing->bindValue(1, $bindingId, PDO::PARAM_INT);
            $existing->bindValue(2, $reference);
            $existing->bindValue(3, $reference);
            $existing->execute();
            if($existing->fetchColumn()) continue;

            $query = "INSERT INTO binding_columns(
                `binding_id`,
                `source_table`,
                `source_column`,
                `reference_key`
            ) VALUES(?,?,?,?)";
            $stmt = self::$xql->prepare($query);
            $stmt->bindValue(1, $bindingId, PDO::PARAM_INT);
            $stmt->bindValue(2, $sourceTable);
            $stmt->bindValue(3, $reference);
            $stmt->bindValue(4, $reference);
            $stmt->execute();
        }
    }

    protected static function insertInstanceBinding(XQLModel $instance, int $bindingId, XQLBinding $binding, string $path): void
    {
        if(!is_string($binding->bindFrom()) || $binding->getBindType() !== XQLBindingType::DB_TO_FILE) {
            return;
        }

        $sourcePk = null;
        foreach($binding->references() as $reference) {
            $value = $binding->get($reference) ?? $binding->get("0." . $reference);
            if($value instanceof XQLField) {
                $value = $value->value();
            }
            if(is_string($value) || is_numeric($value)) {
                $sourcePk = (string) $value;
                break;
            }
        }

        $existing = self::$xql->prepare("SELECT id FROM instance_bindings WHERE instance_id=? AND binding_id=? AND source_pk <=> ? LIMIT 1");
        $existing->bindValue(1, $instance->id());
        $existing->bindValue(2, $bindingId, PDO::PARAM_INT);
        $existing->bindValue(3, $sourcePk);
        $existing->execute();
        if($existing->fetchColumn()) return;

        $query = "INSERT INTO instance_bindings(
            `instance_id`,
            `binding_id`,
            `source_table`,
            `source_pk`,
            `xml_path`,
            `created_at`
        ) VALUES(?,?,?,?,?,now())";
        $stmt = self::$xql->prepare($query);
        $stmt->bindValue(1, $instance->id());
        $stmt->bindValue(2, $bindingId, PDO::PARAM_INT);
        $stmt->bindValue(3, $binding->bindFrom());
        $stmt->bindValue(4, $sourcePk);
        $stmt->bindValue(5, $path . "/" . $binding->fieldName());
        $stmt->execute();
    }

    protected static function createTable(PDO $con, string $tableName, array $config)
    {
        $query = "CREATE TABLE IF NOT EXISTS " . $tableName . "( ";
        $auto = !array_key_exists("primary", $config) && !array_key_exists("id", $config['columns']);
        if($auto) {
            $query .= "`id` int ";
        }
        $columns = array_keys($config['columns']);
        for($i=0; $i<count($columns); $i++) {
            if($i > 0 || $auto) $query .= ", ";
            $columnName = $columns[$i];
            $column = $config['columns'][$columnName];
            $type = $column['type'];
            $nullSetting = $column['null'] ?? 'yes';
            $null = !($nullSetting === 'not' || $nullSetting === 'no');
            $query .= "`" . $columnName . "` " . $type . (($null) ? '' : ' not') . ' null ';
            if(array_key_exists('default', $column)) {
                $query .= " default " . $column['default'] . " ";
            }
        }
        if($auto) {
            $query .= ", primary key (`id`) ";
        } else {
            $query .= ", primary key (`" . $config['primary'] . "`)";
        }
        $query .= " )";
        $con->exec($query);

        if($auto) {
            $con->exec("alter table `" . $tableName . "` modify `id` int auto_increment");
        }
    }

    protected static function ensureColumn(PDO $con, string $tableName, string $columnName, array $column): void
    {
        if(!self::tableExists($con, $tableName)) return;
        if(self::columnExists($con, $tableName, $columnName)) return;
        $con->exec("ALTER TABLE `" . $tableName . "` ADD COLUMN " . self::columnSql($columnName, $column));
    }

    protected static function modifyColumn(PDO $con, string $tableName, string $columnName, array $column): void
    {
        if(!self::tableExists($con, $tableName)) return;
        if(!self::columnExists($con, $tableName, $columnName)) {
            self::ensureColumn($con, $tableName, $columnName, $column);
            return;
        }
        $con->exec("ALTER TABLE `" . $tableName . "` MODIFY COLUMN " . self::columnSql($columnName, $column));
    }

    protected static function tableExists(PDO $con, string $tableName): bool
    {
        $stmt = $con->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        $stmt->bindValue(1, $tableName);
        $stmt->execute();
        return (int) $stmt->fetchColumn() > 0;
    }

    protected static function columnExists(PDO $con, string $tableName, string $columnName): bool
    {
        $stmt = $con->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $stmt->bindValue(1, $tableName);
        $stmt->bindValue(2, $columnName);
        $stmt->execute();
        return (int) $stmt->fetchColumn() > 0;
    }

    protected static function columnSql(string $columnName, array $column): string
    {
        $type = $column['type'];
        $nullSetting = $column['null'] ?? 'yes';
        $null = !($nullSetting === 'not' || $nullSetting === 'no');
        $sql = "`" . $columnName . "` " . $type . (($null) ? '' : ' not') . ' null ';
        if(array_key_exists('default', $column)) {
            $sql .= " default " . $column['default'] . " ";
        }
        return $sql;
    }

    public static function instanceExists(XQLModel $instance): bool
    {
        self::connect();
        return self::instanceIdExists(get_class($instance), $instance->id());
    }

    public static function instanceIdExists(string $modelClass, string $id): bool
    {
        self::connect();
        $stmt = self::$xql->prepare("SELECT id FROM instances WHERE id=? AND model_class=? LIMIT 1");
        $stmt->bindValue(1, $id);
        $stmt->bindValue(2, $modelClass);
        $stmt->execute();
        return (bool) $stmt->fetchColumn();
    }

    public static function schemaDirtyInstances(int $limit = 100): array
    {
        self::connect();
        $stmt = self::$xql->prepare("SELECT id, model_class, model_key, root_table, root_pk, path, schema_signature FROM instances WHERE schema_dirty=1 ORDER BY last_modified ASC LIMIT ?");
        $stmt->bindValue(1, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        $stmt->setFetchMode(PDO::FETCH_ASSOC);
        return $stmt->fetchAll();
    }

    public static function markSchemaRebuildComplete(string $modelClass, string $id, string $schemaSignature): void
    {
        self::connect();
        $stmt = self::$xql->prepare("UPDATE instances SET schema_dirty=0, schema_error=null, schema_signature=?, status='active', version=version + 1, last_modified=now() WHERE id=? AND model_class=?");
        $stmt->bindValue(1, $schemaSignature);
        $stmt->bindValue(2, $id);
        $stmt->bindValue(3, $modelClass);
        $stmt->execute();
    }

    public static function markSchemaRebuildError(string $modelClass, string $id, string $error): void
    {
        self::connect();
        $stmt = self::$xql->prepare("UPDATE instances SET schema_error=?, status='schema_rebuild_failed', last_modified=now() WHERE id=? AND model_class=?");
        $stmt->bindValue(1, $error);
        $stmt->bindValue(2, $id);
        $stmt->bindValue(3, $modelClass);
        $stmt->execute();
    }

    public static function affectedInstancesForChangedRow(string $table, string|int|null $primaryKey): array
    {
        self::connect();
        if($primaryKey === null || $primaryKey === '') return [];
        $primaryKey = (string) $primaryKey;

        $query = "
            SELECT DISTINCT i.id, i.model_class, i.model_key, i.root_table, i.root_pk, i.path, i.schema_signature
            FROM instances i
            WHERE i.root_table = ? AND i.root_pk = ?
            UNION
            SELECT DISTINCT i.id, i.model_class, i.model_key, i.root_table, i.root_pk, i.path, i.schema_signature
            FROM instance_bindings ib
            INNER JOIN instances i ON i.id = ib.instance_id
            WHERE ib.source_table = ? AND ib.source_pk = ?
        ";

        $stmt = self::$xql->prepare($query);
        $stmt->bindValue(1, $table);
        $stmt->bindValue(2, $primaryKey);
        $stmt->bindValue(3, $table);
        $stmt->bindValue(4, $primaryKey);
        $stmt->execute();
        $stmt->setFetchMode(PDO::FETCH_ASSOC);
        return $stmt->fetchAll();
    }

    public static function enqueueJob(string $eventUid, string $table, string|int|null $primaryKey, string $eventType, array $payload): int
    {
        self::connect();

        $existing = self::$xql->prepare("SELECT id FROM jobs WHERE event_uid=? LIMIT 1");
        $existing->bindValue(1, $eventUid);
        $existing->execute();
        $existingId = $existing->fetchColumn();
        if($existingId) return (int) $existingId;

        $query = "INSERT INTO jobs(
            `event_uid`,
            `source_table`,
            `source_pk`,
            `event_type`,
            `payload_json`,
            `status`,
            `attempts`,
            `available_at`,
            `created_at`,
            `updated_at`
        ) VALUES(?,?,?,?,?,'pending',0,now(),now(),now())";
        $stmt = self::$xql->prepare($query);
        $stmt->bindValue(1, $eventUid);
        $stmt->bindValue(2, $table);
        $stmt->bindValue(3, $primaryKey === null ? null : (string) $primaryKey);
        $stmt->bindValue(4, $eventType);
        $stmt->bindValue(5, json_encode($payload));
        $stmt->execute();

        return (int) self::$xql->lastInsertId();
    }

    public static function pendingJobs(int $limit = 100): array
    {
        self::connect();
        $stmt = self::$xql->prepare("SELECT id, event_uid, source_table, source_pk, event_type, payload_json, attempts FROM jobs WHERE status='pending' AND (available_at IS NULL OR available_at <= now()) ORDER BY created_at ASC LIMIT ?");
        $stmt->bindValue(1, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        $stmt->setFetchMode(PDO::FETCH_ASSOC);
        return $stmt->fetchAll();
    }

    public static function markJobProcessing(int $jobId): void
    {
        self::connect();
        $stmt = self::$xql->prepare("UPDATE jobs SET status='processing', attempts=attempts + 1, updated_at=now() WHERE id=?");
        $stmt->bindValue(1, $jobId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public static function markJobComplete(int $jobId): void
    {
        self::connect();
        $stmt = self::$xql->prepare("UPDATE jobs SET status='complete', last_error=null, updated_at=now() WHERE id=?");
        $stmt->bindValue(1, $jobId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public static function markJobError(int $jobId, string $error): void
    {
        self::connect();
        $stmt = self::$xql->prepare("UPDATE jobs SET status='failed', last_error=?, updated_at=now() WHERE id=?");
        $stmt->bindValue(1, $error);
        $stmt->bindValue(2, $jobId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public static function markInstanceRebuildError(string $modelClass, string $id, string $error): void
    {
        self::connect();
        $stmt = self::$xql->prepare("UPDATE instances SET status='rebuild_failed', schema_error=?, last_modified=now() WHERE id=? AND model_class=?");
        $stmt->bindValue(1, $error);
        $stmt->bindValue(2, $id);
        $stmt->bindValue(3, $modelClass);
        $stmt->execute();
    }

    public static function instanceMetadata(string $modelClass, string $id): ?array
    {
        self::connect();
        $modelKey = self::modelKeyForClass($modelClass);
        $stmt = self::$xql->prepare("SELECT * FROM instances WHERE id=? AND model_key=? LIMIT 1");
        $stmt->bindValue(1, $id);
        $stmt->bindValue(2, $modelKey);
        $stmt->execute();
        $stmt->setFetchMode(PDO::FETCH_ASSOC);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function listInstances(string $modelClass, int $limit = 100, int $offset = 0): array
    {
        self::connect();
        $modelKey = self::modelKeyForClass($modelClass);
        $stmt = self::$xql->prepare("SELECT * FROM instances WHERE model_key=? ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $stmt->bindValue(1, $modelKey);
        $stmt->bindValue(2, max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(3, max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        $stmt->setFetchMode(PDO::FETCH_ASSOC);
        return $stmt->fetchAll();
    }

    public static function searchInstances(string $modelClass, string $fieldName, mixed $value, int $limit = 100): array
    {
        self::connect();
        $modelKey = self::modelKeyForClass($modelClass);
        $tableName = self::modelKeyPluralForClass($modelClass) . "_searchable";
        if(!self::tableExists(self::$xql, $tableName)) return [];

        $dynamic = (new DynamicValue($value))->all();
        $conditions = ["field_name = ?"];
        $values = [$fieldName];

        foreach([
            'string_value' => $dynamic['string'],
            'integer_value' => $dynamic['integer'],
            'float_value' => $dynamic['float'],
            'datetime_value' => $dynamic['timestamp'],
        ] as $column => $searchValue) {
            if($searchValue !== null) {
                $conditions[] = "`" . $column . "` = ?";
                $values[] = $searchValue;
                break;
            }
        }

        $query = "SELECT DISTINCT i.* FROM `" . $tableName . "` s INNER JOIN instances i ON i.id = s.instance_id WHERE i.model_key = ? AND " . implode(" AND ", $conditions) . " ORDER BY i.created_at DESC LIMIT ?";
        $stmt = self::$xql->prepare($query);
        $stmt->bindValue(1, $modelKey);
        $index = 2;
        foreach($values as $searchValue) {
            $stmt->bindValue($index, $searchValue);
            $index++;
        }
        $stmt->bindValue($index, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        $stmt->setFetchMode(PDO::FETCH_ASSOC);
        return $stmt->fetchAll();
    }

    protected static function modelKeyForClass(string $modelClass): string
    {
        /** @var XQLModel $model */
        $model = new $modelClass();
        return $model->modelKey();
    }

    protected static function modelKeyPluralForClass(string $modelClass): string
    {
        /** @var XQLModel $model */
        $model = new $modelClass();
        return $model->modelKey(true);
    }

    public static function binlogCheckpoint(?string $sourceHost = null, ?string $sourceDatabase = null): ?array
    {
        self::connect();
        $sourceHost = $sourceHost ?? Env::get("XQL_BINDED_DB_HOST");
        $sourceDatabase = $sourceDatabase ?? Env::get("XQL_BINDED_DB_DATABASE");

        $stmt = self::$xql->prepare("SELECT source_host, source_database, binlog_file, binlog_position, gtid, updated_at FROM binlog_checkpoints WHERE source_host=? AND source_database=? ORDER BY updated_at DESC LIMIT 1");
        $stmt->bindValue(1, $sourceHost);
        $stmt->bindValue(2, $sourceDatabase);
        $stmt->execute();
        $stmt->setFetchMode(PDO::FETCH_ASSOC);
        $checkpoint = $stmt->fetch();

        return $checkpoint ?: null;
    }

    public static function saveBinlogCheckpoint(array $metadata): void
    {
        self::connect();

        $sourceHost = $metadata['source_host'] ?? Env::get("XQL_BINDED_DB_HOST");
        $sourceDatabase = $metadata['source_database'] ?? Env::get("XQL_BINDED_DB_DATABASE");
        $binlogFile = $metadata['binlog_file'] ?? null;
        $binlogPosition = $metadata['binlog_position'] ?? null;
        $gtid = $metadata['gtid'] ?? null;

        $existing = self::$xql->prepare("SELECT id FROM binlog_checkpoints WHERE source_host=? AND source_database=? LIMIT 1");
        $existing->bindValue(1, $sourceHost);
        $existing->bindValue(2, $sourceDatabase);
        $existing->execute();
        $existingId = $existing->fetchColumn();

        if($existingId) {
            $stmt = self::$xql->prepare("UPDATE binlog_checkpoints SET binlog_file=?, binlog_position=?, gtid=?, updated_at=now() WHERE id=?");
            $stmt->bindValue(1, $binlogFile);
            $stmt->bindValue(2, $binlogPosition);
            $stmt->bindValue(3, $gtid);
            $stmt->bindValue(4, $existingId, PDO::PARAM_INT);
            $stmt->execute();
            return;
        }

        $stmt = self::$xql->prepare("INSERT INTO binlog_checkpoints(source_host, source_database, binlog_file, binlog_position, gtid, updated_at) VALUES(?,?,?,?,?,now())");
        $stmt->bindValue(1, $sourceHost);
        $stmt->bindValue(2, $sourceDatabase);
        $stmt->bindValue(3, $binlogFile);
        $stmt->bindValue(4, $binlogPosition);
        $stmt->bindValue(5, $gtid);
        $stmt->execute();
    }

    public static function dataTableColumns(string $table): array
    {
        self::connect();
        $stmt = self::$data->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position ASC");
        $stmt->bindValue(1, $table);
        $stmt->execute();
        return array_map(fn($row) => $row['column_name'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function dataTablePrimaryKey(string $table): ?string
    {
        self::connect();
        $stmt = self::$data->prepare("SELECT column_name FROM information_schema.key_column_usage WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = 'PRIMARY' ORDER BY ordinal_position ASC LIMIT 1");
        $stmt->bindValue(1, $table);
        $stmt->execute();
        $column = $stmt->fetchColumn();
        return $column ? (string) $column : null;
    }

    protected static function getSearchableFields(XQLModel $model)
    {
        if(isset(self::$searchables['$model'])) return;
        self::connect();
        $con = self::$xql;
        $query = "SELECT id, field_name FROM models_with_searchable WHERE model_name=?";
        $stmt = $con->prepare($query);
        $stmt->bindValue(1, $model->modelKey());
        $stmt->execute();

        $stmt->setFetchMode(PDO::FETCH_ASSOC);
        $arr = $stmt->fetchAll();
        if(count($arr) > 0) self::$searchables = array_merge(array($model->modelKey() => $arr), self::$searchables);
    }

    public static function updateSearchableFields(XQLModel $instance, XQLObject $field)
    {
        self::getSearchableFields($instance);
        if(isset(self::$searchables[$instance->modelKey()])) {
            $searchables = array_column(self::$searchables[$instance->modelKey()],  "field_name");
            if(in_array($field->fieldName(), $searchables)) return;
            self::connect();
            $con = self::$xql;
            $insertQuery = "INSERT INTO models_with_searchable(`model_name`, `field_name`) VALUES(?, ?)";
            $stmt = $con->prepare($insertQuery);
            $stmt->bindValue(1, $instance->modelKey());
            $stmt->bindValue(2, $field->fieldName());
            $stmt->execute();
            self::$searchables[$instance->modelKey()][] = $field->fieldName();
        } else {
            self::connect();
            $con = self::$xql;
            $insertQuery = "INSERT INTO models_with_searchable(`model_name`, `field_name`) VALUES(?, ?)";
            $stmt = $con->prepare($insertQuery);
            $stmt->bindValue(1, $instance->modelKey());
            $stmt->bindValue(2, $field->fieldName());
            $stmt->execute();
            $tableName = $instance->modelKey(true) . "_searchable";
            self::createTable($con, $tableName, [
                'columns' => [
                    'instance_id' => [
                        'type' => 'varchar(255)'
                    ],
                    'xpath' => [
                        'type' => 'varchar(255)'
                    ],
                    'field_name' => [
                        'type' => 'varchar(255)'
                    ],
                    'field_type' => [
                        'type' => 'varchar(255)'
                    ],
                    'string_value' => [
                        'type' => 'text'
                    ],
                    'integer_value' => [
                        'type' => 'int'
                    ],
                    'float_value' => [
                        'type' => 'float'
                    ],
                    'datetime_value' => [
                        'type' => 'timestamp'
                    ]
                ]
            ]);
        }
    }

    public static function insertSearchableValue(XQLModel $instance, XQLField $field, $value = null)
    {
        if(!isset($value)) $value = $field->value();
        $instance_id = $instance->id();
        $xpath = $field->xpath();
        $field_name = $field->fieldName();
        $field_type = $field->type();
        $dynamic_values = (new DynamicValue($value))->all();

        self::connect();
        $con = self::$xql;

        $tableName = $instance->modelKey(true) . "_searchable";
        self::deleteExistingSearchableValue($con, $tableName, $instance_id, $xpath, $field_name);

        $query = "INSERT INTO `" . $tableName .
            "` (`instance_id`, `xpath`, `field_name`, `field_type`, `string_value`, `integer_value`, `float_value`, `datetime_value`) VALUES(?,?,?,?,?,?,?,?)";

        $stmt = $con->prepare($query);
        $stmt->bindValue(1, $instance_id);
        $stmt->bindValue(2, $xpath);
        $stmt->bindValue(3, $field_name);
        $stmt->bindValue(4, $field_type->value);
        $stmt->bindValue(5, $dynamic_values['string']);
        $stmt->bindValue(6, $dynamic_values['integer']);
        $stmt->bindValue(7, $dynamic_values['float']);
        $stmt->bindValue(8, $dynamic_values['timestamp']);

        $stmt->execute();

    }

    private static function deleteExistingSearchableValue(PDO $con, string $tableName, string $instanceId, string $xpath, string $fieldName): void
    {
        if(!self::tableExists($con, $tableName)) return;
        $stmt = $con->prepare("DELETE FROM `" . $tableName . "` WHERE instance_id=? AND xpath=? AND field_name=?");
        $stmt->bindValue(1, $instanceId);
        $stmt->bindValue(2, $xpath);
        $stmt->bindValue(3, $fieldName);
        $stmt->execute();
    }

    public static function getBindedValues(string $table, array|string $columns, XQLBindingClause $where, array $equals) {

        $conditions = $where->get();

        self::connect();
        $con = self::$data;
        $query = "SELECT";
        if(!is_array($columns)) {
            $query .= " " . $columns;
        } else {
            $first = true;
            foreach($columns as $column) {
                $query .= ((!$first) ? ", " : " ") . $column;
                $first = false;
            }
        }

        $query .= " FROM `" . $table . "` WHERE";

        $res = self::parseWhere($query, $conditions, $equals);
        $query = $res[0];
        $toBind = $res[1];

        $stmt = $con->prepare($query);
        $order = 1;
        for($i=0; $i<count($toBind); $i++) {
            $condition = $toBind[$i];
            if(is_array($condition['value'])) {
                foreach($condition['value'] as $value) {
                    $stmt->bindValue($order, $value);
                    $order++;
                }
            } else {
                $stmt->bindValue($order, $condition['value']);
                $order++;
            }
        }
        $stmt->execute();

        $stmt->setFetchMode(PDO::FETCH_ASSOC);
        return $stmt->fetchAll();
    }

    protected static function parseWhere(string $query, array $conditions, array $equals, array $values = []): array
    {
        if(count($conditions) == 0) return [$query . " 1", $values];
        foreach($conditions as $condition) {
            if(array_key_exists("condition", $condition)) {
                $query .= " " . $condition['condition'];
            } else if(array_key_exists("column", $condition)) {
                if(!array_key_exists($condition['key'], $equals)) {
                    throw new Exception("Missing value for " . $condition['key'] . " (the column `" . $condition['column'] . "`).");
                }
                if(is_array($equals[$condition['key']])) {
                    $condition['value'] = $equals[$condition['key']];
                    $values[] = $condition;
                    $query .= " `" . $condition['column'] . "` IN (";
                    $first = true;
                    foreach($equals[$condition['key']] as $ignored) {
                        $query .= ($first) ? "?" : ", ?";
                        $first = false;
                    }
                    $query .= ")";
                } else {
                    $condition['value'] = $equals[$condition['key']];
                    $values[] = $condition;
                    $query .= " `" . $condition['column'] . "` = ?";
                }
            } else if(is_array($condition)) {
                $res = self::parseWhere($query, $condition, $equals, $values);
                $query = $res[0];
                $values = $res[1];
            }
        }
        return [$query, $values];
    }
    
    protected static function connect()
    {
        if(!isset(self::$data) || !isset(self::$xql)) {
            $xqlDriver = Env::get("XQL_DB_DRIVER");
            if($xqlDriver == "mysql" || $xqlDriver == "mariadb") {
                self::$xql = self::mysql(
                    Env::get("XQL_DB_HOST"),
                    Env::get("XQL_DB_PORT"),
                    Env::get("XQL_DB_DATABASE"),
                    Env::get("XQL_DB_USERNAME"),
                    Env::get("XQL_DB_PASSWORD"));
            }

            $dataDriver = Env::get("XQL_BINDED_DB_DRIVER");
            if($dataDriver == "mysql" || $dataDriver == "mariadb") {
                self::$data = self::mysql(
                    Env::get("XQL_BINDED_DB_HOST"),
                    Env::get("XQL_BINDED_DB_PORT"),
                    Env::get("XQL_BINDED_DB_DATABASE"),
                    Env::get("XQL_BINDED_DB_USERNAME"),
                    Env::get("XQL_BINDED_DB_PASSWORD"));
            }
        }
    }

    protected static function mysql($host, $port, $database, $username, $password): PDO
    {
        $con = new PDO("mysql:host=$host;port=$port;dbname=$database", $username, $password);
        $con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $con;
    }
    
}
