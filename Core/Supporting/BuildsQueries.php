<?php

namespace XQL\Core\Supporting;

use Exception;
use XQL\Cloud\Cloud;
use XQL\Core\Types\XQLBindingType;
use XQL\Core\Utils\DynamicArr;
use XQL\Core\XQLBinding;
use XQL\Core\XQLField;
use XQL\Core\XQLModel;
use XQL\Core\XQLObject;
use XQL\DB\DBX;

trait BuildsQueries
{
    public static function where(): void
    {

    }

    public static function fetch(string $id)
    {
        $class = get_called_class();
        $instance = new $class(['id' => $id]);
        return $instance;
    }

    public static function create(array|\Closure|null $data = null, ?\Closure $callback = null, array $options = []): XQLModel
    {
        $class = get_called_class();
        $instance = new $class();
        $instance->xpath($instance->modelKey());
        $embedded = (bool) ($options['embedded'] ?? false);

        $callAfterCreate = false;

        if(is_array($data) && !isset($callback)) {
            $values = $data;
        } else if(is_callable($data) || is_a($data, 'Closure')) {
            $reflector = new \ReflectionFunction($data);
            $num_params = $reflector->getNumberOfParameters();
            if($num_params > 1) throw new Exception("Expected at max 1 parameter, got " . $num_params);
            $values = $data($instance);
            if(!is_array($values)) throw new Exception("An array of values must be returned by the callback.");
        } else if(is_array($data) && isset($callback) && (is_callable($callback) || is_a($callback, 'Closure'))) {
            $reflector = new \ReflectionFunction($callback);
            $num_params = $reflector->getNumberOfParameters();
            if($num_params > 2) throw new Exception("Expected at max 2 parameters, got " . $num_params);
            $values = $data;
            $callAfterCreate = true;
        } else {
            throw new Exception("The parameters for create was invalid.");
        }

        self::construct($instance, $instance, $values, $instance->modelKey());

        if($callAfterCreate) {
            $callback($instance->toArray(), $instance);
        }

        $persistsOwnFile = !$embedded || $instance->isStatic();
        if($persistsOwnFile) {
            if($instance->isFinal() && DBX::instanceExists($instance)) {
                throw new Exception("The final XQL instance " . get_class($instance) . ":" . $instance->id() . " already exists and cannot be updated.");
            }
            Cloud::put($instance->path(), $instance->export());
        }

        if($persistsOwnFile) {
            DBX::instanceCreated($instance);
        }

        return $instance;
    }

    private static function construct(XQLModel $instance, XQLObject $object, array $values, string $xpath, $dataObject = null) {

        if(!isset($dataObject)) $dataObject = $instance;

        $dArr = new DynamicArr($values);

        if($object->isSearchable()) DBX::updateSearchableFields($instance, $object);
        if($instance->modelKey() !== $xpath) $xpath = $object->xpathFromParent($xpath);

        $i = 0;
        foreach($object->children() as $child) {

            if(is_array($child)) $child = array_values($child)[0];
            if($child->isSearchable()) DBX::updateSearchableFields($instance, $child);
            $child->xpathFromParent($xpath);

            if($child instanceof XQLField) {

                if($dArr->exists($child->name())) {
                    $dKey = $dArr->find($child->name());
                    if($child->isMultiple() && is_array($values[$dKey])) {
                        foreach($values[$dKey] as $value) {
                            $child->appendMultiple($value);
                            if($child->isSearchable()) DBX::insertSearchableValue($instance, $child, $value);
                        }
                        $dataObject->{$child->name()} = $values[$dKey];
                    } else {
                        $dataObject->{$child->name()} = $values[$dKey];
                        $child->value($values[$dKey]);
                        if($child->isSearchable()) DBX::insertSearchableValue($instance, $child);
                    }
                } else if($child->isEnforced()) {
                    throw new Exception($child->name() . " is required.");
                }
            } else if($child instanceof XQLBinding) {
                if ($dArr->exists($child->name())) {
                    $dKey = $dArr->find($child->name());
                    $dataObject->{$child->name()} = (object)[];
                    $child->retrieve($instance, $child, $values[$dKey]);
                    self::construct($instance, $child, $values[$dKey], $xpath, $dataObject->{$child->name()});
                } else if($child->getBindType() === XQLBindingType::FUNCTION) {
                    $child->retrieve($instance, $child, []);
                } else if ($child->isEnforced()) {
                    throw new Exception($child->name() . " binding values are required.");
                }
            } else if($child instanceof XQLModel) {
                if($dArr->exists($child->name())) {
                    $dKey = $dArr->find($child->name());
                    if($child->isMultiple() && is_array($values[$dKey])) {
//                        $dataObject->{$child->name()} = [];
                        $container = new XQLObject($child->groupName(), true);
                        foreach($values[$dKey] as $value) {
                            $model = $child::create($value, null, ['embedded' => !$child->isStatic()]);
                            $container->appendChild($model);
                            //self::construct($instance, $model, $value, $xpath);
                        }
                        $object->replace($i, $container);
                    } else {
//                        $dataObject->{$child->name()} = (object)[];
                          $object->replace($i, $child::create($values[$dKey], null, ['embedded' => !$child->isStatic()]));
                          //self::construct($instance, $child::create($values[$dKey]), $values[$dKey], $xpath);
                    }
                } else if($child->isEnforced()) {
                    throw new Exception($child->name() . " array values are required.");
                }
            } else {
                if($dArr->exists($child->name())) {
                    $dKey = $dArr->find($child->name());
                    if($child->isMultiple() && is_array($values[$dKey])) {
                        $dataObject->{$child->name()} = [];
                        foreach($values[$dKey] as $value) {
                            $nestedItem = (object)[];
                            self::construct($instance, $child, $value, $xpath, $nestedItem);
                            $dataObject->{$child->name()}[] = $nestedItem;
                        }
                    } else {
                        $dataObject->{$child->name()} = (object)[];
                        self::construct($instance, $child, $values[$dKey], $xpath, $dataObject->{$child->name()});
                    }
                } else if($child->isEnforced()) {
                    throw new Exception($child->name() . " array values are required.");
                }
            }

            $i++;

        }

    }

    public function update(): void
    {

    }

}
