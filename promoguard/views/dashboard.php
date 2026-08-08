<?php
/** @var \PromoGuard\App $app */
/** @var array $headline @var array $promotions @var array $skus @var array $portfolio @var array $meta */
use PromoGuard\App;
require_once __DIR__ . '/partials/helpers.php';

$marginTotal = (float) ($headline['margin_total'] ?? 0);
$total = (int) ($headline['total'] ?? 0);
$profitable = (int) ($headline['profitable'] ?? 0);
$belowCost = (int) ($headline['below_cost'] ?? 0);
$best = (float) ($headline['best_coverage'] ?? 0);
?>

<div class="page-head">
  <div>
    <h1 class="page-title">Diagnóstico del portafolio promocional</h1>
    <p class="page-sub">
      <?= App::num((float) ($headline['sku_count'] ?? 0)) ?> SKUs ·
      <?= App::num((float) ($headline['week_count'] ?? 0)) ?> semanas ·
      <?= $total ?> promociones evaluadas contra su contrafactual
    </p>
  </div>
  <a class="btn btn-primary" href="<?= $app->url('simulator') ?>">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
    Evaluar nueva promoción
  </a>
</div>

<!-- ------------------------------------------------------------- KPIs -->
<div class="grid g4 mb14">
  <div class="card kpi <?= $marginTotal < 0 ? 'is-bad' : 'is-good' ?>">
    <div class="kpi-label">Margen del portafolio</div>
    <div class="kpi-value num <?= $marginTotal < 0 ? 'txt-bad' : 'txt-good' ?>"><?= App::compact($marginTotal) ?></div>
    <div class="kpi-note">Acumulado de las <?= $total ?> campañas históricas</div>
  </div>

  <div class="card kpi <?= $profitable === 0 ? 'is-bad' : 'is-good' ?>">
    <div class="kpi-label">Se pagaron solas</div>
    <div class="kpi-value num"><?= $profitable ?> <span class="txt-dim" style="font-size:19px">/ <?= $total ?></span></div>
    <div class="kpi-note">Mejor cobertura alcanzada: <strong class="txt-warn"><?= App::pct($best) ?></strong></div>
  </div>

  <div class="card kpi <?= $belowCost > 0 ? 'is-bad' : 'is-good' ?>">
    <div class="kpi-label">Vendieron bajo costo</div>
    <div class="kpi-value num <?= $belowCost > 0 ? 'txt-block' : 'txt-good' ?>"><?= $belowCost ?></div>
    <div class="kpi-note">Descuento por encima del punto de equilibrio</div>
  </div>

  <div class="card kpi is-warn">
    <div class="kpi-label">Costo del descuento</div>
    <div class="kpi-value num txt-warn"><?= App::compact((float) ($headline['discount_total'] ?? 0)) ?></div>
    <div class="kpi-note">Contra <?= App::compact((float) ($headline['volume_total'] ?? 0)) ?> ganados por volumen</div>
  </div>
</div>

<!-- --------------------------------------------------- asesor + topes -->
<div class="grid g-side mb14">

  <div class="advisor">
    <div class="advisor-head">
      <div class="advisor-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 2l2.2 6.2L20 10l-5.8 1.8L12 18l-2.2-6.2L4 10l5.8-1.8z"/>
        </svg>
      </div>
      <div>
        <div class="advisor-title">Lectura del asesor</div>
        <div class="advisor-src">Motor de reglas sobre la economía real de cada SKU</div>
      </div>
    </div>
    <div class="advisor-body">
      <div class="advisor-headline"><?= App::e($portfolio['headline']) ?></div>
      <ul class="adv-list">
        <?php foreach ($portfolio['bullets'] as $b): ?>
          <li><?= App::e($b) ?></li>
        <?php endforeach; ?>
      </ul>
      <?php if (!empty($portfolio['actions'])): ?>
        <div class="adv-actions">
          <div class="adv-sub">Qué hacer</div>
          <ul class="adv-list">
            <?php foreach ($portfolio['actions'] as $a): ?>
              <li><?= App::e($a) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-title">Tope de descuento por SKU</div>
    <div class="note mb14">
      <strong>product_margin es un markup sobre costo, no un margen sobre ingreso.</strong>
      El margen real sobre ingreso es <code>m/(1+m)</code>, y ese mismo número es el descuento
      máximo antes de vender a pérdida.
    </div>
    <?php foreach ($skus as $s):
        $be = (float) $s['breakeven_discount'];
        $deepest = 0.0;
        foreach ($promotions as $p) {
            if ((int) $p['product_code'] === (int) $s['product_code']) {
                $deepest = max($deepest, (float) $p['discount']);
            }
        }
        $violated = $deepest > $be;
    ?>
      <div style="padding:9px 0;border-bottom:1px solid var(--line-soft)">
        <div class="flex between items-center" style="margin-bottom:6px">
          <span style="font-size:13px"><?= App::e($s['product_name']) ?></span>
          <span class="pill <?= $violated ? 'pill-block' : 'pill-good' ?>">tope <?= App::pct($be) ?></span>
        </div>
        <div class="breakeven-bar" style="height:8px">
          <div class="be-safe" style="width:<?= round($be / 0.30 * 100, 1) ?>%"></div>
          <div class="be-line" style="left:<?= round($be / 0.30 * 100, 1) ?>%"></div>
          <?php if ($deepest > 0): ?>
            <div class="be-marker" style="left:<?= round(min($deepest, 0.30) / 0.30 * 100, 1) ?>%;width:9px;height:9px;border-width:2px"></div>
          <?php endif; ?>
        </div>
        <div class="be-caption">
          <span>descuento más profundo aplicado: <?= App::pct($deepest) ?></span>
          <?php if ($violated): ?><span class="txt-block">excedido</span><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ----------------------------------------------------- ranking promos -->
