const { Document, Packer, Paragraph, TextRun, HeadingLevel, AlignmentType,
        LevelFormat, convertInchesToTwip } = require("docx");
const fs = require("fs");

const FONT = "Calibri";

function h(text, level=HeadingLevel.HEADING_2) {
  return new Paragraph({ text, heading: level, spacing: { before: 200, after: 80 } });
}
function p(text, opts={}) {
  return new Paragraph({
    children: [new TextRun({ text, font: FONT, size: 20 })],
    spacing: { after: 120 },
    ...opts
  });
}
function bullet(text) {
  return new Paragraph({
    children: [new TextRun({ text, font: FONT, size: 20 })],
    numbering: { reference: "bullets", level: 0 },
    spacing: { after: 60 }
  });
}

const doc = new Document({
  numbering: {
    config: [{
      reference: "bullets",
      levels: [{ level: 0, format: LevelFormat.BULLET, text: "•", alignment: AlignmentType.LEFT,
        style: { paragraph: { indent: { left: convertInchesToTwip(0.25), hanging: convertInchesToTwip(0.15) } } } }]
    }]
  },
  sections: [{
    properties: { page: { size: { width: 12240, height: 15840 }, margin: { top: 720, bottom: 720, left: 900, right: 900 } } },
    children: [
      new Paragraph({
        children: [new TextRun({ text: "VEMIO — Prueba Técnica AI Product Engineer", bold: true, size: 32, font: FONT })],
        spacing: { after: 40 }
      }),
      new Paragraph({
        children: [new TextRun({ text: "Metodología, supuestos, trade-offs y recomendaciones de negocio", italics: true, size: 22, font: FONT, color: "555555" })],
        spacing: { after: 200 }
      }),

      h("1. Supuestos y tratamiento de datos"),
      p("El dataset (283,533 transacciones, ene-2025 a ene-2027) tiene inconsistencias intencionales que se trataron sin eliminar filas: los 500 tickets cancelados (cantidad = 0) se excluyeron de todo análisis de demanda; las 500 ventas de muestra/regalo (monto = 0, cantidad > 0) se mantuvieron en unidades pero se excluyeron de los análisis de precio, donde un precio de $0 no aporta señal. El descuento nulo (5.5% de filas) se imputó como 0 en ventas orgánicas y como la mediana del combo cuando la venta pertenecía a una promoción. Los 110 nulos de costo y precio bruto, y la fila con metadata de producto incompleta, se recuperaron usando la relación fija y verificada por SKU precio_lista = costo × (1 + margen)."),

      h("2. Metodología por reto"),
      p("Reto A — Forecasting: se proyectó la demanda semanal de los 3 SKUs de mayor volumen (Shampoo Rizos, Desodorante 150ml A, Cubito de pollo) a 10 semanas, comparando un baseline seasonal-naive (semana equivalente del año anterior) contra un modelo LightGBM con features de calendario, lags y medias móviles, con pronóstico recursivo. La validación fue walk-forward con 5 orígenes temporales distintos (sin usar información posterior al origen en cada backtest), usando WAPE como métrica por ser robusta a semanas de bajo volumen y directamente interpretable como % de error sobre el volumen total. El modelo ganador se eligió por SKU: LightGBM para Shampoo Rizos (WAPE 13.9% vs 16.2%), seasonal-naive para Desodorante y Cubito, donde el patrón promocional año-contra-año domina."),
      p("Reto B — Elasticidad: para Antitranspirante 150ml C (mayor variación de precio observada) se estimó una regresión log-log de cantidad semanal contra precio efectivo, controlando por tendencia y estacionalidad mensual, con errores robustos. La elasticidad resultó en -2.98 (demanda muy sensible al precio). El simulador precio→demanda/ingreso/margen usa esa elasticidad anclada a la demanda reciente, evaluado solo dentro del rango de precio históricamente observado."),
      p("Reto C — Uplift: para cada una de las 19 promociones del histórico se ajustó un modelo estacional entrenado únicamente con semanas sin promoción de ese SKU, usado para estimar la demanda contrafactual durante la ventana promocional. El uplift es la diferencia entre venta real y esa contrafactual, convertida también a impacto en ingreso y margen usando costo unitario y precio de referencia no-promocional."),

      h("3. Trade-offs y qué haría distinto con más tiempo o datos"),
      bullet("Reto A: probaría modelos jerárquicos (compartir información entre SKUs/bodegas) y agregaría el calendario de promociones planeadas como regresor conocido a futuro, en vez de proyectar solo demanda \"de negocio normal\"."),
      bullet("Reto B: con más historia y variación de precio fuera del rango actual, validaría si la elasticidad es realmente constante o cambia por tramo de precio; hoy la relación es observacional, no experimental, así que no puede leerse como estrictamente causal."),
      bullet("Reto C: probaría un método de control sintético o A/B geográfico (comparar bodegas con y sin la promoción) para separar mejor el efecto promocional puro de la estacionalidad general del negocio."),
      bullet("En general, dado el alcance de 4-6 horas, se priorizó cobertura de los tres retos con un método sólido y explicable por encima de afinar hiperparámetros o probar arquitecturas más complejas (SARIMA, Prophet, modelos causales de uplift tipo synthetic control)."),

      h("4. Recomendaciones de negocio"),
      bullet("No profundizar el descuento de Antitranspirante 150ml C: dentro del rango de precios ya probado, el margen se maximiza cerca del precio de lista — bajar el precio no genera suficiente volumen adicional para compensar la pérdida de margen unitario."),
      bullet("\"Combo Verano 2\" (Antitranspirante) es la promoción con mejor relación volumen/costo de margen de todo el catálogo (+93% de unidades). Vale la pena repetirla, pero probando un descuento 3-5 puntos más bajo: el simulador sugiere que aún así conservaría gran parte del volumen y podría volverse margen-positiva."),
      bullet("Descontinuar promociones tipo \"Combo Invierno 2\" (Cubito de pollo): mueven menos de 10% de volumen adicional y regalan margen a clientes que ya compraban el producto de forma recurrente. Redirigir ese presupuesto a SKUs con mejor respuesta a promociones."),
      bullet("Para el plan de reabasto del próximo trimestre: usar el forecast por SKU recomendado (LightGBM para Shampoo Rizos; seasonal-naive para Desodorante y Cubito) y recalibrarlo cada 4-6 semanas con datos reales, ajustándolo manualmente si se activa una promoción no incluida en el modelo."),
      bullet("Ninguna de las 19 promociones históricas resultó margen-positiva bajo esta metodología. Antes de aprobar nuevas promociones, definir si el objetivo es volumen/exhibición o margen incremental, y medir cada campaña con el mismo enfoque de contrafactual usado aquí — hoy no hay evidencia de que la profundidad de descuento actual del trade marketing esté generando retorno."),
    ]
  }]
});

Packer.toBuffer(doc).then(buf => {
  fs.writeFileSync("report/VEMIO_metodologia_hallazgos.docx", buf);
  console.log("done");
});
