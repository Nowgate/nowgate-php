<?php

declare(strict_types=1);

namespace Nowgate;

use Nowgate\Generated\Services;
use Nowgate\Generated\ServicesWithResponse;
use Nowgate\Internal\Requester;
use Nowgate\Transport\CurlTransport;
use Nowgate\Transport\Transport;

/**
 * The Nowgate API's client.
 *
 *     $nowgate = new Nowgate\Client(['api_key' => getenv('NOWGATE_API_KEY')]);
 *     $payment = $nowgate->payments->create(['network' => 'tron', 'asset' => 'USDT', 'amount' => '25']);
 *
 * The key's environment picks the API: https://api.nowgate.io/v1 for a live key and
 * https://sandbox-api.nowgate.io/v1 for a sandbox key. Other keys need a base_url.
 *
 * @phpstan-type ClientOptions array{api_key: string, base_url?: string, timeout?: int|float, max_retries?: int, transport?: Transport, app_info?: string}
 */
final class Client extends Services
{
    public const DEFAULT_TIMEOUT = 60.0;
    public const DEFAULT_MAX_RETRIES = 2;

    /** The API's base URL, without a trailing slash. */
    public readonly string $baseUrl;
    /** Seconds each attempt may take. */
    public readonly float $timeout;
    /** How many times a failed attempt is retried. */
    public readonly int $maxRetries;
    private readonly Requester $client;
    private readonly ServicesWithResponse $withResponse;

    /** @param ClientOptions $options */
    public function __construct(#[\SensitiveParameter] array $options)
    {
        // The options' types are checked here too, for callers without PHPStan.
        foreach (array_keys($options) as $name) {
            /** @phpstan-ignore function.alreadyNarrowedType */
            if (!in_array($name, ['api_key', 'base_url', 'timeout', 'max_retries', 'transport', 'app_info'], true)) {
                throw new \InvalidArgumentException("Unknown option {$name}");
            }
        }
        $timeout = $options['timeout'] ?? self::DEFAULT_TIMEOUT;
        $maxRetries = $options['max_retries'] ?? self::DEFAULT_MAX_RETRIES;
        /** @phpstan-ignore function.alreadyNarrowedType, booleanAnd.alwaysFalse */
        if ((!is_int($timeout) && !is_float($timeout)) || !is_finite((float) $timeout) || $timeout <= 0) {
            throw new \InvalidArgumentException('timeout must be a positive number of seconds');
        }
        /** @phpstan-ignore function.alreadyNarrowedType */
        if (!is_int($maxRetries) || $maxRetries < 0) {
            throw new \InvalidArgumentException('max_retries must be a whole number, 0 or more');
        }
        $client = new Requester(
            $options['api_key'],
            $options['base_url'] ?? null,
            (float) $timeout,
            $maxRetries,
            $options['transport'] ?? new CurlTransport(),
            $options['app_info'] ?? null,
        );
        parent::__construct($client);
        $this->client = $client;
        $this->withResponse = new ServicesWithResponse($client);
        $this->baseUrl = $client->baseUrl;
        $this->timeout = $client->timeout;
        $this->maxRetries = $client->maxRetries;
    }

    /**
     * The same calls, answering with an ApiResponse: the data with the status, the headers,
     * the request's ID and the idempotency facts.
     */
    public function withResponse(): ServicesWithResponse
    {
        return $this->withResponse;
    }

    /**
     * The client's settings, with the key shown as the dashboard shows it.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return $this->client->__debugInfo();
    }
}
