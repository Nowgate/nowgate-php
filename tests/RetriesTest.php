<?php

declare(strict_types=1);

// Safe retries: an idempotency key on every POST, the same for every attempt, and retries
// only of what the API says may change (api-docs-and-sdks.md section 2; ADR 0032).

namespace Nowgate\Tests;

use Nowgate\Exception\ConflictException;
use Nowgate\Exception\ConnectionException;
use Nowgate\Exception\InvalidRequestException;
use Nowgate\Exception\RateLimitException;
use Nowgate\Exception\ServerException;
use Nowgate\Transport\Request;
use Nowgate\Transport\Response;
use Nowgate\Transport\TransportException;

const CREATE = ['network' => 'tron', 'asset' => 'USDT', 'amount' => '25'];
const UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

test('every POST carries a random idempotency key, the same on every attempt', function (): void {
    $fake = new Fake([Fake::problem(500, 'internal_error'), Fake::refused(), Fake::json(201, Fake::PAYMENT)]);
    $response = $fake->nowgate->withResponse()->payments->create(CREATE);
    same(Fake::PAYMENT, $response->data);
    same(3, count($fake->sent));
    $keys = array_map(static fn (Request $r): string => $r->headers['Idempotency-Key'] ?? '', $fake->sent);
    ok(preg_match(UUID, $keys[0]) === 1, 'a v4 UUID');
    same([$keys[0], $keys[0], $keys[0]], $keys);
    same($keys[0], $response->idempotencyKey);
    same(1, count(array_unique(array_map(static fn (Request $r): string => (string) $r->body, $fake->sent))));
});

test("each call gets its own key, and the caller's key is used as given", function (): void {
    $fake = new Fake([Fake::json(201, Fake::PAYMENT), Fake::json(201, Fake::PAYMENT), Fake::json(201, Fake::PAYMENT)]);
    $fake->nowgate->payments->create(CREATE);
    $fake->nowgate->payments->create(CREATE);
    $fake->nowgate->payments->create(CREATE, ['idempotency_key' => 'order-1042']);
    ok($fake->sent[0]->headers['Idempotency-Key'] !== $fake->sent[1]->headers['Idempotency-Key'], 'two keys');
    same('order-1042', $fake->sent[2]->headers['Idempotency-Key']);
    throws(\InvalidArgumentException::class, fn () => $fake->nowgate->payments->create(CREATE, ['idempotency_key' => '']), '/idempotency_key must be a non-empty string/');
    throws(\InvalidArgumentException::class, fn () => $fake->nowgate->payments->create(CREATE, ['idempotency' => 'x']), '/Unknown option idempotency/');
});

test('GET, PUT, PATCH and DELETE carry no key and are retried by the same rules', function (): void {
    $fake = new Fake([
        Fake::problem(503, 'pool_exhausted'),
        Fake::json(200, Fake::PAYMENT),
        Fake::problem(502, 'http_502'),
        Fake::json(200, ['rules' => []]),
        Fake::refused(),
        Fake::json(200, ['id' => 'plink_1']),
        Fake::problem(504, 'http_504'),
        Fake::json(200, ['id' => 'dst_1']),
    ]);
    $fake->nowgate->payments->retrieve('pay_1');
    $fake->nowgate->forwarding->update(['rules' => []]);
    $fake->nowgate->paymentLinks->update('plink_1', ['title' => 'T-shirt']);
    $fake->nowgate->payoutDestinations->disable('dst_1');
    same(['GET', 'GET', 'PUT', 'PUT', 'PATCH', 'PATCH', 'DELETE', 'DELETE'], array_map(static fn (Request $r): string => $r->method, $fake->sent));
    foreach ($fake->sent as $sent) {
        ok(!isset($sent->headers['Idempotency-Key']), 'no key');
    }
});

test('no answer is retried, then surfaces as a ConnectionException with its cause', function (): void {
    $fake = new Fake([Fake::refused(), Fake::refused(), Fake::refused()]);
    $e = throws(ConnectionException::class, fn () => $fake->nowgate->payments->create(CREATE), "#Couldn't reach Nowgate at https://sandbox-api.nowgate.io/v1: .*Connection refused#");
    ok($e->getPrevious() instanceof TransportException, 'the cause');
    same(3, count($fake->sent));
    same([0.5, 1.0], $fake->waits);
});

test('an attempt that times out is retried, then is a ConnectionException', function (): void {
    $hang = static function (Request $r): Response {
        throw new TransportException('Operation timed out', true);
    };
    $fake = new Fake([$hang, Fake::json(200, Fake::PAYMENT)], ['timeout' => 0.02]);
    same(Fake::PAYMENT, $fake->nowgate->payments->retrieve('pay_1'));
    same(0.02, $fake->sent[0]->timeout);
    $slow = new Fake([$hang, $hang], ['timeout' => 0.02, 'max_retries' => 1]);
    throws(ConnectionException::class, fn () => $slow->nowgate->payments->retrieve('pay_1'), "/didn't answer within 0.02 s/");
});

