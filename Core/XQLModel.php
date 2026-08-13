<?php

namespace XQL\Core;

use SimpleXMLElement;
use Exception;
use XQL\Cloud\Cloud;
use XQL\Core\Supporting\BuildsModels;
use XQL\Core\Supporting\BuildsQueries;
use XQL\Core\Supporting\GeneratesXML;
use XQL\Core\Types\XQLNamingConvention;
use XQL\Core\Utils\DynamicArr;

#[\AllowDynamicProperties]
abstract class XQLModel extends XQLObject {

    use BuildsQueries, BuildsModels, GeneratesXML;

    protected bool $filled = false;
    protected string $id;
    protected bool $static = false;
    protected bool $final = false;
    protected array $primary;

    protected array $hooks = [];
    protected array $attached = [];

    protected array $bindings = [];

    public function __construct(?array $data = null) {
        $this->build();
        if(isset($data)) $this->populate($data);
        parent::__construct();
    }

    abstract protected function schema(XQLModel $model);

    protected function build()
    {
        $primary = $this->schema($this);

        if(isset($primary)) {
            if($primary instanceof XQLObject) {
                $this->primary = [
                    "field" => $primary->name(),
                    "object" => $primary
                ];
            } else if(is_array($primary)) {
                $this->primary = $primary;
            }
        }

        $attached = [];

        foreach($this->attached as $attachment) {
            $attached[] = $attachment;
            $model = new $attachment['model_classpath'];
            $children = $model->attached();
            $dups = [];
            foreach($children as $child) {
                $dup = $child;
                $dup['model_tree_path'] = $this->className() . "." . $child['model_tree_path'];
                $dups[] = $dup;
            }
            $attached = array_merge($dups, $attached);
        }

        $this->attached = $attached;
    }

    public function populate(array $data) {
        if(array_key_exists("id", $data) && isset($data['id'])) $id = $data['id'];
        else $id = $this->id() ?? $this->generateId();
        $this->id = $id;
        $path = $this->modelKey(true) . "/" . $id . ".xml";
        $this->iterate($this, simplexml_load_string(Cloud::get($path)));
        $this->filled = true;
    }

    public function fill($data)
    {
        $this->iterate($this, $data);
        $this->filled = true;
    }

    private function iterate(XQLObject $object, SimpleXMLElement $element, $dataObject = null)
    {

        if(!isset($dataObject)) $dataObject = $this;

        $values = get_object_vars($element->children());
        $dArrSingle = new DynamicArr($values, "singular");
        $dArrMultiple = new DynamicArr($values, "plural");

        $i = 0;
        $children = $object->children();
        foreach($children as $child) {

            if($child instanceof XQLField) {
                if($child->isMultiple() && $dArrMultiple->exists($child->name())) {
                    $dKey = $dArrMultiple->find($child->name());
                    $multipleValues = $values[$dKey];
                    if($values[$dKey] instanceof SimpleXMLElement) $multipleValues = array_values(get_object_vars($values[$dKey]))[0];
                    if(is_array($multipleValues)) {
                        foreach ($multipleValues as $value) {
                            $child->appendMultiple($value);
                        }
                        $dataObject->{$child->name()} = $multipleValues;
                    }
                } else if($dArrSingle->exists($child->name())) {
                    $dKey = $dArrSingle->find($child->name());
                    $dataObject->{$child->name()} = $values[$dKey];
                    $child->value($values[$dKey]);
                } else if($child->isEnforced()) {
                    throw new Exception($child->name() . " is required and were not found.");
                }
            } else if($child instanceof XQLBinding) {
                if (array_key_exists($child->fieldName(), $values)) {
                    $child->parse($values, $dataObject);
                }

            } else if($child instanceof XQLModel) {


                if($child->isMultiple() && $dArrMultiple->exists($child->name())) {

                    $dKey = $dArrMultiple->find($child->name());

                    $vals = get_object_vars((object) $values[$dKey]);

                    if(is_array(array_values($vals)[0])) {

                        $container = new XQLObject($child->groupName(), true);
                        foreach(array_values($vals)[0] as $value) {
                            $class = get_class($child);
                            $model = new $class();
                            $model->fill($value);
                            $container->appendChild($model);
                        }

                        $object->replace($i, $container);

                    } else {

                        $container = new XQLObject($child->groupName(), true);
                        $class = get_class($child);
                        $model = new $class();
                        $model->fill($values[$dKey]);
                        $container->appendChild($model);

                        $object->replace($i, $container);

                    }

                } else if($dArrSingle->exists($child->name())) {

                    $dKey = $dArrSingle->find($child->name());

                    $class = get_class($child);
                    //TODO get id attribute from xml for model instance
                    $model = new $class();
                    $model->fill($values[$dKey]);

                    $object->replace($i, $model);

                } else if($child->isEnforced()) {

                    throw new \Exception($child->name() . " is required and were not found.");

                }

            } else {
                if($dArrSingle->exists($child->name()) || $dArrMultiple->exists($child->name())) {
                    $dKey = $dArrSingle->find($child->name()) ? $dArrSingle->find($child->name()) : $dArrMultiple->find($child->name()) ;
                    $dataObject->{$child->name()} = (object)[];
                    $this->iterate($child, $values[$dKey], $dataObject->{$child->name()});
                }
            }

            $i++;

        }

    }

