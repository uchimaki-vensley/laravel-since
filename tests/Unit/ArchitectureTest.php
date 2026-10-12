<?php

namespace Tests\Unit;

use FilesystemIterator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Support\Facades\Facade;
use PhpParser\Error as PhpParserError;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\Stmt\UseUse;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionFunction;
use SplFileInfo;

class ArchitectureTest extends TestCase
{
    /**
     * @var array<string, array{
     *     directories: list<string>,
     *     allowed_app_namespaces: list<string>,
     *     framework_independent: bool
     * }>
     */
    private const LAYERS = [
        'Domain' => [
            'directories' => ['app/Domain'],
            'allowed_app_namespaces' => ['App\\Domain'],
            'framework_independent' => true,
        ],
        'Application' => [
            'directories' => ['app/Application'],
            'allowed_app_namespaces' => ['App\\Application', 'App\\Domain'],
            'framework_independent' => true,
        ],
        'Infrastructure' => [
            'directories' => ['app/Infrastructure'],
            'allowed_app_namespaces' => ['App\\Infrastructure', 'App\\Application', 'App\\Domain'],
            'framework_independent' => false,
        ],
        'Presentation' => [
            'directories' => ['app/Http', 'app/Console', 'app/Jobs', 'routes'],
            'allowed_app_namespaces' => [
                'App\\Http',
                'App\\Console',
                'App\\Jobs',
                'App\\Application',
            ],
            'framework_independent' => false,
        ],
    ];

    /** @var list<string> */
    private const COMPOSITION_ROOT_DIRECTORIES = ['app/Providers'];

    // shortcut: direct PHP filesystem calls are covered; extend this list if inner-layer I/O needs broader coverage.
    /** @var list<string> */
    private const PHP_FILESYSTEM_FUNCTIONS = [
        'file_get_contents', 'file_put_contents', 'fopen', 'fclose', 'fread', 'fwrite', 'fputs', 'fgets', 'fgetc',
        'fgetcsv', 'fputcsv', 'readfile', 'file', 'copy', 'rename', 'unlink', 'mkdir', 'rmdir', 'scandir', 'glob',
        'opendir', 'readdir', 'closedir', 'tempnam', 'tmpfile', 'touch', 'chmod', 'chown', 'chgrp', 'lstat', 'stat',
        'filesize', 'file_exists', 'is_file', 'is_dir', 'is_readable', 'is_writable', 'realpath', 'link', 'symlink',
        'readlink', 'disk_free_space', 'disk_total_space', 'stream_get_contents', 'stream_copy_to_stream',
    ];

    /** @var list<string> */
    private const PHP_FILESYSTEM_CLASSES = [
        'splfileobject', 'directoryiterator', 'filesystemiterator', 'recursivedirectoryiterator', 'globiterator',
    ];

    /** @var list<string>|null */
    private static ?array $laravelFacadeAliases = null;

    public function test_layer_dependencies_follow_onion_architecture(): void
    {
        $root = dirname(__DIR__, 2);
        $domainFilesChecked = 0;

        foreach (self::LAYERS as $layer => $rules) {
            foreach ($rules['directories'] as $relativeDirectory) {
                $directory = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);

                if (! is_dir($directory)) {
                    continue;
                }

                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                    $directory,
                    FilesystemIterator::SKIP_DOTS,
                ));

                foreach ($iterator as $file) {
                    if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                        continue;
                    }

                    $source = file_get_contents($file->getPathname());
                    $this->assertIsString($source);

                    $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                    $expectedNamespace = str_starts_with($relativePath, 'app/')
                        ? $this->expectedNamespaceForAppFile($relativePath)
                        : null;
                    $violations = $this->findLayerViolations(
                        $source,
                        $rules,
                        $file->getPathname(),
                        $expectedNamespace,
                    );
                    $this->assertSame([], $violations, implode(PHP_EOL, $violations));

                    if ($layer === 'Domain') {
                        $domainFilesChecked++;
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $domainFilesChecked, 'The Domain layer should contain PHP source files.');

        $this->assertFalse($this->isClassifiedAppFile('app/Models/User.php'));
        $this->assertTrue($this->isClassifiedAppFile('app/Providers/AppServiceProvider.php'));

