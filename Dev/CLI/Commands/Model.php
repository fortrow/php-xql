<?php

namespace XQL\Dev\CLI\Commands;

use XQL\Core\Supporting\InflectsText;
use XQL\Core\Utils\Env;
use XQL\Dev\CLI\Command;

class Model extends Command
{
    use InflectsText;

    protected function handle(array $args, array $params, array $flags): bool
    {
        $dir = $this->projectDir;
        if(!array_key_exists("--name", $params)) $this->error("--name is required.", true, 1);
        $modelDir = Env::get("XQL_MODEL_DIRECTORY");
        if(!isset($modelDir)) $this->error("Model directory is not set in the .env file.", true, 1);
        $name = $this->toClass($params['--name']);
        $path = dirname(__FILE__, 4) . $this->separator() . "Examples" . $this->separator() . "Models" . $this->separator() . "Sample.php";
        $contents = file_get_contents($path);
        $newContents = str_replace("Sample", $name, $contents);

        if(!(substr($modelDir, 0, 1) === $this->separator())) {
            $modelDir = $dir . $this->separator() . $modelDir;
        }

        if(!(substr($modelDir, strlen($modelDir) - 1) === $this->separator())) {
            $modelDir .= $this->separator();
        }

        if(file_exists($modelDir)) {

            $modelPath = $modelDir . $name . ".php";
            file_put_contents($modelPath, $newContents);
            $this->success("The model '" . $name . "' was created successfully at " . $modelPath, true);

        } else {
            $this->error("The model directory in your .env configuration does not exist.");
        }

        return true;
    }

}