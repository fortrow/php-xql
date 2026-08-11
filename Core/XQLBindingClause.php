<?php

namespace XQL\Core;

class XQLBindingClause
{

    protected array $conditions = [];

    public function get(): array
    {
        $conditions = [];
        foreach($this->conditions as $condition) {
            if($condition instanceof XQLBindingClause) $conditions[] = $condition->get();
            else $conditions[] = $condition;
        }
        return $conditions;
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

    public function append(string $column, string $key) {
        $this->conditions[] = [
            "column" => $column,
            "key" => $key,
            "value" => null
        ];
        return $this;
    }

    public function appendGroup(XQLBindingClause $clause)
    {
        $this->conditions[] = $clause;
    }

    public function or()
    {
        if(count($this->conditions) === 0) return $this;
        $this->conditions[] = [
            "condition" => "or"
        ];
        return $this;
    }

    public function and()
    {
        if(count($this->conditions) === 0) return $this;
        $this->conditions[] = [
            "condition" => "and"
        ];
        return $this;
    }

}
