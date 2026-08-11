<?php

namespace XQL\Core\Utils;

use Dotenv\Dotenv;

class Env
{

    private static Env $global;
    private static array $runtime = [];

    public function __construct(?string $filename = null)
    {
        $root = self::root();
        $env = (isset($filename)) ? Dotenv::createImmutable($root, $filename) : Dotenv::createImmutable($root);
        //$env->required([]);
        $env->safeLoad();
    }

    public static function get(string $key)
    {
        if(!isset(self::$global)) {
            self::$global = new self(".env");
            if(is_file(self::root() . "/.env.xql")) {
                $xql = Dotenv::createMutable(self::root(), ".env.xql");
                $xql->safeLoad();
            }
        }
        return self::$runtime[$key] ?? $_ENV[$key] ?? getenv($key) ?: null;
    }

    public static function set(string $key, mixed $value): void
    {
        self::$runtime[$key] = $value;
        $_ENV[$key] = $value;
    }

    public static function configure(array $values): void
    {
        foreach($values as $key => $value) {
            self::set((string) $key, $value);
        }
    }

    private static function root(): string
    {
        return str_replace("/public", "", realpath("."));
    }

}
