<?php

declare(strict_types=1);

namespace Nowgate\Internal;

use Nowgate\ApiResponse;
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
use Nowgate\Generated\Types;
use Nowgate\Transport\Request;
use Nowgate\Transport\Response;
use Nowgate\Transport\Transport;
use Nowgate\Transport\TransportException;

/**
 * Sends the services' requests: retries, idempotency keys, amounts, pages and problems
 * (api-docs-and-sdks.md sections 1 and 2; ADR 0032). It holds the key, which nothing shows.
 * Internal: the client makes it.
 *
 * @internal
 * @phpstan-type RequestOptions array{idempotency_key?: string, timeout?: int|float, max_retries?: int}
 */
final class Requester
{
    private const BASE_URLS = [
        'live' => 'https://api.nowgate.io/v1',
        'sandbox' => 'https://sandbox-api.nowgate.io/v1',
    ];
    private const MAX_RETRY_AFTER = 60;
    private const FIRST_BACKOFF = 0.5;
    private const MAX_BACKOFF = 8.0;
    private const RETRIED_STATUSES = [429, 500, 502, 503, 504];
    private const STATUS_CLASSES = [
        400 => InvalidRequestException::class,
        401 => AuthenticationException::class,
        403 => PermissionException::class,
        404 => NotFoundException::class,
        405 => InvalidRequestException::class,
        409 => ConflictException::class,
        413 => InvalidRequestException::class,
        414 => InvalidRequestException::class,
        415 => InvalidRequestException::class,
        422 => InvalidRequestException::class,
        429 => RateLimitException::class,
    ];

    public readonly string $baseUrl;
    /** The key as the dashboard shows it: its prefix and last four characters. */
    public readonly string $keyHint;
    private readonly string $authorization;
    private readonly string $userAgent;
    /** @var \Closure(float): void the waits between attempts: the SDK's own tests replace them */
    public \Closure $sleep;
    /** @var \Closure(): float */
    public \Closure $random;

    public function __construct(
        #[\SensitiveParameter] string $apiKey,
        ?string $baseUrl,
        public readonly float $timeout,
        public readonly int $maxRetries,
        private readonly Transport $transport,
        ?string $appInfo,
    ) {
        if (preg_match('/\Angk_([a-z]+)_([\x21-\x7e]+)\z/', $apiKey, $match) !== 1) {
            throw new \InvalidArgumentException('api_key must be a Nowgate API key: ngk_live_... or ngk_sandbox_...');
        }
        [, $env, $secret] = $match;
        $this->keyHint = "ngk_{$env}_" . (strlen($secret) >= 16 ? substr($secret, 0, 6) . '…' . substr($secret, -4) : '…');
        $base = $baseUrl ?? (self::BASE_URLS[$env] ?? null);
        if ($base === null) {
            throw new \InvalidArgumentException("A {$env} key needs a base_url: only live and sandbox keys have one");
        }
        $this->baseUrl = self::checkBaseUrl($base, $env);
        self::checkTimeout($timeout);
        self::checkRetries($maxRetries);
        $this->authorization = 'Bearer ' . $apiKey;
        $agent = 'nowgate-php/' . Types::VERSION . ' php/' . PHP_VERSION;
        if ($appInfo !== null) {
            if (preg_match('/\A[\x21-\x7e]+( [\x21-\x7e]+)*\z/', $appInfo) !== 1) {
                throw new \InvalidArgumentException('app_info must be printable ASCII, such as my-shop/1.2');
            }
            $agent .= ' ' . $appInfo;
        }
        $this->userAgent = $agent;
        $this->sleep = static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
        $this->random = static fn (): float => mt_rand() / mt_getrandmax();
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['apiKey' => $this->keyHint, 'baseUrl' => $this->baseUrl, 'timeout' => $this->timeout, 'maxRetries' => $this->maxRetries];
    }

