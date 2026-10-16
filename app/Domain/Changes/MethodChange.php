<?php

namespace App\Domain\Changes;

use App\Domain\Api\MethodDeclaration;
use App\Domain\Enums\SymbolChangeKind;
use InvalidArgumentException;

/**
 * 2 つのスナップショットの間でメソッドに起きた変化。
 */
final readonly class MethodChange
{
    public function __construct(
        public SymbolName $symbol,
        public SymbolChangeKind $kind,
        public ?MethodDeclaration $before,
        public ?MethodDeclaration $after,
    ) {
        $valid = match ($kind) {
            SymbolChangeKind::Added => $before === null && $after !== null,
            SymbolChangeKind::Removed => $before !== null && $after === null,
            SymbolChangeKind::SignatureChanged, SymbolChangeKind::Deprecated => $before !== null && $after !== null,
        };

        if (! $valid) {
            throw new InvalidArgumentException("The {$kind->value} change of {$symbol} has an invalid before / after.");
        }
    }
}
