<?php

namespace App\Domain\Api;

use App\Domain\Enums\MethodSource;
use App\Domain\Enums\Visibility;
use InvalidArgumentException;

/**
 * 公開 API としてのメソッド。
 */
final readonly class MethodDeclaration
{
    /**
     * @param  list<ParameterDeclaration>  $parameters
     */
    public function __construct(
        public string $name,
        public Visibility $visibility,
        public bool $isStatic,
        public ?string $returnType,
        public ?string $docblockReturnType,
        public array $parameters,
        public MethodSource $source,
        public bool $isInternal,
        public bool $isDeprecated,
    ) {
        foreach ($parameters as $index => $parameter) {
            if ($parameter->position !== $index) {
                throw new InvalidArgumentException("Parameters of {$name} must be ordered by position.");
            }
        }
    }

    /**
     * シグネチャ（static か、戻り値と引数の型・既定値など）のハッシュ。差分の絞り込みに使う。
     * 名前と、@internal / @deprecated の印は含めない。
     */
    public function signatureHash(): string
    {
        return hash('sha256', serialize([
            $this->visibility->value,
            $this->isStatic,
            $this->returnType,
            $this->docblockReturnType,
            array_map(static fn (ParameterDeclaration $parameter) => [
                $parameter->name,
                $parameter->type,
                $parameter->defaultValue,
                $parameter->isVariadic,
                $parameter->byRef,
                $parameter->docblockType,
            ], $this->parameters),
        ]));
    }
}
