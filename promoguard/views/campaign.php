<?php
/** @var \PromoGuard\App $app @var array $promo @var array $weekly */
use PromoGuard\App;
require_once __DIR__ . '/partials/helpers.php';

$cov   = (float) $promo['coverage'];
$below = (int) $promo['sells_below_cost'] === 1;
$start = (string) $promo['start_date'];
$end   = (string) $promo['end_date'];
$verdict = $below ? 'blocked' : ($cov >= 1 ? 'approve' : ($cov >= .75 ? 'review' : 'reject'));

$real = $base = [];
$b0 = $b1 = null;
foreach ($weekly as $i => $w) {
    $real[] = [(float) $i, (float) $w['units']];
    if ($w['baseline'] !== null) $base[] = [(float) $i, (float) $w['baseline']];
    if ($w['week'] >= $start && $w['week'] <= $end) {
        if ($b0 === null) $b0 = (float) $i;
        $b1 = (float) $i;
    }
}
$xl = [];
$n = count($weekly);
for ($k = 0; $k <= 3; $k++) {
    $idx = (int) round(($n - 1) * $k / 3);
    $xl[] = isset($weekly[$idx]) ? date('M y', strtotime((string) $weekly[$idx]['week'])) : '';
}
?>

<div class="head">
  <div>
    <a href="<?= $app->url('campaigns') ?>" class="dim" style="font-size:13px">← Campañas</a>
    <h1 style="margin-top:var(--s2)"><?= App::e($promo['combo']) ?></h1>
    <p>
      <?= App::e($promo['product_name']) ?> ·
      <?= App::e(date('d/m/Y', strtotime($start))) ?> a <?= App::e(date('d/m/Y', strtotime($end))) ?> ·
      <?= (int) $promo['weeks'] ?> semanas
    </p>
  </div>
  <a class="btn" href="<?= $app->url('simulator', ['sku' => $promo['product_code'], 'd' => round(((float) $promo['discount']) * 100, 1), 'w' => $promo['weeks']]) ?>">
    Reformular en el simulador
  </a>
</div>

