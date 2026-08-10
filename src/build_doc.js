const {
  Document, Packer, Paragraph, TextRun, HeadingLevel, AlignmentType,
  LevelFormat, convertInchesToTwip, Table, TableRow, TableCell,
  WidthType, ShadingType,
} = require("docx");
const fs = require("fs");

const FONT = "Calibri";
const paragraph = (text, options = {}) => new Paragraph({
  children: [new TextRun({ text, font: FONT, size: 21, ...options })],
  spacing: { after: 115 },
});
const bullet = (text) => new Paragraph({
  children: [new TextRun({ text, font: FONT, size: 21 })],
  numbering: { reference: "bullets", level: 0 },
  spacing: { after: 65 },
});
const heading = (text, options = {}) => new Paragraph({
  text,
  heading: HeadingLevel.HEADING_2,
  spacing: { before: 170, after: 65 },
  ...options,
});

const widths = [2900, 1500, 1500, 1500, 2400];
const cell = (text, { bold = false, shade = null, width } = {}) => new TableCell({
  width: { size: width, type: WidthType.DXA },
  shading: shade ? { type: ShadingType.CLEAR, fill: shade } : undefined,
  margins: { top: 50, bottom: 50, left: 80, right: 80 },
  children: [new Paragraph({
    children: [new TextRun({ text, font: FONT, size: 18, bold })],
  })],
});
const row = (values, options = {}) => new TableRow({
  children: values.map((value, index) => cell(value, { ...options, width: widths[index] })),
});
const resultTable = (header, rows) => new Table({
  columnWidths: widths,
  rows: [
    row(header, { bold: true, shade: "E8EEF4" }),
    ...rows.map((values) => row(values)),
  ],
});