    /**
     * One call: its attempts, until an answer that isn't retried.
     *
     * @param array<string, string> $pathParams
     * @param array<array-key, mixed>|null $query
     * @param array<array-key, mixed>|null $body
     * @param list<string> $amounts JSON pointers to the amounts in the body or the query; a "*" segment is every item of a list
     * @param RequestOptions $options
     * @return ApiResponse<mixed>
     */
    public function request(
        string $method,
        string $path,
        array $pathParams = [],
        ?array $query = null,
        ?array $body = null,
        array $amounts = [],
        array $options = [],
    ): ApiResponse {
        $options = self::checkOptions($options);
        if ($body !== null) {
            $body = self::withAmounts($body, $amounts);
        }
        if ($query !== null) {
            $query = self::withAmounts($query, $amounts);
        }
        $url = $this->url($path, $pathParams, $query ?? []);
        $timeout = (float) ($options['timeout'] ?? $this->timeout);
        $retries = $options['max_retries'] ?? $this->maxRetries;
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => $this->authorization,
            'User-Agent' => $this->userAgent,
        ];
        $key = null;
        if ($method === 'POST') {
            $key = $options['idempotency_key'] ?? self::uuid();
            if ($key === '') {
                throw new \InvalidArgumentException('idempotency_key must be a non-empty string, such as a UUID');
            }
            $headers['Idempotency-Key'] = $key;
        }
        $data = null;
        if ($body !== null) {
            $data = $body === [] ? '{}' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $headers['Content-Type'] = 'application/json';
        }
        $attempt = new Request($method, $url, $headers, $data, $timeout);

