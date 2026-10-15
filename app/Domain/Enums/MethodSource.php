<?php

namespace App\Domain\Enums;

enum MethodSource: string
{
    /** ソースに宣言されたメソッド */
    case Declared = 'declared';

    /** 型の docblock の @method で宣言されたメソッド（転送先は解決しない） */
    case Docblock = 'docblock';
}
