<?php

namespace XQL\Dev\Binlog;

interface BinlogAdapter
{
    public function consume(callable $onChangedRow, array $options = []): array;
}
