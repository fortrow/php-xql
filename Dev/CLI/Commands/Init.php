<?php

namespace XQL\Dev\CLI\Commands;

use \XQL\Dev\CLI\Command;
use XQL\Dev\CreateSchema;

class Init extends Command
{
    protected function handle(array $args, array $params, array $flags): bool
    {
        $dir = $this->projectDir;
        if(array_key_exists("dir", $params)) $dir = $params['dir'];

        $cwd = dirname(__FILE__);

        $projectDir = preg_replace("/(([\\\\\/])(Dev)([\\\\\/])).*/", "", $cwd);
        $binDir = $projectDir . $this->separator() . "bin";

        $windsorSource = $binDir . $this->separator() . "windsor";
        $windsorTarget = $dir . $this->separator() . "windsor";

        $envSource = $projectDir . $this->separator() . ".env.example";
        $envTarget = $dir . $this->separator() . ".env.xql.example";

        copy($windsorSource, $windsorTarget);
        copy($envSource, $envTarget);

        return true;
    }

    protected function schema() {

        if(1) { //if env is set or db connection was successful
            CreateSchema::create();
            $this->success("XQL schema created successfully!", true);
        }

    }

}