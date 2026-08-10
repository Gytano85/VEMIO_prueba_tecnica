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
        <?php else: ?>Alcanzó <?= App::pct($cov) ?> del uplift que necesitaba<?php endif; ?>
      </div>
      <div class="verdict-note">
        Descuento de <?= App::pct((float) $promo['discount']) ?> sobre un SKU con tope en <?= App::pct((float) $promo['breakeven_discount']) ?>
      </div>
    </div>
    <div class="verdict-amount">
      <div class="k">Margen incremental</div>
      <div class="v n"><?= App::compact((float) $promo['incremental_margin']) ?></div>
    </div>
  </div>

  <div class="card flush">
    <div class="metrics">
      <div class="metric">
        <div class="k">Unidades vendidas</div>
        <div class="v n"><?= App::num((float) $promo['actual_units']) ?></div>
        <div class="s">contrafactual: <?= App::num((float) $promo['baseline_units']) ?></div>
      </div>
      <div class="metric">
        <div class="k">Uplift real</div>
        <div class="v n"><?= App::pct(((float) $promo['uplift_obs_pct']) / 100) ?></div>
        <div class="s">necesario: <?= $promo['uplift_req_pct'] === null ? '—' : App::pct(((float) $promo['uplift_req_pct']) / 100) ?></div>
      </div>
      <div class="metric">
        <div class="k">Costo del descuento</div>
        <div class="v n"><?= App::compact((float) $promo['discount_cost']) ?></div>
        <div class="s">ganancia por volumen: <?= App::compact((float) $promo['volume_gain']) ?></div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="section-head" style="margin-bottom:var(--s4)">
      <h2>Demanda real contra el contrafactual</h2>
      <span class="meta">la zona sombreada es la ventana promocional</span>
    </div>
    <?= pg_chart($base !== [] ? [$real, $base] : [$real], [
        'height' => 230, 'xlabels' => $xl, 'fill' => false,
        'colors' => ['var(--accent)', 'var(--ink-3)'],
        'dashes' => [null, '4 4'],
        'band' => $b0 !== null ? [$b0, $b1] : null,
        'label' => 'Demanda semanal real frente al contrafactual sin promoción',
    ]) ?>
    <div class="legend">
      <span><i style="background:var(--accent)"></i> demanda real</span>
      <span><i style="background:var(--ink-3)"></i> contrafactual sin promoción</span>
    </div>
  </div>

  <div class="grid2">
    <div class="card">
      <div class="section-head" style="margin-bottom:var(--s4)"><h2>Descomposición</h2></div>
      <div class="formula" style="margin-bottom:var(--s4)">margen = I·(P−C) − A<sub>promo</sub>·P·d</div>
      <dl class="stats">
        <div><dt>Unidades incrementales (I)</dt><dd>+<?= App::num((float) $promo['incremental_units']) ?></dd></div>
        <div><dt>Unidades en promoción</dt><dd><?= App::num((float) $promo['promo_units']) ?></dd></div>
        <div><dt>Ganancia por volumen</dt><dd class="pos"><?= App::money((float) $promo['volume_gain']) ?></dd></div>
        <div><dt>Costo del descuento</dt><dd class="neg">−<?= App::money((float) $promo['discount_cost']) ?></dd></div>
        <div style="border-top:1px solid var(--line-strong);padding-top:10px">
          <dt style="color:var(--ink);font-weight:550">Margen incremental</dt>
          <dd class="<?= ((float) $promo['incremental_margin']) < 0 ? 'neg' : 'pos' ?>" style="font-size:15px"><?= App::money((float) $promo['incremental_margin']) ?></dd>
        </div>
      </dl>
    </div>

    <div class="card">
      <div class="section-head" style="margin-bottom:var(--s4)"><h2>Por qué salió así</h2></div>
      <?php if ($below): ?>
        <p class="note" style="margin-bottom:var(--s3)">
          El descuento superó el tope del SKU. Con un markup de <?= App::pct((float) $promo['markup'], 0) ?>,
          el margen sobre ingreso es <?= App::pct((float) $promo['breakeven_discount']) ?>, y un descuento de
          <?= App::pct((float) $promo['discount']) ?> deja el precio por debajo del costo.
        </p>
        <p class="note">
          Es la peor clase de promoción: <strong>mientras más volumen mueve, más dinero pierde</strong>.
          Su uplift de <?= App::pct(((float) $promo['uplift_obs_pct']) / 100) ?>, de los más altos del catálogo,
          amplificó la pérdida en lugar de compensarla.
        </p>
      <?php else: ?>
        <p class="note" style="margin-bottom:var(--s3)">
          El descuento se aplicó a las <strong><?= App::num((float) $promo['promo_units']) ?></strong> unidades del
          periodo, pero sólo <strong><?= App::num((float) $promo['incremental_units']) ?></strong> fueron
          incrementales. Las demás se habrían vendido igual a precio de lista.
        </p>
        <p class="note">
          Necesitaba <strong><?= $promo['uplift_req_pct'] === null ? '—' : App::pct(((float) $promo['uplift_req_pct']) / 100) ?></strong>
          de uplift y logró <strong><?= App::pct(((float) $promo['uplift_obs_pct']) / 100) ?></strong>.
          <?php if ($cov >= 0.6): ?>
            Está cerca del umbral, así que vale reformularla con menor profundidad.
          <?php else: ?>
            La brecha es demasiado grande para cerrarla ajustando la profundidad.
          <?php endif; ?>
        </p>
      <?php endif; ?>
    </div>
  </div>

</div>
