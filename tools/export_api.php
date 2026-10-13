<?php

declare(strict_types=1);

/** Export the complete public/protected PHP API without constructing PDF objects. */

namespace PDF\Tools\Documentation;

use Composer\InstalledVersions;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionEnum;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @return list<class-string> */
function declarations(string $file): array
{
    $source = file_get_contents($file);
    if ($source === false) {
        throw new \RuntimeException('Cannot read an API source file.');
    }
    $tokens = token_get_all($source, TOKEN_PARSE);
    $namespace = '';
    $names = [];
    $previous = null;
    foreach ($tokens as $index => $token) {
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        if (is_array($token) && $token[0] === T_NAMESPACE) {
            $namespace = '';
            for ($cursor = $index + 1; $cursor < count($tokens); $cursor++) {
                $part = $tokens[$cursor];
                if ($part === ';' || $part === '{') {
                    break;
                }
                if (is_array($part) && in_array($part[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                    $namespace .= $part[1];
                }
            }
        }
        if (is_array($token) && in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            // Anonymous classes and Foo::class are expressions, not declarations.
            if ($previous !== T_NEW && $previous !== T_DOUBLE_COLON) {
                for ($cursor = $index + 1; $cursor < count($tokens); $cursor++) {
                    $part = $tokens[$cursor];
                    if (is_array($part) && in_array($part[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    if (is_array($part) && $part[0] === T_STRING) {
                        $name = ($namespace === '' ? '' : $namespace . '\\') . $part[1];
                        if (!class_exists($name) && !interface_exists($name) && !trait_exists($name) && !enum_exists($name)) {
                            throw new \RuntimeException('Cannot autoload API declaration: ' . $name);
                        }
                        $names[] = $name;
                    }
                    break;
                }
            }
        }
        $previous = is_array($token) ? $token[0] : $token;
    }
    return $names;
}

function description(string|false $comment): string
{
    if ($comment === false) {
        return '';
    }
    $comment = preg_replace('/^\/\*\*|\*\/$/', '', trim($comment)) ?? '';
    $lines = explode("\n", $comment);
    foreach ($lines as &$line) {
        $line = preg_replace('/^\s*\* ?/', '', rtrim($line, "\r")) ?? '';
    }
    unset($line);
    return trim(implode("\n", $lines));
}

function typeName(?ReflectionType $type): ?string
{
    return $type === null ? null : (string) $type;
}

function visibility(ReflectionMethod|ReflectionProperty|ReflectionClassConstant $member): string
{
    return $member->isPublic() ? 'public' : ($member->isProtected() ? 'protected' : 'private');
}

function valueLiteral(mixed $value): string
{
    if ($value instanceof \UnitEnum) {
        return $value::class . '::' . $value->name;
    }
    if (is_array($value)) {
        if ($value === []) {
            return '[]';
        }
        $parts = [];
        foreach ($value as $key => $item) {
            $parts[] = valueLiteral($key) . ' => ' . valueLiteral($item);
        }
        return '[' . implode(', ', $parts) . ']';
    }
    if (is_object($value)) {
        return 'object(' . $value::class . ')';
    }
    return var_export($value, true);
}

/**
 * Read default expressions without ReflectionParameter::getDefaultValue().
 * PHP permits constructor calls in defaults; reflecting those values executes them.
 *
 * @return array<string, string>
 */
function parameterDefaults(ReflectionMethod $method): array
{
    static $sources = [];
    $file = $method->getFileName();
    $start = $method->getStartLine();
    $end = $method->getEndLine();
    if ($file === false || $start === false || $end === false) {
        return [];
    }
    if (!isset($sources[$file])) {
        $lines = file($file);
        if ($lines === false) {
            throw new \RuntimeException('Cannot read API parameter declarations.');
        }
        $sources[$file] = $lines;
    }
    $source = implode('', array_slice($sources[$file], $start - 1, $end - $start + 1));
    $tokens = token_get_all('<?php ' . $source);
    $functionSeen = false;
    $parametersSeen = false;
    $round = 0;
    $square = 0;
    $curly = 0;
    $name = null;
    $default = null;
    $defaults = [];
    foreach ($tokens as $token) {
        $text = is_array($token) ? $token[1] : $token;
        if (!$functionSeen) {
            if (is_array($token) && $token[0] === T_FUNCTION) {
                $functionSeen = true;
            }
            continue;
        }
        if (!$parametersSeen) {
            if ($text === '(') {
                $parametersSeen = true;
                $round = 1;
            }
            continue;
        }
        if ($round === 1 && $square === 0 && $curly === 0 && ($text === ',' || $text === ')')) {
            if ($name !== null && $default !== null) {
                $defaults[$name] = trim($default);
            }
            if ($text === ')') {
                break;
            }
            $name = null;
            $default = null;
            continue;
        }
        if ($round === 1 && $square === 0 && $curly === 0 && $default === null) {
            if (is_array($token) && $token[0] === T_VARIABLE) {
                $name = substr($text, 1);
            } elseif ($text === '=') {
                $default = '';
                continue;
            }
        }
        if ($default !== null) {
            // Preserve literals exactly; normalize only whitespace tokens.
            $default .= is_array($token) && $token[0] === T_WHITESPACE ? ' ' : $text;
        }
        if ($text === '(') {
            $round++;
        } elseif ($text === ')') {
            $round--;
        } elseif ($text === '[' || (is_array($token) && $token[0] === T_ATTRIBUTE)) {
            $square++;
        } elseif ($text === ']') {
            $square--;
        } elseif ($text === '{') {
            $curly++;
        } elseif ($text === '}') {
            $curly--;
        }
    }
    return $defaults;
}

/**
 * @param array<string, string> $defaults
 * @return array<string, mixed>
 */
function parameterRecord(ReflectionParameter $parameter, array $defaults): array
{
    $default = $defaults[$parameter->getName()] ?? null;
    if ($parameter->isDefaultValueAvailable() && $parameter->isDefaultValueConstant()) {
        $default = $parameter->getDefaultValueConstantName();
    }
    return [
        'name' => $parameter->getName(),
        'type' => typeName($parameter->getType()),
        'byReference' => $parameter->isPassedByReference(),
        'variadic' => $parameter->isVariadic(),
        'optional' => $parameter->isOptional(),
        'default' => $default,
    ];
}

/** @return array<string, mixed> */
function methodRecord(ReflectionMethod $method, array $packages): array
{
    $defaults = parameterDefaults($method);
    $file = $method->getFileName();
    $location = $file === false ? [] : sourceLocation($file, $packages);
    return [
        'name' => $method->getName(),
        'visibility' => visibility($method),
        'static' => $method->isStatic(),
        'abstract' => $method->isAbstract(),
        'final' => $method->isFinal(),
        'returnType' => typeName($method->getReturnType() ?? $method->getTentativeReturnType()),
        'parameters' => array_map(static fn (ReflectionParameter $parameter): array => parameterRecord($parameter, $defaults), $method->getParameters()),
        'description' => description($method->getDocComment()),
        'declaringClass' => $method->getDeclaringClass()->getName(),
        ...$location,
        'line' => $method->getStartLine() ?: null,
    ];
}

/** @return array<string, mixed> */
function propertyRecord(ReflectionProperty $property): array
{
    return [
        'name' => $property->getName(),
        'type' => typeName($property->getType()),
        'visibility' => visibility($property),
        'static' => $property->isStatic(),
        'readonly' => $property->isReadOnly(),
        'description' => description($property->getDocComment()),
        'declaringClass' => $property->getDeclaringClass()->getName(),
    ];
}

/** @return array<string, mixed> */
function constantRecord(ReflectionClassConstant $constant): array
{
    return [
        'name' => $constant->getName(),
        'type' => typeName($constant->getType()),
        'visibility' => visibility($constant),
        'value' => valueLiteral($constant->getValue()),
        'description' => description($constant->getDocComment()),
        'declaringClass' => $constant->getDeclaringClass()->getName(),
    ];
}

/**
 * @param ReflectionClass<object> $reflection
 * @return array<string, mixed>
 */
function componentRecord(ReflectionClass $reflection, array $packages): array
{
    $methods = [];
    $inheritedMethods = [];
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
        if ($method->isConstructor()) {
            continue;
        }
        if ($method->getDeclaringClass()->getName() === $reflection->getName()) {
            $methods[] = methodRecord($method, $packages);
        } else {
            $inheritedMethods[] = methodRecord($method, $packages);
        }
    }
    $sort = static fn (array $left, array $right): int => strcmp($left['name'], $right['name']);
    usort($methods, $sort);
    usort($inheritedMethods, $sort);
    $properties = array_map(propertyRecord(...), $reflection->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED));
    $constants = array_map(constantRecord(...), array_filter(
        $reflection->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC | ReflectionClassConstant::IS_PROTECTED),
        static fn (ReflectionClassConstant $constant): bool => !$constant->isEnumCase(),
    ));
    usort($properties, $sort);
    usort($constants, $sort);
    $cases = [];
    if (enum_exists($reflection->getName())) {
        $enum = new ReflectionEnum($reflection->getName());
        foreach ($enum->getCases() as $case) {
            $cases[] = [
                'name' => $case->getName(),
                'value' => $case instanceof \ReflectionEnumBackedCase ? $case->getBackingValue() : null,
            ];
        }
    }
    $interfaces = $reflection->getInterfaceNames();
    $traits = $reflection->getTraitNames();
    sort($interfaces);
    sort($traits);
    $file = $reflection->getFileName();
    if ($file === false) {
        throw new \RuntimeException('An API declaration has no source file.');
    }
    $location = sourceLocation($file, $packages);
    return [
        'name' => $reflection->getName(),
        'shortName' => $reflection->getShortName(),
        'namespace' => $reflection->getNamespaceName(),
        'kind' => $reflection->isEnum() ? 'enum' : ($reflection->isInterface() ? 'interface' : ($reflection->isTrait() ? 'trait' : 'class')),
        'abstract' => $reflection->isAbstract(),
        'final' => $reflection->isFinal(),
        'readonly' => $reflection->isReadOnly(),
        ...$location,
        'line' => $reflection->getStartLine() ?: null,
        'description' => description($reflection->getDocComment()),
        'parent' => ($parent = $reflection->getParentClass()) === false ? null : $parent->getName(),
        'interfaces' => $interfaces,
        'traits' => $traits,
        'constructor' => ($constructor = $reflection->getConstructor()) === null ? null : methodRecord($constructor, $packages),
        'methods' => $methods,
        'inheritedMethods' => $inheritedMethods,
        'properties' => $properties,
        'constants' => $constants,
        'cases' => $cases,
    ];
}

/** @return array<string, array{root: string, repository: string, revision: ?string, optional: bool}> */
function documentationPackages(string $root): array
{
    $root = realpath($root) ?: throw new \RuntimeException('Cannot resolve the core source tree.');
    $packages = [
        'roukmoute/pdf' => ['root' => $root, 'repository' => 'https://github.com/PDFToolkit/pdf', 'revision' => null, 'optional' => false],
    ];
    $lockBytes = file_get_contents($root . '/composer.lock');
    if ($lockBytes === false) {
        throw new \RuntimeException('Cannot read the locked documentation dependencies.');
    }
    $lock = json_decode($lockBytes, true, flags: JSON_THROW_ON_ERROR);
    $lockedPackages = [];
    foreach ([...$lock['packages'], ...$lock['packages-dev']] as $package) {
        $lockedPackages[$package['name']] = $package;
    }
    foreach (['pdf-fonts', 'pdf-images', 'pdf-reader'] as $module) {
        $name = 'pdftoolkit/' . $module;
        if (!InstalledVersions::isInstalled($name)) {
            continue;
        }
        $path = InstalledVersions::getInstallPath($name);
        $path = $path === null ? false : realpath($path);
        $reference = $lockedPackages[$name]['source']['reference']
            ?? $lockedPackages[$name]['dist']['reference']
            ?? InstalledVersions::getReference($name);
        if ($path === false || !is_string($reference) || preg_match('/^[0-9a-f]{40}$/D', $reference) !== 1) {
            throw new \RuntimeException('An optional API package needs an installed source tree and a locked commit: ' . $name);
        }
        if (InstalledVersions::getReference($name) !== $reference) {
            throw new \RuntimeException('An optional API package differs from the lock; run composer install: ' . $name);
        }
        $packages[$name] = [
            'root' => $path,
            'repository' => 'https://github.com/PDFToolkit/' . $module,
            'revision' => $reference,
            'optional' => true,
        ];
    }
    return $packages;
}

/**
 * @param array<string, array{root: string, repository: string, revision: ?string, optional: bool}> $packages
 * @return array{package: string, repository: string, revision: ?string, optional: bool, source: string}
 */
function sourceLocation(string $file, array $packages): array
{
    $file = realpath($file) ?: throw new \RuntimeException('Cannot resolve an API source file.');
    // Installed modules may live inside the core vendor directory.
    foreach (array_reverse($packages, true) as $name => $package) {
        if (str_starts_with($file, $package['root'] . DIRECTORY_SEPARATOR)) {
            return [
                'package' => $name,
                'repository' => $package['repository'],
                'revision' => $package['revision'],
                'optional' => $package['optional'],
                'source' => str_replace('\\', '/', substr($file, strlen($package['root']) + 1)),
            ];
        }
    }
    throw new \RuntimeException('An API declaration is outside the documented source trees.');
}

$arguments = $_SERVER['argv'] ?? [];
if (!is_array($arguments) || count($arguments) !== 2 || !isset($arguments[1]) || !is_string($arguments[1]) || $arguments[1] === '') {
    fwrite(STDERR, "Usage: php tools/export_api.php <output.json>\n");
    exit(1);
}

try {
    $root = dirname(__DIR__);
    $packages = documentationPackages($root);
    $files = [];
    foreach ($packages as $package) {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($package['root'] . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }
    sort($files);
    $components = [];
    $seen = [];
    foreach ($files as $file) {
        foreach (declarations($file) as $name) {
            $key = strtolower($name);
            $reflection = new ReflectionClass($name);
            if (isset($seen[$key]) || realpath($reflection->getFileName() ?: '') !== realpath($file)) {
                throw new \RuntimeException('Duplicate or mismatched API declaration: ' . $name);
            }
            $seen[$key] = true;
            $components[] = componentRecord($reflection, $packages);
        }
    }
    usort($components, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));
    $metadata = [];
    foreach ($packages as $name => $package) {
        unset($package['root']);
        $metadata[] = ['name' => $name, ...$package];
    }
    $json = json_encode(['packages' => $metadata, 'components' => $components], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (file_put_contents($arguments[1], $json . "\n") === false) {
        throw new \RuntimeException('Cannot write the API inventory.');
    }
    fwrite(STDOUT, sprintf("Exported %d API components.\n", count($components)));
} catch (\Throwable $exception) {
    fwrite(STDERR, 'API export failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
