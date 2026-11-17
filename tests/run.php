<?php

declare(strict_types=1);

// The PHP SDK's test runner: php tests/run.php runs every tests/*Test.php, prints the failures
// (every test with -v) and a summary, and exits non-zero when a test fails. It needs nothing
// but PHP.

use Nowgate\Tests\Registry;

$verbose = in_array('-v', $argv ?? [], true);
error_reporting(E_ALL);
set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
    if ((error_reporting() & $level) === 0) {
        return false; // silenced with @
    }
    throw new \ErrorException($message, 0, $level, $file, $line);
});
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/Assert.php';
$files = glob(__DIR__ . '/*Test.php') ?: [];
foreach ($files as $file) {
    require $file;
}
$failed = 0;
foreach (Registry::$tests as [$name, $run]) {
    try {
        $run();
        if ($verbose) {
            echo "ok - {$name}\n";
        }
    } catch (\Throwable $e) {
        $failed++;
        echo "not ok - {$name}\n  " . $e::class . ': ' . $e->getMessage() . "\n  at " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}
$count = count(Registry::$tests);
echo sprintf("%d tests in %d files on PHP %s: %d passed, %d failed\n", $count, count($files), PHP_VERSION, $count - $failed, $failed);
exit($failed === 0 && $count > 0 ? 0 : 1);
