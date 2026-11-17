<?php

declare(strict_types=1);

namespace Nowgate\Exception;

/** A webhook whose Nowgate-Signature doesn't verify: don't trust its body. */
final class SignatureVerificationException extends NowgateException
{
    private const FAILURES = [
        'malformed' => "the Nowgate-Signature header can't be parsed",
        'no_signature' => 'the Nowgate-Signature header has no v1 signature',
        'timestamp' => "the signature's timestamp is outside the tolerance",
        'mismatch' => 'no signature matches the body with any of the secrets',
    ];

    /** @param 'malformed'|'no_signature'|'timestamp'|'mismatch' $reason why, with the published vectors' names */
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason . ': ' . self::FAILURES[$reason]);
    }
}
