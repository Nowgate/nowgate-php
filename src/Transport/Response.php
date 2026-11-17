<?php

declare(strict_types=1);

namespace Nowgate\Transport;

/** An answer, whatever its status. A transport that gets no answer throws a TransportException. */
final class Response
{
    /** @var array<string, string> the headers, by lowercase name */
    public readonly array $headers;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        array $headers = [],
        public readonly string $body = '',
        public readonly string $reason = '',
    ) {
        $lower = [];
        foreach ($headers as $name => $value) {
            $key = strtolower($name);
            $lower[$key] = isset($lower[$key]) ? $lower[$key] . ', ' . $value : $value;
        }
        $this->headers = $lower;
    }
}
