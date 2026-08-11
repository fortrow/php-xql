<?php

namespace XQL\Core\Supporting;

use XQL\Core\Types\XQLDataType;
use XQL\Core\Types\XQLDataTypeCollection;

trait WorksWithNativeData
{
    protected function cast(string|XQLDataType $type, ?string $value = null)
    {
        $type = is_string($type) ? XQLDataTypeCollection::fromStr($type) : $type;
        switch($type) {
            case XQLDataType::INTEGER:
                return intval($value);
            case XQLDataType::FLOAT:
                return floatval($value);
        }
    }
}