    protected function export(): string
    {
        $string = $this->xmlString(true);
        return $string;
    }

    public function toXml(bool $formatted = true): string
    {
        return $formatted ? $this->export() : $this->xmlString(false);
    }

    public function value(string $xpath, mixed $default = null): mixed
    {
        $value = $this->get($xpath);
        if($value instanceof XQLField) {
            return $value->value() ?? $default;
        }
        if($value instanceof XQLObject) {
            return $value->toArray();
        }
        return $value ?? $default;
    }

    public function children(): array
    {
        return $this->objects ?? [];
    }

    public function id(): string
    {
        if(isset($this->primary)) {
            $object = $this->primary['object'];
            if($object instanceof XQLBinding) {

                $dbField = $this->primary['field'];
                $xpath = "0" . "." . $dbField;

                if($object->get($dbField) !== null) {
                    $val = $object->get($dbField);

                    if($val instanceof XQLField) {
                        $val = $val->value();
                    }

                    if (is_string($val) || is_numeric($val)) {
                        return $val;
                    }
                }

                if($object->get($xpath) !== null) {
                    $val = $object->get($xpath);

                    if($val instanceof XQLField) {
                        $val = $val->value();
                    }

                    if (is_string($val) || is_numeric($val)) {
                        return $val;
                    }
                }

            } else {
                return $object->id();
            }
        }
        if(!isset($this->id)) $this->generateId();
        return $this->id;
    }

    protected function binded(): XQLObject
    {
        return $this;
    }

    protected function generateId(): void
    {
        $data = get_called_class() . ":" . time() . ":" . microtime();
        $this->id = hash("sha1", $data);
    }

    public function modelKey(bool $plural = false, bool $camelCase = false)
    {
        $cases = $this->cases();
        $arr = ($plural) ? $cases['plural'] : $cases['singular'];
        return ($camelCase) ? $arr['camel'] : $arr['snake'];
    }

    public function attached(): array
    {
        return $this->attached;
    }

    public function hooks(): array
    {
        return $this->hooks;
    }

    public function schemaMigrations(): array
    {
        return $this->schemaMigrations;
    }

