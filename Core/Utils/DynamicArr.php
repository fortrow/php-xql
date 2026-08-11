<?php

namespace XQL\Core\Utils;

use XQL\Core\Supporting\InflectsText;

class DynamicArr
{
    use InflectsText;

    private array $arr;
    private string $case;

    public function __construct(array $arr, ?string $case = null)
    {
        $this->arr = $arr;
        if(isset($case)) $this->case = $case;
    }

    public function exists(string $key): bool
    {
        if(array_key_exists($key, $this->arr)) return true;
        if(array_key_exists(strtolower($key), $this->arr)) return true;
        $cases = $this->cases($key);
        $all = [];
        if(!isset($this->case)) {
            $singular = array_values($cases['singular']);
            $plural = array_values($cases['plural']);
            $all = array_merge($singular, $plural);
        } else {
            $all = array_values($cases[$this->case]);
        }
        $keys = array_keys($this->arr);
        return count(array_intersect($all, $keys)) > 0;
    }

    public function find(string $key): string|bool
    {
        if(array_key_exists($key, $this->arr)) return $key;
        if(array_key_exists(strtolower($key), $this->arr)) return strtolower($key);
        $cases = $this->cases($key);
        $all = [];
        if(!isset($this->case)) {
            $singular = array_values($cases['singular']);
            $plural = array_values($cases['plural']);
            $all = array_merge($singular, $plural);
        } else {
            $all = array_values($cases[$this->case]);
        }
        $keys = array_keys($this->arr);
        $intersect = array_intersect($all, $keys);
        if(count($intersect) > 0) {
            $unique = array_unique(array_values($intersect));
            if(count($unique) === 1) {
                return $unique[0];
            }
        }
        return false;
    }

}
