<?php

namespace XQL\Core;

use XQL\Core\Supporting\Arrayable;
use SimpleXMLElement;
use XQL\Core\Supporting\BuildsSchemas;
use XQL\Core\Supporting\InflectsText;

class XQLObject implements Arrayable
{
    use BuildsSchemas, InflectsText;

    protected array $objects;
    protected array $labels;
    protected array $checksums;
    protected array $values;
    protected string $name;
    protected array $singular;
    protected array $plural;
    protected string $xpath;
    protected bool $searchable = false;
    protected bool $multiple = false;
    protected bool $enforced = false;
    protected bool $override = false;
    protected array $schemaMigrations = [];

    public function __construct(?string $name = null, bool $overrideName = false)
    {
        $className = $this->className();
        if($className == "XQLObject" && !isset($name)) throw Exception("Basic XQLObject must be constructed with a name.");
        $this->name = $name ?? $className;
        $cases = $this->cases();
        $this->plural = $cases['plural'];
        $this->singular = $cases['singular'];
        $this->override = $overrideName;
    }

    public function name(): string {
        return $this->name ?? $this->className();
    }

    public function groupName()
    {
        return ($this->override) ? $this->name : $this->plural['snake'];
    }

    public function fieldName()
    {
        return ($this->override) ? $this->name : $this->singular['snake'];
    }

    public function labels(): array {
        return $this->labels ?? [];
    }

    public function children(): array {
        return $this->objects ?? [];
    }

    public function checksums(): array
    {
        $this->checksums ?? $this->checksums = [];
        $this->hmac();
        return $this->checksums;
    }

    public function xpath(?string $path = null)
    {
        if(isset($path)) $this->xpath = $path;
        return $this->xpath ?? "";
    }

    public function xpathFromParent(string $parentPath)
    {
        if($parentPath === "") $this->xpath = $this->fieldName();
        else $this->xpath = $parentPath . "/" . $this->fieldName();
        return $this->xpath;
    }

    private function hmac(): void
    {
        return;
        $xml = new SimpleXMLElement("<{$this->name()}></{$this->name()}>");
        foreach($this->children() as $child) {
            if(is_array($child) && count($child) === 1) $child = array_values($child)[0];
            $this->traverse($child, ($child instanceof XQLField) ? $xml : $xml->addChild($child->name()));
        }
        $key = "abc";// config("XQL_HMAC_KEY");
        $content = $xml->asXML();
        $this->checksums[] = new XQLAttribute("md", substr(hash_hmac("sha256", $content, $key), 0, 40));
    }

    protected function traverse(XQLObject $child, SimpleXMLElement $node): SimpleXMLElement
    {
        if(count($child->children()) > 0) {
            foreach ($child->children() as $next) {
                if(is_array($next) && count($next) === 1) $next = array_values($next)[0];
                if ($next instanceof XQLField) $node->addChild($next->name(), $next->value());
                else if ($next instanceof XQLObject) $this->traverse($next, $node->addChild($next->name()));
            }
        } else {
            if ($child instanceof XQLField) $node->addChild($child->name(), $child->value());
        }
        return $node;
    }

    public function replace(int $index, XQLObject $with)
    {
        $this->objects[$index] = $with;
    }

    public function appendMultiple($value)
    {
        $this->values[] = $value;
    }

    public function appendChild(XQLObject $child)
    {
        if(!isset($this->objects)) $this->objects = [$child];
        else $this->objects[] = $child;
    }

    public function isSearchable(): bool
    {
        return $this->searchable;
    }

    public function isEnforced(): bool
    {
        return $this->enforced;
    }

    public function isMultiple(): bool
    {
        return $this->multiple;
    }

    public function get(string $xpath, ?string $cast = null)
    {
        $xpath = preg_replace("/[\\/\\\\]/", ".", $xpath);
        $current = $xpath;
        if(str_contains($xpath, ".")) {
            $split = explode(".", $xpath);
            $next = implode(".", array_splice($split, 1));
            $current = $split[0];
        }

        $value = null;
        if (is_numeric($current)) {
            $current = intval($current);
            if(isset($this->values)) $value = $this->values[$current];
            else if(count($this->children()) > 0) $value = $this->children()[$current];
        } else {
            foreach($this->children() as $child) {
                if($child->name() == $current) {
                    $value = $child;
                    break;
                }
            }
        }

        if(isset($value)) {
            return (isset($next)) ? $value->get($next) : $value;
        } else {
            return null;
        }
    }


    public function json(?string $xpath = null): string
    {
        return "";
    }

    public function xml(?string $xpath = null): string
    {
        return "";
    }

    public function toArray(): array
    {
        $arr = [];
        $arr[$this->name()] = [];
        foreach($this->children() as $child) {
            $arr[$this->name][] = $child->toArray();
        }
        return $arr;
    }

    public function exists(string $xpath): bool
    {
        return $this->get($xpath) !== null;
    }

    public function keys(bool $dimensional = true): array
    {
        $arr = [];
        foreach($this->children() as $child) {
            $keys = $child->keys($dimensional);
            if(!$dimensional) array_push($arr, ...array_values($keys));
            else $arr[] = $keys;
        }
        return $arr;
    }

    public function values(bool $dimensional = true): array
    {
        $arr = [];
        foreach($this->children() as $child) {
            $values = $child->values($dimensional);
            if(!$dimensional) array_push($arr, ...array_values($values));
            else $arr[] = $values;
        }
        return $arr;
    }

    public function fromArray(array $arr)
    {
        foreach($arr as $key => $value) {
            if(is_array($value) && is_string($key)) {
                $object = new XQLObject($key);
                $object->fromArray($value);
                $this->objects[] = $object;
            } else if(is_array($value) && is_numeric($key)) {
                $field = new XQLField(null, $this->name());
                $field->multiple();
                foreach($value as $val) {
                    $field->appendMultiple($val);
                }
                $this->objects[] = $field;
            } else {
                $field = new XQLField($value, $key);
                $this->objects[] = $field;
            }
        }
    }
}
