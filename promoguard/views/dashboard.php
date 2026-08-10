<?php
/** @var \PromoGuard\App $app @var array $headline @var array $promotions @var array $skus @var array $portfolio @var array $meta */
use PromoGuard\App;
require_once __DIR__ . '/partials/helpers.php';

$total      = (int) ($headline['total'] ?? 0);
$profitable = (int) ($headline['profitable'] ?? 0);
$belowCost  = (int) ($headline['below_cost'] ?? 0);
$margin     = (float) ($headline['margin_total'] ?? 0);
$discount   = (float) ($headline['discount_total'] ?? 0);
$volume     = (float) ($headline['volume_total'] ?? 0);
$best       = (float) ($headline['best_coverage'] ?? 0);

$deepest = [];
foreach ($promotions as $p) {
    $c = (int) $p['product_code'];
    $deepest[$c] = max($deepest[$c] ?? 0, (float) $p['discount']);
}
?>

<div class="head">
  <div>
    <h1>Diagnóstico del portafolio</h1>
    <p>
      <?= $total ?> promociones medidas contra su contrafactual, sobre
      <?= App::num((float) ($headline['sku_count'] ?? 0)) ?> SKUs y
      <?= App::num((float) ($headline['week_count'] ?? 0)) ?> semanas de histórico.
    </p>
  </div>
  <a class="btn btn-primary" href="<?= $app->url('simulator') ?>">Evaluar una promoción</a>
</div>

<!-- Cifra protagonista + contexto -->
<section class="headline">
  <div class="headline-figure">
    <div class="label">Margen acumulado de las campañas</div>
    <div class="value n <?= $margin < 0 ? 'neg' : 'pos' ?>"><?= App::compact($margin) ?></div>
    <p class="note">
      <?php if ($profitable === 0): ?>
        Ninguna campaña del histórico se pagó sola. No es un problema de ejecución:
        el descuento se aplica a todas las unidades del periodo, no sólo a las que
        genera de más.
      <?php else: ?>
        <?= $profitable ?> de <?= $total ?> campañas cubrieron el costo de su descuento.
      <?php endif; ?>
    </p>
  </div>

  <div class="facts">
    <div class="fact">
      <div class="k">Se pagaron solas</div>
      <div class="v n"><?= $profitable ?> <span class="faint" style="font-size:16px">/ <?= $total ?></span></div>
      <div class="s">Mejor cobertura: <?= App::pct($best) ?></div>
    </div>
    <div class="fact">
      <div class="k">Vendieron bajo costo</div>
      <div class="v n <?= $belowCost > 0 ? 'neg' : '' ?>"><?= $belowCost ?></div>
      <div class="s">Descuento sobre el tope del SKU</div>
    </div>
    <div class="fact">
      <div class="k">Costo del descuento</div>
      <div class="v n"><?= App::compact($discount) ?></div>
      <div class="s">Ganancia por volumen: <?= App::compact($volume) ?></div>
    </div>
  </div>
</section>

