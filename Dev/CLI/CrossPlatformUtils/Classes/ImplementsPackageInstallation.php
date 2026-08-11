<?php

namespace App\XQL\Dev\CLI\CrossPlatformUtils\Classes;

use App\XQL\Dev\CLI\Traits\ChecksForExistence;
use App\XQL\Dev\CLI\Traits\ExecutesSystemCommand;

abstract class ImplementsPackageInstallation
{

    use ChecksForExistence, ExecutesSystemCommand;

    protected string $packageManager;
    protected string $installCommand;

    protected abstract function executeInstall(string $package): bool;
    protected abstract function installPackageManager();
    protected abstract function hasPermission(): bool;

    public function setPackageManager(string $manager, string $command, bool $install = false): void
    {
        $this->packageManager = $manager;
        $this->installCommand = $command;
        if($install && !$this->executableExists($this->packageManager)) $this->installPackageManager();
    }

    public function install(string $package): bool
    {
        if(isset($this->packageManager) && !$this->executableExists($this->packageManager)) return false;
        if(!$this->hasPermission()) return false;
        if($this->packageInstalled($package)) return true;
        return $this->executeInstall($package);
    }

}