<?php

declare(strict_types=1);

namespace Nowgate;

/**
 * The answer alongside its data, from a call through $nowgate->withResponse().
 *
 * @template T
 */
final class ApiResponse
{
    /**
     * @param T $data the data, as the API sent it
     * @param array<string, string> $headers the answer's headers, by lowercase name
     * @param ?string $requestId the request's ID (X-Request-Id), to quote to support
     * @param ?string $idempotencyKey the idempotency key a POST was sent with
     * @param bool $idempotentReplayed the API replayed the answer it stored for this key: the call had already been made
     */
    public function __construct(
        public readonly mixed $data,
        public readonly int $status,
        public readonly array $headers,
        public readonly ?string $requestId,
        public readonly ?string $idempotencyKey,
        public readonly bool $idempotentReplayed,
    ) {
    }
}