        for ($tries = 0; ; $tries++) {
            try {
                $answer = $this->transport->send($attempt);
            } catch (TransportException $e) {
                if ($tries >= $retries) {
                    $why = $e->timedOut
                        ? sprintf("Nowgate didn't answer within %s s", $timeout)
                        : "Couldn't reach Nowgate at {$this->baseUrl}: {$e->getMessage()}";
                    throw new ConnectionException($why, 0, $e);
                }
                ($this->sleep)($this->backoff($tries));
                continue;
            }
            if ($answer->status >= 200 && $answer->status < 300) {
                return new ApiResponse(
                    self::decode($answer),
                    $answer->status,
                    $answer->headers,
                    $answer->headers['x-request-id'] ?? null,
                    $key,
                    ($answer->headers['idempotent-replayed'] ?? null) === 'true',
                );
            }
            $error = self::apiException($answer);
            if ($tries >= $retries || !self::retryable($error)) {
                throw $error;
            }
            $wait = $this->backoff($tries);
            if ($error->retryAfter !== null) {
                if ($error->retryAfter > self::MAX_RETRY_AFTER) {
                    throw $error;
                }
                $wait = (float) $error->retryAfter;
            }
            ($this->sleep)($wait);
        }
    }

    /**
     * Every item of a paged list, newest first, following starting_after while has_more.
     *
     * @param array<array-key, mixed> $query
     * @param RequestOptions $options
     * @return \Generator<int, mixed, mixed, void>
     */
    public function paginate(string $path, array $query, array $options = []): \Generator
    {
        if (isset($query['ending_before'])) {
            throw new \InvalidArgumentException('listAll walks from the newest item down: give starting_after, not ending_before');
        }
        $query['limit'] ??= 100;
        for (;;) {
            $page = $this->request('GET', $path, [], $query, null, [], $options)->data;
            if (!is_array($page) || !is_array($page['data'] ?? null)) {
                throw new NowgateException("Nowgate answered a list that isn't a page");
            }
            $items = array_values($page['data']);
            yield from $items;
            $last = end($items);
            if (($page['has_more'] ?? false) !== true || !is_array($last)) {
                return;
            }
            $query['starting_after'] = $last['id'] ?? null;
        }
    }

    private function backoff(int $tries): float
    {
        return min(self::MAX_BACKOFF, self::FIRST_BACKOFF * 2 ** min($tries, 16)) * ($this->random)();
    }

    /**
     * @param array<string, string> $pathParams
     * @param array<array-key, mixed> $query
     */
    private function url(string $path, array $pathParams, array $query): string
    {
        $url = $this->baseUrl . preg_replace_callback('/\{([^}]+)\}/', static function (array $m) use ($pathParams): string {
            $value = $pathParams[$m[1]] ?? '';
            if ($value === '') {
                throw new \InvalidArgumentException("{$m[1]} must be a non-empty string: an object's ID");
            }
            return rawurlencode($value);
        }, $path);
        $pairs = [];
        foreach ($query as $name => $value) {
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_int($value)) {
                $value = (string) $value;
            } elseif (!is_string($value)) {
                throw new \InvalidArgumentException("The query parameter {$name} must be a string, an int or a bool");
            }
            $pairs[] = rawurlencode((string) $name) . '=' . rawurlencode($value);
        }
        return $pairs === [] ? $url : $url . '?' . implode('&', $pairs);
    }

    /**
     * @param array<array-key, mixed> $options
     * @return RequestOptions
     */
    private static function checkOptions(array $options): array
    {
        foreach ($options as $name => $value) {
            match ($name) {
                'idempotency_key' => is_string($value) ?: throw new \InvalidArgumentException('idempotency_key must be a non-empty string, such as a UUID'),
                'timeout' => self::checkTimeout($value),
                'max_retries' => self::checkRetries($value),
                default => throw new \InvalidArgumentException("Unknown option {$name}: give idempotency_key, timeout or max_retries"),
            };
        }
        /** @var RequestOptions $options */
        return $options;
    }

    private static function checkTimeout(mixed $timeout): bool
    {
        if ((!is_int($timeout) && !is_float($timeout)) || !is_finite((float) $timeout) || $timeout <= 0) {
            throw new \InvalidArgumentException('timeout must be a positive number of seconds');
        }
        return true;
    }

    private static function checkRetries(mixed $retries): bool
    {
        if (!is_int($retries) || $retries < 0) {
            throw new \InvalidArgumentException('max_retries must be a whole number, 0 or more');
        }
        return true;
    }

    private static function checkBaseUrl(string $base, string $env): string
    {
        $url = parse_url($base);
        $scheme = is_array($url) ? ($url['scheme'] ?? '') : '';
        if (!is_array($url) || !in_array($scheme, ['https', 'http'], true) || ($url['host'] ?? '') === '') {
            throw new \InvalidArgumentException("base_url must be an https URL: {$base}");
        }
        if ($scheme === 'http' && in_array($env, ['live', 'sandbox'], true)) {
            throw new \InvalidArgumentException("A {$env} key is sent only over https");
        }
        if (isset($url['query']) || isset($url['fragment']) || str_contains($base, '?') || str_contains($base, '#')) {
            throw new \InvalidArgumentException("base_url can't have a query or a fragment");
        }
        return rtrim($base, '/');
    }

    /**
     * The payload with its amounts as the API reads them: ints as decimal strings, floats and
     * bools refused.
     *
     * @param array<array-key, mixed> $payload
     * @param list<string> $pointers
     * @return array<array-key, mixed>
     */
    private static function withAmounts(array $payload, array $pointers): array
    {
        foreach ($pointers as $pointer) {
            $payload = self::visit($payload, array_slice(explode('/', $pointer), 1), '');
            assert(is_array($payload));
        }
        return $payload;
    }

    /** @param list<string> $path */
    private static function visit(mixed $value, array $path, string $at): mixed
    {
        if ($path === []) {
            if (is_float($value) || is_bool($value)) {
                $kind = is_float($value) ? 'float' : 'bool';
                throw new \InvalidArgumentException("{$at} is a {$kind}: amounts are decimal strings, such as \"12.5\"");
            }
            return is_int($value) ? (string) $value : $value;
        }
        if (!is_array($value)) {
            return $value;
        }
        $segment = $path[0];
        $rest = array_slice($path, 1);
        if ($segment === '*') {
            if (array_is_list($value)) {
                foreach ($value as $i => $item) {
                    $value[$i] = self::visit($item, $rest, "{$at}/{$i}");
                }
            }
            return $value;
        }
        $key = str_replace(['~1', '~0'], ['/', '~'], $segment);
        if (array_key_exists($key, $value)) {
            $value[$key] = self::visit($value[$key], $rest, "{$at}/{$segment}");
        }
        return $value;
    }

    private static function decode(Response $answer): mixed
    {
        if ($answer->body === '') {
            return null;
        }
        try {
            return json_decode($answer->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new NowgateException("Nowgate answered {$answer->status} with a body that isn't JSON", 0, $e);
        }
    }

    /** The exception for an answer that isn't a success. */
    public static function apiException(Response $answer, ?float $now = null): ApiException
    {
        $status = $answer->status;
        $class = self::STATUS_CLASSES[$status] ?? ($status >= 500 ? ServerException::class : ApiException::class);
        $retryAfter = self::retryAfter($answer->headers['retry-after'] ?? null, $now ?? microtime(true));
        $requestId = $answer->headers['x-request-id'] ?? null;
        $problem = self::problem($answer);
        if ($problem !== null) {
            $text = static fn (string $key): ?string => is_string($problem[$key] ?? null) ? $problem[$key] : null;
            /** @var list<array{pointer: string, code: string, detail: string}> $errors */
            $errors = is_array($problem['errors'] ?? null) ? array_values($problem['errors']) : [];
            return new $class(
                $status,
                (string) $text('code'),
                (string) $text('title'),
                $text('detail'),
                $text('type'),
                $text('instance'),
                $text('request_id') ?? $requestId,
                $retryAfter,
                $errors,
                $answer->headers,
            );
        }
        $detail = self::firstBytes($answer->body, 500);
        return new $class(
            $status,
            "http_{$status}",
            $answer->reason !== '' ? $answer->reason : "HTTP {$status}",
            $detail !== '' ? $detail : null,
            null,
            null,
            $requestId,
            $retryAfter,
            [],
            $answer->headers,
        );
    }

    /** @return array<array-key, mixed>|null RFC 9457 problem JSON with a code and a title, or null */
    private static function problem(Response $answer): ?array
    {
        if (preg_match('/\bjson\b/i', $answer->headers['content-type'] ?? '') !== 1) {
            return null;
        }
        $value = json_decode($answer->body, true);
        if (!is_array($value) || array_is_list($value) || !is_string($value['code'] ?? null) || !is_string($value['title'] ?? null)) {
            return null;
        }
        return $value;
    }

    /** Retry-After, in seconds or as an HTTP date, as whole seconds from now. */
    public static function retryAfter(?string $value, float $now): ?int
    {
        if ($value === null) {
            return null;
        }
        $text = trim($value);
        if (preg_match('/\A[0-9]+\z/', $text) === 1) {
            return (int) $text;
        }
        $at = \DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $text, new \DateTimeZone('UTC'));
        if ($at === false) {
            return null;
        }
        return max(0, (int) ceil($at->getTimestamp() - $now));
    }

    /** The text of at most the first $n bytes, whole UTF-8 characters only. */
    private static function firstBytes(string $body, int $n): string
    {
        if (strlen($body) <= $n) {
            return $body;
        }
        $end = $n;
        while ($end > 0 && (ord($body[$end]) & 0xC0) === 0x80) {
            $end--; // not inside a character
        }
        return substr($body, 0, $end);
    }

    private static function retryable(ApiException $error): bool
    {
        if ($error->status === 409) {
            return $error->errorCode === 'idempotency_key_in_progress';
        }
        return in_array($error->status, self::RETRIED_STATUSES, true);
    }

    /** A random version 4 UUID. */
    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
