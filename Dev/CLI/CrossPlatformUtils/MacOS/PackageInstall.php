<?php

namespace App\XQL\Dev\CLI\CrossPlatformUtils\MacOS;

use App\XQL\Dev\CLI\CrossPlatformUtils\Classes\ImplementsPackageInstallation;

class PackageInstall extends ImplementsPackageInstallation
{

    public function __construct()
    {
        $this->setPackageManager("brew", "install -y", true);
    }

    protected function executeInstall(string $package): bool
    {
        $res = $this->execute($this->packageManager . " " . $this->installCommand . " " . $package);
        return $res['code'] === 0;
    }

    protected function installPackageManager()
    {
        $this->withOut("xcode-select --install");
        $this->withOut("curl -fsSL -o install.sh https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh");
    }

    protected function hasPermission(): bool
    {
        return true;
    }
}