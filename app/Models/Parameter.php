<?php

namespace App\Models;

use Database\Factories\ParameterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['method_id', 'position', 'name', 'type', 'default_value', 'is_variadic', 'by_ref', 'docblock_type'])]
class Parameter extends Model
{
    /** @use HasFactory<ParameterFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return BelongsTo<Method, $this> */
    public function method(): BelongsTo
    {
        return $this->belongsTo(Method::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_variadic' => 'boolean',
            'by_ref' => 'boolean',
        ];
    }
}
