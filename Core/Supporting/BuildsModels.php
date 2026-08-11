<?php

namespace XQL\Core\Supporting;

use XQL\Core\Types\XQLHookType;
use XQL\Core\XQLAttribute;
use XQL\Core\XQLBinding;
use XQL\Core\XQLField;
use XQL\Core\XQLHook;
use XQL\Core\XQLObject;
use XQL\Core\XQLModel;
use Exception;

trait BuildsModels
{

    protected function hook(XQLHookType|string $type, string $table, array $columns = []): XQLHook
    {
        if(is_string($type)) {
            $type = match (strtolower($type)) {
                "update", "updated", "onupdate", "on_update" => XQLHookType::UPDATE,
                "insert", "create", "created", "oncreate", "on_create" => XQLHookType::INSERT,
                "delete", "deleted", "ondelete", "on_delete" => XQLHookType::DELETE,
                default => throw new Exception("Unknown XQL hook type: " . $type),
            };
        }

        $hook = new XQLHook($type, $table, $columns);
        $this->hooks[] = $hook;
        return $hook;
    }

    //attach another model
    protected function attach(string $model): XQLObject
    {
        $object = new $model();
        $this->objects[] = $object;
        $this->attached[] = [
            'model_classpath' => $model,
            'model_tree_path' => $this->className(get_called_class())
        ];
        return $object;
    }

    //define a new "tree" object
    protected function group(string $name): XQLObject
    {
        $object = new XQLObject($name);
        $this->objects[] = $object;
        return $object;
    }

    protected function static() : void
    {
        $this->static = true;
    }

    protected function final(): void
    {
        $this->final = true;
    }

}
