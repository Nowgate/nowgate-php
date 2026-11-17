<?php

declare(strict_types=1);

namespace Nowgate\Tests;

/** The tests, registered by test() as each test file is loaded, and run by run.php. */
final class Registry
{
    /** @var list<array{string, \Closure(): void}> */
    public static array $tests = [];
}

final class Failure extends \Exception
{
}

function test(string $name, \Closure $run): void
{
    Registry::$tests[] = [$name, $run];
}

function same(mixed $want, mixed $got, string $what = ''): void
{
    if ($want !== $got) {
        throw new Failure(($what !== '' ? "{$what}: " : '') . 'want ' . var_export($want, true) . ', got ' . var_export($got, true));
    }
}

function ok(bool $condition, string $what): void
{
    if (!$condition) {
        throw new Failure($what);
    }
}

/**
 * Runs $run, which must throw a $class whose message matches $pattern, and returns it.
 *
 * @template T of \Throwable
 * @param class-string<T> $class
 * @return T
 */
function throws(string $class, \Closure $run, ?string $pattern = null): \Throwable
{
    try {
        $run();
    } catch (\Throwable $e) {
        if (!$e instanceof $class) {
            throw new Failure("want a {$class}, got " . $e::class . ': ' . $e->getMessage());
        }
        if ($pattern !== null && preg_match($pattern, $e->getMessage()) !== 1) {
            throw new Failure("the message doesn't match {$pattern}: " . $e->getMessage());
        }
        return $e;
    }
    throw new Failure("want a {$class}, nothing was thrown");
}
