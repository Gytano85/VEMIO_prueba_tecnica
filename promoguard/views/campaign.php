<?php
/** @var \PromoGuard\App $app @var array $promo @var array $weekly */
use PromoGuard\App;
require_once __DIR__ . '/partials/helpers.php';

$cov = (float) $promo['coverage'];
$below = (int) $promo['sells_below_cost'] === 1;
$start = (string) $promo['start_date'];
$end = (string) $promo['end_date'];

// Series para el gráfico: real vs contrafactual
$real = [];
$base = [];
$labels = [];
$bandFrom = null;
$bandTo = null;
foreach ($weekly as $i => $w) {
    $real[] = [(float) $i, (float) $w['units']];
    if ($w['baseline'] !== null) {
        $base[] = [(float) $i, (float) $w['baseline']];
    }
    if ($w['week'] >= $start && $w['week'] <= $end) {
        if ($bandFrom === null) { $bandFrom = (float) $i; }
        $bandTo = (float) $i;
    }
}
$n = count($weekly);
for ($k = 0; $k <= 4; $k++) {
    $idx = (int) round(($n - 1) * $k / 4);
    $labels[] = isset($weekly[$idx]) ? date('M y', strtotime((string) $weekly[$idx]['week'])) : '';
}
?>

<div class="page-head">
  <div>
    <a href="<?= $app->url('campaigns') ?>" style="font-size:12.5px;color:var(--text-dim)">← Campañas</a>
    <h1 class="page-title mt8"><?= App::e($promo['combo']) ?></h1>
    <p class="page-sub">
      <?= App::e($promo['product_name']) ?> ·
      <?= App::e(date('d/m/Y', strtotime($start))) ?> – <?= App::e(date('d/m/Y', strtotime($end))) ?> ·
      <?= (int) $promo['weeks'] ?> semanas
    </p>
  </div>
  <a class="btn btn-primary" href="<?= $app->url('simulator', ['sku' => $promo['product_code'], 'd' => round(((float) $promo['discount']) * 100, 1), 'w' => $promo['weeks']]) ?>">
    Reformular en el simulador
  </a>
</div>

<div class="verdict <?= $below ? 'v-blocked' : ($cov >= 1 ? 'v-approve' : ($cov >= .75 ? 'v-review' : 'v-reject')) ?> mb14">
  <div class="verdict-light"><?= pg_verdict_icon($below ? 'blocked' : ($cov >= 1 ? 'approve' : ($cov >= .75 ? 'review' : 'reject'))) ?></div>
  <div style="flex:1">
    <div class="verdict-title">
      <?php if ($below): ?>
        Vendió por debajo del costo
      <?php elseif ($cov >= 1): ?>
        Se pagó sola
      <?php else: ?>
        Alcanzó <?= App::pct($cov) ?> del uplift que necesitaba
      <?php endif; ?>
    </div>
    <div class="verdict-note">
      Descuento de <?= App::pct((float) $promo['discount']) ?> sobre un SKU cuyo punto de equilibrio es
      <?= App::pct((float) $promo['breakeven_discount']) ?>
    </div>
  </div>
  <div style="text-align:right">
    <div style="font-size:10.5px;letter-spacing:.06em;text-transform:uppercase;color:var(--text-faint)">Margen incremental</div>
    <div class="num" style="font-size:26px;font-weight:680;letter-spacing:-.03em;color:<?= ((float) $promo['incremental_margin']) < 0 ? 'var(--bad)' : 'var(--good)' ?>">
      <?= App::compact((float) $promo['incremental_margin']) ?>
    </div>
  </div>
</div>

<div class="grid g4 mb14">
  <div class="card kpi">
    <div class="kpi-label">Unidades vendidas</div>
    <div class="kpi-value sm num"><?= App::num((float) $promo['actual_units']) ?></div>
    <div class="kpi-note">contrafactual: <?= App::num((float) $promo['baseline_units']) ?></div>
  </div>
  <div class="card kpi">
    <div class="kpi-label">Uplift real</div>
    <div class="kpi-value sm num txt-accent"><?= App::pct(((float) $promo['uplift_obs_pct']) / 100) ?></div>
    <div class="kpi-note">+<?= App::num((float) $promo['incremental_units']) ?> unidades</div>
  </div>
  <div class="card kpi is-warn">
    <div class="kpi-label">Uplift requerido</div>
    <div class="kpi-value sm num txt-warn">
      <?= $promo['uplift_req_pct'] === null ? '∞' : App::pct(((float) $promo['uplift_req_pct']) / 100) ?>
    </div>
    <div class="kpi-note">para cubrir el costo del descuento</div>
  </div>
  <div class="card kpi is-bad">
    <div class="kpi-label">Costo del descuento</div>
    <div class="kpi-value sm num txt-bad"><?= App::compact((float) $promo['discount_cost']) ?></div>
    <div class="kpi-note">ganancia por volumen: <?= App::compact((float) $promo['volume_gain']) ?></div>
  </div>
