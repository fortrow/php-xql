<?php

namespace XQL\Examples\Models;

use XQL\Core\XQLModel;

/**
 * RaceResult is the root XML model for this example set.
 *
 * Root models usually bind to an application table. XQL stores metadata about
 * that table binding so Windsor can rebuild the XML when binlog row events show
 * that a related database row changed.
 */
class RaceResult extends XQLModel
{
    protected function schema(XQLModel $model)
    {
        /**
         * bindAll('race_results', 'result') means:
         *
         * - read every selected column from the race_results table;
         * - place the values under a <result> XML node;
         * - use where('id') to rebuild by primary key;
         * - make the binding required with enforced();
         * - use the bound id as the XML instance id with primary('id').
         */
        $result = $model
            ->bindAll('race_results', 'result')
            ->where('id')
            ->enforced();

        /**
         * Attach immutable event context and a repeated list of competitor results.
         * These are included in the RaceResult XML and can also have their own files
         * when the child model is marked static().
         */
        $model->attach(EventSnapshot::class)->enforced();
        $model->attach(CompetitorResult::class)->multiple()->enforced();

        /**
         * Generated values are computed during create/rebuild and stored as XML.
         * Use generate() when no external input is required.
         */
        $model->generate('indexed_at', fn() => gmdate(DATE_ATOM));

        /**
         * Hooks document the source rows that should cause this model to update.
         * Windsor's binlog processing matches changed rows against hooks/bindings
         * and queues affected XML instances for rebuild.
         */
        $model->hook('update', 'race_results', ['status', 'published_at']);
        $model->hook('delete', 'race_results', ['id']);

        /**
         * Schema migrations are recorded with the model definition. When the schema
         * signature changes, existing XML instances can be marked dirty and migrated.
         */
        $model->renameField('result/old_status', 'result/status');
        $model->addField('result/source', 'xql');

        return $result->primary('id');
    }
}
