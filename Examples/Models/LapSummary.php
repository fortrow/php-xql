<?php

namespace XQL\Examples\Models;

use XQL\Core\XQLModel;

/**
 * LapSummary is an immutable embedded snapshot for one competitor.
 *
 * It demonstrates normal scalar fields. These are not database bindings; they are
 * values passed into the parent create() payload or generated during rebuild.
 */
class LapSummary extends XQLModel
{
    protected function schema(XQLModel $model)
    {
        $model->static();
        $model->final();

        $model->field('laps_completed')->enforced();
        $model->field('best_lap')->enforced();
        $model->field('best_lap_time')->enforced();
        $model->field('total_time')->enforced();
        $model->field('average_lap_time');
    }
}