<!-- Lectura del asesor -->
<section class="section">
  <div class="grid2">
    <div class="panel panel-pad brief">
      <p class="brief-lead"><?= App::e($portfolio['headline']) ?></p>
      <ul>
        <?php foreach ($portfolio['bullets'] as $b): ?>
          <li><?= App::e($b) ?></li>
        <?php endforeach; ?>
      </ul>
      <?php if (!empty($portfolio['actions'])): ?>
        <div class="brief-actions">
          <div class="k">Qué hacer</div>
          <ul>
            <?php foreach ($portfolio['actions'] as $a): ?>
              <li><?= App::e($a) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </div>

    <div class="panel">
      <div class="panel-head">
        <h2>Tope de descuento por SKU</h2>
        <span class="meta">antes de vender bajo costo</span>
      </div>
      <div class="panel-body">
      <div class="note" style="margin-bottom:var(--s4)">
        <strong>product_margin es un markup sobre costo</strong>, no un margen sobre ingreso.
        El margen real es <code>m/(1+m)</code>, y ese número es el descuento máximo que
        aguanta el producto.
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Producto</th>
              <th class="num">Markup</th>
              <th class="num">Tope</th>
              <th class="num">Máx. aplicado</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($skus as $s):
              $code = (int) $s['product_code'];
              $be = (float) $s['breakeven_discount'];
              $max = $deepest[$code] ?? null;
              $over = $max !== null && $max > $be;
          ?>
            <tr class="linked" tabindex="0" role="link" data-href="<?= $app->url('simulator', ['sku' => $code]) ?>">
              <td class="cell-main"><?= App::e($s['product_name']) ?></td>
              <td class="num dim"><?= App::pct((float) $s['markup'], 0) ?></td>
              <td class="num"><?= App::pct($be) ?></td>
              <td class="num">
                <?php if ($max === null): ?>
                  <span class="faint">sin promociones</span>
                <?php elseif ($over): ?>
                  <span class="tag tag-block"><?= App::pct($max) ?></span>
                <?php else: ?>
                  <span class="dim"><?= App::pct($max) ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      </div>
    </div>
  </div>
</section>

<!-- Ranking de campañas -->
<section class="section">
  <div class="panel">
  <div class="panel-head">
    <h2>Campañas por cobertura</h2>
    <span class="meta">cobertura = uplift obtenido / uplift necesario</span>
  </div>
  <div class="panel-body">

  <div class="formula" style="margin-bottom:var(--s4)">
    margen = I·(P−C) − A<sub>promo</sub>·P·d      se paga sola si   I / A<sub>promo</sub> &gt; (1+m)·d / m
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Campaña</th>
          <th class="num">Descuento</th>
          <th class="num">Tope</th>
          <th class="num">Uplift real</th>
          <th class="num">Necesario</th>
          <th class="num">Cobertura</th>
          <th class="num">Margen</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($promotions as $p):
          $cov = (float) $p['coverage'];
          $below = (int) $p['sells_below_cost'] === 1;
      ?>
        <tr class="linked" tabindex="0" role="link" data-href="<?= $app->url('campaign', ['id' => $p['id_combo']]) ?>">
          <td>
            <div class="cell-main"><?= App::e($p['combo']) ?></div>
            <div class="cell-sub"><?= App::e($p['product_name']) ?> · <?= App::e(substr((string) $p['start_date'], 0, 7)) ?></div>
          </td>
          <td class="num"><?= App::pct((float) $p['discount']) ?></td>
          <td class="num faint"><?= App::pct((float) $p['breakeven_discount']) ?></td>
          <td class="num"><?= App::pct(((float) $p['uplift_obs_pct']) / 100) ?></td>
          <td class="num faint"><?= $p['uplift_req_pct'] === null ? '—' : App::pct(((float) $p['uplift_req_pct']) / 100) ?></td>
          <td class="num">
            <span class="cov">
              <span class="cov-track"><span class="cov-fill" style="width:<?= round(min(1, $cov) * 100) ?>%;background:<?= pg_color($cov, $below) ?>"></span></span>
              <span class="tag <?= pg_tag($cov, $below) ?>"><?= $below ? 'bajo costo' : number_format($cov, 2) ?></span>
            </span>
          </td>
          <td class="num <?= ((float) $p['incremental_margin']) < 0 ? 'neg' : 'pos' ?>"><?= App::compact((float) $p['incremental_margin']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  </div>
  </div>
</section>

<?php if (!empty($meta['imported_at'])): ?>
  <p class="faint" style="margin-top:var(--s6);font-size:12px">
    <?= App::num((float) ($meta['rows_total'] ?? 0)) ?> transacciones importadas el
    <?= App::e(date('d/m/Y', strtotime((string) $meta['imported_at']))) ?>.
    <?= App::num((float) ($meta['rows_cancelled'] ?? 0)) ?> tickets cancelados excluidos,
    <?= App::num((float) ($meta['rows_null_disc'] ?? 0)) ?> descuentos imputados.
  </p>
<?php endif; ?>
