<?php

namespace XQL\Cloud;

interface CloudDriver
{
    public function put(string $key, string $content): void;

    public function get(string $key): string;
}
