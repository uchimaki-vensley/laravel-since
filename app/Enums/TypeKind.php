<?php

namespace App\Enums;

enum TypeKind: string
{
    case ClassType = 'class';
    case InterfaceType = 'interface';
    case TraitType = 'trait';
    case EnumType = 'enum';
}
