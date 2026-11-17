<?php

declare(strict_types=1);

// make sdk-package's check: the SDK as its release archive holds it, loaded through the PSR-4
// map in the archive's own composer.json as Composer's autoloader loads it, verifies a published
// signature vector.
// Usage: php installed.php <unpacked archive> <signature-vectors.json> <version>

use Nowgate\Client;
use Nowgate\Generated\Types;
use Nowgate\Webhook;

function check(bool $ok, string $what): void
{
    if (!$ok) {
        fwrite(STDERR, "installed check failed: {$what}\n");
        exit(1);
    }
}

/** @return array<mixed> */
function readJson(string $file): array
{
    $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    check(is_array($data), "{$file} holds a JSON object");
    /** @var array<mixed> $data */
    return $data;
}

$args = $argv ?? [];
$root = realpath($args[1] ?? '');
$vectorsFile = $args[2] ?? '';
$version = $args[3] ?? '';
if ($root === false || $vectorsFile === '' || $version === '') {
    fwrite(STDERR, "usage: php installed.php <unpacked archive> <signature-vectors.json> <version>\n");
    exit(2);
}

$autoload = readJson($root . '/composer.json')['autoload'] ?? null;
$psr4 = is_array($autoload) ? ($autoload['psr-4'] ?? null) : null;
check(is_array($psr4) && $psr4 !== [], "composer.json's PSR-4 map");
/** @var array<mixed> $psr4 */
foreach ($psr4 as $prefix => $dir) {
    check(is_string($prefix) && is_string($dir), "composer.json's PSR-4 map");
    /** @var string $prefix */
    /** @var string $dir */
    $base = $root . '/' . rtrim($dir, '/') . '/';
    spl_autoload_register(static function (string $class) use ($prefix, $base): void {
        if (str_starts_with($class, $prefix)) {
            $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
}

// The import, timed: a client made and the webhook class loaded, each file compiled as it is
// first loaded (the CLI runs without opcache).
$began = hrtime(true);
$client = new Client(['api_key' => 'ngk_sandbox_' . str_repeat('0123456789', 4) . 'abc']);
$tolerance = Webhook::DEFAULT_TOLERANCE;
$importMs = (hrtime(true) - $began) / 1e6;

$loaded = (string) (new \ReflectionClass(Client::class))->getFileName();
check(str_starts_with($loaded, $root . '/'), "Nowgate\\Client loaded from {$loaded}");

$vectors = readJson($vectorsFile)['vectors'] ?? null;
check(is_array($vectors) && is_array($vectors[0] ?? null), 'the published vectors');
/** @var array{0: array{secrets: list<string>, body: string, header: string, now: int}} $vectors */
$v = $vectors[0];
$event = Webhook::constructEvent($v['body'], $v['header'], $v['secrets'], $tolerance, $v['now']);
check($event['type'] === 'payment.finished', "the vector's event");
check($event['api_version'] === Types::API_VERSION, 'the API version');
check(Types::VERSION === $version, 'the version ' . Types::VERSION . ", not {$version}");
check($client->baseUrl === 'https://sandbox-api.nowgate.io/v1', "the sandbox's base URL");
$bytes = 0;
$files = 0;
foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
    /** @var \SplFileInfo $file */
    $bytes += (int) $file->getSize();
    $files++;
}
echo 'nowgate/nowgate ', Types::VERSION, ' on PHP ', PHP_VERSION, ": installed ({$bytes} bytes in {$files} files), loaded in ", sprintf('%.1f', $importMs), " ms, a vector verified\n";
