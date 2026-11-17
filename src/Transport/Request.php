<?php

declare(strict_types=1);

namespace Nowgate\Transport;

/** One attempt of a call, as the client sends it. */
final class Request
{
    /**
     * @param array<string, string> $headers
     * @param float $timeout seconds the attempt may take
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly ?string $body,
        public readonly float $timeout,
    ) {
    }

    /**
     * The request, with the key hidden.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $headers = $this->headers;
        if (isset($headers['Authorization'])) {
            $headers['Authorization'] = 'Bearer …';
        }
        return ['method' => $this->method, 'url' => $this->url, 'headers' => $headers, 'timeout' => $this->timeout];
    }
}
