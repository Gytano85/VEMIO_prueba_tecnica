<?php
/** @var \PromoGuard\App $app */
use PromoGuard\App;
?>

<div class="page-head">
  <div>
    <h1 class="page-title">Instalación</h1>
    <p class="page-sub">La base de datos todavía no se ha construido</p>
  </div>
</div>

<div class="card" style="max-width:760px">
  <div class="card-title">Tres pasos</div>
  <ol class="setup-steps">
    <li>
      <div>
        <div style="font-weight:600;margin-bottom:5px">Coloca el extracto de transacciones</div>
        <div style="color:var(--text-dim);font-size:13.5px">
          Copia el CSV de sell-in en <code>data/</code>. El importador espera las columnas
          <code>product_code</code>, <code>product_name</code>, <code>date</code>,
          <code>sell_in_quantity</code>, <code>sell_in_amount</code>, <code>product_margin</code>,
          y opcionalmente <code>id_combo</code>, <code>combo</code>, <code>discount</code>,
          <code>bruto</code>, <code>product_cost</code>.
        </div>
      </div>
    </li>
    <li>
      <div>
        <div style="font-weight:600;margin-bottom:5px">Corre el importador</div>
        <pre>php bin/import.php data/20260806_prueba_tecnica_dataset.csv</pre>
        <div style="color:var(--text-dim);font-size:13.5px;margin-top:8px">
          Limpia los datos, calcula la economía unitaria de cada SKU, estima elasticidades,
          ajusta el contrafactual de demanda y evalúa cada promoción histórica.
        </div>
      </div>
    </li>
    <li>
      <div>
        <div style="font-weight:600;margin-bottom:5px">Recarga esta página</div>
        <div style="color:var(--text-dim);font-size:13.5px">
          El sistema arranca con el diagnóstico del portafolio promocional.
        </div>
      </div>
    </li>
  </ol>

  <div class="note mt20">
    <strong>El asesor de IA funciona sin configuración.</strong> Opera con un motor de reglas local
    y determinista. Si defines la variable de entorno <code>ANTHROPIC_API_KEY</code>, el dictamen
    se enriquece con Claude y degrada al modo local si la llamada falla.
  </div>
</div>
