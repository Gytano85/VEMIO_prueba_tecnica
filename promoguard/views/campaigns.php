<?php
/** @var \PromoGuard\App $app @var array $promotions @var array $simulations */
use PromoGuard\App;
require_once __DIR__ . '/partials/helpers.php';
?>

<div class="head">
  <div>
    <h1>Campañas</h1>
    <p>Histórico ejecutado y escenarios evaluados en el simulador.</p>
  </div>
  <a class="btn btn-primary" href="<?= $app->url('simulator') ?>">Nuevo escenario</a>
</div>

<section>
  <div class="panel">
  <div class="panel-head">
    <h2>Ejecutadas</h2>
    <span class="meta">medidas contra su contrafactual</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Campaña</th><th>Periodo</th>
        <th class="num">Descuento</th><th class="num">Unidades</th>
        <th class="num">Incrementales</th><th class="num">Cobertura</th><th class="num">Margen</th>
      </tr></thead>
      <tbody>
      <?php foreach ($promotions as $p):
          $cov = (float) $p['coverage'];
          $below = (int) $p['sells_below_cost'] === 1;
      ?>
        <tr class="linked" tabindex="0" role="link" data-href="<?= $app->url('campaign', ['id' => $p['id_combo']]) ?>">
          <td>
            <div class="cell-main"><?= App::e($p['combo']) ?></div>
            <div class="cell-sub"><?= App::e($p['product_name']) ?></div>
          </td>
          <td class="dim" style="font-size:12.5px;white-space:nowrap">
            <?= App::e(date('d/m/y', strtotime((string) $p['start_date']))) ?> –
            <?= App::e(date('d/m/y', strtotime((string) $p['end_date']))) ?>
          </td>
          <td class="num">
            <?= App::pct((float) $p['discount']) ?>
            <?php if ($below): ?><div class="cell-sub neg">sobre el tope</div><?php endif; ?>
          </td>
          <td class="num"><?= App::num((float) $p['actual_units']) ?></td>
          <td class="num">+<?= App::num((float) $p['incremental_units']) ?></td>
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
</section>

<section class="section">
  <div class="panel">
  <div class="panel-head">
    <h2>Escenarios guardados</h2>
    <span class="meta">registro de decisiones</span>
  </div>
  <?php if ($simulations === []): ?>
    <div class="empty">
      <h3>Todavía no hay escenarios guardados</h3>
      <p style="font-size:13px;margin:0 0 var(--s4)">Evalúa una promoción y guárdala para dejar constancia de la decisión.</p>
      <a class="btn" href="<?= $app->url('simulator') ?>">Ir al simulador</a>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr>
          <th>Fecha</th><th>Producto</th>
          <th class="num">Descuento</th><th class="num">Semanas</th>
          <th class="num">Cobertura</th><th class="num">Margen</th><th>Veredicto</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($simulations as $s):
            $cov = (float) $s['coverage'];
            $blocked = $s['verdict'] === 'blocked';
        ?>
          <tr>
            <td class="dim" style="font-size:12.5px;white-space:nowrap"><?= App::e(date('d/m/Y H:i', strtotime((string) $s['created_at']))) ?></td>
            <td class="cell-main"><?= App::e($s['product_name']) ?></td>
            <td class="num"><?= App::pct((float) $s['discount']) ?></td>
            <td class="num"><?= (int) $s['weeks'] ?></td>
            <td class="num"><?= number_format($cov, 2) ?></td>
            <td class="num <?= ((float) $s['incremental_margin']) < 0 ? 'neg' : 'pos' ?>"><?= App::compact((float) $s['incremental_margin']) ?></td>
            <td><span class="tag <?= pg_tag($cov, $blocked) ?>"><?= App::e(\PromoGuard\Simulator::verdictLabel((string) $s['verdict'])) ?></span></td>
            <td class="num">
              <form method="post" action="<?= $app->url('delete-scenario') ?>" onsubmit="return confirm('¿Eliminar este escenario?')">
                <input type="hidden" name="_t" value="<?= App::e(App::csrfToken()) ?>">
                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <button class="btn btn-sm btn-quiet" type="submit" aria-label="Eliminar escenario">Eliminar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
  </div>
</section>
