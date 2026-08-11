<?php

namespace XQL\Core\Utils;

class DynamicValue
{

    private $string;
    private $integer;
    private $float;
    private $timestamp;

    public function __construct($value)
    {
        $this->string = $this->stringValue($value);
        $this->integer = $this->integerValue($value);
        $this->float = $this->floatValue($value);
        $this->timestamp = $this->timestampValue($value);
    }

    public function all()
    {
        return [
            'string' => $this->string,
            'integer' => $this->integer,
            'float' => $this->float,
            'timestamp' => $this->timestamp
        ];
    }

    private function stringValue($value) {
        return "" . $value;
    }

    private function integerValue($value) {
        if(is_numeric($value)) {
            return intval($value);
        }
        return null;
    }

    private function floatValue($value) {
        if(is_numeric($value)) {
            return floatval($value);
        }
        return null;
    }

    private function timestampValue($value) {
        $val = strtotime($value);
        if($val === false) return null;
        return $val;
    }

}