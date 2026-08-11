<?php

namespace XQL\Core\Supporting;

use DOMDocument;
use SimpleXMLElement;
use XQL\Core\XQLField;
use XQL\Core\XQLObject;

trait GeneratesXML
{
    protected function xmlString(bool $formatted = false): string
    {
        $data = $this->binded();
        $xml = new SimpleXMLElement("<{$this->modelKey()}></{$this->modelKey()}>");
        foreach($data->labels() as $label) $xml->addAttribute($label->name(), $label->get());
        foreach($data->checksums() as $attr) if(!isset($xml[$attr->name()])) $xml->addAttribute($attr->name(), $attr->get());
        foreach($data->children() as $child) {
            if(is_array($child) && count($child) === 1) $child = array_values($child)[0];
            $this->append($child, ($child instanceof XQLField) ? $xml : $xml->addChild((($child->isMultiple()) ? $child->groupName() : $child->fieldName())));
        }
        return ($formatted) ? $this->formatXml($xml->asXML()) : $xml->asXML();
    }

    protected function formatXml(string $input) {
        $doc = new DOMDocument;
        $doc->preserveWhiteSpace = false;
        $doc->loadXML($input);
        $doc->formatOutput = true;
        return $doc->saveXML();
    }

    protected function append(XQLObject $child, SimpleXMLElement $parent): SimpleXMLElement
    {

        if(count($child->children()) > 0) {
            $node = ($child->isMultiple()) ? $parent->addChild($child->groupName()) : $parent;
            foreach ($child->children() as $next) {
                if(is_array($next) && count($next) === 1) $next = array_values($next)[0];
                if ($next instanceof XQLField) {
                    if($next->isMultiple() && is_array($next->value())) {
                        foreach($next->value() as $value) $node->addChild($next->fieldName(), $value);
                    } else {
                        $node->addChild($next->fieldName(), $next->value());
                    }
                }
                else if ($next instanceof XQLObject) {
                    $this->append($next, $node->addChild(($next->isMultiple()) ? $next->groupName() : $next->fieldName()));
                }
            }
        } else {
            if ($child instanceof XQLField) {
                if($child->isMultiple() && is_array($child->value())) {
                    $node = $parent->addChild($child->groupName());
                    foreach($child->value() as $value) $node->addChild($child->fieldName(), $value);
                } else {
                    $parent->addChild($child->fieldName(), $child->value());
                }
            }
        }
        //foreach($child->checksums() as $attr) if(!isset($node[$attr->name()])) $node->addAttribute($attr->name(), $attr->get());
        return $parent;
    }

}