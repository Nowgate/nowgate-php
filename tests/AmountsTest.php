<?php

declare(strict_types=1);

// Amounts are decimal strings: floats are refused before sending, ints are sent as decimal
// strings, and the helpers convert and compare exactly (api-docs-and-sdks.md section 1.6).

namespace Nowgate\Tests;

use Nowgate\Amounts;

test('a float or bool amount is refused before sending, naming the field', function (): void {
    $fake = new Fake([]);
    $n = $fake->nowgate;
    // Each passes what a caller without PHPStan could.
    $cases = [
        '/amount is a float' => fn () => $n->payments->create(['network' => 'tron', 'asset' => 'USDT', 'amount' => 25.0]), // @phpstan-ignore argument.type
        '/price_amount is a bool' => fn () => $n->payments->create(['network' => 'tron', 'asset' => 'USDT', 'price_amount' => true]), // @phpstan-ignore argument.type
        '/items/1/amount is a float' => fn () => $n->payoutBatches->create(['items' => [['network' => 'tron', 'asset' => 'USDT', 'amount' => '1'], ['network' => 'tron', 'asset' => 'USDT', 'amount' => 2.0]]]), // @phpstan-ignore argument.type
        '/open_amount/presets/1 is a float' => fn () => $n->paymentLinks->create(['title' => 'Tips', 'open_amount' => ['currency' => 'USD', 'presets' => ['5', 10.0]]]), // @phpstan-ignore argument.type
        '/price_amount is a float' => fn () => $n->rates->estimate(['network' => 'tron', 'asset' => 'USDT', 'price_amount' => 12.5, 'price_currency' => 'USD']), // @phpstan-ignore argument.type
        '/rules/0/min_amount is a float' => fn () => $n->forwarding->update(['rules' => [['network' => 'tron', 'asset' => 'USDT', 'destination' => 'dst_1', 'mode' => 'daily', 'min_amount' => 0.1]]]), // @phpstan-ignore argument.type
    ];
    foreach ($cases as $message => $call) {
        throws(\InvalidArgumentException::class, $call, '/^' . preg_quote($message, '/') . ': amounts are decimal strings, such as "12\.5"$/');
    }
    same([], $fake->sent);
});

test('ints are sent as decimal strings, and strings as given: their format is the API\'s to check', function (): void {
    $fake = new Fake([Fake::json(201, Fake::PAYMENT), Fake::json(201, Fake::PAYMENT), Fake::json(200, [])]);
    $fake->nowgate->payments->create(['network' => 'tron', 'asset' => 'USDT', 'amount' => 25]);
    $fake->nowgate->payments->create(['network' => 'tron', 'asset' => 'USDT', 'amount' => '25.00', 'metadata' => ['qty' => '2']]);
    same('25', ((array) $fake->body(0))['amount']);
    same(['network' => 'tron', 'asset' => 'USDT', 'amount' => '25.00', 'metadata' => ['qty' => '2']], $fake->body(1));
    $fake->nowgate->rates->estimate(['network' => 'tron', 'asset' => 'USDT', 'price_amount' => 5, 'price_currency' => 'USD']);
    ok(str_contains($fake->sent[2]->url, 'price_amount=5&'), $fake->sent[2]->url);
});

test('toUnits gives the smallest units, exactly, as digits', function (): void {
    same('1500000', Amounts::toUnits('1.5', 6));
    same('0', Amounts::toUnits('0', 18));
    same('12345678901234567890123456789012345678', Amounts::toUnits('12345678901234567890.123456789012345678', 18));
    same('1500000', Amounts::toUnits('1.500000000', 6));
    same('7', Amounts::toUnits('7', 0));
    throws(\InvalidArgumentException::class, fn () => Amounts::toUnits('1.0000001', 6), "/more decimals than the asset's 6/");
    foreach (['', '1.', '.5', '01', '-1', '1e3', ' 1', '1,5', "1\n"] as $bad) {
        throws(\InvalidArgumentException::class, fn () => Amounts::toUnits($bad, 6));
    }
    foreach ([-1, 256] as $decimals) {
        throws(\InvalidArgumentException::class, fn () => Amounts::toUnits('1', $decimals));
    }
});

test("fromUnits gives the API's canonical form", function (): void {
    same('1.5', Amounts::fromUnits(1500000, 6));
    same('0', Amounts::fromUnits(0, 6));
    same('0.000000000000000001', Amounts::fromUnits('1', 18));
    same('1', Amounts::fromUnits('1000000', 6));
    same('12345678901234567890.123456789012345678', Amounts::fromUnits('12345678901234567890123456789012345678', 18));
    same('7', Amounts::fromUnits(7, 0));
    throws(\InvalidArgumentException::class, fn () => Amounts::fromUnits(-1, 6), '/negative/');
    foreach (['1.5', '-1', ''] as $bad) {
        throws(\InvalidArgumentException::class, fn () => Amounts::fromUnits($bad, 6));
    }
});

test('compare orders amounts of any precision', function (): void {
    same(0, Amounts::compare('1.5', '1.50'));
    same(1, Amounts::compare('1.5', '1.49999999999999999999'));
    same(-1, Amounts::compare('9', '10'));
    same(-1, Amounts::compare('0', '0.000000000000000001'));
    same(1, Amounts::compare('100', '99.999'));
    throws(\InvalidArgumentException::class, fn () => Amounts::compare('1', 'x'));
});
