const { Document, Packer, Paragraph, TextRun, HeadingLevel, AlignmentType, LevelFormat,
        convertInchesToTwip, Table, TableRow, TableCell, WidthType, ShadingType, BorderStyle } = require("docx");
const fs = require("fs");
const F = "Calibri";

const P = (text, o={}) => new Paragraph({ children:[new TextRun({ text, font:F, size:19, ...o })], spacing:{after:110}, ...(o.para||{}) });
const B = (text) => new Paragraph({ children:[new TextRun({ text, font:F, size:19 })], numbering:{reference:"b", level:0}, spacing:{after:70} });
const H = (text) => new Paragraph({ text, heading:HeadingLevel.HEADING_2, spacing:{before:190, after:70} });

const W = [2900, 1500, 1500, 1500, 2400];
const cell = (t, {bold=false, shade=null, w}={}) => new TableCell({
  width:{size:w, type:WidthType.DXA},
  shading: shade ? {type:ShadingType.CLEAR, fill:shade} : undefined,
  margins:{top:50,bottom:50,left:80,right:80},
  children:[new Paragraph({children:[new TextRun({text:t, font:F, size:17, bold})]})]
});
const row = (cs, o={}) => new TableRow({ children: cs.map((t,i)=>cell(t,{...o, w:W[i]})) });
const table = (header, rows) => new Table({
  columnWidths: W,
  rows: [row(header,{bold:true, shade:"E8EEF4"}), ...rows.map(r=>row(r))]
});

