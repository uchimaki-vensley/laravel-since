<?php

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class ArchitectureTest extends TestCase
{
    /** @var list<string> */
    private const LARAVEL_HELPERS = [
        'abort', 'app', 'auth', 'back', 'base_path', 'broadcast', 'cache', 'collect', 'config', 'cookie',
        'csrf_token', 'decrypt', 'dispatch', 'dispatch_sync', 'event', 'fake', 'info', 'logger', 'now', 'old',
        'redirect', 'report', 'request', 'rescue', 'resolve', 'response', 'route', 'session', 'storage_path',
        'today', 'trans', 'url', 'validator', 'view', 'with',
    ];

    public function test_domain_does_not_depend_on_framework_or_outer_layers(): void
    {
        $filesChecked = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Domain',
            FilesystemIterator::SKIP_DOTS,
        ));

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            $this->assertIsString($source);
            $tokens = token_get_all($source);

            for ($index = 0; $index < count($tokens); $index++) {
                $token = $tokens[$index];

                if (! is_array($token)) {
                    continue;
                }

                if ($token[0] === T_USE) {
                    if ($this->nextTokenText($tokens, $index + 1) === '(') {
                        continue;
                    }

                    [$statement, $index] = $this->readUseStatement($tokens, $index + 1);
                    $this->assertOnlyDomainImports($statement, $file->getPathname());

                    continue;
                }

                if (in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $name = ltrim($token[1], '\\');

                    if (str_contains($name, '\\')) {
                        $this->assertTrue(
                            str_starts_with($name, 'App\\Domain\\'),
                            $file->getPathname()." references outer dependency {$name}.",
                        );

                        continue;
                    }

                    $this->assertNotLaravelHelperCall($tokens, $index, $name, $file->getPathname());

                    continue;
                }

                if ($token[0] === T_STRING) {
                    $this->assertNotLaravelHelperCall($tokens, $index, $token[1], $file->getPathname());
                }
            }

            $filesChecked++;
        }

        $this->assertGreaterThan(0, $filesChecked, 'The Domain layer should contain PHP source files.');
    }

    /**
     * @param  array<int, array{int, string, int}|string>  $tokens
     * @return array{string, int}
     */
    private function readUseStatement(array $tokens, int $start): array
    {
        $statement = '';

        for ($index = $start; $index < count($tokens); $index++) {
            $token = $tokens[$index];
            $text = is_array($token) ? $token[1] : $token;

            if ($text === ';') {
                return [$statement, $index];
            }

            $statement .= $text;
        }

        return [$statement, $start];
    }

    private function assertOnlyDomainImports(string $statement, string $path): void
    {
        $statement = preg_replace('/^(?:function|const)\s+/', '', trim($statement)) ?? trim($statement);

        $groupStart = strpos($statement, '{');

        if ($groupStart !== false) {
            $namespace = substr($statement, 0, $groupStart);
            $this->assertDomainImportNamespace($namespace, $path);

            return;
        }

        foreach (explode(',', $statement) as $import) {
            $parts = preg_split('/\s+as\s+/i', trim($import));
            $this->assertDomainImportNamespace($parts[0] ?? '', $path);
        }
    }

    private function assertDomainImportNamespace(string $name, string $path): void
    {
        $name = trim(trim($name), '\\');

        if (! str_contains($name, '\\')) {
            return;
        }

        $this->assertTrue(
            $name === 'App\\Domain' || str_starts_with($name, 'App\\Domain\\'),
            $path." imports outer dependency {$name}.",
        );
    }

    /**
     * @param  array<int, array{int, string, int}|string>  $tokens
     */
    private function assertNotLaravelHelperCall(array $tokens, int $index, string $name, string $path): void
    {
        if (! in_array(strtolower($name), self::LARAVEL_HELPERS, true) || $this->nextTokenText($tokens, $index + 1) !== '(') {
            return;
        }

        if (in_array($this->previousTokenText($tokens, $index - 1), ['->', '?->', '::', 'function'], true)) {
            return;
        }

        $this->fail($path." calls Laravel helper {$name}().");
    }

    /**
     * @param  array<int, array{int, string, int}|string>  $tokens
     */
    private function nextTokenText(array $tokens, int $index): ?string
    {
        for (; $index < count($tokens); $index++) {
            $token = $tokens[$index];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token) ? $token[1] : $token;
        }

        return null;
    }

    /**
     * @param  array<int, array{int, string, int}|string>  $tokens
     */
    private function previousTokenText(array $tokens, int $index): ?string
    {
        for (; $index >= 0; $index--) {
            $token = $tokens[$index];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token) ? $token[1] : $token;
        }

        return null;
    }
}
