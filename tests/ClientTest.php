<?php

declare(strict_types=1);

// The client: keys and environments, what every request carries, bodies, paths and queries,
// and the key never shown (api-docs-and-sdks.md sections 1.2 and 1.3).

namespace Nowgate\Tests;

use Nowgate\Client;
use Nowgate\Exception\AuthenticationException;
use Nowgate\Exception\NowgateException;
use Nowgate\Generated\Types;
use Nowgate\Transport\Request;
use Nowgate\Transport\Response;

test("the base URL follows the key's environment", function (): void {
    same('https://api.nowgate.io/v1', (new Client(['api_key' => 'ngk_live_' . Fake::SECRET]))->baseUrl);
    same('https://sandbox-api.nowgate.io/v1', (new Client(['api_key' => Fake::KEY]))->baseUrl);
    same('https://sandbox.example.com/v1', (new Client(['api_key' => Fake::KEY, 'base_url' => 'https://sandbox.example.com/v1/']))->baseUrl);
});

test('other environments need a base URL, and live keys go only over https', function (): void {
    throws(\InvalidArgumentException::class, fn () => new Client(['api_key' => 'ngk_test_' . Fake::SECRET]), '/A test key needs a base_url/');
    same('http://127.0.0.1:8080/v1', (new Client(['api_key' => 'ngk_test_' . Fake::SECRET, 'base_url' => 'http://127.0.0.1:8080/v1']))->baseUrl);
    foreach (['live', 'sandbox'] as $env) {
        throws(\InvalidArgumentException::class, fn () => new Client(['api_key' => "ngk_{$env}_" . Fake::SECRET, 'base_url' => 'http://api.example.com/v1']), "/A {$env} key is sent only over https/");
    }
    foreach (['ftp://example.com/v1', 'example.com', 'https://x/v1?a=1', 'https://x/v1#f'] as $base) {
        throws(\InvalidArgumentException::class, fn () => new Client(['api_key' => Fake::KEY, 'base_url' => $base]));
    }
});

test("a key that isn't a Nowgate key is refused before any request, without showing it", function (): void {
    foreach (['sk_live_' . Fake::SECRET, 'ngk_sandbox_', 'ngk_Live_' . Fake::SECRET, Fake::KEY . ' '] as $key) {
        $e = throws(\InvalidArgumentException::class, fn () => new Client(['api_key' => $key]));
        ok(!str_contains($e->getMessage(), Fake::SECRET), 'the key is shown');
        ok(!str_contains($e->getTraceAsString(), Fake::SECRET) || PHP_VERSION_ID < 80200, 'the key is in the trace');
    }
});

test('options are checked when the client is made', function (): void {
    foreach ([['timeout' => 0], ['timeout' => -1], ['timeout' => INF], ['timeout' => '5'], ['max_retries' => -1], ['max_retries' => 1.5], ['app_info' => "my shop\n"], ['app_info' => 'café/1'], ['retries' => 2]] as $options) {
        /** @phpstan-ignore argument.type (what a caller without PHPStan could pass) */
        throws(\InvalidArgumentException::class, fn () => new Client(['api_key' => Fake::KEY] + $options), null);
    }
});

test('every request carries the key, the User-Agent and Accept, public operations too', function (): void {
    $fake = new Fake([Fake::json(200, ['object' => 'status'])], ['app_info' => 'my-shop/1.2']);
    $fake->nowgate->status->retrieve();
    $sent = $fake->sent[0];
    same('GET', $sent->method);
    same('https://sandbox-api.nowgate.io/v1/status', $sent->url);
    same('Bearer ' . Fake::KEY, $sent->headers['Authorization']);
    same('application/json', $sent->headers['Accept']);
    same('nowgate-php/' . Types::VERSION . ' php/' . PHP_VERSION . ' my-shop/1.2', $sent->headers['User-Agent']);
    same(null, $sent->body);
    ok(!isset($sent->headers['Content-Type']), 'no Content-Type without a body');
    same(60.0, $sent->timeout);
});

test('a body is JSON, path IDs are encoded, and query parameters are sent as given', function (): void {
    $fake = new Fake([Fake::json(201, Fake::PAYMENT), Fake::json(200, Fake::PAYMENT), Fake::json(200, ['data' => []])]);
    $fake->nowgate->payments->create(CREATE + ['metadata' => ['order' => '1042 ü/x']]);
    same('application/json', $fake->sent[0]->headers['Content-Type']);
    same('{"network":"tron","asset":"USDT","amount":"25","metadata":{"order":"1042 ü/x"}}', $fake->sent[0]->body);
    $fake->nowgate->payments->retrieve('pay_1/../x?y');
    same('https://sandbox-api.nowgate.io/v1/payments/pay_1%2F..%2Fx%3Fy', $fake->sent[1]->url);
    $fake->nowgate->payments->list(['limit' => 2, 'status' => 'finished', 'order_id' => 'a&b=c']);
    same('https://sandbox-api.nowgate.io/v1/payments?limit=2&status=finished&order_id=a%26b%3Dc', $fake->sent[2]->url);
});

test('a body without fields is sent as an empty object; a POST without a body has none', function (): void {
    $fake = new Fake([Fake::json(201, ['id' => 'rf_1']), Fake::json(200, ['id' => 'po_1'])]);
    $fake->nowgate->refunds->create('pay_1');
    same('{}', $fake->sent[0]->body);
    $fake->nowgate->payouts->cancel('po_1');
    same(null, $fake->sent[1]->body);
    ok(isset($fake->sent[1]->headers['Idempotency-Key']), 'a POST has a key');
});

test('an empty ID is refused before sending', function (): void {
    $fake = new Fake([]);
    throws(\InvalidArgumentException::class, fn () => $fake->nowgate->payments->retrieve(''), '/id must be a non-empty string/');
    same([], $fake->sent);
});

test('the key is never shown: not by var_dump, print_r, var_export or exceptions', function (): void {
    $fake = new Fake([Fake::problem(401, 'invalid_api_key')]);
    ob_start();
    var_dump($fake->nowgate);
    var_dump($fake->nowgate->payments);
    var_dump($fake->nowgate->withResponse());
    print_r($fake->nowgate);
    $shown = (string) ob_get_clean();
    $e = throws(AuthenticationException::class, fn () => $fake->nowgate->payments->retrieve('pay_1'));
    $shown .= $e->getMessage() . print_r($e->headers, true);
    ok(!str_contains($shown, Fake::SECRET), 'the key is shown');
    ok(str_contains($shown, 'ngk_sandbox_012345…9abc'), 'the hint is shown');
});

test("a transport of the caller's own is used for every request", function (): void {
    $fake = new Fake([Fake::json(200, Fake::PAYMENT), Fake::json(200, Fake::PAYMENT)]);
    $fake->nowgate->payments->retrieve('pay_1');
    $fake->nowgate->withResponse()->payments->retrieve('pay_2');
    same(2, count($fake->sent));
});

test("a success that isn't JSON is an exception, not retried", function (): void {
    $fake = new Fake([new Response(200, ['Content-Type' => 'text/html'], '<html>')]);
    throws(NowgateException::class, fn () => $fake->nowgate->payments->retrieve('pay_1'), "/Nowgate answered 200 with a body that isn't JSON/");
    same(1, count($fake->sent));
});