</div>

<div class="card mb14">
  <div class="card-title">Demanda real contra el contrafactual</div>
  <?= pg_line_chart(
      $base !== [] ? [$real, $base] : [$real],
      [
        'height'  => 260,
        'xlabels' => $labels,
        'colors'  => ['var(--accent)', 'var(--text-faint)'],
        'dashes'  => [null, '5 4'],
        'band'    => $bandFrom !== null ? [$bandFrom, $bandTo] : null,
      ]
  ) ?>
  <div class="chart-legend">
    <span><i class="swatch" style="background:var(--accent)"></i> demanda real</span>
    <span><i class="swatch" style="background:var(--text-faint)"></i> contrafactual (sin promoción)</span>
    <span><i class="swatch" style="background:var(--warn);opacity:.5"></i> ventana promocional</span>
  </div>
</div>

<div class="grid g2">
  <div class="card">
    <div class="card-title">Descomposición</div>
    <div class="formula mb14">margen = I·(P−C) − A<sub>promo</sub>·P·d</div>
    <dl style="margin:0">
      <div class="stat-row"><dt>Unidades incrementales (I)</dt><dd class="num">+<?= App::num((float) $promo['incremental_units']) ?></dd></div>
      <div class="stat-row"><dt>Unidades en promoción</dt><dd class="num"><?= App::num((float) $promo['promo_units']) ?></dd></div>
      <div class="stat-row"><dt>Ganancia por volumen</dt><dd class="num txt-good"><?= App::money((float) $promo['volume_gain']) ?></dd></div>
      <div class="stat-row"><dt>Costo del descuento</dt><dd class="num txt-bad">−<?= App::money((float) $promo['discount_cost']) ?></dd></div>
      <div class="stat-row" style="border-top:1px solid var(--line);padding-top:11px">
        <dt style="font-weight:600;color:var(--text)">Margen incremental</dt>
        <dd class="num <?= ((float) $promo['incremental_margin']) < 0 ? 'txt-bad' : 'txt-good' ?>" style="font-size:15px"><?= App::money((float) $promo['incremental_margin']) ?></dd>
      </div>
    </dl>
  </div>

  <div class="card">
    <div class="card-title">Por qué salió así</div>
    <?php if ($below): ?>
      <div class="note mb14">
        <strong>El descuento superó el punto de equilibrio del SKU.</strong>
        Con un markup de <?= App::pct((float) $promo['markup']) ?> sobre costo, el margen sobre ingreso es
        <?= App::pct((float) $promo['breakeven_discount']) ?>. Un descuento de <?= App::pct((float) $promo['discount']) ?>
        deja el precio por debajo del costo unitario.
      </div>
      <div class="note">
        Es la peor clase de promoción: <strong>mientras más volumen mueve, más dinero pierde</strong>.
        Su uplift de <?= App::pct(((float) $promo['uplift_obs_pct']) / 100) ?> — de los más altos del catálogo —
        amplificó la pérdida en lugar de compensarla.
      </div>
    <?php else: ?>
      <div class="note mb14">
        El descuento se aplicó a las <strong><?= App::num((float) $promo['promo_units']) ?></strong> unidades del periodo,
        pero sólo <strong><?= App::num((float) $promo['incremental_units']) ?></strong> fueron incrementales.
        Las demás se habrían vendido igual a precio de lista.
      </div>
      <div class="note">
        Para cubrir su costo necesitaba <strong><?= $promo['uplift_req_pct'] === null ? '∞' : App::pct(((float) $promo['uplift_req_pct']) / 100) ?></strong>
        de uplift y logró <strong><?= App::pct(((float) $promo['uplift_obs_pct']) / 100) ?></strong>:
        cobertura de <strong><?= number_format($cov, 2) ?></strong>.
        <?php if ($cov >= 0.6): ?>
          Está lo bastante cerca del umbral como para que valga la pena reformularla con menor profundidad.
        <?php else: ?>
          La brecha es demasiado grande para cerrarla ajustando la profundidad.
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
