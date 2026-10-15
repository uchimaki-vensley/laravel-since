<?php

// laravel/framework v13.2.0 の src/Illuminate/Database/Eloquent/Builder.php から、差分のテストに使うメソッドだけを抜き出したもの。

namespace Illuminate\Database\Eloquent;

use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;

class Builder implements BuilderContract
{
    /**
     * Increment a column's value by a given amount.
     *
     * @param  string|\Illuminate\Contracts\Database\Query\Expression  $column
     * @param  float|int  $amount
     * @param  array  $extra
     * @return int
     */
    public function increment($column, $amount = 1, array $extra = [])
    {
        return $this->toBase()->increment(
            $column, $amount, $this->addUpdatedAtColumn($extra)
        );
    }

    /**
     * Decrement a column's value by a given amount.
     *
     * @param  string|\Illuminate\Contracts\Database\Query\Expression  $column
     * @param  float|int  $amount
     * @param  array  $extra
     * @return int
     */
    public function decrement($column, $amount = 1, array $extra = [])
    {
        return $this->toBase()->decrement(
            $column, $amount, $this->addUpdatedAtColumn($extra)
        );
    }

    /**
     * Add the "updated at" column to an array of values.
     *
     * @param  array  $values
     * @return array
     */
    protected function addUpdatedAtColumn(array $values)
    {
        return $values;
    }
}
