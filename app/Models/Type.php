<?php

namespace App\Models;

use App\Enums\TypeKind;
use Database\Factories\TypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['release_id', 'fqcn', 'kind', 'file_path'])]
class Type extends Model
{
    /** @use HasFactory<TypeFactory> */
    use HasFactory;

    public $timestamps = false;

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
        ];
    }
}
