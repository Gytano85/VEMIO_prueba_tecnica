<?php
/** @var \PromoGuard\App $app */
/** @var array $skus @var array $sku @var array $sim @var array $curve @var array $analogs @var array $advice @var string $aiMode */
use PromoGuard\App;
require_once __DIR__ . '/partials/helpers.php';

$be = (float) $sku['breakeven_discount'];
$scaleMax = 0.35;
?>

<div class="page-head">
  <div>
    <h1 class="page-title">Simulador de promociones</h1>
    <p class="page-sub">Evalúa una mecánica antes de aprobarla — contra la economía real del SKU</p>
  </div>
  <form method="post" action="<?= $app->url('save') ?>" id="saveForm">
    <input type="hidden" name="sku" value="<?= (int) $sku['product_code'] ?>">
    <input type="hidden" name="d" id="saveD" value="<?= round($sim['discount'] * 100, 1) ?>">
    <input type="hidden" name="w" id="saveW" value="<?= (int) $sim['weeks'] ?>">
    <button class="btn" type="submit">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2zM17 21v-8H7v8M7 3v5h8"/></svg>
      Guardar escenario
    </button>
  </form>
</div>

<div class="grid g-sim">

  <!-- ================================================== panel de control -->
  <div class="card" style="align-self:start;position:sticky;top:24px">
    <div class="card-title">Parámetros</div>

    <div class="control">
      <div class="control-head"><span class="control-label">Producto</span></div>
      <select id="skuSelect">
        <?php foreach ($skus as $s): ?>
          <option value="<?= (int) $s['product_code'] ?>" <?= (int) $s['product_code'] === (int) $sku['product_code'] ? 'selected' : '' ?>>
            <?= App::e($s['product_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="control">
      <div class="control-head">
        <span class="control-label">Profundidad de descuento</span>
        <span class="control-value num" id="dLabel"><?= App::pct($sim['discount']) ?></span>
      </div>
      <input type="range" id="dRange" min="0" max="35" step="0.5" value="<?= round($sim['discount'] * 100, 1) ?>">

      <div class="breakeven-bar">
        <div class="be-safe" style="width:<?= round($be / $scaleMax * 100, 1) ?>%"></div>
        <div class="be-danger" style="width:<?= round((1 - $be / $scaleMax) * 100, 1) ?>%"></div>
        <div class="be-line" style="left:<?= round($be / $scaleMax * 100, 1) ?>%"></div>
        <div class="be-marker" id="beMarker" style="left:<?= round(min($sim['discount'], $scaleMax) / $scaleMax * 100, 1) ?>%"></div>
      </div>
      <div class="be-caption">
        <span>0%</span>
        <span class="txt-block">punto de equilibrio <?= App::pct($be) ?></span>
        <span><?= App::pct($scaleMax, 0) ?></span>
      </div>
    </div>

    <div class="control">
      <div class="control-head">
        <span class="control-label">Duración</span>
        <span class="control-value num" id="wLabel"><?= (int) $sim['weeks'] ?> sem</span>
      </div>
      <input type="range" id="wRange" min="1" max="16" step="1" value="<?= (int) $sim['weeks'] ?>">
      <div class="range-scale"><span>1 semana</span><span>16 semanas</span></div>
    </div>

    <div class="control">
      <div class="control-head">
        <span class="control-label">Uplift esperado</span>
        <span class="control-value num txt-accent" id="uLabel"><?= App::pct($sim['expected_uplift_pct'] / 100) ?></span>
      </div>
      <input type="range" id="uRange" min="0" max="250" step="5" value="<?= round($sim['expected_uplift_pct']) ?>">
      <div class="range-scale">
        <span>0%</span>
        <span id="modelHint">modelo: <?= App::pct($sim['model_uplift_pct'] / 100) ?></span>
        <span>250%</span>
      </div>
    </div>

    <button class="btn mt8" id="resetModel" style="width:100%">Volver al uplift del modelo</button>

    <div class="mt20">
      <div class="card-title">Economía del SKU</div>
      <dl style="margin:0">
        <div class="stat-row"><dt>Costo unitario</dt><dd class="num"><?= App::money((float) $sku['unit_cost'], 2) ?></dd></div>
        <div class="stat-row"><dt>Precio de lista</dt><dd class="num"><?= App::money((float) $sku['list_price'], 2) ?></dd></div>
        <div class="stat-row"><dt>Markup sobre costo</dt><dd class="num"><?= App::pct((float) $sku['markup']) ?></dd></div>
        <div class="stat-row"><dt>Margen sobre ingreso</dt><dd class="num"><?= App::pct((float) $sku['margin_on_revenue']) ?></dd></div>
        <div class="stat-row"><dt>Elasticidad estimada</dt><dd class="num"><?= $sku['elasticity'] !== null ? number_format((float) $sku['elasticity'], 2) : '—' ?></dd></div>
        <div class="stat-row"><dt>Demanda base semanal</dt><dd class="num"><?= App::num((float) $sku['baseline_weekly']) ?> u</dd></div>
      </dl>
    </div>
  </div>

  <!-- ================================================== panel de resultado -->
  <div id="results">

    <div class="verdict <?= pg_verdict_class((string) $sim['verdict']) ?> mb14" id="verdictBox">
      <div class="verdict-light" id="verdictLight"><?= pg_verdict_icon((string) $sim['verdict']) ?></div>
      <div style="flex:1">
        <div class="verdict-title" id="verdictTitle"><?= App::e((string) $sim['verdict_label']) ?></div>
        <div class="verdict-note" id="verdictNote">
          <?php if ($sim['sells_below_cost']): ?>
            El precio promocional (<?= App::money((float) $sim['promo_price'], 2) ?>) queda por debajo del costo unitario (<?= App::money((float) $sim['unit_cost'], 2) ?>).
          <?php elseif ($sim['required_uplift_pct'] !== null): ?>
            Necesita <?= App::pct($sim['required_uplift_pct'] / 100) ?> de uplift · proyectado <?= App::pct($sim['expected_uplift_pct'] / 100) ?> · cobertura <?= number_format((float) $sim['coverage'], 2) ?>
          <?php endif; ?>
        </div>
      </div>
      <div style="text-align:right">
        <div style="font-size:10.5px;letter-spacing:.06em;text-transform:uppercase;color:var(--text-faint)">Margen incremental</div>
        <div class="num" id="marginBig" style="font-size:26px;font-weight:680;letter-spacing:-.03em;color:<?= ((float) $sim['incremental_margin']) < 0 ? 'var(--bad)' : 'var(--good)' ?>">
          <?= App::compact((float) $sim['incremental_margin']) ?>
        </div>
      </div>
    </div>

    <div class="grid g3 mb14">
      <div class="card">
        <div class="card-title">Descomposición del margen</div>
        <?php
          $gain = (float) $sim['volume_gain'];
          $cost = (float) $sim['discount_cost'];
          $net  = (float) $sim['incremental_margin'];
          $peak = max(abs($gain), abs($cost), abs($net), 1);
        ?>
        <div class="waterfall" id="waterfall">
          <div class="wf-col">
            <span class="wf-value txt-good num" data-wf="gain"><?= App::compact($gain) ?></span>
            <div class="wf-bar wf-gain" data-wf-bar="gain" style="height:<?= round(abs($gain) / $peak * 100) ?>%"></div>
            <span class="wf-label">Ganancia<br>por volumen</span>
          </div>
          <div class="wf-col">
            <span class="wf-value txt-bad num" data-wf="cost">−<?= App::compact($cost) ?></span>
            <div class="wf-bar wf-cost" data-wf-bar="cost" style="height:<?= round(abs($cost) / $peak * 100) ?>%"></div>
            <span class="wf-label">Costo del<br>descuento</span>
          </div>
          <div class="wf-col">
            <span class="wf-value num" data-wf="net" style="color:<?= $net < 0 ? 'var(--block)' : 'var(--good)' ?>"><?= App::compact($net) ?></span>
            <div class="wf-bar <?= $net < 0 ? 'wf-net-n' : 'wf-net-p' ?>" data-wf-bar="net" style="height:<?= round(abs($net) / $peak * 100) ?>%"></div>
            <span class="wf-label">Margen<br>incremental</span>
          </div>
        </div>
        <div class="note mt14" style="font-size:11.5px">
          El descuento se aplica a <strong data-f="promo_units"><?= App::num((float) $sim['promo_units']) ?></strong> unidades,
          no sólo a las <strong data-f="incremental_units"><?= App::num((float) $sim['incremental_units']) ?></strong> incrementales.
        </div>
      </div>

      <div class="card">
        <div class="card-title">Umbral de aprobación</div>
        <div class="kpi-label">Uplift necesario</div>
        <div class="kpi-value sm num txt-warn" data-f="required_uplift_pct">
          <?= $sim['required_uplift_pct'] === null ? 'inalcanzable' : App::pct($sim['required_uplift_pct'] / 100) ?>
        </div>
        <div style="height:1px;background:var(--line-soft);margin:12px 0"></div>
        <div class="kpi-label">Uplift proyectado</div>
        <div class="kpi-value sm num txt-accent" data-f="expected_uplift_pct"><?= App::pct($sim['expected_uplift_pct'] / 100) ?></div>
        <div class="mt14">
          <div class="cov-track" style="width:100%;height:7px">
            <div class="cov-fill" id="covBar" style="width:<?= round(min(1, (float) $sim['coverage']) * 100) ?>%;background:<?= pg_coverage_color((float) $sim['coverage'], (bool) $sim['sells_below_cost']) ?>"></div>
          </div>
          <div class="be-caption"><span>cobertura</span><span class="num" data-f="coverage"><?= number_format((float) $sim['coverage'], 2) ?></span></div>
        </div>
      </div>

      <div class="card">
        <div class="card-title">Viabilidad estructural</div>
        <div class="kpi-label">Tope antes de vender bajo costo</div>
        <div class="kpi-value sm num txt-block"><?= App::pct($be) ?></div>
        <div style="height:1px;background:var(--line-soft);margin:12px 0"></div>
        <?php if (!empty($sim['structurally_viable'])): ?>
          <div class="kpi-label">Descuento máximo rentable</div>
          <div class="kpi-value sm num txt-good" data-f="max_viable_discount"><?= App::pct((float) $sim['max_viable_discount']) ?></div>
          <div class="kpi-note">El más profundo que todavía se paga solo</div>
        <?php else: ?>
          <div class="kpi-label">Elasticidad requerida</div>
          <div class="flex items-center gap8" style="margin:7px 0 3px">
            <span class="kpi-value sm num txt-bad" style="margin:0">|<?= number_format((float) $sim['required_elasticity'], 1) ?>|</span>
            <span class="txt-dim" style="font-size:13px">vs <?= number_format(abs((float) $sim['elasticity']), 2) ?> real</span>
          </div>
          <div class="kpi-note">
            Con este markup <strong class="txt-bad">ninguna profundidad se paga sola</strong>.
            Descontar es la palanca equivocada en este SKU.
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- curva de margen -->
    <div class="card mb14">
      <div class="flex between items-center mb14">
        <div class="card-title" style="margin:0">Margen incremental por profundidad de descuento</div>
        <span style="font-size:11.5px;color:var(--text-faint)">duración: <span id="curveWeeks"><?= (int) $sim['weeks'] ?></span> semanas</span>
      </div>
      <div id="curveChart">
        <?php
          $pts = [];
          foreach ($curve as $c) {
              $pts[] = [$c['discount'] * 100, $c['incremental_margin']];
          }
          $labels = [];
          for ($i = 0; $i <= 4; $i++) {
              $labels[] = number_format(($curve[count($curve) - 1]['discount'] * 100) * $i / 4, 0) . '%';
          }
          echo pg_line_chart([$pts], ['height' => 210, 'xlabels' => $labels, 'colors' => ['var(--accent)']]);
        ?>
      </div>
      <div class="chart-legend">
        <span><i class="swatch" style="background:var(--accent)"></i> margen incremental proyectado</span>
        <span><i class="swatch" style="background:var(--block)"></i> punto de equilibrio del SKU: <?= App::pct($be) ?></span>
      </div>
    </div>

    <!-- asesor IA -->
    <div class="advisor mb14" id="advisorBox">
      <div class="advisor-head">
        <div class="advisor-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 2l2.2 6.2L20 10l-5.8 1.8L12 18l-2.2-6.2L4 10l5.8-1.8z"/>
          </svg>
        </div>
        <div style="flex:1">
          <div class="advisor-title">Dictamen del asesor</div>
          <div class="advisor-src" id="adviceSource"><?= App::e((string) $advice['source']) ?></div>
        </div>
        <span class="pill <?= $aiMode === 'claude' ? 'pill-good' : 'pill-dim' ?>"><?= $aiMode === 'claude' ? 'Claude' : 'local' ?></span>
      </div>
      <div class="advisor-body">
        <div class="advisor-headline" id="adviceHeadline"><?= App::e((string) $advice['headline']) ?></div>
        <ul class="adv-list" id="adviceBullets">
          <?php foreach ($advice['bullets'] as $b): ?><li><?= App::e($b) ?></li><?php endforeach; ?>
        </ul>
        <div class="adv-actions">
          <div class="adv-sub">Recomendación</div>
          <ul class="adv-list" id="adviceActions">
            <?php foreach ($advice['actions'] as $a): ?><li><?= App::e($a) ?></li><?php endforeach; ?>
          </ul>
        </div>
      </div>
    </div>

    <!-- históricos comparables -->
    <div class="card">
      <div class="card-title">Historial de este SKU</div>
      <?php if ($analogs === []): ?>
        <div class="empty">
          <h3>Sin promociones previas</h3>
          <p style="font-size:13px">La proyección se apoya únicamente en la elasticidad estimada.</p>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead><tr>
              <th>Campaña</th><th class="t-num">Descuento</th><th class="t-num">Uplift real</th>
              <th class="t-num">Requerido</th><th class="t-num">Cobertura</th><th class="t-num">Margen</th>
            </tr></thead>
            <tbody>
            <?php foreach ($analogs as $a):
                $cov = (float) $a['coverage'];
                $below = (int) $a['sells_below_cost'] === 1;
            ?>
              <tr onclick="location.href='<?= $app->url('campaign', ['id' => $a['id_combo']]) ?>'" style="cursor:pointer">
                <td>
                  <div style="font-weight:560"><?= App::e($a['combo']) ?></div>
                  <div style="font-size:11.5px;color:var(--text-faint)"><?= App::e(substr((string) $a['start_date'], 0, 7)) ?></div>
                </td>
                <td class="t-num"><?= App::pct((float) $a['discount']) ?></td>
                <td class="t-num"><?= App::pct(((float) $a['uplift_obs_pct']) / 100) ?></td>
                <td class="t-num txt-dim"><?= $a['uplift_req_pct'] === null ? '∞' : App::pct(((float) $a['uplift_req_pct']) / 100) ?></td>
                <td class="t-num"><span class="pill <?= pg_coverage_pill($cov, $below) ?>"><?= $below ? 'bajo costo' : number_format($cov, 2) ?></span></td>
                <td class="t-num <?= ((float) $a['incremental_margin']) < 0 ? 'txt-bad' : 'txt-good' ?>"><?= App::compact((float) $a['incremental_margin']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<script>
window.PG_SIM = {
  skuCode: <?= (int) $sku['product_code'] ?>,
  scaleMax: <?= $scaleMax ?>,
  breakeven: <?= $be ?>
};
</script>
