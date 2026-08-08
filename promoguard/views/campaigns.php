<?php
/** @var \PromoGuard\App $app @var array $promotions @var array $simulations */
use PromoGuard\App;
require_once __DIR__ . '/partials/helpers.php';
?>

<div class="page-head">
  <div>
    <h1 class="page-title">Campañas</h1>
    <p class="page-sub">Histórico ejecutado y escenarios guardados</p>
  </div>
  <a class="btn btn-primary" href="<?= $app->url('simulator') ?>">Nuevo escenario</a>
</div>

<div class="card mb14">
  <div class="card-title">Ejecutadas · medidas contra su contrafactual</div>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Campaña</th><th>SKU</th><th>Periodo</th>
        <th class="t-num">Desc.</th><th class="t-num">Unidades</th>
        <th class="t-num">Incrementales</th><th class="t-num">Cobertura</th><th class="t-num">Margen</th>
      </tr></thead>
      <tbody>
      <?php foreach ($promotions as $p):
          $cov = (float) $p['coverage'];
          $below = (int) $p['sells_below_cost'] === 1;
      ?>
        <tr onclick="location.href='<?= $app->url('campaign', ['id' => $p['id_combo']]) ?>'" style="cursor:pointer">
          <td style="font-weight:560"><?= App::e($p['combo']) ?></td>
          <td style="font-size:12.5px;color:var(--text-dim)"><?= App::e($p['product_name']) ?></td>
          <td style="font-size:12px;color:var(--text-faint)">
            <?= App::e(date('d/m/y', strtotime((string) $p['start_date']))) ?> –
            <?= App::e(date('d/m/y', strtotime((string) $p['end_date']))) ?>
          </td>
          <td class="t-num">
            <?= App::pct((float) $p['discount']) ?>
            <?php if ($below): ?><br><span class="pill pill-block" style="font-size:9.5px">bajo costo</span><?php endif; ?>
          </td>
          <td class="t-num"><?= App::num((float) $p['actual_units']) ?></td>
          <td class="t-num txt-accent">+<?= App::num((float) $p['incremental_units']) ?></td>
          <td class="t-num">
            <div class="cov">
              <div class="cov-track"><div class="cov-fill" style="width:<?= round(min(1, $cov) * 100) ?>%;background:<?= pg_coverage_color($cov, $below) ?>"></div></div>
              <span class="pill <?= pg_coverage_pill($cov, $below) ?>"><?= $below ? '0' : number_format($cov, 2) ?></span>
            </div>
          </td>
          <td class="t-num <?= ((float) $p['incremental_margin']) < 0 ? 'txt-bad' : 'txt-good' ?>"><?= App::compact((float) $p['incremental_margin']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-title">Escenarios guardados en el simulador</div>
  <?php if ($simulations === []): ?>
    <div class="empty">
      <h3>Todavía no hay escenarios guardados</h3>
      <p style="font-size:13px">Evalúa una promoción en el simulador y guárdala para dejar registro de la decisión.</p>
      <a class="btn btn-primary mt14" href="<?= $app->url('simulator') ?>">Ir al simulador</a>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr>
          <th>Fecha</th><th>SKU</th><th class="t-num">Desc.</th><th class="t-num">Semanas</th>
          <th class="t-num">Cobertura</th><th class="t-num">Margen</th><th>Veredicto</th>
        </tr></thead>
        <tbody>
        <?php foreach ($simulations as $s):
            $cov = (float) $s['coverage'];
            $blocked = $s['verdict'] === 'blocked';
        ?>
          <tr>
            <td style="font-size:12.5px;color:var(--text-dim)"><?= App::e(date('d/m/Y H:i', strtotime((string) $s['created_at']))) ?></td>
            <td><?= App::e($s['product_name']) ?></td>
            <td class="t-num"><?= App::pct((float) $s['discount']) ?></td>
            <td class="t-num"><?= (int) $s['weeks'] ?></td>
            <td class="t-num"><?= number_format($cov, 2) ?></td>
            <td class="t-num <?= ((float) $s['incremental_margin']) < 0 ? 'txt-bad' : 'txt-good' ?>"><?= App::compact((float) $s['incremental_margin']) ?></td>
            <td><span class="pill <?= pg_coverage_pill($cov, $blocked) ?>"><?= App::e(\PromoGuard\Simulator::verdictLabel((string) $s['verdict'])) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
