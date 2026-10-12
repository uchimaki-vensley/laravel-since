<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Database\Factories\SymbolFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['fqcn', 'member'])]
class Symbol extends Model
{
    /** @use HasFactory<SymbolFactory> */
    use HasFactory;

    public $timestamps = false;

    protected static function newFactory(): SymbolFactory
    {
        return SymbolFactory::new();
    }

    /** @return HasMany<SymbolChange, $this> */
    public function changes(): HasMany
    {
        return $this->hasMany(SymbolChange::class);
    }
}
