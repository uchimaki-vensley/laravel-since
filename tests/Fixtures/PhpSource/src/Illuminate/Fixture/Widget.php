<?php

namespace Illuminate\Fixture;

use ArrayAccess;
use Closure;
use Countable;
use Illuminate\Fixture\Contracts\Renderable;

/**
 * @method static \Illuminate\Fixture\Widget make(string|null $name = null, array ...$options)
 * @method static array<int, string> names()
 * @method string render()
 * @method void forward(int &$count, $untyped = self::DEFAULT)
 * @method void FORWARD()
 * @method int hidden()
 */
class Widget implements Renderable
{
    public const DEFAULT = 'default';

    public function __construct(public readonly string $name = 'widget') {}

    /**
     * Configure the widget.
     *
     * @param  array<string, int|string>  $attributes
     * @param  (Closure(static): void)|null  $callback
     * @param  non-empty-string  ...$tags
     * @return $this
     */
    public function configure(
        array $attributes = [],
        ?Closure $callback = null,
        int &$count = 0,
        Renderable|string|null $fallback = self::DEFAULT,
        string ...$tags,
    ): static {
        return $this;
    }

    public function intersect((Countable&ArrayAccess)|null $value, $untyped = PHP_EOL): void {}

    public function render(): string
    {
        return $this->name;
    }

    /**
     * @internal
     */
    public function internalHelper(): void {}

    /**
     * @deprecated Use configure() instead.
     */
    public static function legacy(): ?Renderable
    {
        return null;
    }

    #[\Deprecated]
    public function attributeDeprecated(): void {}

    /**
     * @return array<
     */
    public function brokenDocblock(): array
    {
        return [];
    }

    public function anonymous(): object
    {
        return new class
        {
            public function anonymousMethod(): void {}
        };
    }

    protected function hidden(): void {}

    private function secret(): void {}
}

throw new \LogicException('This fixture must never be executed.');
