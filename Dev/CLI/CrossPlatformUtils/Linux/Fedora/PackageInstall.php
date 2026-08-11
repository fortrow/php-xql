<?php

namespace App\XQL\Dev\CLI\CrossPlatformUtils\Linux\Fedora;

use App\XQL\Dev\CLI\CrossPlatformUtils\Classes\ImplementsPackageInstallation;

class PackageInstall extends ImplementsPackageInstallation
{

    public function __construct()
    {
        $this->setPackageManager("dnf", "install -y");
    }

    protected function executeInstall(string $package): bool
    {
        $res = $this->execute($this->packageManager . " " . $this->installCommand . " " . $package);
        return $res['code'] === 0;
    }

    protected function installPackageManager()
    {
        // installed
    }

    protected function hasPermission(): bool
    {
        return true;
    }
}