<?php

namespace Illuminate\Fixture;

if (PHP_VERSION_ID >= 80500) {
    /**
     * @internal
     */
    class Conditional
    {
        public function first(): void {}
    }
} else {
    class Conditional
    {
        public function second(): void {}
    }
}
