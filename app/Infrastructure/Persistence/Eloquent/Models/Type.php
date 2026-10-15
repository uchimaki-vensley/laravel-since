<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use App\Domain\Enums\TypeKind;
use Database\Factories\TypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['release_id', 'fqcn', 'kind', 'file_path', 'is_internal', 'is_deprecated'])]
class Type extends Model
{
    /** @use HasFactory<TypeFactory> */
    use HasFactory;

    public $timestamps = false;

    protected static function newFactory(): TypeFactory
    {
        return TypeFactory::new();
    }

    /** @return BelongsTo<Release, $this> */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    /** @return HasMany<Method, $this> */
    public function methods(): HasMany
    {
        return $this->hasMany(Method::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => TypeKind::class,
            'is_internal' => 'boolean',
            'is_deprecated' => 'boolean',
        ];
    }
}