<div class="card">
  <div class="flex between items-center mb14">
    <div class="card-title" style="margin:0">Campañas ordenadas por cobertura</div>
    <span style="font-size:11.5px;color:var(--text-faint)">cobertura = uplift obtenido / uplift necesario</span>
  </div>

  <div class="formula mb14">
    margen incremental = I·(P−C) − A<sub>promo</sub>·P·d   ⟹   se paga sola si   I / A<sub>promo</sub> &gt; (1+m)·d / m
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Campaña</th>
          <th>SKU</th>
          <th class="t-num">Desc.</th>
          <th class="t-num">Tope</th>
          <th class="t-num">Uplift real</th>
          <th class="t-num">Requerido</th>
          <th class="t-num">Cobertura</th>
          <th class="t-num">Margen</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($promotions as $p):
          $cov = (float) $p['coverage'];
          $below = (int) $p['sells_below_cost'] === 1;
      ?>
        <tr onclick="location.href='<?= $app->url('campaign', ['id' => $p['id_combo']]) ?>'" style="cursor:pointer">
          <td>
            <div style="font-weight:570"><?= App::e($p['combo']) ?></div>
            <div style="font-size:11.5px;color:var(--text-faint)">
              <?= App::e(substr((string) $p['start_date'], 0, 7)) ?> · <?= (int) $p['weeks'] ?> sem
            </div>
          </td>
          <td style="font-size:12.5px;color:var(--text-dim)"><?= App::e($p['product_name']) ?></td>
          <td class="t-num"><?= App::pct((float) $p['discount']) ?></td>
          <td class="t-num txt-dim"><?= App::pct((float) $p['breakeven_discount']) ?></td>
          <td class="t-num"><?= App::pct(((float) $p['uplift_obs_pct']) / 100) ?></td>
          <td class="t-num txt-dim">
            <?= $p['uplift_req_pct'] === null ? '∞' : App::pct(((float) $p['uplift_req_pct']) / 100) ?>
          </td>
          <td class="t-num">
            <div class="cov">
              <div class="cov-track">
                <div class="cov-fill" style="width:<?= round(min(1, $cov) * 100) ?>%;background:<?= pg_coverage_color($cov, $below) ?>"></div>
              </div>
              <span class="pill <?= pg_coverage_pill($cov, $below) ?>">
                <?= $below ? 'bajo costo' : number_format($cov, 2) ?>
              </span>
            </div>
          </td>
          <td class="t-num <?= ((float) $p['incremental_margin']) < 0 ? 'txt-bad' : 'txt-good' ?>">
            <?= App::compact((float) $p['incremental_margin']) ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (!empty($meta['imported_at'])): ?>
  <div style="margin-top:16px;font-size:11.5px;color:var(--text-faint)">
    Datos importados el <?= App::e(date('d/m/Y H:i', strtotime($meta['imported_at']))) ?> ·
    <?= App::num((float) ($meta['rows_total'] ?? 0)) ?> transacciones ·
    <?= App::num((float) ($meta['rows_cancelled'] ?? 0)) ?> tickets cancelados excluidos ·
    <?= App::num((float) ($meta['rows_null_disc'] ?? 0)) ?> descuentos imputados
  </div>
<?php endif; ?>
