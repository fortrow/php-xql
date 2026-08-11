<?php

namespace XQL\Core;

class XQLAttribute
{

    private string $name;
    private string $value;

    public function __construct(string $name, string $value)
    {
        $this->name = $name;
        $this->value = $value;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function get(): string
    {
        return $this->value;
    }

}