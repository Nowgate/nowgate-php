<?php

declare(strict_types=1);

// The generated surface: the versions, the error codes, and every operation as a method in
// both families (api-docs-and-sdks.md sections 1.2 and 4).

namespace Nowgate\Tests;

use Nowgate\Client;
use Nowgate\Generated\ErrorCode;
use Nowgate\Generated\Types;

test("the versions are the spec's and the package's", function (): void {
    $spec = (string) file_get_contents(Fake::fixture('api/openapi.yaml'));
    preg_match('/^info:\n(?:  .*\n)*?  version: [\'"]?([0-9-]+)[\'"]?$/m', $spec, $m);
    same(Types::API_VERSION, $m[1] ?? null);
    /** @var array{version?: string} $composer */
    $composer = json_decode((string) file_get_contents(Fake::sdk() . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    same(Types::VERSION, $composer['version'] ?? null);
});

test('every error code the API documents is a constant', function (): void {
    /** @var array{errors: list<array{code: string}>} $errors */
    $errors = json_decode((string) file_get_contents(Fake::fixture('api/errors.json')), true, 512, JSON_THROW_ON_ERROR);
    $codes = array_map(static fn (array $e): string => $e['code'], $errors['errors']);
    same($codes, ErrorCode::ALL);
    same('insufficient_balance', ErrorCode::INSUFFICIENT_BALANCE);
});

test('every named operation is a method in both families', function (): void {
    /** @var array{operations: array<string, string>} $names */
    $names = json_decode((string) file_get_contents(Fake::fixture('sdks/operations.json')), true, 512, JSON_THROW_ON_ERROR);
    $nowgate = new Client(['api_key' => Fake::KEY]);
    $methods = static fn (object $service): array => get_class_methods($service);
    foreach ($names['operations'] as $operation => $name) {
        [$resource, $method] = explode('.', $name) + ['', ''];
        $service = $nowgate->{$resource};
        $withResponse = $nowgate->withResponse()->{$resource};
        ok(is_object($service) && in_array($method, $methods($service), true), $operation);
        ok(is_object($withResponse) && in_array($method, $methods($withResponse), true), "{$operation} with the response");
    }
    ok(in_array('listAll', $methods($nowgate->payments), true), 'listAll');
    ok(!in_array('listAll', $methods($nowgate->withResponse()->payments), true), 'no listAll with the response');
});
