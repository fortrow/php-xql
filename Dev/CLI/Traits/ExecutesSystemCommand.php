<?php

namespace App\XQL\Dev\CLI\Traits;

trait ExecutesSystemCommand
{

    protected function execute(string $command)
    {
        $output = [];
        $exit_code = -256;
        exec($command, $output, $exit_code);
        $combined = "";
        $start = true;
        foreach($output as $line) {
            $combined .= (($start) ? '' : '\n') . $line;
            $start = false;
        }
        return [
            'command' => $command,
            'code' => $exit_code,
            'data' => $output,
            'output' => $combined
        ];
    }

    protected function stream(string $command)
    {
        $exit_code = -256;
        system($command, $exit_code);
        return [
            'command' => $command,
            'code' => $exit_code,
        ];
    }

}