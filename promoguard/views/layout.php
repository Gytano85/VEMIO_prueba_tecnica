<?php
/** @var \PromoGuard\App $app @var string $content @var string $title */
use PromoGuard\App;

$route = $_GET['r'] ?? 'dashboard';
$current = in_array($route, ['campaign'], true) ? 'campaigns' : $route;
$tabs = [
    'dashboard' => 'Diagnóstico',
    'simulator' => 'Simulador',
    'campaigns' => 'Campañas',
];
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= App::e($title ?? 'PromoGuard') ?> · PromoGuard</title>
<link rel="stylesheet" href="assets/app.css">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='7' fill='%230F4C81'/><path d='M16 8l7 3v5.5c0 4-3 7.2-7 8.5-4-1.3-7-4.5-7-8.5V11z' fill='%23fff'/></svg>">
</head>
<body>

<header class="topbar">
  <div class="topbar-inner">
    <span class="mark">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M12 3l8 3.5V12c0 4.6-3.4 8.3-8 9.5-4.6-1.2-8-4.9-8-9.5V6.5z"/>
      </svg>
      PromoGuard
    </span>

    <nav class="tabs" aria-label="Secciones">
      <?php foreach ($tabs as $key => $label): ?>
        <a href="<?= $app->url($key) ?>"<?= $current === $key ? ' aria-current="page"' : '' ?>><?= App::e($label) ?></a>
      <?php endforeach; ?>
    </nav>

    <div class="topbar-end">
      <span><?= App::e($app->config['client']) ?></span>
    </div>
  </div>
</header>

<main class="page">
  <?= $content ?>
</main>

<script src="assets/app.js"></script>
</body>
</html>