    public function migrateXml(string $xml, ?string $fromSignature = null): string
    {
        $document = new \DOMDocument();
        $document->preserveWhiteSpace = false;
        $document->formatOutput = true;
        $document->loadXML($xml);

        foreach($this->schemaMigrations as $migration) {
            if(!empty($migration['from_signature']) && $migration['from_signature'] !== $fromSignature) {
                continue;
            }

            match ($migration['type'] ?? 'custom') {
                'rename_field' => $this->applyRenameFieldMigration($document, $migration),
                'add_field' => $this->applyAddFieldMigration($document, $migration),
                'remove_field' => $this->applyRemoveFieldMigration($document, $migration),
                'transform_field' => $this->applyTransformFieldMigration($document, $migration),
                'custom' => $this->applyCustomXmlMigration($document, $migration),
                default => null,
            };
        }

        return $document->saveXML();
    }

    public function path(): string
    {
        return $this->modelKey(true) . "/" . $this->id() . ".xml";
    }

    public function primaryBindingInfo(): array
    {
        $info = [
            'table' => null,
            'binding' => null,
            'field' => null,
            'value' => null,
        ];

        if(!isset($this->primary)) return $info;

        $object = $this->primary['object'] ?? null;
        $field = $this->primary['field'] ?? null;
        $info['field'] = $field;

        if($object instanceof XQLBinding) {
            $info['binding'] = $object->name();
            if(is_string($object->bindFrom())) {
                $info['table'] = $object->bindFrom();
            }

            if(isset($field)) {
                $value = $object->get($field) ?? $object->get("0." . $field);
                if($value instanceof XQLField) {
                    $value = $value->value();
                }
                if(is_string($value) || is_numeric($value)) {
                    $info['value'] = (string) $value;
                }
            }
        } else if($object instanceof XQLModel) {
            $info['value'] = $object->id();
        }

        return $info;
    }

    public function canAutoCreateFromRootBinding(): bool
    {
        $primary = $this->primaryBindingInfo();
        if(empty($primary['table']) || empty($primary['binding']) || empty($primary['field'])) {
            return false;
        }

        return !$this->hasRequiredExternalPayload($this);
    }

    public function schemaSignature(): string
    {
        return hash("sha256", json_encode($this->schemaShape($this)));
    }

    private function schemaShape(XQLObject $object): array
    {
        $children = [];
        foreach($object->children() as $child) {
            if(is_array($child) && count($child) === 1) {
                $child = array_values($child)[0];
            }

            $shape = [
                'class' => get_class($child),
                'name' => $child->name(),
                'field_name' => $child->fieldName(),
                'group_name' => $child->groupName(),
                'searchable' => $child->isSearchable(),
                'multiple' => $child->isMultiple(),
                'enforced' => $child->isEnforced(),
            ];

            if($child instanceof XQLBinding) {
                $shape['binding'] = [
                    'type' => $child->getBindType()->value,
                    'from' => is_string($child->bindFrom()) ? $child->bindFrom() : get_class($child->bindFrom()),
                    'references' => $child->references(),
                    'where' => $child->whereConditions(),
                ];
            }

            if($child instanceof XQLModel) {
                $shape['model_key'] = $child->modelKey();
                $shape['static'] = $child->isStatic();
                $shape['final'] = $child->isFinal();
            }

            if($child instanceof XQLObject) {
                $shape['children'] = $this->schemaShape($child);
            }

            $children[] = $shape;
        }

        return [
            'class' => get_class($object),
            'name' => $object->name(),
            'field_name' => $object->fieldName(),
            'group_name' => $object->groupName(),
            'children' => $children,
        ];
    }

    private function applyRenameFieldMigration(\DOMDocument $document, array $migration): void
    {
        $node = $this->firstXmlNode($document, $migration['from'] ?? '');
        if(!$node) return;

        $toParts = $this->xmlPathParts($migration['to'] ?? '');
        $newName = array_pop($toParts);
        if(!$newName) return;

        $replacement = $document->createElement($newName);
        while($node->firstChild) {
            $replacement->appendChild($node->firstChild);
        }
        if($node->attributes) {
            foreach(iterator_to_array($node->attributes) as $attribute) {
                $replacement->setAttribute($attribute->nodeName, $attribute->nodeValue);
            }
        }
        $node->parentNode?->replaceChild($replacement, $node);
    }

