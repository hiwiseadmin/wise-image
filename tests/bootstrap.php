<?php

declare(strict_types=1);

// wise-image Package Test Bootstrap
// ---------------------------------------------------------------
// Loads Composer autoloader and provides manual PSR-4 fallback.

// Resolve autoloader from project src/vendor
$autoloadPaths = [
    __DIR__ . '/../../../vendor/autoload.php',    // src/vendor
    __DIR__ . '/../../../../vendor/autoload.php',  // root/vendor (fallback)
];

$found = false;
foreach ($autoloadPaths as $path) {
    $normalized = realpath($path);
    if ($normalized !== false && file_exists($normalized)) {
        require $normalized;
        $found = true;
        break;
    }
}

// Manual PSR-4 fallback: register wise\image namespace if not
// already handled by the root composer.json
if (!class_exists(\wise\image\ImageManager::class, false)) {
    spl_autoload_register(function (string $class) {
        $prefix = 'wise\\image\\';
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relativeClass = substr($class, $len);
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', $relativeClass) . '.php';

        if (file_exists($file)) {
            require $file;
        }
    });
}

if (!$found) {
    fwrite(STDERR, "Warning: Composer autoloader not found. Using manual PSR-4 fallback.\n");
}
