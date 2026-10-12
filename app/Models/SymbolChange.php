<?php

namespace App\Models;

use App\Enums\SymbolChangeKind;
use Database\Factories\SymbolChangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['symbol_id', 'release_id', 'kind', 'before', 'after'])]
class SymbolChange extends Model
{
    /** @use HasFactory<SymbolChangeFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return BelongsTo<Symbol, $this> */
    public function symbol(): BelongsTo
    {
        return $this->belongsTo(Symbol::class);
    }

    /** @return BelongsTo<Release, $this> */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => SymbolChangeKind::class,
            'before' => 'array',
            'after' => 'array',
        ];
    }
}
