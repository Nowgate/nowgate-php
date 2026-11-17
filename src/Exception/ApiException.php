<?php

declare(strict_types=1);

namespace Nowgate\Exception;

/**
 * A problem the API answered with (RFC 9457 problem JSON), of its status's class, carrying the
 * whole problem. The problem's code is errorCode, since an exception's own code is a number.
 *
 * @phpstan-import-type FieldError from \Nowgate\Generated\Types
 */
class ApiException extends NowgateException
{
    /**
     * @param string $errorCode the problem's stable code, such as insufficient_balance; http_<status> for an answer that isn't a problem
     * @param ?string $type the code's page in the docs
     * @param ?string $requestId the request's ID, to quote to support
     * @param ?int $retryAfter the seconds the API asked to wait before trying again (Retry-After)
     * @param list<FieldError> $errors each field's problem, with its JSON pointer into the request: /amount
     * @param array<string, string> $headers the answer's headers, by lowercase name
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        public readonly string $title,
        public readonly ?string $detail = null,
        public readonly ?string $type = null,
        public readonly ?string $instance = null,
        public readonly ?string $requestId = null,
        public readonly ?int $retryAfter = null,
        public readonly array $errors = [],
        public readonly array $headers = [],
    ) {
        $page = $type !== null && $type !== '' ? " ({$type})" : '';
        $what = $detail !== null && $detail !== '' ? $detail : $title;
        parent::__construct("{$errorCode}: {$what}{$page}");
    }
}
