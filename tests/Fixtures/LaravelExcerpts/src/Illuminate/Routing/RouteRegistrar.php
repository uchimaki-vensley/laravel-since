<?php

// laravel/framework v13.36.0 の src/Illuminate/Routing/RouteRegistrar.php からの抜粋（Issue #11 の実例）。
// docblock の @method と同名の public メソッド metadata() がある。関係しない @method・プロパティ・メソッドと
// メソッド本体の一部は省略した。末尾の throw は実行されていないことを確かめるために追加したもの。
// Copyright (c) Taylor Otwell. MIT License（https://github.com/laravel/framework/blob/v13.36.0/LICENSE.md）

namespace Illuminate\Routing;

use BadMethodCallException;

/**
 * @method \Illuminate\Routing\Route get(string $uri, \Closure|array|string|null $action = null)
 * @method \Illuminate\Routing\RouteRegistrar as(string $value)
 * @method \Illuminate\Routing\RouteRegistrar metadata(array $metadata)
 * @method \Illuminate\Routing\RouteRegistrar middleware(array|string|null $middleware)
 */
class RouteRegistrar
{
    /**
     * The attributes that can be set through this class.
     *
     * @var string[]
     */
    protected $allowedAttributes = [
        'as',
        'metadata',
        'middleware',
    ];

    /**
     * Set the value for a given attribute.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return $this
     *
     * @throws \InvalidArgumentException
     */
    public function attribute($key, $value)
    {
        // （省略）
        return $this;
    }

    /**
     * Add metadata to routes registered by the registrar.
     *
     * @param  array  $metadata
     * @return $this
     */
    public function metadata(array $metadata)
    {
        return $this->attribute('metadata', $metadata);
    }

    /**
     * Dynamically handle calls into the route registrar.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return \Illuminate\Routing\Route|$this
     *
     * @throws \BadMethodCallException
     */
    public function __call($method, $parameters)
    {
        // （省略）
        throw new BadMethodCallException;
    }
}

throw new \LogicException('This fixture must never be executed.');
