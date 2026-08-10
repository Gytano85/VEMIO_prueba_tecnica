<?php
/** @var \PromoGuard\App $app @var array $skus @var array $sku @var array $sim @var array $paths
 *  @var array $curve @var array $analogs @var array $advice @var string $aiMode
 *  @var array $weekly @var array $forecast */
use PromoGuard\App;
require_once __DIR__ . '/partials/helpers.php';

$be    = (float) $sku['breakeven_discount'];
$scale = 0.35;
$gain  = (float) $sim['volume_gain'];
$cost  = (float) $sim['discount_cost'];
$net   = (float) $sim['incremental_margin'];
$peak  = max(abs($gain), abs($cost), abs($net), 1);

// Serie de proyección de demanda (absorbe la antigua pantalla de Proyección)
$hist = array_slice($weekly, -32);
$fBase = array_values(array_filter($forecast, static fn(array $f): bool => $f['scenario'] === 'base'));
$fPromo = array_values(array_filter($forecast, static fn(array $f): bool => $f['scenario'] === 'promo'));
$sH = $sB = $sP = [];
$i = 0;
foreach ($hist as $w) { $sH[] = [(float) $i, (float) $w['units']]; $i++; }
$anchor = $i - 1;
$lastU = $hist !== [] ? (float) end($hist)['units'] : 0.0;
if ($hist !== []) { $sB[] = [(float) $anchor, $lastU]; $sP[] = [(float) $anchor, $lastU]; }
$j = $i; foreach ($fBase as $f)  { $sB[] = [(float) $j, (float) $f['units']]; $j++; }
$j = $i; foreach ($fPromo as $f) { $sP[] = [(float) $j, (float) $f['units']]; $j++; }
$allW = array_merge(array_column($hist, 'week'), array_column($fBase, 'week'));
$xl = [];
for ($k = 0; $k <= 3; $k++) {
    $idx = (int) round((count($allW) - 1) * $k / 3);
    $xl[] = isset($allW[$idx]) ? date('M y', strtotime((string) $allW[$idx])) : '';
}
$avgB = $fBase  ? array_sum(array_column($fBase, 'units')) / count($fBase)   : 0.0;
$avgP = $fPromo ? array_sum(array_column($fPromo, 'units')) / count($fPromo) : 0.0;

// Punto actual sobre la curva de margen
$mk = null;
foreach ($curve as $c) {
    if ($mk === null || abs($c['discount'] - $sim['discount']) < abs($mk[0] - $sim['discount'])) {
        $mk = [$c['discount'], $c['incremental_margin']];
    }
}
?>

<div class="head">
  <div>
    <h1>Simulador</h1>
    <p>Elige una promoción y descubre si hará ganar o perder dinero.</p>
  </div>
  <form method="post" action="<?= $app->url('save') ?>">
    <input type="hidden" name="_t" value="<?= App::e(App::csrfToken()) ?>">
    <input type="hidden" name="sku" value="<?= (int) $sku['product_code'] ?>">
    <input type="hidden" name="d" id="saveD" value="<?= round($sim['discount'] * 100, 1) ?>">
    <input type="hidden" name="w" id="saveW" value="<?= (int) $sim['weeks'] ?>">
    <button class="btn" type="submit">Guardar escenario</button>
  </form>
</div>

