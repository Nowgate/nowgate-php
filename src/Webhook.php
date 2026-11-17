<?php

declare(strict_types=1);

namespace Nowgate;

use Nowgate\Exception\NowgateException;
use Nowgate\Exception\SignatureVerificationException;

/**
 * Webhook verification, exactly as the engine's webhooks/signing.Verify does. It needs no
 * client, since a webhook handler often has none. The published vectors in
 * api/webhooks/signature-vectors.json pin the format:
 * Nowgate-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")>
 *
 * @phpstan-import-type WebhookEvent from \Nowgate\Generated\Types
 */
final class Webhook
{
    public const DEFAULT_TOLERANCE = 300;

    // Go's strings.TrimSpace trims Unicode's White_Space characters: these, as UTF-8 bytes.
    private const SPACE = '(?:[\t\n\x0B\f\r ]|\xC2[\x85\xA0]|\xE1\x9A\x80|\xE2\x80[\x80-\x8A\xA8\xA9\xAF]|\xE2\x81\x9F|\xE3\x80\x80)';
    private const INT64_MAX = '9223372036854775807';

    /**
     * Checks a webhook's Nowgate-Signature header against its raw body. It returns when the
     * signature's time is within $tolerance seconds of now and any v1 signature matches any of
     * the secrets (two while you rotate), and throws a SignatureVerificationException
     * otherwise. Every comparison takes the same time.
     *
     * @param string $payload the body exactly as received, before any JSON parsing: file_get_contents('php://input')
     * @param string|list<string>|null $header the Nowgate-Signature header
     * @param string|list<string> $secrets your endpoint's secret (whsec_...), or a list of them while you rotate
     * @param int|float|\DateTimeInterface|null $now the time to verify at, in Unix seconds: now by default
     */
    public static function verifySignature(
        string $payload,
        string|array|null $header,
        #[\SensitiveParameter] string|array $secrets,
        int|float $tolerance = self::DEFAULT_TOLERANCE,
        int|float|\DateTimeInterface|null $now = null,
    ): void {
        $keys = self::secretList($secrets);
        if (!is_finite((float) $tolerance) || $tolerance < 0) {
            throw new \InvalidArgumentException('tolerance must be a number of seconds, 0 or more');
        }
        $at = self::seconds($now);
        $parsed = self::parse(self::headerText($header));
        if (is_string($parsed)) {
            throw new SignatureVerificationException($parsed);
        }
        [$timestamp, $signatures] = $parsed;
        $age = $at - $timestamp;
        if ($age > $tolerance || $age < -$tolerance) {
            throw new SignatureVerificationException('timestamp');
        }
        $message = $timestamp . '.' . $payload;
        $ok = false;
        foreach ($keys as $secret) {
            $mac = hash_hmac('sha256', $message, $secret, true);
            foreach ($signatures as $signature) {
                $ok = hash_equals($mac, $signature) || $ok; // no early exit
            }
        }
        if (!$ok) {
            throw new SignatureVerificationException('mismatch');
        }
    }

    /**
     * Checks a webhook's signature as verifySignature does, then returns its event, told apart
     * by its type: payment.*, payout.*, refund.*, destination.* or batch.*.
     *
     * @param string|list<string>|null $header
     * @param string|list<string> $secrets
     * @return WebhookEvent
     */
    public static function constructEvent(
        string $payload,
        string|array|null $header,
        #[\SensitiveParameter] string|array $secrets,
        int|float $tolerance = self::DEFAULT_TOLERANCE,
        int|float|\DateTimeInterface|null $now = null,
    ): array {
        self::verifySignature($payload, $header, $secrets, $tolerance, $now);
        try {
            $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new NowgateException("The webhook's signature verified, but its body isn't JSON", 0, $e);
        }
        if (!is_array($event)) {
            throw new NowgateException("The webhook's signature verified, but its body isn't an event");
        }
        /** @var WebhookEvent $event */
        return $event;
    }

    /** @return array{int, list<string>}|'malformed'|'no_signature' the header as signing.parse reads it, or why it can't be */
    private static function parse(string $header): array|string
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $item = (string) preg_replace('/\A' . self::SPACE . '+|' . self::SPACE . '+\z/', '', $part);
            $eq = strpos($item, '=');
            if ($eq === false) {
                return 'malformed';
            }
            $key = substr($item, 0, $eq);
            $value = substr($item, $eq + 1);
            if ($key === 't') {
                // strconv.ParseInt(value, 10, 64): an optional sign, ASCII digits, within int64.
                if ($timestamp !== null || preg_match('/\A([+-]?)0*([0-9]+)\z/', $value, $m) !== 1) {
                    return 'malformed';
                }
                $digits = $m[2];
                $tooBig = strlen($digits) > strlen(self::INT64_MAX)
                    || (strlen($digits) === strlen(self::INT64_MAX) && strcmp($digits, self::INT64_MAX) > 0);
                if ($m[1] === '-' || $tooBig || (int) $digits <= 0) {
                    return 'malformed';
                }
                $timestamp = (int) $digits;
            } elseif ($key === 'v1') {
                if (preg_match('/\A[0-9a-fA-F]{64}\z/', $value) !== 1) {
                    return 'malformed';
                }
                $signatures[] = (string) hex2bin($value);
            } // other schemes are ignored, so new ones can be added
        }
        if ($timestamp === null) {
            return 'malformed';
        }
        if ($signatures === []) {
            return 'no_signature';
        }
        return [$timestamp, $signatures];
    }

    /** @param string|list<string>|null $header */
    private static function headerText(string|array|null $header): string
    {
        if ($header === null || is_string($header)) {
            return $header ?? '';
        }
        foreach ($header as $part) {
            /** @phpstan-ignore function.alreadyNarrowedType (for callers without PHPStan) */
            if (!is_string($part)) {
                throw new \InvalidArgumentException('header must be the Nowgate-Signature header: a string, or a list of them');
            }
        }
        return implode(',', $header);
    }

    /**
     * @param string|list<string> $secrets
     * @return list<string>
     */
    private static function secretList(#[\SensitiveParameter] string|array $secrets): array
    {
        /** @phpstan-ignore arrayValues.list (a caller without PHPStan may pass a map) */
        $keys = is_string($secrets) ? [$secrets] : array_values($secrets);
        foreach ($keys as $key) {
            /** @phpstan-ignore function.alreadyNarrowedType (for callers without PHPStan) */
            if (!is_string($key) || $key === '') {
                $keys = [];
                break;
            }
        }
        if ($keys === []) {
            throw new \InvalidArgumentException("secrets must be your endpoint's secret (whsec_...), or a list of them while you rotate");
        }
        return $keys;
    }

    private static function seconds(int|float|\DateTimeInterface|null $now): float
    {
        if ($now === null) {
            return microtime(true);
        }
        if ($now instanceof \DateTimeInterface) {
            return (float) $now->format('U.u');
        }
        if (!is_finite((float) $now)) {
            throw new \InvalidArgumentException('now must be a DateTimeInterface or a number of Unix seconds');
        }
        return (float) $now;
    }
}
