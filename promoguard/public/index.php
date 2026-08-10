<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/App.php';

use PromoGuard\App;
use PromoGuard\Simulator;

try {
    $app = App::boot();
} catch (\Throwable $e) {
    App::fail('No se pudo abrir la base de datos: ' . $e->getMessage());
    exit;
}

$route = $_GET['r'] ?? 'dashboard';

// Rutas retiradas al pasar de cinco pantallas a tres.
$moved = ['skus' => 'dashboard', 'forecast' => 'simulator'];
if (isset($moved[$route])) {
    header('Location: ' . $app->url($moved[$route]), true, 301);
    exit;
}

if (!$app->repo->isReady() && $route !== 'setup') {
    $app->render('setup', ['title' => 'Instalación']);
    exit;
}

switch ($route) {

    case 'dashboard': {
        $promos = $app->repo->promotions();
        $skus = $app->repo->skus();
        $app->render('dashboard', [
            'title'      => 'Diagnóstico',
            'headline'   => $app->repo->headline(),
            'promotions' => $promos,
            'skus'       => $skus,
            'portfolio'  => $app->advisor->portfolio($promos, $skus),
            'meta'       => $app->repo->meta(),
        ]);
        break;
    }

    case 'simulator': {
        $skus = $app->repo->skus();
        $code = isset($_GET['sku']) ? (int) $_GET['sku'] : (int) ($skus[0]['product_code'] ?? 0);
        $sku = $app->repo->sku($code) ?? $app->repo->firstSku();
        if ($sku === null) {
            $app->render('setup', ['title' => 'Instalación']);
            break;
        }

        $discount = isset($_GET['d']) ? ((float) $_GET['d']) / 100 : 0.15;
        $weeks = isset($_GET['w']) ? (int) $_GET['w'] : 4;
        $uplift = isset($_GET['u']) && $_GET['u'] !== '' ? (float) $_GET['u'] : null;

        $sim = Simulator::evaluate($sku, $discount, $weeks, $uplift);
        $analogs = $app->repo->promotions((int) $sku['product_code']);

        $app->render('simulator', [
            'title'    => 'Simulador',
            'skus'     => $skus,
            'sku'      => $sku,
            'sim'      => $sim,
            'curve'    => Simulator::curve($sku, $weeks),
            'analogs'  => $analogs,
            'advice'   => $app->advisor->analyze($sku, $sim, $analogs),
            'aiMode'   => $app->advisor->mode(),
            'weekly'   => $app->repo->weekly((int) $sku['product_code']),
            'forecast' => $app->repo->forecast((int) $sku['product_code']),
        ]);
        break;
    }

    // Endpoint JSON que alimenta el simulador en vivo.
    case 'api/simulate': {
        $code = (int) ($_GET['sku'] ?? 0);
        $sku = $app->repo->sku($code);
        if ($sku === null) {
            $app->json(['error' => 'SKU no encontrado'], 404);
            break;
        }
        $weeks = (int) ($_GET['w'] ?? 4);
        $sim = Simulator::evaluate(
            $sku,
            ((float) ($_GET['d'] ?? 15)) / 100,
            $weeks,
            isset($_GET['u']) && $_GET['u'] !== '' ? (float) $_GET['u'] : null
        );
        $analogs = $app->repo->promotions($code);
        header('Cache-Control: no-store');
        $app->json([
            'sim'    => $sim,
            'curve'  => Simulator::curve($sku, $weeks),
            'advice' => $app->advisor->analyze($sku, $sim, $analogs),
            'sku'    => $sku,
        ]);
        break;
    }

    case 'save': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !App::csrfValid($_POST['_t'] ?? null)) {
            header('Location: ' . $app->url('simulator'), true, 303);
            break;
        }
        $code = (int) ($_POST['sku'] ?? 0);
        $sku = $app->repo->sku($code);
        if ($sku !== null) {
            $sim = Simulator::evaluate(
                $sku,
                ((float) ($_POST['d'] ?? 15)) / 100,
                (int) ($_POST['w'] ?? 4),
                isset($_POST['u']) && $_POST['u'] !== '' ? (float) $_POST['u'] : null
            );
            $app->repo->saveSimulation($sku, $sim);
        }
        header('Location: ' . $app->url('campaigns'), true, 303);
        break;
    }

    case 'delete-scenario': {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && App::csrfValid($_POST['_t'] ?? null)) {
            $app->repo->deleteSimulation((int) ($_POST['id'] ?? 0));
        }
        header('Location: ' . $app->url('campaigns'), true, 303);
        break;
    }

    case 'campaigns': {
        $app->render('campaigns', [
            'title'       => 'Campañas',
            'promotions'  => $app->repo->promotions(),
            'simulations' => $app->repo->simulations(),
        ]);
        break;
    }

    case 'campaign': {
        $promo = $app->repo->promotion((int) ($_GET['id'] ?? 0));
        if ($promo === null) {
            header('Location: ' . $app->url('campaigns'), true, 302);
            break;
        }
        $app->render('campaign', [
            'title'  => $promo['combo'],
            'promo'  => $promo,
            'weekly' => $app->repo->weekly((int) $promo['product_code']),
        ]);
        break;
    }

    case 'setup': {
        $app->render('setup', ['title' => 'Instalación']);
        break;
    }

    default: {
        header('Location: ' . $app->url('dashboard'), true, 302);
    }
}