<div class="stack">

  <div class="verdict <?= pg_verdict_class($verdict) ?>">
    <span class="verdict-dot"></span>
    <div class="verdict-body">
      <div class="verdict-title">
        <?php if ($below): ?>Vendió por debajo del costo
        <?php elseif ($cov >= 1): ?>Se pagó sola
        <?php else: ?>Recuperó <?= App::pct(min(1.0, $cov)) ?> del dinero entregado en descuentos<?php endif; ?>
      </div>
      <div class="verdict-note">
        Descuento de <?= App::pct((float) $promo['discount']) ?> sobre un producto cuyo límite es <?= App::pct((float) $promo['breakeven_discount']) ?>
      </div>
    </div>
    <div class="verdict-amount">
      <div class="k">Ganancia / pérdida de la campaña</div>
      <div class="v n"><?= App::compact((float) $promo['incremental_margin']) ?></div>
    </div>
  </div>

  <div class="card flush">
    <div class="metrics">
      <div class="metric">
        <div class="k">Unidades vendidas</div>
        <div class="v n"><?= App::num((float) $promo['actual_units']) ?></div>
        <div class="s">estimadas sin promoción: <?= App::num((float) $promo['baseline_units']) ?></div>
      </div>
      <div class="metric">
        <div class="k">Aumento real en ventas</div>
        <div class="v n"><?= App::pct(((float) $promo['uplift_obs_pct']) / 100) ?></div>
        <div class="s">para no perder: <?= $promo['uplift_req_pct'] === null ? '—' : App::pct(((float) $promo['uplift_req_pct']) / 100) ?></div>
      </div>
      <div class="metric">
        <div class="k">Costo del descuento</div>
        <div class="v n"><?= App::compact((float) $promo['discount_cost']) ?></div>
        <div class="s">recuperado por ventas extra: <?= App::compact((float) $promo['volume_gain']) ?></div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="section-head" style="margin-bottom:var(--s4)">
      <h2>Ventas reales contra ventas esperadas sin promoción</h2>
      <span class="meta">la zona sombreada es la ventana promocional</span>
    </div>
    <?= pg_chart($base !== [] ? [$real, $base] : [$real], [
        'height' => 230, 'xlabels' => $xl, 'fill' => false,
        'colors' => ['var(--accent)', 'var(--ink-3)'],
        'dashes' => [null, '4 4'],
        'band' => $b0 !== null ? [$b0, $b1] : null,
        'label' => 'Ventas semanales reales frente a ventas esperadas sin promoción',
    ]) ?>
    <div class="legend">
      <span><i style="background:var(--accent)"></i> demanda real</span>
      <span><i style="background:var(--ink-3)"></i> estimación sin promoción</span>
    </div>
  </div>

  <details class="advanced-block">
    <summary>Ver cómo se calculó</summary>
    <div class="advanced-content grid2">
    <div class="card">
      <div class="section-head" style="margin-bottom:var(--s4)"><h2>De dónde salió el resultado</h2></div>
      <dl class="stats">
        <div><dt>Ventas extra</dt><dd>+<?= App::num((float) $promo['incremental_units']) ?></dd></div>
        <div><dt>Unidades en promoción</dt><dd><?= App::num((float) $promo['promo_units']) ?></dd></div>
        <div><dt>Recuperado por ventas extra</dt><dd class="pos"><?= App::money((float) $promo['volume_gain']) ?></dd></div>
        <div><dt>Costo del descuento</dt><dd class="neg">−<?= App::money((float) $promo['discount_cost']) ?></dd></div>
        <div style="border-top:1px solid var(--line-strong);padding-top:10px">
          <dt style="color:var(--ink);font-weight:550">Ganancia / pérdida final</dt>
          <dd class="<?= ((float) $promo['incremental_margin']) < 0 ? 'neg' : 'pos' ?>" style="font-size:15px"><?= App::money((float) $promo['incremental_margin']) ?></dd>
        </div>
      </dl>
    </div>

    <div class="card">
      <div class="section-head" style="margin-bottom:var(--s4)"><h2>Por qué salió así</h2></div>
      <?php if ($below): ?>
        <p class="note" style="margin-bottom:var(--s3)">
          El descuento superó el límite de este producto. Gana <?= App::pct((float) $promo['markup'], 0) ?> sobre su costo,
          el margen sobre ingreso es <?= App::pct((float) $promo['breakeven_discount']) ?>, y un descuento de
          <?= App::pct((float) $promo['discount']) ?> deja el precio por debajo del costo.
        </p>
        <p class="note">
          Es la peor clase de promoción: <strong>mientras más volumen mueve, más dinero pierde</strong>.
          Vendió <?= App::pct(((float) $promo['uplift_obs_pct']) / 100) ?> más de lo normal, de lo más alto
          del catálogo, y eso amplificó la pérdida en lugar de compensarla.
        </p>
      <?php else: ?>
        <p class="note" style="margin-bottom:var(--s3)">
          El descuento se aplicó a las <strong><?= App::num((float) $promo['promo_units']) ?></strong> unidades del
          periodo, pero sólo <strong><?= App::num((float) $promo['incremental_units']) ?></strong> fueron
          incrementales. Las demás se habrían vendido igual a precio de lista.
        </p>
        <p class="note">
          Necesitaba aumentar las ventas <strong><?= $promo['uplift_req_pct'] === null ? '—' : App::pct(((float) $promo['uplift_req_pct']) / 100) ?></strong>
          y logró <strong><?= App::pct(((float) $promo['uplift_obs_pct']) / 100) ?></strong>.
          <?php if ($cov >= 0.6): ?>
            Está cerca del umbral, así que vale reformularla con menor profundidad.
          <?php else: ?>
            La brecha es demasiado grande para cerrarla ajustando la profundidad.
          <?php endif; ?>
        </p>
      <?php endif; ?>
    </div>
  </details>

</div>
