<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use App\Domain\Enums\MethodSource;
use App\Domain\Enums\Visibility;
use Database\Factories\MethodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['type_id', 'name', 'visibility', 'is_static', 'return_type', 'docblock_return_type', 'signature_hash', 'source', 'is_internal', 'is_deprecated'])]
class Method extends Model
{
    /** @use HasFactory<MethodFactory> */
    use HasFactory;

    public $timestamps = false;

    protected static function newFactory(): MethodFactory
    {
        return MethodFactory::new();
    }

    /** @return BelongsTo<Type, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(Type::class);
    }

    /** @return HasMany<Parameter, $this> */
    public function parameters(): HasMany
    {
        return $this->hasMany(Parameter::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'visibility' => Visibility::class,
            'is_static' => 'boolean',
            'source' => MethodSource::class,
            'is_internal' => 'boolean',
            'is_deprecated' => 'boolean',
        ];
    }
}
