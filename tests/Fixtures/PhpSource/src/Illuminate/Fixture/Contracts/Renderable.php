<?php

namespace Illuminate\Fixture\Contracts;

interface Renderable
{
    /**
     * @return non-empty-string
     */
    public function render(): string;
}
