<?php

namespace XQL\Core\Supporting;

use Doctrine\Inflector\CachedWordInflector;
use Doctrine\Inflector\Inflector;
use Doctrine\Inflector\RulesetInflector;
use Doctrine\Inflector\Rules\English;

trait InflectsText
{

    protected function className($class = null) {
        return (new \ReflectionClass((($class ?? $this))))->getShortName();
    }

    protected function cases(?string $text = null) {
        $class = $text ?? ($this->name ?? $this->className());
        $words = $this->words($class);
        $sClass = $this->singular($words);
        $pClass = $this->plural($words);
        return [
            'singular' => [
                'class' => $sClass,
                'lowercase' => strtolower($sClass),
                'snake' => $this->snake($sClass),
                'camel' => $this->camel($sClass),
            ],
            'plural' => [
                'class' => $pClass,
                'lowercase' => strtolower($pClass),
                'snake' => $this->snake($pClass),
                'camel' => $this->camel($pClass),
            ],
        ];
    }

    protected function words(string $text): array
    {
        $string = $text;
        if(preg_match("/[A-Z]/", $string) > 0) {
            $string = $this->snake(strtoupper(substr($string, 0, 1)) . substr($string, 1));
        }
        $words = [$string];
        if(str_contains($string, "_")) {
            $words = explode("_", $string);
        }
        for($i=0; $i<count($words); $i++) {
            $words[$i] = $this->upperFirst($words[$i]);
        }
        return $words;
    }

    protected function toClass(string $text) {
        return $this->inflector()->classify($text);
    }

    protected function snake(string $text) {
        return $this->inflector()->tableize($text);
    }

    protected function plural(string|array $value, ?string $delimiter = null, bool $lowercase = false) {

        if(is_array($value)) {
            $string = "";
            $last = $this->inflector()->pluralize($value[count($value) - 1]);
            for($i = 0; $i < count($value); $i++) {
                $word = ($i === count($value) - 1) ? $last : $value[$i];
                if($i > 0 && isset($delimiter)) $string .= $delimiter;
                if($lowercase) $word = strtolower($word);
                else $word = $this->upperFirst($word);
                $string .= $word;
            }
            return $string;
        }

        return $this->inflector()->pluralize($value);
    }

    protected function singular(string|array $value, ?string $delimiter = null, bool $lowercase = false) {

        if(is_array($value)) {
            $string = "";
            $last = $this->inflector()->singularize($value[count($value) - 1]);
            for($i = 0; $i < count($value); $i++) {
                $word = ($i === count($value) - 1) ? $last : $value[$i];
                if($i > 0 && isset($delimiter)) $string .= $delimiter;
                if($lowercase) $word = strtolower($word);
                else $word = $this->upperFirst($word);
                $string .= $word;
            }
            return $string;
        }

        return $this->inflector()->singularize($value);
    }

    protected function camel(string $text) {
        return $this->inflector()->camelize($text);
    }

    protected function upperFirst(string $text) {
        return strtoupper(substr($text, 0, 1)) . strtolower(substr($text, 1));
    }

    protected function inflector() {
        return new Inflector(
            new CachedWordInflector(new RulesetInflector(
                English\Rules::getSingularRuleset()
            )),
            new CachedWordInflector(new RulesetInflector(
                English\Rules::getPluralRuleset()
            ))
        );
    }

}
