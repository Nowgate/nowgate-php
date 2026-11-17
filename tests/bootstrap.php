<?php

declare(strict_types=1);

// Loads the SDK, and its tests, without Composer: it stands in for Composer's
// vendor/autoload.php, since the SDK has no dependencies.
spl_autoload_register(static function (string $class): void {
    foreach (['Nowgate\\Tests\\' => __DIR__ . '/', 'Nowgate\\' => __DIR__ . '/../src/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});
