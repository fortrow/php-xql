<?php

namespace XQL\Dev;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Throwable;
use XQL\Cloud\Cloud;
use XQL\Core\Utils\Env;
use XQL\Core\XQLModel;
use XQL\DB\DBX;

class CreateSchema extends DBX
{

    public static function create()
    {
        self::connect();
        $con = self::$xql;
        self::models($con);
        self::instances($con);
        self::withSearchable($con);
        self::withBindings($con);
        self::schemaMigrations($con);
        self::bindingColumns($con);
        self::hooks($con);
        self::instanceBindings($con);
        self::binlogCheckpoints($con);
        self::jobs($con);
    }

    public static function migrate(): void
    {
        self::create();
        self::connect();
        $con = self::$xql;

        self::modifyColumn($con, "instances", "id", ['type' => 'varchar(128)', 'null' => 'no']);
        self::ensureColumn($con, "instances", "model_class", ['type' => 'varchar(255)']);
        self::ensureColumn($con, "instances", "root_table", ['type' => 'varchar(255)']);
        self::ensureColumn($con, "instances", "root_pk", ['type' => 'varchar(255)']);
        self::ensureColumn($con, "instances", "status", ['type' => 'varchar(32)', 'null' => 'no', 'default' => "'active'"]);
        self::ensureColumn($con, "instances", "version", ['type' => 'int', 'null' => 'no', 'default' => '1']);
        self::ensureColumn($con, "instances", "checksum", ['type' => 'varchar(128)']);
        self::ensureColumn($con, "instances", "schema_signature", ['type' => 'varchar(64)']);
        self::ensureColumn($con, "instances", "schema_dirty", ['type' => 'tinyint(1)', 'null' => 'no', 'default' => '0']);
        self::ensureColumn($con, "instances", "schema_error", ['type' => 'text']);
        self::ensureColumn($con, "instances", "last_binlog_file", ['type' => 'varchar(255)']);
        self::ensureColumn($con, "instances", "last_binlog_position", ['type' => 'bigint']);
        self::modifyColumn($con, "instances", "path", ['type' => 'varchar(1024)']);

        self::ensureColumn($con, "models", "schema_signature", ['type' => 'varchar(64)']);

        self::ensureColumn($con, "models_with_bindings", "source_table", ['type' => 'varchar(255)']);
        self::ensureColumn($con, "models_with_bindings", "source_model_class", ['type' => 'varchar(255)']);
        self::ensureColumn($con, "models_with_bindings", "xml_path", ['type' => 'varchar(1024)']);
        self::ensureColumn($con, "models_with_bindings", "where_json", ['type' => 'json']);
        self::ensureColumn($con, "models_with_bindings", "created_at", ['type' => 'timestamp', 'null' => 'no', 'default' => 'current_timestamp']);
    }

