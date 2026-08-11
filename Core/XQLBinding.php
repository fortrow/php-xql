<?php

namespace XQL\Core;

use PhpParser\Node\Expr\Cast\Object_;
use SimpleXMLElement;
use XQL\Core\Types\XQLBindingType;
use XQL\Core\Types\XQLNamingConvention;
use XQL\DB\DBX;

class XQLBinding extends XQLObject
{

    protected string|XQLModel $bindFrom;
    protected array $references;
    protected XQLBindingType $bindType;
    protected XQLBindingClause $clause;
    protected XQLModel $bindedModel;
    protected $callback;

    public function __construct(string $name, string|XQLModel $from, array $references, XQLBindingType $type, ?XQLModel $bindedModel = null, $fn = null)
    {
        $this->name = $name;
        $this->bindFrom = $from;
        $this->references = $references;
        $this->bindType = $type;
        $this->clause = new XQLBindingClause();
        if(isset($bindedModel)) $this->bindedModel = $bindedModel;
        if(isset($fn)) $this->callback = $fn;
        parent::__construct($name);
    }

    public static function store(string $name, string|XQLModel $from, array $references, ?XQLModel $to = null): XQLBinding
    {
        if ($from instanceof XQLModel && isset($to)) {
            $type = XQLBindingType::FILE_TO_FILE;
            $fromName = $from->name();
        } else if($from instanceof XQLModel) {
            $type = XQLBindingType::FILE_TO_DB;
            $fromName = $from->name();
        } else {
            $type = XQLBindingType::DB_TO_FILE;
            $fromName = $from;
        }
        //DBX::relationship();
        return new XQLBinding($name, $fromName, $references, $type, $to);
    }

    public static function await(string $name, XQLModel $from, array $args, $fn): XQLBinding
    {
        return new XQLBinding($name, $from, $args, XQLBindingType::FUNCTION, null, $fn);
    }

    public function retrieve(XQLModel $model, XQLObject $parent, array $equals) : void
    {

        if($this->bindType === XQLBindingType::FUNCTION) {
            $this->call($model, $parent, $equals);
            return;
        } else if($this->bindType === XQLBindingType::FILE_TO_FILE) {
            $this->read($model, $parent, $equals);
            return;
        }

        $references = (!isset($this->references) || count($this->references) == 0) ? "*" : $this->references;
        $values = DBX::getBindedValues($this->bindFrom, $references, $this->clause, $equals);

        foreach($values as $value) {
            $name = $this->singular['snake'];
            if(count($value) > 1) {
                $object = new XQLObject($name);
                $object->xpathFromParent($parent->xpath());
            }
            $keys = array_keys($value);
            foreach($keys as $key) {
                $field = new XQLField($value[$key], $key);
                if(isset($object)) {
                    $object->appendChild($field);
                    $field->xpathFromParent($object->xpath());
                } else {
                    $this->objects[] = $field;
                    $field->xpathFromParent($this->name());
                }

                if($this->isSearchable()) DBX::insertSearchableValue($model, $field);
            }
            if(isset($object)) $this->objects[] = $object;
        }
    }

    private function call(XQLModel $model, XQLObject $parent, array $equals)
    {
        $args = [];
        if(count($equals) !== count($this->references)) {
            throw new \Exception("Invalid number of arguments to function call binding.");
        }
        foreach($this->references as $param) {
            $args[] = $equals[$param];
        }

        $fn = $this->callback;
        if(is_string($fn)) {
            $res = $model->{$fn}(...$args);
        } else {
            $res = $fn(...$args);
        }

        if(is_array($res)) {
            $this->fromArray($res);
        } else {
            $field = new XQLField($res, $this->name);
            $this->objects[] = $field;
        }
    }

    private function read(XQLModel $model, XQLObject $parent, array $equals)
    {
        if (!isset($this->bindedModel)) {
            return;
        }
        $targetClass = get_class($this->bindedModel);

        $id = null;
        foreach ($this->references as $refKey => $refVal) {
            if (isset($equals[$refVal])) {
                $id = $equals[$refVal];
                break;
            } else if (isset($equals[$refKey])) {
                $id = $equals[$refKey];
                break;
            }
        }

        if (!isset($id)) {
            $id = reset($equals);
        }

        if (empty($id)) {
            return;
        }

        try {
            $targetInstance = $targetClass::fetch($id);
            if ($targetInstance) {
                $this->objects[] = $targetInstance;
            }
        } catch (\Exception $e) {
            // Handle or ignore if not found
        }
    }

    public function parse(array $input, &$dataObject)
    {

        $all = get_object_vars($input[$this->fieldName()]);
        $values = array_values($all);
        $keys = array_keys($all);

        $ofObjects = true;
        $collection = [];
        for($i=0; $i<count($all); $i++) {
            $value = $values[$i];
            $key = $keys[$i];
            if($key === $this->singular['snake'] && !is_string($value)) {
                $collection[] = [ "key" => $key, "value" => get_object_vars($value) ];
            } else {
                $ofObjects = false;
                $collection[] = [ "key" => $key, "value" => !is_string($value) ? null : $value ];
            }
        }

        foreach($collection as $value) {

            if($ofObjects) {
                $object = new XQLObject($this->toClass($value['key']));
                $fields = array_keys($value['value']);
                $i=0;
                foreach($value['value'] as $field) {
                    $name = $this->toClass($fields[$i]);
                    $val = is_string($field) ? $field : null;
                    $fieldObj = new XQLField($val, $name);
                    $object->appendChild($fieldObj);
                    $i++;
                }
                $this->objects[] = $object;
            } else {
                $name = $this->toClass($value['key']);
                $val = is_string($value['value']) ? $value['value'] : null;
                $fieldObj = new XQLField($val, $name);
                $this->objects[] = $fieldObj;
            }

        }

    }

    public function where(string|array|callable $column, ?string $key = null) {
        if(is_array($column)) {
            $hasKeys = array_keys($column) !== range(0, count($column) - 1);
            $keys = ($hasKeys) ? array_keys($column) : $column;
            $values = ($hasKeys) ? array_values($column) : $column;
            for ($i = 0; $i < count($column); $i++) {
                $this->clause->and();
                $this->clause->append($keys[$i], $values[$i]);
            }
        } else if(is_callable($column)) {
            $this->clause->and();
            $clause = new XQLBindingClause();
            $this->clause->appendGroup($clause);
            $column($clause);
        } else {
            $this->clause->and();
            $this->clause->append($column, (isset($key)) ? $key : $column);
        }
        return $this;
    }

    public function orWhere(string|array|callable $column, ?string $key = null)
    {
        if(is_array($column)) {
            $hasKeys = array_keys($column) !== range(0, count($column) - 1);
            $keys = ($hasKeys) ? array_keys($column) : $column;
            $values = ($hasKeys) ? array_values($column) : $column;
            for($i=0; $i<count($column); $i++) {
                $this->clause->or();
                $this->clause->append($keys[$i], $values[$i]);
            }
        } else if(is_callable($column)) {
            $this->clause->or();
            $clause = new XQLBindingClause();
            $this->clause->appendGroup($clause);
            $column($clause);
        } else {
            $this->clause->or();
            $this->clause->append($column, (isset($key)) ? $key : $column);
        }
        return $this;
    }

    public function fieldName()
    {
        return $this->snake($this->name);
    }

    public function getBindType(): XQLBindingType
    {
        return $this->bindType;
    }

    public function bindFrom(): string|XQLModel
    {
        return $this->bindFrom;
    }

    public function references(): array
    {
        return $this->references;
    }

    public function whereConditions(): array
    {
        return $this->clause->get();
    }

}
