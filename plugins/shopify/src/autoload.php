<?php

/**
 * PSR-4 autoloading for the plugin's classes (Inventoros\Plugins\Shopify\ in
 * src/). Required once per process from Plugin.php.
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'Inventoros\\Plugins\\Shopify\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

    if (is_file($file)) {
        require $file;
    }
});
