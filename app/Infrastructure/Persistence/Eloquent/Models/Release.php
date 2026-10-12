<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Database\Factories\ReleaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['package', 'version', 'tag', 'released_at', 'parsed_at'])]
class Release extends Model
{
    /** @use HasFactory<ReleaseFactory> */
    use HasFactory;

    public $timestamps = false;

    protected static function newFactory(): ReleaseFactory
    {
        return ReleaseFactory::new();
    }

    /** @return HasMany<Type, $this> */
    public function types(): HasMany
    {
        return $this->hasMany(Type::class);
    }

    /** @return HasMany<SymbolChange, $this> */
    public function symbolChanges(): HasMany
    {
        return $this->hasMany(SymbolChange::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'released_at' => 'datetime',
            'parsed_at' => 'datetime',
        ];
    }
}
