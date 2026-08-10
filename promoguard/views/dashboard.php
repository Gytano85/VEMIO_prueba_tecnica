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
    <h1>¿Qué pasó con las promociones?</h1>
    <p>
      Revisamos <?= $total ?> promociones para saber cuánto vendieron y si dejaron dinero, sobre
      <?= App::num((float) ($headline['sku_count'] ?? 0)) ?> productos y
      <?= App::num((float) ($headline['week_count'] ?? 0)) ?> semanas de histórico.
    </p>
  </div>
  <a class="btn btn-primary" href="<?= $app->url('simulator') ?>">Evaluar una promoción</a>
</div>

<!-- Cifra protagonista + contexto -->
<section class="headline">
  <div class="headline-figure">
    <div class="label">Ganancia o pérdida total</div>
    <div class="value n <?= $margin < 0 ? 'neg' : 'pos' ?>"><?= App::compact($margin) ?></div>
    <p class="note">
      <?php if ($profitable === 0): ?>
        Ninguna campaña recuperó el dinero entregado en descuentos. Vendieron más,
        pero esas ventas adicionales no alcanzaron para cubrir la rebaja.
      <?php else: ?>
        <?= $profitable ?> de <?= $total ?> campañas cubrieron el costo de su descuento.
      <?php endif; ?>
    </p>
  </div>

  <div class="facts">
    <div class="fact">
      <div class="k">Se pagaron solas</div>
      <div class="v n"><?= $profitable ?> <span class="faint" style="font-size:16px">/ <?= $total ?></span></div>
      <div class="s">La mejor recuperó <?= App::pct($best) ?> de su descuento</div>
    </div>
    <div class="fact">
      <div class="k">Perdieron dinero por unidad</div>
      <div class="v n <?= $belowCost > 0 ? 'neg' : '' ?>"><?= $belowCost ?></div>
      <div class="s">El precio quedó debajo del costo</div>
    </div>
    <div class="fact">
      <div class="k">Dinero dado en descuentos</div>
      <div class="v n"><?= App::compact($discount) ?></div>
      <div class="s">Recuperado por ventas extra: <?= App::compact($volume) ?></div>
    </div>
  </div>
</section>

<section class="value-strip" aria-label="Valor protegido por PromoGuard">
  <div>
    <span class="value-label">Pérdida que se pudo detectar antes</span>
    <strong class="n"><?= App::compact(abs(min(0.0, $margin))) ?></strong>
  </div>
  <p>
    PromoGuard habría advertido este riesgo antes de aprobar las campañas.
    La empresa crea valor cuando evita o corrige una promoción; <b>esta cifra no es una ganancia ya obtenida.</b>
  </p>
  <a class="btn" href="<?= $app->url('simulator') ?>">Reformular una campaña</a>
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
        <h2>Descuento máximo sin perder por unidad</h2>
        <span class="meta">límite por producto</span>
      </div>
      <div class="panel-body">
      <div class="note" style="margin-bottom:var(--s4)">
        Si el descuento supera este límite, cada unidad se vende por menos de lo que costó.
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Producto</th>
              <th class="num">Ganancia sobre costo</th>
              <th class="num">Límite</th>
              <th class="num">Mayor descuento usado</th>
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

<details class="advanced-block">
<summary>Ver resultados de todas las campañas</summary>
<div class="advanced-content">
<!-- Ranking de campañas -->
<section class="section">
  <div class="panel">
  <div class="panel-head">
    <h2>Resultados de cada campaña</h2>
    <span class="meta">ordenadas de la más cercana a recuperar el descuento</span>
  </div>
  <div class="panel-body">

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Campaña</th>
          <th class="num">Descuento</th>
          <th class="num">Límite</th>
          <th class="num">Ventas extra</th>
          <th class="num">Ventas extra necesarias</th>
          <th class="num">Descuento recuperado</th>
          <th class="num">Ganancia / pérdida</th>
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
              <span class="tag <?= pg_tag($cov, $below) ?>"><?= $below ? 'bajo costo' : App::pct(min(1.0, $cov), 0) ?></span>
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
</div>
</details>

<?php if (!empty($meta['imported_at'])): ?>
  <p class="faint" style="margin-top:var(--s6);font-size:12px">
    <?= App::num((float) ($meta['rows_total'] ?? 0)) ?> transacciones importadas el
    <?= App::e(date('d/m/Y', strtotime((string) $meta['imported_at']))) ?>.
    <?= App::num((float) ($meta['rows_cancelled'] ?? 0)) ?> tickets cancelados excluidos,
    <?= App::num((float) ($meta['rows_null_disc'] ?? 0)) ?> descuentos imputados.
  </p>
<?php endif; ?>