    public static function syncModelDefinitions(bool $createInstances = false): array
    {
        self::migrate();

        $result = [
            'models' => 0,
            'instances_created' => 0,
            'instances_skipped' => 0,
            'errors' => [],
        ];

        foreach(self::discoverModelClasses() as $class) {
            try {
                /** @var XQLModel $model */
                $model = new $class();
                DBX::modelDefined($model);
                $result['models']++;

                if($createInstances) {
                    $created = self::createMissingInstancesForModel($class, $model);
                    $result['instances_created'] += $created['created'];
                    $result['instances_skipped'] += $created['skipped'];
                }
            } catch(Throwable $e) {
                $result['errors'][] = [
                    'model' => $class,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    public static function rebuildDirtyInstances(int $limit = 100): array
    {
        self::migrate();

        $result = [
            'rebuilt' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        foreach(DBX::schemaDirtyInstances($limit) as $instance) {
            $class = $instance['model_class'];
            $id = $instance['id'];

            try {
                if(!class_exists($class)) {
                    throw new \Exception("Model class does not exist.");
                }

                /** @var XQLModel $model */
                $model = new $class();
                if($model->isFinal()) {
                    throw new \Exception("Final model instances cannot be rebuilt.");
                }

                if($model->canAutoCreateFromRootBinding()) {
                    $primary = $model->primaryBindingInfo();
                    $class::create([
                        $primary['binding'] => [
                            $primary['field'] => $id,
                        ],
                    ]);
                } else if(count($model->schemaMigrations()) > 0 && !empty($instance['path'])) {
                    $xml = Cloud::get($instance['path']);
                    $migrated = $model->migrateXml($xml, $instance['schema_signature'] ?? null);
                    Cloud::put($instance['path'], $migrated);
                    DBX::markSchemaRebuildComplete($class, $id, $model->schemaSignature());
                } else {
                    throw new \Exception("Model requires external payload or computed context and cannot be rebuilt from root DB binding only.");
                }
                $result['rebuilt']++;
            } catch(Throwable $e) {
                DBX::markSchemaRebuildError($class, $id, $e->getMessage());
                $result['skipped']++;
                $result['errors'][] = [
                    'model' => $class,
                    'id' => $id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    private static function discoverModelClasses(): array
    {
        $classes = [];

        foreach(self::configuredModelClasses() as $class) {
            if(self::isConcreteXqlModel($class)) {
                $classes[] = $class;
            }
        }

        foreach(self::configuredModelDirectories() as $baseDir) {
            if(!is_dir($baseDir)) continue;

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir));
            foreach($iterator as $file) {
                if(!$file->isFile() || $file->getExtension() !== "php") continue;

                $class = self::classFromPhpFile($file->getPathname());
                if($class && self::isConcreteXqlModel($class)) {
                    $classes[] = $class;
                }
            }
        }

        $classes = array_values(array_unique($classes));
        sort($classes);
        return $classes;
    }

    private static function configuredModelClasses(): array
    {
        return self::splitConfig(Env::get("XQL_MODEL_CLASSES"));
    }

    private static function configuredModelDirectories(): array
    {
        $dirs = self::splitConfig(Env::get("XQL_MODEL_DIRECTORIES") ?: Env::get("XQL_MODEL_DIRECTORY"));
        return array_map(fn($dir) => self::absolutePath($dir), $dirs);
    }

    private static function splitConfig(?string $value): array
    {
        if(!$value) return [];
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    private static function absolutePath(string $path): string
    {
        if(str_starts_with($path, "/")) return $path;
        return getcwd() . "/" . $path;
    }

    private static function classFromPhpFile(string $path): ?string
    {
        $source = (string) file_get_contents($path);
        $namespace = null;
        $class = null;

        if(preg_match('/namespace\s+([^;]+);/', $source, $match)) {
            $namespace = trim($match[1]);
        }

        if(preg_match('/\bclass\s+([A-Za-z_][A-Za-z0-9_]*)\b/', $source, $match)) {
            $class = trim($match[1]);
        }

        if(!$class) return null;
        return $namespace ? $namespace . "\\" . $class : $class;
    }

    private static function isConcreteXqlModel(string $class): bool
    {
        if(!class_exists($class)) return false;
        $reflection = new ReflectionClass($class);
        return !$reflection->isAbstract() && $reflection->isSubclassOf(XQLModel::class);
    }

    private static function createMissingInstancesForModel(string $class, XQLModel $model): array
    {
        if(!$model->canAutoCreateFromRootBinding()) {
            return ['created' => 0, 'skipped' => 1];
        }

        $primary = $model->primaryBindingInfo();
        $table = $primary['table'];
        $field = $primary['field'];
        $binding = $primary['binding'];

        self::connect();
        $query = "SELECT `" . $field . "` FROM `" . $table . "` WHERE `" . $field . "` IS NOT NULL";
        $stmt = self::$data->query($query);
        $created = 0;
        $skipped = 0;

        while($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $id = (string) $row[$field];
            if(DBX::instanceIdExists($class, $id)) {
                $skipped++;
                continue;
            }

            try {
                $class::create([
                    $binding => [
                        $field => $id,
                    ],
                ]);
                $created++;
            } catch(Throwable) {
                $skipped++;
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    private static function models($con) {
        self::createTable($con, "models", [
            'columns' => [
                'model_class' => [
                    'type' => 'varchar(255)',
                    'null' => 'no'
                ],
                'model_key' => [
                    'type' => 'varchar(255)',
                    'null' => 'no'
                ],
                'root_table' => [
                    'type' => 'varchar(255)'
                ],
                'root_binding_name' => [
                    'type' => 'varchar(255)'
                ],
                'is_static' => [
                    'type' => 'tinyint(1)',
                    'null' => 'no'
                ],
                'is_final' => [
                    'type' => 'tinyint(1)',
                    'null' => 'no'
                ],
                'schema_signature' => [
                    'type' => 'varchar(64)'
                ],
                'created_at' => [
                    'type' => 'timestamp',
                    'null' => 'no',
                    'default' => 'current_timestamp'
                ],
                'updated_at' => [
                    'type' => 'timestamp',
                    'null' => 'no',
                    'default' => 'current_timestamp'
                ]
            ]
        ]);
    }

    private static function instances($con) {
        self::createTable($con, "instances", [
            'columns' => [
                'id' => [
                    'type' => 'varchar(128)',
                    'null' => 'no'
                ],
                'model_class' => [
                    'type' => 'varchar(255)',
                    'null' => 'no'
                ],
                'model_key' => [
                    'type' => 'varchar(255)',
                    'null' => 'no'
                ],
                'type' => [
                    'type' => 'varchar(255)'
                ],
                'path' => [
                    'type' => 'varchar(1024)',
                    'null' => 'no'
                ],
                'root_table' => [
                    'type' => 'varchar(255)'
                ],
                'root_pk' => [
                    'type' => 'varchar(255)'
                ],
                'status' => [
                    'type' => 'varchar(32)',
                    'null' => 'no',
                    'default' => "'active'"
                ],
                'version' => [
                    'type' => 'int',
                    'null' => 'no',
                    'default' => '1'
                ],
                'checksum' => [
                    'type' => 'varchar(128)'
                ],
                'schema_signature' => [
                    'type' => 'varchar(64)'
                ],
                'schema_dirty' => [
                    'type' => 'tinyint(1)',
                    'null' => 'no',
                    'default' => '0'
                ],
                'schema_error' => [
                    'type' => 'text'
                ],
                'last_binlog_file' => [
                    'type' => 'varchar(255)'
                ],
                'last_binlog_position' => [
                    'type' => 'bigint'
                ],
                'last_modified' => [
                    'type' => 'timestamp',
                    'null' => 'no',
                    'default' => 'current_timestamp'
                ],
                'created_at' => [
                    'type' => 'timestamp',
                    'null' => 'no',
                    'default' => 'current_timestamp'
                ]
            ],
            'primary' => 'id'
        ]);
    }

    private static function withSearchable($con) {
        self::createTable($con, "models_with_searchable", [
            'columns' => [
                'model_name' => [
                    'type' => 'varchar(255)',
                    'null' => 'no'
                ],
                'field_name' => [
                    'type' => 'varchar(255)',
                    'null' => 'no'
                ],
            ],
        ]);
    }

    private static function withBindings($con) {
        self::createTable($con, "models_with_bindings", [
            'columns' => [
                'model_name' => [
                    'type' => 'varchar(255)',
                    'null' => 'no'
                ],
                'bind_name' => [
                    'type' => 'varchar(255)',
                    'null' => 'no'
                ],
                'reference_name' => [
                    'type' => 'varchar(255)'
                ],
                'model_field_name' => [
                    'type' => 'varchar(255)'
                ],
                'type' => [
                    'type' => 'int',
                    'null' => 'no'
                ],
                'source_table' => [
                    'type' => 'varchar(255)'
                ],
                'source_model_class' => [
                    'type' => 'varchar(255)'
                ],
                'xml_path' => [
                    'type' => 'varchar(1024)'
                ],
                'where_json' => [
                    'type' => 'json'
                ],
                'created_at' => [
                    'type' => 'timestamp',
                    'null' => 'no',
                    'default' => 'current_timestamp'
                ]
            ],
        ]);
    }

    private static function bindingColumns($con) {
        self::createTable($con, "binding_columns", [
            'columns' => [
                'binding_id' => [
                    'type' => 'int',
                    'null' => 'no'
                ],
                'source_table' => [
                    'type' => 'varchar(255)'
                ],
                'source_column' => [
                    'type' => 'varchar(255)',
                    'null' => 'no'
                ],
                'reference_key' => [
                    'type' => 'varchar(255)',
                    'null' => 'no'
                ],
            ],
        ]);
    }

    private static function schemaMigrations($con) {
        self::createTable($con, "model_schema_migrations", [
            'columns' => [
                'model_class' => [
                    'type' => 'varchar(255)',
                    'null' => 'no'
                ],
                'model_key' => [
                    'type' => 'varchar(255)',
                    'null' => 'no'
                ],
                'migration_name' => [
                    'type' => 'varchar(255)'
                ],
                'migration_type' => [
                    'type' => 'varchar(64)',
                    'null' => 'no'
                ],
                'from_signature' => [
                    'type' => 'varchar(64)'
                ],
                'definition_json' => [
                    'type' => 'json'
                ],
                'created_at' => [
                    'type' => 'timestamp',
                    'null' => 'no',
                    'default' => 'current_timestamp'
                ],
            ],
        ]);
    }

    private static function hooks($con) {
        self::createTable($con, "hooks", [
            'columns' => [
                'source_table' => [
                    'type' => 'varchar(255)',
                    'null' => 'no'
                ],
                'event_type' => [
                    'type' => 'varchar(32)',
                    'null' => 'no'
                ],
                'source_columns_json' => [
                    'type' => 'json'
                ],
                'model_class' => [
                    'type' => 'varchar(255)'
                ],
                'binding_id' => [
                    'type' => 'int'
                ],
                'handler_class' => [
                    'type' => 'varchar(255)'
                ],
                'handler_method' => [
                    'type' => 'varchar(255)'
                ],
                'enabled' => [
                    'type' => 'tinyint(1)',
                    'null' => 'no',
                    'default' => '1'
                ],
                'created_at' => [
                    'type' => 'timestamp',
                    'null' => 'no',
                    'default' => 'current_timestamp'
                ],
            ],
        ]);
    }

    private static function instanceBindings($con) {
        self::createTable($con, "instance_bindings", [
            'columns' => [
                'instance_id' => [
                    'type' => 'varchar(128)',
                    'null' => 'no'
                ],
                'binding_id' => [
                    'type' => 'int',
                    'null' => 'no'
                ],
                'source_table' => [
                    'type' => 'varchar(255)'
                ],
                'source_pk' => [
                    'type' => 'varchar(255)'
                ],
                'xml_path' => [
                    'type' => 'varchar(1024)'
                ],
                'created_at' => [
                    'type' => 'timestamp',
                    'null' => 'no',
                    'default' => 'current_timestamp'
                ],
            ],
        ]);
    }

    private static function binlogCheckpoints($con) {
        self::createTable($con, "binlog_checkpoints", [
            'columns' => [
                'source_host' => [
                    'type' => 'varchar(255)',
                    'null' => 'no'
                ],
                'source_database' => [
                    'type' => 'varchar(255)',
                    'null' => 'no'
                ],
                'binlog_file' => [
                    'type' => 'varchar(255)'
                ],
                'binlog_position' => [
                    'type' => 'bigint'
                ],
                'gtid' => [
                    'type' => 'varchar(255)'
                ],
                'updated_at' => [
                    'type' => 'timestamp',
                    'null' => 'no',
                    'default' => 'current_timestamp'
                ],
            ],
        ]);
    }

    private static function jobs($con) {
        self::createTable($con, "jobs", [
            'columns' => [
                'event_uid' => [
                    'type' => 'varchar(128)',
                    'null' => 'no'
                ],
                'source_table' => [
                    'type' => 'varchar(255)',
                    'null' => 'no'
                ],
                'source_pk' => [
                    'type' => 'varchar(255)'
                ],
                'event_type' => [
                    'type' => 'varchar(32)',
                    'null' => 'no'
                ],
                'payload_json' => [
                    'type' => 'json'
                ],
                'status' => [
                    'type' => 'varchar(32)',
                    'null' => 'no',
                    'default' => "'pending'"
                ],
                'attempts' => [
                    'type' => 'int',
                    'null' => 'no',
                    'default' => '0'
                ],
                'available_at' => [
                    'type' => 'timestamp'
                ],
                'last_error' => [
                    'type' => 'text'
                ],
                'created_at' => [
                    'type' => 'timestamp',
                    'null' => 'no',
                    'default' => 'current_timestamp'
                ],
                'updated_at' => [
                    'type' => 'timestamp',
                    'null' => 'no',
                    'default' => 'current_timestamp'
                ],
            ],
        ]);
    }

}