const doc = new Document({
  numbering:{ config:[{ reference:"b", levels:[{ level:0, format:LevelFormat.BULLET, text:"•",
    alignment:AlignmentType.LEFT, style:{paragraph:{indent:{left:convertInchesToTwip(0.25), hanging:convertInchesToTwip(0.15)}}}}]}]},
  sections:[{
    properties:{ page:{ size:{width:12240,height:15840}, margin:{top:700,bottom:700,left:850,right:850} } },
    children:[
      new Paragraph({ children:[new TextRun({text:"VEMIO — Prueba Técnica AI Product Engineer", bold:true, size:30, font:F})], spacing:{after:30} }),
      new Paragraph({ children:[new TextRun({text:"Metodología, supuestos, trade-offs y recomendaciones de negocio", italics:true, size:21, font:F, color:"555555"})], spacing:{after:180} }),

      H("1. El hallazgo que ordena todo el análisis"),
      P("product_margin (0.20–0.30) es un markup sobre costo, no un margen sobre ingreso. La distinción define cuánto descuento soporta cada SKU antes de vender a pérdida: si precio_lista = costo × (1+m), el margen sobre ingreso es m/(1+m), y ese mismo número es el descuento de equilibrio."),
      P("Con márgenes de 18%–23% sobre ingreso, dos combos de Desodorante 150 ml A (descuentos de 20.1% y 21.1%, equilibrio en 18.0%) vendieron por debajo del costo. Ninguna cantidad de volumen adicional puede rescatar una venta bajo costo: es una pérdida que escala con el éxito de la promoción."),

      H("2. Supuestos y tratamiento de datos"),
      P("No se eliminó ninguna fila: cada inconsistencia se marca con una bandera y se filtra sólo donde distorsiona. Los 500 tickets cancelados (cantidad = 0) se excluyen de los análisis de demanda. Las 500 ventas de muestra/regalo (monto = 0, cantidad > 0) cuentan en unidades — movieron inventario real — pero se excluyen de los análisis de precio, donde un precio de $0 no es señal de pricing. El descuento nulo (5.5% de filas) se imputa como 0 en ventas orgánicas y como la mediana del combo cuando la venta es promocional, porque el descuento se define a nivel de combo y no siempre se prorratea línea por línea. Los 110 nulos de costo y bruto, y la fila con metadata de producto vacía, se reconstruyen con la relación fija por SKU precio_lista = costo × (1 + margen), verificada empíricamente."),
      P("Un supuesto que resultó importante: el costo unitario NO es constante. Sube 5%–6% al año. Anclar la economía de cada promoción a su propia ventana (en vez de usar la mediana histórica del SKU) cambia los resultados de margen hasta en 44%.", {italics:true}),
      P("Nota sobre el brief: el documento del caso indica en un lugar “~41,300 clientes” y en otro “~52,500”, y el periodo aparece como “ene-2025 a ene-2027 (~24 meses)” y también como “2025-01 a 2026-05, 17 meses”. Los datos reales tienen 41,334 clientes y van de 2025-01-06 a 2027-01-03. Se trabajó con los datos; vale la pena confirmarlo con ustedes."),

      H("3. Metodología por reto"),
      P("Reto A — Forecasting. Demanda semanal a 10 semanas para los 3 SKUs de mayor volumen. Validación walk-forward con 5 orígenes temporales: en cada uno se entrena sólo con datos anteriores y se proyecta hacia adelante. Métrica WAPE, por ser robusta a semanas de bajo volumen y leerse como “% de error sobre el volumen total”. Se compararon tres modelos:", {bold:true}),
      table(["Modelo", "Shampoo Rizos", "Desodorante", "Cubito", "Comentario"], [
        ["seasonal-naive (baseline)", "16.2%", "38.2%", "10.0%", "Repite la semana equivalente del año anterior"],
        ["LightGBM", "13.9%", "59.1%", "9.8%", "Calendario + lags + medias móviles"],
        ["LightGBM + calendario promo", "14.4%", "9.4%", "9.9%", "Modelo elegido para los 3 SKUs"],
      ]),
      P(""),
      P("El resultado decisivo está en Desodorante: sin el calendario promocional ningún modelo sirve (38%–59% de error). Es el SKU más promocionado y sus combos duplican la demanda; un modelo que no sabe cuándo ocurren sólo puede promediar ruido. Incorporarlos baja el error a 9.4%, mejor en los 5 orígenes del backtest sin excepción. El calendario no es fuga de información: el equipo comercial lo decide con anticipación, así que en producción se conoce para todo el horizonte."),
      P("Se optó por un único modelo para los tres SKUs (11.2% de WAPE promedio) en vez de elegir el mejor por SKU sin información promocional (20.6%): es más preciso, más simple de operar, y evita sobreajustar la elección sobre sólo 5 observaciones de backtest."),

      P("Reto B — Sensibilidad al precio. Regresión log-log de cantidad semanal contra precio efectivo, controlando tendencia y estacionalidad, con errores robustos. La elasticidad estimada es −2.98, y se mueve a −2.58 al controlar por “hay combo activo”; se reporta el rango, no el punto, porque la precisión aparente del primer número no está respaldada.", {bold:true}),
      P("Advertencia de identificación: en este dataset el precio efectivo semanal casi no varía por pricing puro, varía porque hay o no un combo activo (correlación −0.93). El coeficiente captura el efecto combinado de activar un combo a profundidad d — precio, visibilidad y mecánica de bundle juntos — no una elasticidad de manual. Es honesto nombrarlo así, y sigue siendo útil: esa es la palanca que el equipo realmente controla."),
      P("Dato que resume la tensión del negocio: el precio que maximiza ingreso ($46.36) está por debajo del costo unitario ($46.85). Perseguir ingreso en este SKU es vender a pérdida."),

      P("Reto C — Uplift promocional. Para cada uno de los 19 combos se ajustó un modelo estacional entrenado únicamente con semanas sin promoción de ese SKU, usado para estimar la demanda contrafactual durante la ventana. El margen incremental se descompone como (unidades incrementales × margen unitario) − (unidades en promo × descuento por unidad), lo que da un umbral de aprobación directo: la promo se paga sola sólo si las unidades incrementales superan (1+m)·d/m veces el volumen promocional. Se reporta como cobertura = uplift observado / uplift requerido.", {bold:true}),
      P("El margen incremental se calculó por dos vías independientes —empírica (montos facturados reales) y analítica (la descomposición)— y coinciden dentro de 1.5%. Fue ese chequeo el que reveló el sesgo del costo histórico descrito arriba."),
      P("Se descartó deliberadamente una métrica de “margen incremental como % del ingreso incremental”: cuando el descuento es profundo y el uplift pequeño el ingreso incremental puede ser negativo, y un margen negativo sobre un ingreso negativo da un porcentaje positivo, invirtiendo el ranking."),

      H("4. Recomendaciones de negocio"),
      B("Fijar un tope de descuento por SKU y hacerlo regla dura. Cada producto tiene un descuento a partir del cual se vende bajo costo: 18.0% para Desodorante y Shampoo Verde, 19.4% para Cubito, 20.6% para Shampoo Azul, 21.3% para Shampoo Rizos, 23.1% para Antitranspirante. Dos combos de Desodorante ya cruzaron ese límite. Es la corrección más barata y de mayor impacto disponible."),
      B("Suspender “Combo Quincena Desodorante” y “Combo Cierre Trimestre Desodorante”. Vendieron bajo costo, así que ningún volumen las rescata. Son especialmente engañosas porque exhiben los uplifts más altos del catálogo (+86% y +128%): cuanto mejor funcionan, más cuestan."),
      B("Volver a probar “Combo Verano 2” (Antitranspirante), pero más suave. Es la promoción con mejor desempeño del catálogo: generó +93% de unidades contra el +135% que necesitaba para pagarse (cobertura 0.69). Bajar la profundidad de 16.1% a ~11% reduce el costo del descuento un tercio y la deja cerca del equilibrio. Correrla como test controlado en un subconjunto de bodegas, no como rollout completo."),
      B("Dejar de asumir que un descuento suave es una promoción segura. “Combo Temporada Fría” (Cubito) tuvo el descuento más bajo del catálogo (10%) y aun así perdió $61,816, porque su uplift fue de apenas +2.2%. Lo que decide la rentabilidad no es la profundidad sino la respuesta de demanda; en los SKUs de alimentos esa respuesta es consistentemente baja."),
      B("Usar el forecast con escenario promocional para el plan de reabasto. El modelo proyecta demanda con y sin promoción: para Desodorante, activar un combo al 15% lleva la demanda semanal de ~650 a ~1,360 unidades. Planear inventario con el escenario que corresponda al calendario aprobado, y recalibrar cada 4–6 semanas."),

      H("5. Trade-offs y qué haría distinto con más tiempo o datos"),
      B("Ninguna de las 19 promociones fue rentable en margen. Antes de concluir que el trade marketing destruye valor, habría que verificar con el cliente si el objetivo declarado es margen o si estas promociones compran distribución, espacio en anaquel o defensa competitiva — beneficios reales que este dataset no captura."),
      B("Reto C: el contrafactual estacional es razonable pero no es causal. Con más tiempo probaría control sintético o un diseño A/B geográfico (bodegas con y sin promoción), que separaría el efecto promocional de la estacionalidad general."),
      B("Reto B: la elasticidad no es identificable limpiamente porque el precio sólo se mueve con los combos. Un test de precio aleatorizado por bodega, aunque sea pequeño, valdría más que cualquier refinamiento del modelo actual."),
      B("Reto A: agregaría modelos jerárquicos que compartan información entre SKUs y bodegas, e intervalos de predicción — para reabasto importa tanto el nivel de servicio como el punto central."),
      B("En general, con un alcance de 4–6 horas se priorizó cobertura sólida de los tres retos y validación cruzada de los resultados por encima de afinar hiperparámetros o probar arquitecturas más complejas (SARIMA, Prophet, modelos causales de uplift)."),
    ]
  }]
});

Packer.toBuffer(doc).then(b => { fs.writeFileSync("report/VEMIO_metodologia_hallazgos.docx", b); console.log("docx ok"); });