test("409 idempotency_key_in_progress is retried after its Retry-After; other conflicts aren't", function (): void {
    $fake = new Fake([
        Fake::problem(409, 'idempotency_key_in_progress', ['Retry-After' => '1']),
        Fake::json(201, Fake::PAYMENT),
        Fake::problem(409, 'payout_not_cancelable'),
    ]);
    $fake->nowgate->payments->create(CREATE);
    same([1.0], $fake->waits);
    throws(ConflictException::class, fn () => $fake->nowgate->payouts->cancel('po_1'));
    same(3, count($fake->sent));
});

test('429 is retried after its Retry-After, in seconds or as a date, or with backoff', function (): void {
    $soon = gmdate('D, d M Y H:i:s', time() + 3) . ' GMT';
    $fake = new Fake([
        Fake::problem(429, 'rate_limited', ['Retry-After' => '2']),
        Fake::problem(429, 'rate_limited', ['Retry-After' => $soon]),
        Fake::json(200, Fake::PAYMENT),
        Fake::problem(429, 'rate_limited'),
        Fake::json(200, Fake::PAYMENT),
    ]);
    $fake->nowgate->payments->retrieve('pay_1');
    $fake->nowgate->payments->retrieve('pay_1');
    same(2.0, $fake->waits[0]);
    ok(in_array($fake->waits[1], [2.0, 3.0, 4.0], true), 'the date, in whole seconds: ' . $fake->waits[1]);
    same(0.5, $fake->waits[2]);
});

test("a Retry-After over 60 seconds isn't waited for: the exception carries it", function (): void {
    $fake = new Fake([Fake::problem(429, 'rate_limited', ['Retry-After' => '61'])]);
    $e = throws(RateLimitException::class, fn () => $fake->nowgate->payments->retrieve('pay_1'));
    same(61, $e->retryAfter);
    same([], $fake->waits);
});

test("500, 502, 503 and 504 are retried; 501 isn't", function (): void {
    foreach ([500, 502, 503, 504] as $status) {
        $fake = new Fake([Fake::problem($status, 'internal_error'), Fake::json(200, Fake::PAYMENT)]);
        same(Fake::PAYMENT, $fake->nowgate->payments->retrieve('pay_1'), "status {$status}");
    }
    $fake = new Fake([Fake::problem(501, 'internal_error')]);
    throws(ServerException::class, fn () => $fake->nowgate->payments->retrieve('pay_1'));
    same(1, count($fake->sent));
});

test("other 4xx aren't retried, idempotency_key_reused among them", function (): void {
    foreach ([[400, 'invalid_request'], [401, 'invalid_api_key'], [403, 'insufficient_scope'], [404, 'not_found'], [422, 'idempotency_key_reused']] as [$status, $code]) {
        $fake = new Fake([Fake::problem($status, $code)]);
        $e = throws(\Nowgate\Exception\ApiException::class, fn () => $fake->nowgate->payments->create(CREATE));
        same($code, $e->errorCode);
        same(1, count($fake->sent));
    }
    $fake = new Fake([Fake::problem(422, 'idempotency_key_reused')]);
    throws(InvalidRequestException::class, fn () => $fake->nowgate->payments->create(CREATE));
});

test('two retries by default; per client and per call', function (): void {
    $fake = new Fake(array_fill(0, 3, Fake::problem(503, 'pool_exhausted')));
    throws(ServerException::class, fn () => $fake->nowgate->payments->retrieve('pay_1'));
    same(3, count($fake->sent));
    $none = new Fake([Fake::problem(503, 'pool_exhausted')], ['max_retries' => 0]);
    throws(ServerException::class, fn () => $none->nowgate->payments->retrieve('pay_1'));
    same(1, count($none->sent));
    $call = new Fake(array_fill(0, 5, Fake::problem(503, 'pool_exhausted')));
    throws(ServerException::class, fn () => $call->nowgate->payments->retrieve('pay_1', ['max_retries' => 4]));
    same(5, count($call->sent));
});

test('backoff doubles from half a second up to 8 seconds, each a random fraction of that', function (): void {
    $fake = new Fake(array_fill(0, 7, Fake::refused()), ['max_retries' => 6]);
    throws(ConnectionException::class, fn () => $fake->nowgate->payments->retrieve('pay_1'));
    same([0.5, 1.0, 2.0, 4.0, 8.0, 8.0], $fake->waits);
    $half = new Fake([Fake::refused(), Fake::json(200, Fake::PAYMENT)], [], 0.5);
    $half->nowgate->payments->retrieve('pay_1');
    same([0.25], $half->waits);
});

test('withResponse gives the status, the headers, the request ID and the idempotency facts', function (): void {
    $fake = new Fake([Fake::json(200, Fake::PAYMENT, ['X-Request-Id' => 'req_1', 'Idempotent-Replayed' => 'true']), Fake::json(200, Fake::PAYMENT)]);
    $response = $fake->nowgate->withResponse()->payments->create(CREATE, ['idempotency_key' => 'order-1']);
    same(Fake::PAYMENT, $response->data);
    same(200, $response->status);
    same('req_1', $response->headers['x-request-id']);
    same('req_1', $response->requestId);
    same('order-1', $response->idempotencyKey);
    same(true, $response->idempotentReplayed);
    $get = $fake->nowgate->withResponse()->payments->retrieve('pay_1');
    same(null, $get->idempotencyKey);
    same(false, $get->idempotentReplayed);
    same(null, $get->requestId);
});
