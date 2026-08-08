<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/App.php';

use PromoGuard\App;
use PromoGuard\Simulator;

$app = App::boot();
$route = $_GET['r'] ?? 'dashboard';

// Sin base de datos: pantalla de instalación.
if (!$app->repo->isReady() && $route !== 'setup') {
    $app->render('setup', ['title' => 'Instalacion']);
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
            $app->render('setup', ['title' => 'Instalacion']);
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
        ]);
        break;
    }

    // Endpoint JSON que alimenta el simulador en vivo (sin recargar la página).
    case 'api/simulate': {
        $code = (int) ($_GET['sku'] ?? 0);
        $sku = $app->repo->sku($code);
        if ($sku === null) {
            $app->json(['error' => 'SKU no encontrado'], 404);
            break;
        }
        $discount = ((float) ($_GET['d'] ?? 15)) / 100;
        $weeks = (int) ($_GET['w'] ?? 4);
        $uplift = isset($_GET['u']) && $_GET['u'] !== '' ? (float) $_GET['u'] : null;

        $sim = Simulator::evaluate($sku, $discount, $weeks, $uplift);
        $analogs = $app->repo->promotions($code);

        $app->json([
            'sim'    => $sim,
            'curve'  => Simulator::curve($sku, $weeks),
            'advice' => $app->advisor->analyze($sku, $sim, $analogs),
            'sku'    => $sku,
        ]);
        break;
    }

    case 'save': {
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
        header('Location: ' . $app->url('campaigns'));
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
            http_response_code(404);
            $app->render('campaigns', [
                'title'       => 'Campañas',
                'promotions'  => $app->repo->promotions(),
                'simulations' => $app->repo->simulations(),
            ]);
            break;
        }
        $app->render('campaign', [
            'title'  => $promo['combo'],
            'promo'  => $promo,
            'weekly' => $app->repo->weekly((int) $promo['product_code']),
        ]);
        break;
    }

    case 'skus': {
        $app->render('skus', [
            'title' => 'Catálogo',
            'skus'  => $app->repo->skus(),
        ]);
        break;
    }

    case 'forecast': {
        $skus = $app->repo->skus();
        $code = isset($_GET['sku']) ? (int) $_GET['sku'] : (int) ($skus[0]['product_code'] ?? 0);
        $sku = $app->repo->sku($code) ?? $app->repo->firstSku();
        $app->render('forecast', [
            'title'    => 'Proyección',
            'skus'     => $skus,
            'sku'      => $sku,
            'weekly'   => $sku ? $app->repo->weekly((int) $sku['product_code']) : [],
            'forecast' => $sku ? $app->repo->forecast((int) $sku['product_code']) : [],
        ]);
        break;
    }

    case 'setup': {
        $app->render('setup', ['title' => 'Instalacion']);
        break;
    }

    default: {
        http_response_code(404);
        $app->render('dashboard', [
            'title'      => 'Diagnóstico',
            'headline'   => $app->repo->headline(),
            'promotions' => $app->repo->promotions(),
            'skus'       => $app->repo->skus(),
            'portfolio'  => $app->advisor->portfolio($app->repo->promotions(), $app->repo->skus()),
            'meta'       => $app->repo->meta(),
        ]);
    }
}