    private function applyAddFieldMigration(\DOMDocument $document, array $migration): void
    {
        $parts = $this->xmlPathParts($migration['xpath'] ?? '');
        if(count($parts) === 0) return;

        $field = array_pop($parts);
        $parent = $this->ensureXmlPath($document, $parts);
        if(!$parent) return;

        foreach($parent->childNodes as $child) {
            if($child instanceof \DOMElement && $child->tagName === $field) return;
        }

        $parent->appendChild($document->createElement($field, (string) ($migration['default'] ?? '')));
    }

    private function applyRemoveFieldMigration(\DOMDocument $document, array $migration): void
    {
        $node = $this->firstXmlNode($document, $migration['xpath'] ?? '');
        $node?->parentNode?->removeChild($node);
    }

    private function applyTransformFieldMigration(\DOMDocument $document, array $migration): void
    {
        $node = $this->firstXmlNode($document, $migration['xpath'] ?? '');
        if(!$node) return;

        $handler = $migration['handler'] ?? null;
        $callback = $migration['callback'] ?? null;
        if(is_string($handler) && method_exists($this, $handler)) {
            $node->nodeValue = (string) $this->{$handler}($node->nodeValue, $node, $document);
        } else if(is_callable($callback)) {
            $node->nodeValue = (string) $callback($node->nodeValue, $node, $document);
        }
    }

    private function applyCustomXmlMigration(\DOMDocument $document, array $migration): void
    {
        $handler = $migration['handler'] ?? null;
        $callback = $migration['callback'] ?? null;
        if(is_string($handler) && method_exists($this, $handler)) {
            $this->{$handler}($document, $migration);
        } else if(is_callable($callback)) {
            $callback($document, $migration);
        }
    }

    private function firstXmlNode(\DOMDocument $document, string $path): ?\DOMElement
    {
        $parts = $this->xmlPathParts($path);
        if(count($parts) === 0) return null;

        $query = "//" . implode("/", array_map(fn($part) => "*[local-name()='" . $part . "']", $parts));
        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query($query);
        $node = $nodes?->item(0);

        return $node instanceof \DOMElement ? $node : null;
    }

    private function ensureXmlPath(\DOMDocument $document, array $parts): ?\DOMElement
    {
        $current = $document->documentElement;
        if(!$current) return null;

        if(count($parts) > 0 && $parts[0] === $current->tagName) {
            array_shift($parts);
        }

        foreach($parts as $part) {
            $next = null;
            foreach($current->childNodes as $child) {
                if($child instanceof \DOMElement && $child->tagName === $part) {
                    $next = $child;
                    break;
                }
            }
            if(!$next) {
                $next = $document->createElement($part);
                $current->appendChild($next);
            }
            $current = $next;
        }

        return $current;
    }

    private function xmlPathParts(string $path): array
    {
        $path = trim(str_replace("\\", "/", $path), "/");
        if($path === '') return [];
        return array_values(array_filter(explode("/", $path), fn($part) => $part !== ''));
    }

    private function hasRequiredExternalPayload(XQLObject $object): bool
    {
        foreach($object->children() as $child) {
            if(is_array($child) && count($child) === 1) {
                $child = array_values($child)[0];
            }

            if($child instanceof XQLBinding) {
                continue;
            }

            if($child->isEnforced()) {
                return true;
            }

            if($child instanceof XQLObject && $this->hasRequiredExternalPayload($child)) {
                return true;
            }
        }

        return false;
    }

    public function isStatic()
    {
        return $this->static;
    }

    public function isFinal()
    {
        return $this->final;
    }

    public function toArray(): array
    {
        return json_decode(json_encode(simplexml_load_string($this->export())), true);
    }

}
