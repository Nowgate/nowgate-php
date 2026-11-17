# Nowgate for PHP

Nowgate's SDK for its merchant API: payments, payment links, payouts and refunds, with webhook
verification. It needs PHP 8.1 or later with `ext-curl` and `ext-json`, and no other package. Its
results are arrays, described for PHPStan as array shapes.

```bash
composer require nowgate/nowgate
```

## Create a payment

```php
use Nowgate\Client;

$nowgate = new Client(['api_key' => getenv('NOWGATE_API_KEY')]);

$payment = $nowgate->payments->create(
    ['network' => 'tron', 'asset' => 'USDT', 'amount' => '25.00', 'order_id' => '1042'],
    ['idempotency_key' => 'order-1042'],
);
echo $payment['id'], ' ', $payment['deposit']['address'], ' ', $payment['amount'], "\n";
```

The key picks the API: an `ngk_sandbox_…` key reaches the sandbox and an `ngk_live_…` key the
live API. Amounts are decimal strings, never floats. A failed call is retried safely with its
idempotency key, and lists page with `listAll`.

## Verify a webhook

```php
use Nowgate\Exception\SignatureVerificationException;
use Nowgate\Webhook;

$payload = (string) file_get_contents('php://input'); // the raw body, exactly as received
$header = $_SERVER['HTTP_NOWGATE_SIGNATURE'] ?? null;
try {
    $event = Webhook::constructEvent($payload, $header, getenv('NOWGATE_WEBHOOK_SECRET'));
} catch (SignatureVerificationException $e) {
    http_response_code(400);
    return;
}
```

## Documentation

The guides and the API reference are at [docs.nowgate.io](https://docs.nowgate.io), and this
SDK's page is [docs.nowgate.io/sdks/php](https://docs.nowgate.io/sdks/php).
[CHANGELOG.md](CHANGELOG.md) lists its releases, each with the API version it was made for.
