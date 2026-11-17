<?php

declare(strict_types=1);

namespace Nowgate\Tests;

use Nowgate\Client;
use Nowgate\Internal\Requester;
use Nowgate\Transport\Request;
use Nowgate\Transport\Response;
use Nowgate\Transport\Transport;
use Nowgate\Transport\TransportException;

/**
 * A fake transport for the unit tests: it answers each request from a script and records
 * what it was sent, and the client's waits between attempts are recorded instead of slept.
 */
final class Fake implements Transport
{
    private const DIGITS = '0123456789';
    /**
     * A test key's secret, built from repeated digits as the other SDKs' tests build it, so
     * no secret scanner reads it as a real one.
     */
    public const SECRET = self::DIGITS . self::DIGITS . self::DIGITS . self::DIGITS . 'abc';
    public const KEY = 'ngk_sandbox_' . self::SECRET;
    public const PAYMENT = [
        'id' => 'pay_01m6k00000000000000000000a',
        'object' => 'payment',
        'status' => 'waiting',
        'network' => 'tron',
        'asset' => 'USDT',
        'amount' => '25',
    ];

    public readonly Client $nowgate;
    /** @var list<Request> */
    public array $sent = [];
    /** @var list<float> */
    public array $waits = [];

    /**
     * @param list<Response|\Throwable|\Closure(Request): Response> $answers
     * @param array<string, mixed> $options
     */
    public function __construct(private array $answers, array $options = [], float $random = 1.0)
    {
        /** @var array{api_key: string} $all */
        $all = ['api_key' => self::KEY, 'transport' => $this] + $options;
        $this->nowgate = new Client($all);
        $requester = self::requester($this->nowgate);
        $requester->sleep = function (float $seconds): void {
            $this->waits[] = $seconds;
        };
        $requester->random = static fn (): float => $random;
    }

    public static function requester(Client $nowgate): Requester
    {
        $requester = (new \ReflectionProperty(Client::class, 'client'))->getValue($nowgate);
        assert($requester instanceof Requester);
        return $requester;
    }

    public function send(Request $request): Response
    {
        $this->sent[] = $request;
        $answer = array_shift($this->answers);
        if ($answer === null) {
            throw new Failure("unexpected request: {$request->method} {$request->url}");
        }
        if ($answer instanceof \Throwable) {
            throw $answer;
        }
        return $answer instanceof Response ? $answer : $answer($request);
    }

    /** The JSON body of request $i. */
    public function body(int $i): mixed
    {
        return json_decode((string) $this->sent[$i]->body, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, string> $headers */
    public static function json(int $status, mixed $body, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * RFC 9457 problem JSON, as the API answers.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed> $extra
     */
    public static function problem(int $status, string $code, array $headers = [], array $extra = []): Response
    {
        $body = [
            'type' => "https://docs.nowgate.io/errors/{$code}",
            'title' => 'Problem',
            'status' => $status,
            'code' => $code,
            'detail' => "The {$code} detail.",
            'instance' => '/v1/payments',
            'request_id' => 'req_problem',
        ] + $extra;
        return new Response($status, ['Content-Type' => 'application/problem+json'] + $headers, json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** What a transport throws when nothing listens. */
    public static function refused(): TransportException
    {
        return new TransportException('Failed to connect to 127.0.0.1 port 9: Connection refused');
    }

    /** Nowgate's repository, when the SDK is in it. */
    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** The SDK's own directory: its composer.json, and testdata/ when it stands alone. */
    public static function sdk(): string
    {
        return dirname(__DIR__);
    }

    /**
     * A file the SDKs' tests share, by its path in Nowgate's repository: from this SDK's
     * testdata/ when the SDK stands alone, as in its public repository, else from the
     * repository's own path.
     */
    public static function fixture(string $path): string
    {
        $local = self::sdk() . '/testdata/' . basename($path);
        return is_file($local) ? $local : self::root() . '/' . $path;
    }
}
