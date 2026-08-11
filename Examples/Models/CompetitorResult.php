<?php

namespace XQL\Examples\Models;

use XQL\Core\XQLModel;

/**
 * CompetitorResult is a repeated child model on RaceResult.
 *
 * static(): each competitor can keep its own XML instance. This is useful when
 * another model needs to reference a specific competitor result later.
 */
class CompetitorResult extends XQLModel
{
    protected function schema(XQLModel $model)
    {
        $model->static();

        // Groups keep related values together in the XML.
        $ids = $model->group('ids');
        $ids->field('competitor_id')->enforced();
        $ids->field('transponder_id');
        $ids->field('racer_id');

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
