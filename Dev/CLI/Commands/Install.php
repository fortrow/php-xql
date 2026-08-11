<?php

namespace XQL\Dev\CLI\Commands;

use \XQL\Dev\CLI\Command;
use XQL\Dev\CreateSchema;

class Install extends Command
{
    protected function handle(array $args, array $params, array $flags): bool
    {
        CreateSchema::migrate();
        $this->success("XQL schema migrated successfully.");

        if($this->hasFlag($flags, "sync-models")) {
            $result = CreateSchema::syncModelDefinitions($this->hasFlag($flags, "create-instances"));
            $this->success("XQL model sync complete. Models: " . $result['models'] . ", created: " . $result['instances_created'] . ", skipped: " . $result['instances_skipped'] . ", errors: " . count($result['errors']) . ".");
        }

        if($this->hasFlag($flags, "rebuild-dirty")) {
            $limit = isset($params['limit']) ? (int) $params['limit'] : 100;
            $result = CreateSchema::rebuildDirtyInstances($limit);
            $this->success("XQL dirty rebuild complete. Rebuilt: " . $result['rebuilt'] . ", skipped: " . $result['skipped'] . ", errors: " . count($result['errors']) . ".");
        }

        if($this->hasFlag($flags, "systemd-unit")) {
            $target = $params['unit-path'] ?? ($this->projectDir . "/xql-windsor.service");
            file_put_contents($target, $this->systemdUnit($params, $this->hasFlag($flags, "binlog")));
            $this->success("XQL systemd unit written to " . $target . ".");
        }

        return true;
    }

    private function hasFlag(array $flags, string $name): bool
    {
        return in_array($name, $flags, true) || array_key_exists($name, $flags) || in_array("--" . $name, $flags, true) || array_key_exists("--" . $name, $flags);
    }


    private function resolveWindsorBinary(): string
    {
        $candidates = [
            $this->projectDir . "/vendor/bin/windsor",
            dirname(__DIR__, 3) . "/bin/windsor",
            $this->projectDir . "/bin/windsor",
            $this->projectDir . "/app/XQL/bin/windsor",
        ];

        foreach($candidates as $candidate) {
            if(is_file($candidate)) {
                return $candidate;
            }
        }

        return $this->projectDir . "/vendor/bin/windsor";
    }

    private function systemdUnit(array $params, bool $binlog = false): string
    {
        $php = $params['php'] ?? "/usr/bin/php";
        $user = $params['user'] ?? "ubuntu";
        $interval = isset($params['interval']) ? (int) $params['interval'] : 5;
        $limit = isset($params['limit']) ? (int) $params['limit'] : 100;
        $healthLogInterval = isset($params['health-log-interval']) ? (int) $params['health-log-interval'] : 300;
        $workingDirectory = $this->projectDir;
        $windsor = $this->resolveWindsorBinary();

        $binlogFlag = $binlog ? " --binlog" : "";

        return "[Unit]\n"
            . "Description=Fortrow XQL Windsor Daemon\n"
            . "After=network-online.target\n"
            . "Wants=network-online.target\n\n"
            . "[Service]\n"
            . "Type=simple\n"
            . "User=" . $user . "\n"
            . "WorkingDirectory=" . $workingDirectory . "\n"
            . "# In --binlog mode, Windsor resumes from the saved XQL binlog checkpoint when no explicit file/position is passed.\n"
            . "ExecStart=" . $php . " " . $windsor . " daemon --interval=" . $interval . " --limit=" . $limit . " --health-log-interval=" . $healthLogInterval . $binlogFlag . "\n"
            . "Restart=always\n"
            . "RestartSec=5\n"
            . "StartLimitIntervalSec=300\n"
            . "StartLimitBurst=20\n"
            . "KillSignal=SIGTERM\n\n"
            . "[Install]\n"
            . "WantedBy=multi-user.target\n";
    }

}