const doc = new Document({
  numbering: {
    config: [{
      reference: "bullets",
      levels: [{
        level: 0,
        format: LevelFormat.BULLET,
        text: "•",
        alignment: AlignmentType.LEFT,
        style: {
          paragraph: {
            indent: {
              left: convertInchesToTwip(0.25),
              hanging: convertInchesToTwip(0.15),
            },
          },
        },
      }],
    }],
  },
  sections: [{
    properties: {
      page: {
        size: { width: 12240, height: 15840 },
        margin: { top: 700, bottom: 700, left: 850, right: 850 },
      },
    },
    children: [
      new Paragraph({
        children: [new TextRun({
          text: "Prueba técnica | AI Product Engineer",
          bold: true,
          size: 30,
          font: FONT,
        })],
        spacing: { after: 30 },
      }),
      new Paragraph({
        children: [new TextRun({
          text: "Forecasting, sensibilidad al precio y promociones",
          size: 21,
          font: FONT,
          color: "555555",
        })],
        spacing: { after: 160 },
      }),

      heading("Enfoque"),
      paragraph("Trabajé con 283,533 transacciones de seis SKUs. Antes de modelar revisé nulos, cancelaciones, ventas de muestra y la relación entre precio, costo y margen. El punto más importante fue confirmar que product_margin es un markup sobre costo. Por eso, un markup de 20% no permite descontar 20%: el margen real sobre ingreso es 16.7%."),
      paragraph("Excluí 500 tickets con cantidad cero de los análisis de demanda. Las 500 ventas con monto cero sí cuentan en unidades, pero no en los modelos de precio. Para descuentos nulos usé cero en ventas orgánicas y la mediana del combo en ventas promocionales. Los costos y montos brutos faltantes se reconstruyeron con la relación observada de cada SKU. Además, calculé el costo dentro de cada periodo promocional, porque usar un costo histórico fijo alteraba algunos resultados de margen hasta 44%."),
      paragraph("El brief presenta dos cifras distintas para clientes y periodo. En el archivo encontré 41,334 clientes y fechas del 6 de enero de 2025 al 3 de enero de 2027; trabajé con esos valores."),

      heading("Reto A | Pronóstico de demanda"),
      paragraph("Proyecté diez semanas para los tres SKUs con mayor volumen. Validé con cinco cortes walk-forward: en cada corte el modelo sólo ve información anterior a la semana que intenta predecir. Usé WAPE porque expresa el error como porcentaje del volumen total y no se dispara en semanas pequeñas."),
      resultTable(
        ["Modelo", "Shampoo Rizos", "Desodorante", "Cubito", "Uso"],
        [
          ["Seasonal naive", "16.2%", "38.2%", "10.0%", "Baseline anual"],
          ["LightGBM", "13.9%", "59.4%", "10.4%", "Calendario y rezagos"],
          ["LightGBM + promociones", "14.3%", "9.2%", "10.2%", "Modelo final"],
        ],
      ),
      paragraph("Elegí LightGBM con calendario promocional para los tres SKUs. Su WAPE promedio es 11.2%. La mejora principal aparece en Desodorante, donde las promociones duplican la demanda y el modelo sin calendario no puede anticiparlas. Considero válido usar ese calendario porque el equipo comercial lo define antes del horizonte de reabasto."),

      heading("Reto B | Sensibilidad al precio"),
      paragraph("Para el SKU con mayor variación ajusté una regresión log-log de demanda semanal contra precio efectivo, con tendencia y estacionalidad. La elasticidad estimada está entre −2.98 y −2.58, según se controle o no por semanas con combo."),
      paragraph("Tomo ese rango con cautela. El precio cambia casi siempre cuando hay una promoción; la correlación entre precio y combo activo es −0.93. El coeficiente mezcla precio, visibilidad y mecánica promocional, así que no lo interpreto como un efecto causal puro. Dentro del rango observado, el simulador muestra que el precio que maximiza ingreso ($46.36) queda por debajo del costo unitario ($46.85). Mi recomendación es no perseguir ingreso a costa de margen. Con más datos probaría precios por bodega para separar mejor cada efecto."),

      heading("Reto C | Uplift promocional", { pageBreakBefore: true }),
      paragraph("Estimé el contrafactual de los 19 combos con un modelo estacional entrenado sólo en semanas sin promoción del mismo SKU. Después comparé las unidades observadas contra esa base. Para evaluar rentabilidad separé la ganancia de las unidades adicionales y el costo de aplicar el descuento a todas las unidades vendidas durante la promoción."),
      paragraph("Ninguna de las 19 promociones dejó margen incremental positivo. La mejor fue Combo Verano 2, con cobertura de 0.69: consiguió 69% del uplift necesario para pagarse. Dos promociones de Desodorante vendieron por debajo del costo, aunque mostraron los uplifts más altos. Verifiqué el margen con dos cálculos independientes y la diferencia fue menor a 1.5%. Si tuviera un grupo de bodegas sin promoción, lo usaría como control en lugar de depender sólo del modelo estacional."),

      heading("Recomendaciones"),
      bullet("Definir un tope de descuento por SKU y usarlo como regla de aprobación. En este portafolio los límites van de 18.0% a 23.1%."),
      bullet("Suspender Combo Quincena Desodorante y Combo Cierre Trimestre Desodorante. Ambos cruzan el costo unitario."),
      bullet("Volver a probar Combo Verano 2 con menor descuento y sólo en algunas bodegas. Fue la promoción más cercana al equilibrio, pero todavía no fue rentable."),
      bullet("No aprobar promociones sólo porque el descuento parece bajo. Combo Temporada Fría descontó 10% y perdió $61,816 por su baja respuesta de demanda."),
      bullet("Usar el escenario promocional del forecast para reabasto y recalibrarlo cada cuatro a seis semanas."),

    ],
  }],
});

Packer.toBuffer(doc).then((buffer) => {
  fs.writeFileSync("report/VEMIO_metodologia_hallazgos.docx", buffer);
  console.log("docx ok");
});
