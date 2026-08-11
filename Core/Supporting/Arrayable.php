<?php

namespace XQL\Core\Supporting;

interface Arrayable
{
    public function get(string $xpath, ?string $cast = null);
    public function json(?string $xpath = null) : string;
    public function xml(?string $xpath = null) : string;
    public function toArray() : array;
    public function exists(string $xpath) : bool;
    public function keys() : array;
    public function values() : array;
}
