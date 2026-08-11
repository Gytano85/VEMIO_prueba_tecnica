<?php
declare(strict_types=1);

/**
 * Importador de PromoGuard.
 *
 *   php bin/import.php ruta/al/extracto.csv
 *
 * Construye data/promoguard.sqlite desde el extracto de transacciones sell-in.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script se ejecuta desde la linea de comandos.\n");
    exit(1);
}

// El ETL conserva el extracto limpio en memoria para reutilizarlo en todos los modelos.
// El dataset de la prueba (283k filas) supera el limite predeterminado de 128 MB de PHP.
ini_set('memory_limit', '512M');

require __DIR__ . '/../src/App.php';

use PromoGuard\App;
use PromoGuard\Importer;
use PromoGuard\Repository;

App::registerAutoloader();
$config = require dirname(__DIR__) . '/config.php';

$csv = $argv[1] ?? null;
if ($csv === null) {
    // Buscar automáticamente un CSV en data/
    $candidates = glob(dirname(__DIR__) . '/data/*.csv') ?: [];
    $csv = $candidates[0] ?? null;
}

if ($csv === null || !is_readable($csv)) {
    fwrite(STDERR, "\nUso: php bin/import.php <ruta-al-csv>\n");
    fwrite(STDERR, "     (o coloca el CSV en data/ y corre el script sin argumentos)\n\n");
    exit(1);
}

$dbPath = $config['database'];
$dir = dirname($dbPath);
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}
if (is_file($dbPath)) {
    if (!unlink($dbPath)) {
        throw new RuntimeException("No se pudo reemplazar la base SQLite: {$dbPath}");
    }
}
foreach ([$dbPath . '-wal', $dbPath . '-shm'] as $sidecar) {
    if (is_file($sidecar) && !unlink($sidecar)) {
        throw new RuntimeException("No se pudo eliminar el archivo temporal de SQLite: {$sidecar}");
    }
}

$t0 = microtime(true);
echo "\n  PromoGuard · importando " . basename($csv) . "\n\n";

$pdo = Repository::connect($dbPath);
$importer = new Importer($pdo);

try {
    $importer->run($csv);
} catch (\Throwable $e) {
    fwrite(STDERR, "\n  Error: " . $e->getMessage() . "\n\n");
    exit(1);
}

printf("\n  Completado en %.1f s -> %s\n", microtime(true) - $t0, $dbPath);
echo "  Levanta el sistema con:  php -S localhost:8000 -t public\n\n";
