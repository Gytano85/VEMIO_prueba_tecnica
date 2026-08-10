<?php
/** @var \PromoGuard\App $app */
use PromoGuard\App;
?>

<div class="head">
  <div>
    <h1>Instalación</h1>
    <p>La base de datos todavía no se ha construido.</p>
  </div>
</div>

<div class="card" style="max-width:720px">
  <div class="stack">
    <div>
      <h2 style="font-size:14.5px;margin-bottom:var(--s2)">1. Coloca el extracto</h2>
      <p class="dim" style="margin:0;font-size:13.5px">
        Copia el CSV de sell-in en <code>data/</code>. El importador necesita
        <code>product_code</code>, <code>product_name</code>, <code>date</code>,
        <code>sell_in_quantity</code>, <code>sell_in_amount</code> y <code>product_margin</code>.
        Si están, también usa <code>id_combo</code>, <code>combo</code>, <code>discount</code>,
        <code>bruto</code> y <code>product_cost</code>.
      </p>
    </div>

    <div>
      <h2 style="font-size:14.5px;margin-bottom:var(--s2)">2. Corre el importador</h2>
      <pre>php bin/import.php data/20260806_prueba_tecnica_dataset.csv</pre>
      <p class="dim" style="margin:var(--s2) 0 0;font-size:13.5px">
        Limpia los datos, calcula la economía unitaria de cada SKU, estima elasticidades,
        ajusta el contrafactual de demanda y evalúa cada promoción histórica.
      </p>
    </div>

    <div>
      <h2 style="font-size:14.5px;margin-bottom:var(--s2)">3. Recarga</h2>
      <p class="dim" style="margin:0;font-size:13.5px">El sistema arranca en el diagnóstico del portafolio.</p>
    </div>

    <p class="note">
      <strong>El asesor funciona sin configurar nada.</strong> Opera con un motor de reglas
      local y determinista. Si defines <code>ANTHROPIC_API_KEY</code>, el dictamen se enriquece
      con Claude y cae al modo local si la llamada falla.
    </p>
  </div>
</div>
