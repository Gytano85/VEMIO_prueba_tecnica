<?php
declare(strict_types=1);

/**
 * Genera docs/preview.html renderizando las vistas reales.
 *
 *   php bin/preview.php
 *
 * La versión anterior de este archivo era un espejo escrito a mano en otro lenguaje,
 * y se desincronizaba cada vez que alguien tocaba una vista. Este script usa las
 * mismas plantillas que el sistema, así que no puede quedar desfasado.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script se ejecuta desde la linea de comandos.\n");
    exit(1);
}

require dirname(__DIR__) . '/src/App.php';

use PromoGuard\App;
use PromoGuard\Simulator;

$app = App::boot();

if (!$app->repo->isReady()) {
    fwrite(STDERR, "\n  La base no esta construida. Corre primero:\n"
                 . "  php bin/import.php ruta/al/extracto.csv\n\n");
    exit(1);
}

/** Captura el HTML de una vista sin enviarlo al navegador. */
function render(App $app, string $view, array $data): string
{
    ob_start();
    $app->render($view, $data);
    return (string) ob_get_clean();
}

$promos = $app->repo->promotions();
$skus   = $app->repo->skus();

// --- Diagnóstico ---
$_GET['r'] = 'dashboard';
$dashboard = render($app, 'dashboard', [
    'title'      => 'Diagnóstico',
    'headline'   => $app->repo->headline(),
    'promotions' => $promos,
    'skus'       => $skus,
    'portfolio'  => $app->advisor->portfolio($promos, $skus),
    'meta'       => $app->repo->meta(),
]);

// --- Simulador, con un escenario representativo ---
$sku = $app->repo->firstSku();
foreach ($skus as $s) {                       // preferir el SKU con mejor identificación
    if (str_contains((string) $s['product_name'], 'Antitranspirante')) {
        $sku = $s;
        break;
    }
}
$sim = Simulator::evaluate($sku, 0.16, 10);
$analogs = $app->repo->promotions((int) $sku['product_code']);

$_GET['r'] = 'simulator';
$simulator = render($app, 'simulator', [
    'title'    => 'Simulador',
    'skus'     => $skus,
    'sku'      => $sku,
    'sim'      => $sim,
    'curve'    => Simulator::curve($sku, 10),
    'analogs'  => $analogs,
    'advice'   => $app->advisor->analyze($sku, $sim, $analogs),
    'aiMode'   => $app->advisor->mode(),
    'weekly'   => $app->repo->weekly((int) $sku['product_code']),
    'forecast' => $app->repo->forecast((int) $sku['product_code']),
    'paths'    => Simulator::profitPaths($sim),
]);

/** Extrae el contenido de <main> de una página completa. */
function mainOf(string $html): string
{
    return preg_match('#<main[^>]*>(.*?)</main>#s', $html, $m) ? $m[1] : $html;
}

// El preview vive en docs/, un nivel bajo la raíz: se corrigen las rutas a los assets.
$page = preg_replace(
    '#(href|src)="assets/#',
    '$1="../public/assets/',
    $dashboard
);

$aviso = '<p class="note" style="margin-bottom:var(--s6)">'
       . '<strong>Vista previa estatica</strong> generada desde las vistas reales del sistema. '
       . 'Los controles no responden; para usarlo, corre '
       . '<code>php -S localhost:8000 -t public</code>.</p>';

$html = preg_replace(
    '#(<main[^>]*>)#',
    '$1' . "\n" . $aviso,
    $page,
    1
);

// Se anexa el simulador debajo del diagnóstico, en la misma página.
$html = str_replace('</main>', mainOf($simulator) . "\n</main>", $html);

// Los formularios y enlaces internos no llevan a ningún lado en un archivo estático.
$html = preg_replace('#\s(action|href)="\?[^"]*"#', ' $1="#"', $html);

// Sin servidor detrás, el JS del simulador llamaría a un endpoint inexistente en cada
// movimiento de los controles. Se retira para que el archivo sea inerte.
$html = preg_replace('#<script\b[^>]*>.*?</script>#s', '', $html);
$html = str_replace('<input type="range"', '<input type="range" disabled', $html);

$out = dirname(__DIR__) . '/docs/preview.html';
if (!is_dir(dirname($out))) {
    mkdir(dirname($out), 0775, true);
}
file_put_contents($out, $html);

printf("  preview.html generado desde las vistas (%d KB)\n", (int) round(strlen($html) / 1024));
