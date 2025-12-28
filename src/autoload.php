<?php
declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'BoringBot\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
    $path = __DIR__ . DIRECTORY_SEPARATOR . $relativePath;

    if (is_file($path)) {
        require $path;
    }
});

