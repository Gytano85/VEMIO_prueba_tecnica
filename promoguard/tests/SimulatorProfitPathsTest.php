<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Simulator.php';

use PromoGuard\Simulator;

function closeTo(float $actual, float $expected, float $epsilon = 0.0001): void
{
    if (abs($actual - $expected) > $epsilon) {
        throw new RuntimeException("Expected {$expected}, got {$actual}");
    }
}

$sku = [
    'unit_cost' => 80.0,
    'markup' => 0.25,
    'list_price' => 100.0,
    'breakeven_discount' => 0.20,
    'baseline_weekly' => 1000.0,
    'elasticity' => -2.0,
    'elasticity_r2' => 0.90,
];

$loss = Simulator::evaluate($sku, 0.10, 1, 20.0);
$paths = Simulator::profitPaths($loss);
closeTo((float) $paths['gap'], 8000.0);
closeTo((float) $paths['funding']['share'], 2 / 3);
closeTo((float) $paths['targeting']['max_share'], 1 / 3);
closeTo((float) $paths['uplift']['additional_units'], 800.0);

$profit = Simulator::evaluate($sku, 0.01, 1, 400.0);
$profitPaths = Simulator::profitPaths($profit);
if (!$profitPaths['is_profitable'] || $profitPaths['recommended'] !== 'keep') {
    throw new RuntimeException('A profitable scenario must be preserved.');
}

$belowCost = Simulator::evaluate($sku, 0.25, 1, 100.0);
$blockedPaths = Simulator::profitPaths($belowCost);
if ($blockedPaths['uplift']['possible']) {
    throw new RuntimeException('Volume must not be offered as a solution below unit cost.');
}

$unreachable = Simulator::evaluate($sku, 0.19, 1, 20.0);
$unreachablePaths = Simulator::profitPaths($unreachable);
if ($unreachablePaths['uplift']['testable']) {
    throw new RuntimeException('The UI must not offer an uplift outside its supported range.');
}

echo "Simulator profit paths: OK\n";
