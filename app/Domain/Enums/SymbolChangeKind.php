<?php

namespace App\Domain\Enums;

enum SymbolChangeKind: string
{
    case Added = 'added';
    case Removed = 'removed';
    case SignatureChanged = 'signature_changed';
    case Deprecated = 'deprecated';
}
