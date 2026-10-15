<?php

namespace Illuminate\Fixture;

enum Status: string
{
    case Active = 'active';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public static function fromLabel(string $label): self
    {
        return self::from(strtolower($label));
    }
}
