<?php

namespace XQL\Examples;

use PDO;
use XQL\XQL;
use XQL\Examples\Models\RaceResult;

/**
 * RuntimeUsage shows framework-neutral ways to configure and operate XQL.
 *
 * These methods are examples, not an executable test suite. Replace credentials,
 * database names, bucket names, and payload values with your application data.
 */
class RuntimeUsage
{
    /**
     * Configure XQL with local disk storage.
     *
     * This is the safest setup for development because it does not require cloud
     * credentials. XML files are written beneath XQL_LOCAL_STORAGE_PATH.
     */
    public static function configureForLocalDevelopment(): void
    {
        XQL::configure([
            'XQL_CLOUD_DRIVER' => 'local',
            'XQL_LOCAL_STORAGE_PATH' => __DIR__ . '/storage/xql',

            'XQL_DB_DRIVER' => 'mysql',
            'XQL_DB_HOST' => '127.0.0.1',
            'XQL_DB_PORT' => '3306',
            'XQL_DB_DATABASE' => 'app_xql',
            'XQL_DB_USERNAME' => 'xql',
            'XQL_DB_PASSWORD' => 'secret',

            'XQL_BINDED_DB_DRIVER' => 'mysql',
            'XQL_BINDED_DB_HOST' => '127.0.0.1',
            'XQL_BINDED_DB_PORT' => '3306',
            'XQL_BINDED_DB_DATABASE' => 'app',
            'XQL_BINDED_DB_USERNAME' => 'app_reader',
            'XQL_BINDED_DB_PASSWORD' => 'secret',

            'XQL_MODEL_DIRECTORIES' => 'Examples/Models',
        ]);
    }

    /**
     * Inject PDO connections directly.
     *
     * This is useful in tests, workers, or frameworks that already manage DB
     * connections. The first PDO is the XQL metadata DB; the second PDO is the
     * application DB being watched/bound.
     */
    public static function configureWithPdo(PDO $xql, PDO $application): void
    {
        XQL::useConnections($xql, $application);
    }

    /**
     * Create a root XML instance from an application payload.
     *
     * The result id comes from the primary race_results.id binding. XQL writes the
     * XML document to object storage and records metadata/bindings in the XQL DB.
     */
    public static function createRaceResult(): RaceResult
    {
        return XQL::create(RaceResult::class, [
            'result' => [
                'id' => 'result_1001',
                'status' => 'published',
                'published_at' => gmdate(DATE_ATOM),
            ],
            'event_snapshot' => [
                'event_id' => 'event_2026_001',
                'name' => 'Opening Night 50',
                'host_name' => 'Example Speedway',
                'starts_at' => '2026-04-10T19:00:00-04:00',
                'timezone' => 'America/New_York',
            ],
            'competitor_results' => [
                [
                    'ids' => [
                        'competitor_id' => 'competitor_1',
                        'transponder_id' => 'TX-100',
                        'racer_id' => 'racer_1',
                    ],
                    'number' => '7',
                    'display_name' => 'Sam Driver',
                    'finish_position' => 1,
                    'lap_summary' => [
                        'laps_completed' => 50,
                        'best_lap' => 18,
                        'best_lap_time' => '14.228',
                        'total_time' => '12:18.224',
                    ],
                ],
            ],
        ]);
    }

    /**
     * Notify XQL manually when your application already knows a row changed.
     *
     * Windsor's binlog daemon does this automatically in production. Manual calls
     * are useful in tests, queue-only deployments, or migration scripts.
     */
    public static function notifyChangedRow(): array
    {
        return XQL::changedRow(
            table: 'race_results',
            eventType: 'update',
            before: ['id' => 'result_1001', 'status' => 'draft'],
            after: ['id' => 'result_1001', 'status' => 'published'],
            metadata: ['primary_key' => 'result_1001']
        );
    }

    /**
     * Process queued XQL jobs.
     *
     * This is what `vendor/bin/windsor daemon` does continuously. Calling it
     * directly is useful for one-shot workers or tests.
     */
    public static function processQueuedJobs(): array
    {
        return XQL::processJobs(limit: 100);
    }

    /**
     * Fetch a previously written XML instance and read values from it.
     */
    public static function readRaceResult(string $id): array
    {
        $result = XQL::fetch(RaceResult::class, $id);

        return [
            'xml' => XQL::xml($result),
            'status' => XQL::value($result, 'result/status'),
            'event_name' => XQL::value($result, 'event_snapshot/name'),
        ];
    }
}
