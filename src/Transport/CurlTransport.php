<?php

declare(strict_types=1);

namespace Nowgate\Transport;

/**
 * The default transport: ext-curl, following no redirects, with the system's certificates. The
 * API never redirects: an answer that does is returned as it is, not followed with the key.
 */
final class CurlTransport implements Transport
{
    public function send(Request $request): Response
    {
        $handle = curl_init();
        if ($handle === false || $request->url === '' || $request->method === '') {
            throw new TransportException('curl_init failed');
        }
        $lines = ['Expect:'];
        foreach ($request->headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $headers = [];
        $reason = '';
        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => max(1, (int) ceil($request->timeout * 1000)),
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $handle, string $line) use (&$headers, &$reason): int {
                $text = rtrim($line, "\r\n");
                if (preg_match('#^HTTP/\S+ \d{3}(?: (.*))?$#', $text, $match) === 1) {
                    $headers = []; // a new answer, after a 100 Continue
                    $reason = $match[1] ?? '';
                } elseif (str_contains($text, ':')) {
                    [$name, $value] = explode(':', $text, 2);
                    $key = strtolower(trim($name));
                    $headers[$key] = isset($headers[$key]) ? $headers[$key] . ', ' . trim($value) : trim($value);
                }
                return strlen($line);
            },
        ];
        if ($request->body !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }
        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);
        if (!is_string($body)) {
            $errno = curl_errno($handle);
            throw new TransportException(curl_error($handle), $errno === CURLE_OPERATION_TIMEDOUT);
        }
        /** @var array<string, string> $headers */
        return new Response((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $headers, $body, $reason);
    }
}
