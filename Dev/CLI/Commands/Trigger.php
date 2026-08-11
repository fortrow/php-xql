<?php

namespace XQL\Dev\CLI\Commands;

use XQL\Dev\CLI\Command;
use XQL\Dev\ProcessTrigger;

class Trigger extends Command
{
    protected function handle(array $args, array $params, array $flags): bool
    {
        if($this->hasFlag($flags, "process")) {
            $limit = isset($params['limit']) ? (int) $params['limit'] : 100;
            $result = ProcessTrigger::processPendingJobs($limit);
            $this->success("XQL jobs processed. Jobs: " . $result['jobs'] . ", rebuilt: " . $result['rebuilt'] . ", skipped: " . $result['skipped'] . ", errors: " . count($result['errors']) . ".");
            return true;
        }

        if(!array_key_exists("table", $params)) $this->error("--table is required.", true, 1);
        if(!array_key_exists("event", $params)) $this->error("--event is required.", true, 1);
        if(!array_key_exists("id", $params)) $this->error("--id is required.", true, 1);

        $result = ProcessTrigger::changedRow(
            $params['table'],
            $params['event'],
            [],
            ['id' => $params['id']],
            ['primary_key' => $params['id']]
        );

        $this->success("XQL trigger queued. Job: " . $result['job_id'] . ", affected instances: " . $result['affected_instances'] . ".");

        return true;
    }

    private function hasFlag(array $flags, string $name): bool
    {
        return in_array($name, $flags, true) || array_key_exists($name, $flags) || in_array("--" . $name, $flags, true) || array_key_exists("--" . $name, $flags);
    }

}
