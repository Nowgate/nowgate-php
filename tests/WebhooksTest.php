<?php

declare(strict_types=1);

// Webhook verification gives the engine's answers: the 21 published vectors, and the edge
// cases the engine's own tests pin (api-docs-and-sdks.md section 1.7).

namespace Nowgate\Tests;

use Nowgate\Exception\NowgateException;
use Nowgate\Exception\SignatureVerificationException;
use Nowgate\Webhook;

/** @return array{tolerance_seconds: int, vectors: list<array{name: string, secrets: list<string>, body: string, header: string, now: int, valid: bool, error?: string}>} */
function vectors(string $path): array
{
    /** @var array{tolerance_seconds: int, vectors: list<array{name: string, secrets: list<string>, body: string, header: string, now: int, valid: bool, error?: string}>} */
    return json_decode((string) file_get_contents(Fake::fixture($path)), true, 512, JSON_THROW_ON_ERROR);
}

function outcome(\Closure $run): string
{
    try {
        $run();
    } catch (SignatureVerificationException $e) {
        return $e->reason;
    }
    return 'valid';
}

/** @return array{name: string, secrets: list<string>, body: string, header: string, now: int, valid: bool, error?: string} */
function valid(): array
{
    $first = vectors('api/webhooks/signature-vectors.json')['vectors'][0];
    ok($first['valid'], 'the first published vector must be valid');
    return $first;
}

foreach (['published vectors' => 'api/webhooks/signature-vectors.json', 'edge cases' => 'sdks/testdata/signature-edge-cases.json'] as $name => $path) {
    test("verifySignature gives the engine's answer on every one of the {$name}", function () use ($name, $path): void {
        $file = vectors($path);
        ok(count($file['vectors']) >= ($name === 'published vectors' ? 21 : 20), 'every vector');
        foreach ($file['vectors'] as $v) {
            $got = outcome(fn () => Webhook::verifySignature($v['body'], $v['header'], $v['secrets'], $file['tolerance_seconds'], $v['now']));
            same($v['valid'] ? 'valid' : ($v['error'] ?? ''), $got, $v['name']);
        }
    });

    test("constructEvent gives the same answers on the {$name}", function () use ($path): void {
        foreach (vectors($path)['vectors'] as $v) {
            $at = new \DateTimeImmutable('@' . $v['now']);
            try {
                $got = outcome(fn () => Webhook::constructEvent($v['body'], $v['header'], $v['secrets'], Webhook::DEFAULT_TOLERANCE, $at));
            } catch (NowgateException) {
                $got = 'valid, not an event';
            }
            $event = json_decode($v['body'], true);
            $want = $v['valid'] ? (is_array($event) ? 'valid' : 'valid, not an event') : ($v['error'] ?? '');
            same($want, $got, $v['name']);
        }
    });
}

test('constructEvent returns the event', function (): void {
    $v = valid();
    $event = Webhook::constructEvent($v['body'], $v['header'], $v['secrets'][0], Webhook::DEFAULT_TOLERANCE, $v['now']);
    same('event', $event['object']);
    same('payment.finished', $event['type']);
    same('payment', $event['data']['object']['object']);
});

test('the header may be a list, or missing', function (): void {
    $v = valid();
    [$t, $v1] = explode(',', $v['header']);
    Webhook::verifySignature($v['body'], [$t, $v1], $v['secrets'], Webhook::DEFAULT_TOLERANCE, $v['now']);
    same('malformed', outcome(fn () => Webhook::verifySignature($v['body'], null, $v['secrets'], Webhook::DEFAULT_TOLERANCE, $v['now'])));
});

test("the tolerance and the time are the caller's to set", function (): void {
    $v = valid();
    preg_match('/^t=(\d+),/', $v['header'], $m);
    $signedAt = (int) ($m[1] ?? 0);
    same('timestamp', outcome(fn () => Webhook::verifySignature($v['body'], $v['header'], $v['secrets'], Webhook::DEFAULT_TOLERANCE, $signedAt + 3600)));
    Webhook::verifySignature($v['body'], $v['header'], $v['secrets'], 3600, $signedAt + 3600);
    throws(SignatureVerificationException::class, fn () => Webhook::verifySignature($v['body'], $v['header'], $v['secrets'], Webhook::DEFAULT_TOLERANCE, $signedAt + 301));
    same('timestamp', outcome(fn () => Webhook::verifySignature($v['body'], $v['header'], $v['secrets'])), 'now is the clock by default, long after the vector');
});

test("arguments the verifier can't use are InvalidArgumentExceptions, not verification failures", function (): void {
    $v = valid();
    foreach (['', [], [''], [$v['secrets'][0], 7]] as $secrets) {
        /** @phpstan-ignore argument.type (what a caller without PHPStan could pass) */
        throws(\InvalidArgumentException::class, fn () => Webhook::verifySignature($v['body'], $v['header'], $secrets, Webhook::DEFAULT_TOLERANCE, $v['now']));
    }
    throws(\InvalidArgumentException::class, fn () => Webhook::verifySignature($v['body'], $v['header'], $v['secrets'], Webhook::DEFAULT_TOLERANCE, NAN), '/now must be/');
    throws(\InvalidArgumentException::class, fn () => Webhook::verifySignature($v['body'], $v['header'], $v['secrets'], -1, $v['now']), '/tolerance/');
    /** @phpstan-ignore argument.type (what a caller without PHPStan could pass) */
    throws(\InvalidArgumentException::class, fn () => Webhook::verifySignature($v['body'], [7], $v['secrets'], Webhook::DEFAULT_TOLERANCE, $v['now']), '/header must be/');
});

test("the failure says why, with the vectors' names", function (): void {
    $v = valid();
    $e = throws(SignatureVerificationException::class, fn () => Webhook::verifySignature($v['body'] . ' ', $v['header'], $v['secrets'], Webhook::DEFAULT_TOLERANCE, $v['now']));
    same('mismatch', $e->reason);
    same('mismatch: no signature matches the body with any of the secrets', $e->getMessage());
});
