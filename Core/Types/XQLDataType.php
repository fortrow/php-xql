<?php

namespace XQL\Core\Types;

enum XQLDataType: int
{
    case INTEGER = 1;
    case FLOAT = 2;
    case STRING = 3;
    case BOOLEAN = 4;
    case DATE = 5;
    case TIME = 6;
    case DATETIME = 7;
    case TIMESTAMP = 8;

    case XQL_OBJECT = 9;
    case XML = 10;
    case JSON = 11;
    case ARRAY = 12;
    case FILE = 13;

    case DYNAMIC = 14;
}

class XQLDataTypeCollection {

    public static function fromStr(string $str): XQLDataType
    {
        return match (strtolower($str)) {
            "int", "integer" => XQLDataType::INTEGER,
            "float", "double", "decimal" => XQLDataType::FLOAT,
            "string", "str", "text" => XQLDataType::STRING,
            "bool", "boolean" => XQLDataType::BOOLEAN,
            "date" => XQLDataType::DATE,
            "time" => XQLDataType::TIME,
            "datetime" => XQLDataType::DATETIME,
            "timestamp" => XQLDataType::TIMESTAMP,
            "xql", "native" => XQLDataType::XQL_OBJECT,
            "xml", "simplexml" => XQLDataType::XML,
            "json" => XQLDataType::JSON,
            "array", "arr" => XQLDataType::ARRAY,
            "file" => XQLDataType::FILE,
            default => XQLDataType::DYNAMIC,
        };
    }

}