<div class="split">

  <!-- Controles -->
  <form class="sticky stack" id="controls" method="get" action="">
    <input type="hidden" name="r" value="simulator">
    <div>
      <label class="field-label" for="skuSelect">Producto</label>
      <select id="skuSelect" name="sku" style="margin-top:var(--s2)">
        <?php foreach ($skus as $s): ?>
          <option value="<?= (int) $s['product_code'] ?>"<?= (int) $s['product_code'] === (int) $sku['product_code'] ? ' selected' : '' ?>>
            <?= App::e($s['product_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <div class="field-head">
        <span class="field-label">Descuento</span>
        <span class="field-value n" id="dLabel"><?= App::pct($sim['discount']) ?></span>
      </div>
      <input type="range" id="dRange" name="d" min="0" max="35" step="0.5" value="<?= round($sim['discount'] * 100, 1) ?>"
             aria-label="Profundidad de descuento en porcentaje">
      <div class="gauge">
        <div class="gauge-track">
          <span class="gauge-safe" style="width:<?= round($be / $scale * 100, 1) ?>%"></span>
          <span class="gauge-over" style="width:<?= round((1 - $be / $scale) * 100, 1) ?>%"></span>
          <span class="gauge-limit" style="left:<?= round($be / $scale * 100, 1) ?>%"></span>
          <span class="gauge-pin" id="pin" style="left:<?= round(min($sim['discount'], $scale) / $scale * 100, 1) ?>%"></span>
        </div>
        <div class="gauge-labels">
          <span>0%</span>
          <span class="gauge-limit-label">tope <?= App::pct($be) ?></span>
          <span>35%</span>
        </div>
      </div>
    </div>

    <div class="field">
      <div class="field-head">
        <span class="field-label">Duración</span>
        <span class="field-value n" id="wLabel"><?= (int) $sim['weeks'] ?> sem</span>
      </div>
      <input type="range" id="wRange" name="w" min="1" max="16" step="1" value="<?= (int) $sim['weeks'] ?>" aria-label="Duración en semanas">
      <div class="scale"><span>1</span><span>16 semanas</span></div>
    </div>

    <div class="field">
      <div class="field-head">
        <span class="field-label">Aumento esperado en ventas</span>
        <span class="field-value n" id="uLabel"><?= App::pct($sim['expected_uplift_pct'] / 100) ?></span>
      </div>
      <input type="range" id="uRange" name="u" min="0" max="400" step="5" value="<?= round($sim['expected_uplift_pct']) ?>" aria-label="Uplift esperado en porcentaje">
      <div class="scale">
        <span id="modelHint">estimación histórica: <?= App::pct($sim['model_uplift_pct'] / 100) ?></span>
        <button type="button" class="btn btn-sm" id="resetModel">Usar estimación</button>
      </div>
    </div>

    <details class="simple-details">
      <summary>Ver datos del producto</summary>
      <div class="simple-details-body">
      <dl class="stats">
        <div><dt>Costo unitario</dt><dd><?= App::money((float) $sku['unit_cost'], 2) ?></dd></div>
        <div><dt>Precio de lista</dt><dd><?= App::money((float) $sku['list_price'], 2) ?></dd></div>
        <div><dt>Ganancia sobre costo</dt><dd><?= App::pct((float) $sku['markup'], 0) ?></dd></div>
        <div><dt>Ganancia sobre venta</dt><dd><?= App::pct((float) $sku['margin_on_revenue']) ?></dd></div>
        <div>
          <dt>Respuesta de ventas al precio</dt>
          <dd>
            <?= $sku['elasticity'] !== null ? number_format((float) $sku['elasticity'], 2) : '—' ?>
            <?php $q = $sim['elasticity_quality']; ?>
            <span class="quality quality-<?= App::e($q['level']) ?>" title="<?= App::e($q['note']) ?>"><?= App::e($q['label']) ?></span>
          </dd>
        </div>
        <div><dt>Ventas habituales</dt><dd><?= App::num((float) $sku['baseline_weekly']) ?> unidades por semana</dd></div>
      </dl>
      </div>
    </details>
    <noscript>
      <button class="btn btn-primary" type="submit" style="width:100%;justify-content:center">Recalcular</button>
      <p class="note" style="margin-top:var(--s3)">
        Con JavaScript activo el simulador recalcula solo al mover los controles.
      </p>
    </noscript>
  </form>

  <!-- Resultado -->
  <div class="stack" id="results">

    <div class="verdict <?= pg_verdict_class((string) $sim['verdict']) ?>" id="verdictBox">
      <span class="verdict-dot" id="verdictDot"></span>
      <div class="verdict-body">
        <div class="verdict-title" id="verdictTitle"><?= App::e((string) $sim['verdict_label']) ?></div>
        <div class="verdict-note" id="verdictNote">
          <?php if ($sim['sells_below_cost']): ?>
            El precio promocional (<?= App::money((float) $sim['promo_price'], 2) ?>) queda debajo del costo unitario (<?= App::money((float) $sim['unit_cost'], 2) ?>).
          <?php elseif ($sim['required_uplift_pct'] !== null): ?>
            Necesita aumentar las ventas <?= App::pct($sim['required_uplift_pct'] / 100) ?> y este escenario estima <?= App::pct($sim['expected_uplift_pct'] / 100) ?>.
          <?php endif; ?>
        </div>
      </div>
      <div class="verdict-amount">
        <div class="k">Ganancia o pérdida</div>
        <div class="v n" id="marginBig"><?= App::compact($net) ?></div>
        <a class="verdict-link" href="#profitBuilder" id="profitLink"><?= $net < 0 ? 'Hacerla rentable' : 'Ver resultado' ?></a>
      </div>
    </div>

    <section class="profit-builder" id="profitBuilder" aria-labelledby="profitTitle">
      <div class="profit-head">
        <div>
          <div class="eyebrow">Ruta a rentabilidad</div>
          <h2 id="profitTitle">Cómo evitar perder dinero</h2>
          <p id="profitHeadline"><?= App::e((string) $paths['headline']) ?></p>
        </div>
        <div class="profit-gap">
          <span>Dinero que falta recuperar</span>
          <strong class="n" id="profitGap"><?= App::compact((float) $paths['gap']) ?></strong>
        </div>
      </div>

      <div class="profit-routes">
        <article class="profit-route<?= $paths['recommended'] === 'funding' ? ' is-recommended' : '' ?>" id="routeFunding">
          <div class="route-top"><span class="route-number">01</span><span class="route-source">Cálculo directo</span></div>
          <h3>Aporte del proveedor</h3>
          <div class="route-value n" id="fundingShare"><?= App::pct((float) $paths['funding']['share']) ?> del descuento</div>
          <p><strong id="fundingAmount"><?= App::compact((float) $paths['funding']['amount']) ?></strong> en total, equivalente a <strong id="fundingUnit"><?= App::money((float) $paths['funding']['per_unit'], 2) ?></strong> por unidad promocional.</p>
        </article>

        <article class="profit-route<?= $paths['recommended'] === 'targeting' ? ' is-recommended' : '' ?>" id="routeTargeting">
          <div class="route-top"><span class="route-number">02</span><span class="route-source assumption">Opción por validar</span></div>
          <h3>Promoción dirigida</h3>
          <div class="route-value n" id="targetShare">Incentivar máximo <?= App::pct((float) $paths['targeting']['max_share']) ?></div>
          <p>Limitar el beneficio a <strong id="targetUnits"><?= App::num((float) $paths['targeting']['max_units']) ?></strong> unidades y dejar <strong id="excludedUnits"><?= App::num((float) $paths['targeting']['units_without_subsidy']) ?></strong> sin subsidio.</p>
        </article>

        <article class="profit-route<?= $paths['recommended'] === 'mechanic' ? ' is-recommended' : '' ?>" id="routeUplift">
          <div class="route-top"><span class="route-number">03</span><span class="route-source assumption">Estimación histórica</span></div>
          <h3>Ventas extra necesarias</h3>
          <div class="route-value n" id="upliftRequired"><?php if ($paths['uplift']['possible']): ?>Aumentar <?= App::pct((float) $paths['uplift']['required_pct'] / 100) ?><?php else: ?>No se resuelve vendiendo más<?php endif; ?></div>
          <p id="upliftDetail"><?php if ($paths['uplift']['possible']): ?>Faltan <strong><?= App::num((float) $paths['uplift']['additional_units']) ?></strong> unidades incrementales para llegar al equilibrio.<?php else: ?>El precio queda debajo del costo. Cambia la mecánica o consigue financiamiento externo.<?php endif; ?></p>
        </article>
      </div>
      <div class="profit-next">
        <span>Prueba recomendada</span>
        <strong id="profitNext"><?= App::e((string) $paths['next_action']) ?></strong>
        <button class="btn btn-sm" type="button" id="tryRequiredUplift" data-value="<?= $paths['uplift']['required_pct'] === null ? '' : App::e((string) $paths['uplift']['required_pct']) ?>"<?= !$paths['uplift']['testable'] ? ' hidden' : '' ?>>Probar ventas mínimas</button>
      </div>
      <p class="profit-disclaimer">Son estimaciones para no perder dinero, no una ganancia garantizada. La opción dirigida debe validarse con una prueba pequeña.</p>
    </section>

    <details class="advanced-block">
      <summary>Ver explicación y datos detallados</summary>
      <div class="advanced-content stack">

    <div class="card flush">
      <div class="metrics">
        <div class="metric">
          <div class="k">Ventas extra necesarias</div>
          <div class="v n" data-f="required_uplift_pct"><?= $sim['required_uplift_pct'] === null ? '—' : App::pct($sim['required_uplift_pct'] / 100) ?></div>
          <div class="s">en este escenario <span data-f="expected_uplift_pct"><?= App::pct($sim['expected_uplift_pct'] / 100) ?></span></div>
        </div>
        <div class="metric">
          <div class="k">Descuento recuperado</div>
          <div class="v n" data-f="coverage"><?= App::pct(min(1.0, (float) $sim['coverage'])) ?></div>
          <div class="s">100% significa que no pierde dinero</div>
        </div>
        <div class="metric">
          <div class="k"><?= !empty($sim['structurally_viable']) ? 'Descuento máximo rentable' : '¿Puede funcionar sólo con descuento?' ?></div>
          <?php if (!empty($sim['structurally_viable'])): ?>
            <div class="v n pos" data-f="max_viable_discount"><?= App::pct((float) $sim['max_viable_discount']) ?></div>
            <div class="s">el más profundo que se paga solo</div>
          <?php else: ?>
            <div class="v n neg">No</div>
            <div class="s">las ventas reaccionan muy poco al descuento</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="grid2">
      <div class="card">
        <div class="section-head" style="margin-bottom:var(--s4)"><h2>De dónde sale el margen</h2></div>
        <div class="bars">
          <div class="bar-col">
            <span class="bar-val n pos" data-b="gain"><?= App::compact($gain) ?></span>
            <span class="bar bar-pos" data-bar="gain" style="height:<?= round(abs($gain) / $peak * 100) ?>%"></span>
            <span class="bar-key">Ganancia por<br>ventas extra</span>
          </div>
          <div class="bar-col">
            <span class="bar-val n neg" data-b="cost">−<?= App::compact($cost) ?></span>
            <span class="bar bar-neg" data-bar="cost" style="height:<?= round(abs($cost) / $peak * 100) ?>%"></span>
            <span class="bar-key">Costo del<br>descuento</span>
          </div>
          <div class="bar-col">
            <span class="bar-val n <?= $net < 0 ? 'neg' : 'pos' ?>" data-b="net"><?= App::compact($net) ?></span>
            <span class="bar <?= $net < 0 ? 'bar-net-neg' : 'bar-net-pos' ?>" data-bar="net" style="height:<?= round(abs($net) / $peak * 100) ?>%"></span>
            <span class="bar-key">Ganancia o<br>pérdida final</span>
          </div>
        </div>
        <p class="note" style="margin-top:var(--s4)">
          El descuento se aplica a <strong data-f="promo_units"><?= App::num((float) $sim['promo_units']) ?></strong> unidades,
          aunque sólo <strong data-f="incremental_units"><?= App::num((float) $sim['incremental_units']) ?></strong> son ventas nuevas.
        </p>
      </div>

      <div class="card">
        <div class="section-head" style="margin-bottom:var(--s4)">
          <h2>Ganancia o pérdida según el descuento</h2>
          <span class="meta"><span id="curveWeeks"><?= (int) $sim['weeks'] ?></span> sem</span>
        </div>
        <div id="curveChart">
          <?php
            $pts = array_map(static fn(array $c): array => [$c['discount'] * 100, $c['incremental_margin']], $curve);
            $labels = [];
            $maxD = $curve[count($curve) - 1]['discount'] * 100;
            for ($k = 0; $k <= 3; $k++) $labels[] = number_format($maxD * $k / 3, 0) . '%';
            echo pg_chart([$pts], [
                'height' => 176, 'xlabels' => $labels,
                'vline' => $be * 100,
                'marker' => $mk ? [$mk[0] * 100, $mk[1]] : null,
                'label' => 'Margen incremental según la profundidad de descuento',
            ]);
          ?>
        </div>
        <div class="legend">
          <span><i style="background:var(--accent)"></i> margen proyectado</span>
          <span><i style="background:var(--neg)"></i> tope <?= App::pct($be) ?></span>
        </div>
      </div>
    </div>

    <!-- Dictamen -->
    <div class="card">
      <div class="section-head" style="margin-bottom:var(--s4)">
        <h2>Qué significa</h2>
        <span class="meta" id="adviceSource"><?= App::e((string) $advice['source']) ?></span>
      </div>
      <div class="brief">
        <p class="brief-lead" id="adviceHeadline"><?= App::e((string) $advice['headline']) ?></p>
        <ul id="adviceBullets">
          <?php foreach ($advice['bullets'] as $b): ?><li><?= App::e($b) ?></li><?php endforeach; ?>
        </ul>
        <div class="brief-actions">
          <div class="k">Recomendación</div>
          <ul id="adviceActions">
            <?php foreach ($advice['actions'] as $a): ?><li><?= App::e($a) ?></li><?php endforeach; ?>
          </ul>
        </div>
      </div>
    </div>

    <!-- Proyección de demanda -->
    <?php if ($sH !== []): ?>
    <div class="card">
      <div class="section-head" style="margin-bottom:var(--s4)">
        <h2>Ventas esperadas</h2>
        <span class="meta">10 semanas · <?= App::num($avgB) ?> u/sem sin promo · <?= App::num($avgP) ?> con promo al 15%</span>
      </div>
      <?= pg_chart([$sH, $sB, $sP], [
            'height' => 200, 'xlabels' => $xl, 'fill' => false,
            'colors' => ['var(--accent)', 'var(--ink-3)', 'var(--warn)'],
            'dashes' => [null, '4 4', '2 3'],
            'label' => 'Demanda histórica y proyectada por semana',
      ]) ?>
      <div class="legend">
        <span><i style="background:var(--accent)"></i> histórico</span>
        <span><i style="background:var(--ink-3)"></i> sin promoción</span>
        <span><i style="background:var(--warn)"></i> con promoción 15%</span>
      </div>
      <p class="note" style="margin-top:var(--s4)">
        Sirve para dimensionar reabasto, no para justificar la promoción. Si el escenario con
        promo es el que vas a planear, revisa primero arriba si esa promoción se paga sola.
      </p>
    </div>
    <?php endif; ?>

    <!-- Histórico del producto -->
    <?php if ($analogs !== []): ?>
    <div class="card flush">
      <div class="section-head" style="padding:var(--s5) var(--s5) var(--s3);margin-bottom:0">
        <h2>Historial de este producto</h2>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr>
            <th style="padding-left:var(--s5)">Campaña</th>
            <th class="num">Descuento</th><th class="num">Ventas extra</th>
            <th class="num">Descuento recuperado</th><th class="num" style="padding-right:var(--s5)">Ganancia / pérdida</th>
          </tr></thead>
          <tbody>
          <?php foreach ($analogs as $a):
              $cov = (float) $a['coverage'];
              $below = (int) $a['sells_below_cost'] === 1;
          ?>
            <tr class="linked" tabindex="0" role="link" data-href="<?= $app->url('campaign', ['id' => $a['id_combo']]) ?>">
              <td style="padding-left:var(--s5)">
                <div class="cell-main"><?= App::e($a['combo']) ?></div>
                <div class="cell-sub"><?= App::e(substr((string) $a['start_date'], 0, 7)) ?></div>
              </td>
              <td class="num"><?= App::pct((float) $a['discount']) ?></td>
              <td class="num"><?= App::pct(((float) $a['uplift_obs_pct']) / 100) ?></td>
              <td class="num"><span class="tag <?= pg_tag($cov, $below) ?>"><?= $below ? 'bajo costo' : App::pct(min(1.0, $cov), 0) ?></span></td>
              <td class="num <?= ((float) $a['incremental_margin']) < 0 ? 'neg' : 'pos' ?>" style="padding-right:var(--s5)"><?= App::compact((float) $a['incremental_margin']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

      </div>
    </details>

  </div>
</div>

<script>
window.PG = {
  sku: <?= (int) $sku['product_code'] ?>,
  scale: <?= $scale ?>,
  breakeven: <?= $be ?>
};
</script>
