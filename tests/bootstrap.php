<?php declare(strict_types=1);

// Minimaler Autoloader: Die getesteten Klassen brauchen weder Shopware noch Composer-Abhängigkeiten.
if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
}

spl_autoload_register(static function (string $class): void {
    $map = ['Nova\\Search\\Tests\\' => __DIR__ . '/', 'Nova\\Search\\' => __DIR__ . '/../src/'];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});
