<?php
/** @var \PromoGuard\App $app */
/** @var string $content */
/** @var string $title */
use PromoGuard\App;

$route = $_GET['r'] ?? 'dashboard';
$nav = [
    ['dashboard', 'Diagnóstico', 'M3 13h4l3 8 4-16 3 8h4'],
    ['simulator', 'Simulador',   'M12 3v18M5 8l7-5 7 5M5 16l7 5 7-5'],
    ['campaigns', 'Campañas',    'M4 5h16M4 12h16M4 19h10'],
    ['skus',      'Catálogo',    'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z'],
    ['forecast',  'Proyección',  'M3 17l6-6 4 4 8-8M21 7v6h-6'],
];
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= App::e($title ?? 'PromoGuard') ?> · PromoGuard</title>
<link rel="stylesheet" href="assets/app.css">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%2322D3EE'/><path d='M16 7l7 3v6c0 4-3 7-7 9-4-2-7-5-7-9v-6z' fill='%23041220'/></svg>">
</head>
<body>
<div class="shell">

  <aside class="sidebar">
    <div class="brand">
      <div class="brand-mark">
        <svg viewBox="0 0 24 24" fill="none" stroke="#04121A" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 3l8 3.5V12c0 4.6-3.4 8.3-8 9.5-4.6-1.2-8-4.9-8-9.5V6.5z"/>
          <path d="M9 12l2 2 4-4"/>
        </svg>
      </div>
      <div>
        <div class="brand-name">PromoGuard</div>
        <div class="brand-sub">Trade Promotion Control</div>
      </div>
    </div>

    <nav class="nav">
      <div class="nav-label">Operación</div>
      <?php foreach ($nav as [$r, $label, $path]): ?>
        <a href="<?= $app->url($r) ?>" class="<?= $route === $r || ($route === 'campaign' && $r === 'campaigns') ? 'active' : '' ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="<?= $path ?>"/></svg>
          <?= App::e($label) ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="sidebar-foot">
      <div class="ai-badge">
        <span class="ai-dot"></span>
        <span>Asesor IA · <?= $app->advisor->mode() === 'claude' ? 'Claude' : 'motor local' ?></span>
      </div>
      <div style="font-size:10.5px;color:var(--text-faint);margin-top:11px;padding:0 3px;line-height:1.5">
        <?= App::e($app->config['client']) ?>
      </div>
    </div>
  </aside>

  <main class="main">
    <?= $content ?>
  </main>

</div>
<script src="assets/app.js"></script>
</body>
</html>
