<?php

namespace XQL\Dev\CLI;
use XQL\Core\Supporting\InflectsText;
use Minicli\App;
use Minicli\Command\CommandCall;

abstract class Command
{

    use InflectsText;

    private App $_;
    private CommandCall $v;
    protected string $projectDir;

    public function __construct(App $app, CommandCall $call, string $projectDir)
    {
        $this->_ = $app;
        $this->v = $call;
        $this->projectDir = $projectDir;
        if(!$this->subcommand($this->v->subcommand, $this->v->args, $this->v->params, $this->v->flags)) {
            if(!$this->handle($this->v->args, $this->v->params, $this->v->flags)) {
                //TODO
            }
        }
    }

    protected abstract function handle(array $args, array $params, array $flags): bool;

    protected function subcommand(string $subcommand, array $args, array $params, array $flags) {
        if($subcommand === 'default') return false;
        $subcommand = preg_replace("/[:\\-\\.]/", "_", $subcommand);
        $subcommand = str_contains($subcommand, "_") ? $this->toClass($subcommand) : $subcommand;
        $cases = $this->cases($subcommand)['singular'];
        $func = null;
        if(method_exists(get_called_class(), $cases['snake'])) {
            $func = $cases['snake'];
        } else if(method_exists(get_called_class(), $cases['camel'])) {
            $func = $cases['camel'];
        }
        if(isset($func)) {
            $this->{$func}($args, $params, $flags);
            return true;
        }
        return false;
    }

    protected function newline()
    {
        $this->console()->newline();
    }

    protected function info(string $message)
    {
        $this->console()->info($message);
        $this->newline();
    }

    protected function success(string $message, bool $exit = false, int $exitCode = 0) {
        $this->console()->success($message);
        $this->newline();
        if($exit) exit($exitCode);
    }

    protected function out(string $message)
    {
        $this->console()->out($message);
        $this->newline();
    }

    protected function error(string $message, bool $exit = false, int $exitCode = 0)
    {
        $this->console()->error($message);
        $this->newline();
        if($exit) exit($exitCode);
    }

    protected function separator(): string
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
            return "\\";
        else
            return "/";
    }

    private function console()
    {
        return $this->_->getPrinter();
    }

}