<?php
/** @var \PromoGuard\App $app @var array $skus @var array $sku @var array $weekly @var array $forecast */
use PromoGuard\App;
require_once __DIR__ . '/partials/helpers.php';

if ($sku === null) {
    echo '<div class="empty"><h3>Sin datos</h3></div>';
    return;
}

// Últimas 40 semanas de histórico + 10 de proyección
$hist = array_slice($weekly, -40);
$base = [];
$promo = [];
foreach ($forecast as $f) {
    if ($f['scenario'] === 'base') {
        $base[] = $f;
    } else {
        $promo[] = $f;
    }
}

$serieHist = [];
$serieBase = [];
$seriePromo = [];
$i = 0;
foreach ($hist as $w) {
    $serieHist[] = [(float) $i, (float) $w['units']];
    $i++;
}
$anchor = $i - 1;
$lastUnits = $hist !== [] ? (float) end($hist)['units'] : 0.0;
$serieBase[] = [(float) $anchor, $lastUnits];
$seriePromo[] = [(float) $anchor, $lastUnits];
$j = $i;
foreach ($base as $f) {
    $serieBase[] = [(float) $j, (float) $f['units']];
    $j++;
}
$j = $i;
foreach ($promo as $f) {
    $seriePromo[] = [(float) $j, (float) $f['units']];
    $j++;
}

$labels = [];
$allWeeks = array_merge(array_column($hist, 'week'), array_column($base, 'week'));
$n = count($allWeeks);
for ($k = 0; $k <= 4; $k++) {
    $idx = (int) round(($n - 1) * $k / 4);
    $labels[] = isset($allWeeks[$idx]) ? date('M y', strtotime((string) $allWeeks[$idx])) : '';
}

$avgBase = $base !== [] ? array_sum(array_column($base, 'units')) / count($base) : 0.0;
$avgPromo = $promo !== [] ? array_sum(array_column($promo, 'units')) / count($promo) : 0.0;
$avgHist = $hist !== [] ? array_sum(array_column($hist, 'units')) / count($hist) : 0.0;
?>

<div class="page-head">
  <div>
    <h1 class="page-title">Proyección de demanda</h1>
    <p class="page-sub">10 semanas hacia adelante · con y sin promoción activa</p>
  </div>
  <form method="get" style="min-width:250px">
    <input type="hidden" name="r" value="forecast">
    <select name="sku" onchange="this.form.submit()">
      <?php foreach ($skus as $s): ?>
        <option value="<?= (int) $s['product_code'] ?>" <?= (int) $s['product_code'] === (int) $sku['product_code'] ? 'selected' : '' ?>>
          <?= App::e($s['product_name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<div class="grid g3 mb14">
  <div class="card kpi">
    <div class="kpi-label">Promedio histórico reciente</div>
    <div class="kpi-value sm num"><?= App::num($avgHist) ?> <span style="font-size:14px;color:var(--text-faint)">u/sem</span></div>
    <div class="kpi-note">últimas <?= count($hist) ?> semanas</div>
  </div>
  <div class="card kpi">
    <div class="kpi-label">Proyección sin promoción</div>
    <div class="kpi-value sm num txt-accent"><?= App::num($avgBase) ?> <span style="font-size:14px;color:var(--text-faint)">u/sem</span></div>
    <div class="kpi-note">demanda de negocio normal</div>
  </div>
  <div class="card kpi is-warn">
    <div class="kpi-label">Con promoción al 15%</div>
    <div class="kpi-value sm num txt-warn"><?= App::num($avgPromo) ?> <span style="font-size:14px;color:var(--text-faint)">u/sem</span></div>
    <div class="kpi-note">
      <?= $avgBase > 0 ? '+' . App::pct($avgPromo / $avgBase - 1) : '—' ?> sobre la base
    </div>
  </div>
</div>

<div class="card mb14">
  <div class="card-title"><?= App::e($sku['product_name']) ?> · unidades por semana</div>
  <?= pg_line_chart(
      [$serieHist, $serieBase, $seriePromo],
      [
        'height'  => 280,
        'xlabels' => $labels,
        'colors'  => ['var(--accent)', 'var(--text-dim)', 'var(--warn)'],
        'dashes'  => [null, '5 4', '2 3'],
      ]
  ) ?>
  <div class="chart-legend">
    <span><i class="swatch" style="background:var(--accent)"></i> histórico</span>
    <span><i class="swatch" style="background:var(--text-dim)"></i> proyección sin promoción</span>
    <span><i class="swatch" style="background:var(--warn)"></i> proyección con promoción 15%</span>
  </div>
</div>

<div class="grid g2">
  <div class="card">
    <div class="card-title">Semanas proyectadas</div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Semana</th><th class="t-num">Sin promo</th><th class="t-num">Con promo 15%</th><th class="t-num">Δ</th></tr></thead>
        <tbody>
        <?php foreach ($base as $k => $f):
            $p = $promo[$k]['units'] ?? 0;
        ?>
          <tr>
            <td><?= App::e(date('d/m/Y', strtotime((string) $f['week']))) ?></td>
            <td class="t-num"><?= App::num((float) $f['units']) ?></td>
            <td class="t-num txt-warn"><?= App::num((float) $p) ?></td>
            <td class="t-num txt-accent">+<?= App::num((float) $p - (float) $f['units']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-title">Cómo leer esta proyección</div>
    <div class="note mb14">
      La <strong>línea base no asume promociones futuras</strong>: proyecta demanda de negocio normal
      a partir del nivel reciente y el patrón estacional del SKU.
    </div>
    <div class="note mb14">
      El escenario promocional aplica la elasticidad estimada
      (<strong><?= $sku['elasticity'] !== null ? number_format((float) $sku['elasticity'], 2) : '—' ?></strong>)
      a un descuento del 15%. Sirve para dimensionar cuánto inventario adicional exige activar una promo,
      no para justificarla: la rentabilidad se evalúa en el simulador.
    </div>
    <div class="note">
      <strong>Antes de comprometer inventario</strong>, verifica en el simulador si la promoción que
      motiva ese volumen extra se paga sola. El tope de descuento de este SKU es
      <strong class="txt-block"><?= App::pct((float) $sku['breakeven_discount']) ?></strong>.
    </div>
    <a class="btn btn-primary mt14" href="<?= $app->url('simulator', ['sku' => $sku['product_code']]) ?>" style="width:100%;justify-content:center">
      Evaluar una promoción para este SKU
    </a>
  </div>
</div>
