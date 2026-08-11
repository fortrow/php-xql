<?php

namespace XQL\Examples\Models;

use XQL\Core\XQLModel;

/**
 * EventSnapshot is a static/final child model.
 *
 * static(): this child writes its own XML file and can be referenced by other XML files.
 * final(): once an instance exists, XQL will refuse to update it. This is useful for
 * immutable historical context, such as event name/date/location at publish time.
 */
class EventSnapshot extends XQLModel
{
    protected function schema(XQLModel $model)
    {
        $model->static();
        $model->final();

        // This child is normally passed as payload while creating the root RaceResult.
        $model->field('event_id')->enforced();
        $model->field('name')->enforced();
        $model->field('host_name')->enforced();
        $model->field('starts_at')->enforced();
        $model->field('timezone')->enforced();
    }
}
