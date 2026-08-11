<?php
/** @var \PromoGuard\App $app @var string $content @var string $title */
use PromoGuard\App;

$route = $_GET['r'] ?? 'dashboard';
$current = $route === 'campaign' ? 'campaigns' : $route;
$tabs = [
    'dashboard' => 'Diagnóstico',
    'simulator' => 'Simulador',
    'campaigns' => 'Campañas',
];

// Prefijo de los estáticos: vacío cuando la raíz del dominio es public/, y "public/"
// cuando el sistema se sirve desde la carpeta de la aplicación (index.php de la raíz).
$base = defined('PG_BASE') ? PG_BASE : '';
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="theme-color" content="#0F4C81">
<meta name="description" content="Control de rentabilidad para promociones de consumo masivo.">
<title><?= App::e($title ?? 'PromoGuard') ?> · PromoGuard</title>
<link rel="stylesheet" href="<?= App::e($base) ?>assets/app.css">
<link rel="icon" href="<?= App::e($base) ?>assets/favicon.svg" type="image/svg+xml">
</head>
<body>

<a class="skip" href="#main">Saltar al contenido</a>

<header class="topbar" id="topbar">
  <div class="topbar-inner">
    <a class="mark" href="<?= $app->url('dashboard') ?>" aria-label="PromoGuard, inicio">
      <span class="mark-badge" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 3l8 3.5V12c0 4.6-3.4 8.3-8 9.5-4.6-1.2-8-4.9-8-9.5V6.5z"/>
          <path d="M9 12.2l2.1 2.1L15.4 10"/>
        </svg>
      </span>
      <span class="mark-lockup">
        <span class="mark-name">PromoGuard</span>
        <span class="mark-by">by VEMIO</span>
      </span>
    </a>

    <nav class="tabs" aria-label="Secciones">
      <?php foreach ($tabs as $key => $label): ?>
        <a href="<?= $app->url($key) ?>"<?= $current === $key ? ' aria-current="page"' : '' ?>><?= App::e($label) ?></a>
      <?php endforeach; ?>
    </nav>

    <div class="topbar-end">
      <span class="dot-live" aria-hidden="true"></span>
      <span>Asesor <?= $app->advisor->mode() === 'claude' ? 'Claude' : 'local' ?></span>
    </div>
  </div>
</header>

<main class="page" id="main">
  <?= $content ?>
</main>

<footer class="foot">
  <div class="foot-inner">
    <span class="foot-mark">
      PromoGuard <span class="foot-by">by VEMIO</span>
    </span>
    <span class="foot-meta">Inteligencia comercial con IA para CPG</span>
  </div>
</footer>

<script src="<?= App::e($base) ?>assets/app.js"></script>
</body>
</html>
