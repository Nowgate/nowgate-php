<?php

declare(strict_types=1);

// Problems as exceptions of their status's class, carrying the whole problem
// (api-docs-and-sdks.md section 1.5).

namespace Nowgate\Tests;

use Nowgate\Exception\ApiException;
use Nowgate\Exception\AuthenticationException;
use Nowgate\Exception\ConflictException;
use Nowgate\Exception\ConnectionException;
use Nowgate\Exception\InvalidRequestException;
use Nowgate\Exception\NotFoundException;
use Nowgate\Exception\NowgateException;
use Nowgate\Exception\PermissionException;
use Nowgate\Exception\RateLimitException;
use Nowgate\Exception\ServerException;
use Nowgate\Exception\SignatureVerificationException;
use Nowgate\Transport\Response;

test('a problem carries every field, its pointers and the docs page', function (): void {
    $fields = [
        ['pointer' => '/amount', 'code' => 'amount_too_precise', 'detail' => 'USDT has 6 decimals.'],
        ['pointer' => '/items/2/amount', 'code' => 'amount_invalid', 'detail' => 'Not a decimal.'],
    ];
    $fake = new Fake([Fake::problem(422, 'amount_too_precise', ['Retry-After' => '5', 'X-Request-Id' => 'req_h'], ['errors' => $fields])]);
    $e = throws(InvalidRequestException::class, fn () => $fake->nowgate->payments->create(['network' => 'tron', 'asset' => 'USDT', 'amount' => '1.0000001']));
    same([ApiException::class, NowgateException::class, \RuntimeException::class, \Exception::class], array_values(class_parents($e)), 'the hierarchy');
    same(422, $e->status);
    same('amount_too_precise', $e->errorCode);
    same('Problem', $e->title);
    same('The amount_too_precise detail.', $e->detail);
    same('https://docs.nowgate.io/errors/amount_too_precise', $e->type);
    same('/v1/payments', $e->instance);
    same('req_problem', $e->requestId);
    same(5, $e->retryAfter);
    same($fields, $e->errors);
    same('req_h', $e->headers['x-request-id']);
    same('amount_too_precise: The amount_too_precise detail. (https://docs.nowgate.io/errors/amount_too_precise)', $e->getMessage());
});

test('each status has its class', function (): void {
    $classes = [400 => InvalidRequestException::class, 401 => AuthenticationException::class, 403 => PermissionException::class, 404 => NotFoundException::class, 405 => InvalidRequestException::class, 409 => ConflictException::class, 413 => InvalidRequestException::class, 414 => InvalidRequestException::class, 415 => InvalidRequestException::class, 422 => InvalidRequestException::class, 429 => RateLimitException::class, 500 => ServerException::class, 501 => ServerException::class, 503 => ServerException::class, 418 => ApiException::class];
    foreach ($classes as $status => $class) {
        $fake = new Fake([Fake::problem($status, 'some_code')], ['max_retries' => 0]);
        $e = throws(ApiException::class, fn () => $fake->nowgate->payments->retrieve('pay_1'));
        same($class, $e::class, "status {$status}");
        same($status, $e->status);
    }
});

test("an answer that isn't problem JSON is its status's exception, with the body's first 500 bytes", function (): void {
    $page = '<html><body>x' . str_repeat('é', 400) . '</body></html>';
    $fake = new Fake([new Response(502, ['Content-Type' => 'text/html', 'X-Request-Id' => 'req_proxy'], $page, 'Bad Gateway')], ['max_retries' => 0]);
    $e = throws(ServerException::class, fn () => $fake->nowgate->payments->retrieve('pay_1'));
    same('http_502', $e->errorCode);
    same('Bad Gateway', $e->title);
    same(null, $e->type);
    same('req_proxy', $e->requestId);
    ok(str_starts_with((string) $e->detail, '<html><body>xéé'), 'the start of the body');
    same(499, strlen((string) $e->detail));
    ok(mb_check_encoding((string) $e->detail, 'UTF-8'), 'whole characters');
    ok(str_starts_with($e->getMessage(), 'http_502: <html>'), 'the message');
});

test("JSON that isn't a problem, and an empty answer, fall back the same way", function (): void {
    $fake = new Fake([new Response(404, ['Content-Type' => 'application/json'], '{"error":"nope"}'), new Response(403), Fake::json(400, ['code', 'title'])], ['max_retries' => 0]);
    $e = throws(NotFoundException::class, fn () => $fake->nowgate->payments->retrieve('pay_1'));
    same('http_404', $e->errorCode);
    same('{"error":"nope"}', $e->detail);
    $e = throws(PermissionException::class, fn () => $fake->nowgate->payments->retrieve('pay_1'));
    same(null, $e->detail);
    same('http_403: HTTP 403', $e->getMessage());
    same('http_400', throws(InvalidRequestException::class, fn () => $fake->nowgate->payments->retrieve('pay_1'))->errorCode);
});

test('the exceptions without an answer are NowgateExceptions, not ApiExceptions', function (): void {
    $failure = new SignatureVerificationException('mismatch');
    same([NowgateException::class, \RuntimeException::class, \Exception::class], array_values(class_parents($failure)), 'a signature failure');
    same('mismatch', $failure->reason);
    $connection = new ConnectionException('no answer');
    same([NowgateException::class, \RuntimeException::class, \Exception::class], array_values(class_parents($connection)), 'no answer');
});