        $unclassifiedFiles = [];
        $appIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $root.DIRECTORY_SEPARATOR.'app',
            FilesystemIterator::SKIP_DOTS,
        ));

        foreach ($appIterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            if (! $this->isClassifiedAppFile($relativePath)) {
                $unclassifiedFiles[] = "{$file->getPathname()}:1 has no architecture layer.";
            }
        }

        $this->assertSame([], $unclassifiedFiles, implode(PHP_EOL, $unclassifiedFiles));
    }

    public function test_architecture_scanner_detects_imports_and_layer_violations(): void
    {
        $groupedImport = <<<'PHP'
<?php
namespace App\Domain;
use App\{
    Domain\Enums\TypeKind,
    Infrastructure\Persistence\Eloquent\Models\User,
};
PHP;
        $violations = $this->findLayerViolations($groupedImport, self::LAYERS['Domain'], 'grouped-import.php');
        $this->assertNotSame([], $violations, 'Grouped imports from outer layers must be rejected.');
        $this->assertStringContainsString('grouped-import.php:5', implode(PHP_EOL, $violations));

        $validImports = <<<'PHP'
<?php
namespace App\Application;
use App\Domain\Enums;
use App\Domain\{Cache};
use App\{Domain\Enums\TypeKind, function Domain\helper};
class Example
{
    public function kind(): string
    {
        $grouped = TypeKind::class;
        $cache = Cache::class;

        return Enums\TypeKind::class.$grouped.$cache;
    }
}
PHP;
        $this->assertSame(
            [],
            $this->findLayerViolations($validImports, self::LAYERS['Application'], 'valid-imports.php'),
            'Grouped imports and allowed namespace aliases should resolve correctly.',
        );

        $applicationDependency = <<<'PHP'
<?php
namespace App\Application;
use App\Infrastructure\Persistence\Eloquent\Models\Release;
PHP;
        $violations = $this->findLayerViolations(
            $applicationDependency,
            self::LAYERS['Application'],
            'application-dependency.php',
        );
        $this->assertNotSame([], $violations, 'Application must not depend on Infrastructure.');

        $presentationDependency = <<<'PHP'
<?php
namespace App\Http\Controllers;
use App\Infrastructure\Persistence\Eloquent\Models\Release;
PHP;
        $violations = $this->findLayerViolations(
            $presentationDependency,
            self::LAYERS['Presentation'],
            'presentation-dependency.php',
        );
        $this->assertNotSame([], $violations, 'Presentation must not depend on Infrastructure.');

        $wrongNamespace = <<<'PHP'
<?php
namespace App\Application;
class Example {}
PHP;
        $violations = $this->findLayerViolations(
            $wrongNamespace,
            self::LAYERS['Presentation'],
            'app/Http/Example.php',
            'App\\Http',
        );
        $this->assertNotSame([], $violations, 'The declared namespace must match the layer directory.');
        $this->assertStringContainsString('expected App\\Http', implode(PHP_EOL, $violations));

        $domainDependency = <<<'PHP'
<?php
namespace App\Http\Controllers;
use App\Domain\Enums\TypeKind;
PHP;
        $violations = $this->findLayerViolations(
            $domainDependency,
            self::LAYERS['Presentation'],
            'presentation-domain-dependency.php',
        );
        $this->assertNotSame([], $violations, 'Presentation must depend on Domain through Application.');

        $traitAdaptation = <<<'PHP'
<?php
namespace App\Domain;
class Example
{
    use \Illuminate\Database\Eloquent\Concerns\HasAttributes {
        getAttribute as protected;
    }
}
PHP;
        $violations = $this->findLayerViolations(
            $traitAdaptation,
            self::LAYERS['Domain'],
            'trait-adaptation.php',
        );
        $this->assertNotSame([], $violations, 'Trait adaptation must not hide an outer dependency.');
    }

    public function test_domain_and_application_reject_laravel_helpers_and_facade_aliases(): void
    {
        $source = <<<'PHP'
<?php
namespace App\Domain;
$key = env('APP_KEY');
$label = __('name');
$other = \DB::table('releases');
PHP;

        $violations = $this->findLayerViolations($source, self::LAYERS['Domain'], 'framework-dependency.php');

        $this->assertCount(3, $violations);
        $this->assertStringContainsString('env', implode(PHP_EOL, $violations));
        $this->assertStringContainsString('__', implode(PHP_EOL, $violations));
        $this->assertStringContainsString('DB', implode(PHP_EOL, $violations));

        $filesystemCalls = <<<'PHP'
<?php
namespace App\Domain;
$contents = file_get_contents('release.json');
$handle = fopen('release.json', 'rb');
PHP;
        $this->assertCount(
            2,
            $this->findLayerViolations($filesystemCalls, self::LAYERS['Domain'], 'filesystem-calls.php'),
            'PHP filesystem functions must be rejected in framework-independent layers.',
        );

        $filesystemClass = <<<'PHP'
<?php
namespace App\Domain;
$file = new \SplFileObject('release.json');
PHP;
        $this->assertNotSame(
            [],
            $this->findLayerViolations($filesystemClass, self::LAYERS['Domain'], 'filesystem-class.php'),
            'PHP filesystem classes must be rejected in framework-independent layers.',
        );

        $sameNamedClass = <<<'PHP'
<?php
namespace App\Domain;
class Cache {}
class Example
{
    public function cache(): string
    {
        return Cache::class;
    }
}
PHP;
        $this->assertSame(
            [],
            $this->findLayerViolations($sameNamedClass, self::LAYERS['Domain'], 'same-named-class.php'),
            'A domain class with the same short name as a facade alias is not a facade dependency.',
        );

        $facadeTypeReference = <<<'PHP'
<?php
namespace App\Domain;
class Example
{
    public const FACADE = \DB::class;

    public function connect(\DB $facade): void {}
}
PHP;
        $this->assertCount(
            2,
            $this->findLayerViolations($facadeTypeReference, self::LAYERS['Domain'], 'facade-type-reference.php'),
            'Facade aliases must be rejected in class constants and type declarations.',
        );

        $helperImport = <<<'PHP'
<?php
namespace App\Domain;
use function env;
PHP;
        $this->assertNotSame(
            [],
            $this->findLayerViolations($helperImport, self::LAYERS['Domain'], 'helper-import.php'),
            'Importing a Laravel helper must also be rejected.',
        );
    }

    public function test_facade_alias_detection_includes_application_and_package_aliases(): void
    {
        $aliases = $this->readFacadeAliases(
            ['aliases' => ['CustomFacade' => 'Example\\CustomFacade']],
            ['PackageFacade' => 'Example\\PackageFacade'],
        );

        $this->assertTrue($this->isLaravelFacadeAlias('CustomFacade', $aliases));
        $this->assertTrue($this->isLaravelFacadeAlias('PackageFacade', $aliases));
    }

    /**
     * @param  array{
     *     directories: list<string>,
     *     allowed_app_namespaces: list<string>,
     *     framework_independent: bool
     * }  $rules
     * @return list<string>
     */
    private function findLayerViolations(
        string $source,
        array $rules,
        string $path,
        ?string $expectedNamespace = null,
    ): array {
        try {
            // shortcut: PHP-Parser comes from Larastan; declare it directly if this test must outlive that dependency.
            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];

            $traverser = new NodeTraverser;
            $traverser->addVisitor(new ParentConnectingVisitor);
            $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false]));
            $ast = $traverser->traverse($ast);
        } catch (PhpParserError $error) {
            return ["{$path}:{$error->getStartLine()} contains invalid PHP: {$error->getMessage()}"];
        }

        $finder = new NodeFinder;
        $violations = [];
        $groupedImportNames = [];
        $namespaces = $finder->findInstanceOf($ast, Namespace_::class);
        $declaredNamespace = count($namespaces) === 1 ? $namespaces[0]->name?->toString() : null;

        if ($expectedNamespace !== null && $declaredNamespace !== $expectedNamespace) {
            $line = $namespaces === [] ? 1 : $namespaces[0]->getStartLine();
            $actual = $declaredNamespace ?? '(global namespace)';
            $violations[] = "{$path}:{$line} declares namespace {$actual}; expected {$expectedNamespace}.";
        }

        foreach ($finder->findInstanceOf($ast, GroupUse::class) as $use) {
            foreach ($use->uses as $import) {
                $groupedImportNames[spl_object_id($import->name)] = true;
                $kind = $import->type === Use_::TYPE_UNKNOWN ? $use->type : $import->type;
                $name = trim($use->prefix->toString().'\\'.$import->name->toString(), '\\');

                if ($this->isDisallowedReference($name, $rules)) {
                    $violations[] = $this->formatViolation($path, $import->getStartLine(), $name);
                }

                if ($rules['framework_independent']
                    && ($kind & Use_::TYPE_FUNCTION) === Use_::TYPE_FUNCTION
                    && $this->isLaravelHelper($name)) {
                    $violations[] = $this->formatViolation($path, $import->getStartLine(), $name);
                }

                if ($rules['framework_independent']
                    && ($kind & Use_::TYPE_FUNCTION) === Use_::TYPE_FUNCTION
                    && $this->isPhpFilesystemFunction($name)) {
                    $violations[] = $this->formatViolation($path, $import->getStartLine(), $name);
                }
            }
        }

        foreach ($finder->findInstanceOf($ast, Name::class) as $name) {
            if (isset($groupedImportNames[spl_object_id($name)])) {
                continue;
            }

            $resolvedName = $name->getAttribute('resolvedName');
            $dependency = $resolvedName instanceof Name ? $resolvedName->toString() : $name->toString();

            if ($this->isDisallowedReference($dependency, $rules)) {
                $violations[] = $this->formatViolation($path, $name->getStartLine(), $dependency);
            }

            if ($rules['framework_independent']
                && $this->isPhpFilesystemClass($dependency)
                && $this->isFacadeClassReference($name)) {
                $violations[] = $this->formatViolation($path, $name->getStartLine(), $dependency);
            }

            if ($rules['framework_independent']
                && $this->isLaravelFacadeAlias($dependency)
                && $this->isFacadeClassReference($name)) {
                $violations[] = $this->formatViolation($path, $name->getStartLine(), $dependency);
            }
        }

        if ($rules['framework_independent']) {
            foreach ($finder->findInstanceOf($ast, FuncCall::class) as $call) {
                if ($call->name instanceof Name) {
                    $resolvedName = $call->name->getAttribute('resolvedName');
                    $name = $resolvedName instanceof Name ? $resolvedName->toString() : $call->name->toString();

                    if ($this->isLaravelHelper($call->name->toString())) {
                        $violations[] = $this->formatViolation($path, $call->getStartLine(), $name);
                    }

                    if ($this->isPhpFilesystemFunction($name)
                        || $this->isPhpFilesystemFunction($call->name->toString())) {
                        $violations[] = $this->formatViolation($path, $call->getStartLine(), $name);
                    }
                }
            }

            foreach ($finder->findInstanceOf($ast, Use_::class) as $use) {
                foreach ($use->uses as $import) {
                    $kind = $import->type === Use_::TYPE_UNKNOWN ? $use->type : $import->type;
                    $name = $import->name->toString();

                    if (($kind & Use_::TYPE_FUNCTION) === Use_::TYPE_FUNCTION && $this->isLaravelHelper($name)) {
                        $violations[] = $this->formatViolation($path, $import->getStartLine(), $name);
                    }

                    if (($kind & Use_::TYPE_FUNCTION) === Use_::TYPE_FUNCTION
                        && $this->isPhpFilesystemFunction($name)) {
                        $violations[] = $this->formatViolation($path, $import->getStartLine(), $name);
                    }

                    if (($kind & Use_::TYPE_NORMAL) === Use_::TYPE_NORMAL
                        && ! str_contains($name, '\\')
                        && $this->isLaravelFacadeAlias($name)) {
                        $violations[] = $this->formatViolation($path, $import->getStartLine(), $name);
                    }

                    if (($kind & Use_::TYPE_NORMAL) === Use_::TYPE_NORMAL
                        && ! str_contains($name, '\\')
                        && $this->isPhpFilesystemClass($name)) {
                        $violations[] = $this->formatViolation($path, $import->getStartLine(), $name);
                    }
                }
            }
        }

        return array_values(array_unique($violations));
    }

    private function isFacadeClassReference(Name $name): bool
    {
        $parent = $name->getAttribute('parent');

        return ! ($parent instanceof FuncCall)
            && ! ($parent instanceof ConstFetch)
            && ! ($parent instanceof Namespace_)
            && ! ($parent instanceof UseUse)
            && ! ($parent instanceof GroupUse);
    }

    private function isClassifiedAppFile(string $relativePath): bool
    {
        $directories = self::COMPOSITION_ROOT_DIRECTORIES;

        foreach (self::LAYERS as $rules) {
            foreach ($rules['directories'] as $directory) {
                if (str_starts_with($directory, 'app/')) {
                    $directories[] = $directory;
                }
            }
        }

        foreach ($directories as $directory) {
            if (str_starts_with($relativePath, $directory.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{
     *     directories: list<string>,
     *     allowed_app_namespaces: list<string>,
     *     framework_independent: bool
     * }  $rules
     */
    private function isDisallowedReference(string $name, array $rules): bool
    {
        if (! str_contains($name, '\\')) {
            return false;
        }

        $name = strtolower($name);

        if (! str_starts_with($name, 'app\\')) {
            return $rules['framework_independent'];
        }

        foreach ($rules['allowed_app_namespaces'] as $namespace) {
            $namespace = strtolower($namespace);

            if ($name === $namespace || str_starts_with($name, $namespace.'\\')) {
                return false;
            }
        }

        return true;
    }

    private function isLaravelHelper(string $name): bool
    {
        if (! function_exists($name)) {
            return false;
        }

        $file = (new ReflectionFunction($name))->getFileName();

        return is_string($file)
            && str_contains(str_replace('\\', '/', $file), '/vendor/laravel/framework/');
    }

    private function isPhpFilesystemFunction(string $name): bool
    {
        return in_array(strtolower(ltrim($name, '\\')), self::PHP_FILESYSTEM_FUNCTIONS, true);
    }

    private function isPhpFilesystemClass(string $name): bool
    {
        return in_array(strtolower(ltrim($name, '\\')), self::PHP_FILESYSTEM_CLASSES, true);
    }

    private function expectedNamespaceForAppFile(string $relativePath): string
    {
        $relativeDirectory = dirname(substr($relativePath, strlen('app/')));

        return $relativeDirectory === '.'
            ? 'App'
            : 'App\\'.str_replace('/', '\\', $relativeDirectory);
    }

    /**
     * @param  list<string>|null  $aliases
     */
    private function isLaravelFacadeAlias(string $name, ?array $aliases = null): bool
    {
        if ($aliases === null) {
            self::$laravelFacadeAliases ??= $this->readFacadeAliases();
            $aliases = self::$laravelFacadeAliases;
        }

        return in_array(strtolower($name), $aliases, true);
    }

    /**
     * @param  array<string, mixed>|null  $configuration
     * @param  array<string, mixed>|null  $packageAliases
     * @return list<string>
     */
    private function readFacadeAliases(?array $configuration = null, ?array $packageAliases = null): array
    {
        $root = dirname(__DIR__, 2);

        if ($configuration === null) {
            $loadedConfiguration = require $root.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'app.php';
            $configuration = is_array($loadedConfiguration) ? $loadedConfiguration : [];
        }

        if ($packageAliases === null) {
            $manifestPath = $root.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'packages.php';
            $packageAliases = (new PackageManifest(new Filesystem, $root, $manifestPath))->aliases();
        }

        $configuredAliases = $configuration['aliases'] ?? [];
        $names = Facade::defaultAliases()->keys()->all();

        foreach ([$configuredAliases, $packageAliases] as $aliases) {
            if (is_array($aliases)) {
                foreach (array_keys($aliases) as $alias) {
                    if (is_string($alias)) {
                        $names[] = $alias;
                    }
                }
            }
        }

        $normalized = [];

        foreach ($names as $alias) {
            if (is_string($alias)) {
                $normalized[] = strtolower($alias);
            }
        }

        return array_values(array_unique($normalized));
    }

    private function formatViolation(string $path, int $line, string $dependency): string
    {
        return "{$path}:{$line} uses forbidden dependency {$dependency}.";
    }
}
