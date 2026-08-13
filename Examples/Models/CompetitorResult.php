<?php

namespace XQL\Examples\Models;

use XQL\Core\XQLModel;

/**
 * CompetitorResult is a repeated child model on RaceResult.
 *
 * This model is embedded, not static. A competitor's row in a published race
 * result is only fetched as part of that result, so creating one file per
 * competitor would waste storage and increase lookup complexity.
 *
 * final(): once the race result is published, this embedded row should not be
 * mutated. Corrections should create a new reviewed result workflow.
 */
class CompetitorResult extends XQLModel
{
    protected function schema(XQLModel $model)
    {
        $model->final();

        // Groups keep related values together in the XML.
        $identity = $model->group('identity');
        $identity->field('competitor_id')->enforced();
        $identity->field('transponder_id');
        $identity->field('racer_id');

        // Searchable fields are mirrored into XQL searchable index tables.
        $model->field('number')->enforced()->searchable();
        $model->field('display_name')->enforced()->searchable();
        $model->field('finish_position')->enforced()->searchable();

        // Optional result annotations used by race control systems.
        $model->field('dq');
        $model->field('inspection');
        $model->field('notes');

        $model->attach(LapSummary::class)->enforced();
    }
}
