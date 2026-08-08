<?php
/** @var \PromoGuard\App $app @var array $skus */
use PromoGuard\App;
require_once __DIR__ . '/partials/helpers.php';
?>

<div class="page-head">
  <div>
    <h1 class="page-title">Catálogo</h1>
    <p class="page-sub">Economía unitaria y tope de descuento por producto</p>
  </div>
</div>

<div class="note mb14">
  <strong>El tope de descuento no es una política comercial: es aritmética.</strong>
  <code>product_margin</code> es un markup sobre costo, así que el margen sobre ingreso es
  <code>m/(1+m)</code>. Cualquier descuento por encima de ese número vende bajo costo,
  y ningún volumen adicional lo compensa.
</div>

<div class="grid g3 mb14">
  <?php foreach (array_slice($skus, 0, 3) as $s): ?>
    <div class="card kpi">
      <div class="kpi-label"><?= App::e($s['product_name']) ?></div>
      <div class="kpi-value sm num txt-block"><?= App::pct((float) $s['breakeven_discount']) ?></div>
      <div class="kpi-note">tope de descuento · markup <?= App::pct((float) $s['markup']) ?></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-title">Todos los SKUs</div>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Producto</th><th>Categoría</th>
        <th class="t-num">Costo</th><th class="t-num">Lista</th>
        <th class="t-num">Markup</th><th class="t-num">Margen s/ingreso</th>
        <th class="t-num">Tope descuento</th><th class="t-num">Elasticidad</th>
        <th class="t-num">Base semanal</th><th class="t-num">Ingreso total</th>
      </tr></thead>
      <tbody>
      <?php foreach ($skus as $s): ?>
        <tr onclick="location.href='<?= $app->url('simulator', ['sku' => $s['product_code']]) ?>'" style="cursor:pointer">
          <td>
            <div style="font-weight:560"><?= App::e($s['product_name']) ?></div>
            <div style="font-size:11.5px;color:var(--text-faint)"><?= App::e($s['brand']) ?></div>
          </td>
          <td style="font-size:12.5px;color:var(--text-dim)"><?= App::e($s['subcategory']) ?></td>
          <td class="t-num"><?= App::money((float) $s['unit_cost'], 2) ?></td>
          <td class="t-num"><?= App::money((float) $s['list_price'], 2) ?></td>
          <td class="t-num"><?= App::pct((float) $s['markup']) ?></td>
          <td class="t-num txt-dim"><?= App::pct((float) $s['margin_on_revenue']) ?></td>
          <td class="t-num"><span class="pill pill-block"><?= App::pct((float) $s['breakeven_discount']) ?></span></td>
          <td class="t-num"><?= $s['elasticity'] !== null ? number_format((float) $s['elasticity'], 2) : '—' ?></td>
          <td class="t-num"><?= App::num((float) $s['baseline_weekly']) ?></td>
          <td class="t-num"><?= App::compact((float) $s['total_revenue']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
