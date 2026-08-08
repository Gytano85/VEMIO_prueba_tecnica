# VEMIO — Prueba Técnica AI Product Engineer

Forecasting, elasticidad de precio y uplift promocional sobre el dataset de transacciones
sell-in de VEMIO (283,533 filas · ene-2025 a ene-2027 · 6 SKUs · 12 bodegas · 19 combos).

## Hallazgos principales

1. **El calendario promocional es el principal driver de error de forecast.** Incluirlo como
   regresor (es conocido a futuro: el equipo comercial lo decide) baja el WAPE del SKU más
   promocionado de **38% a 9%**.
2. **`product_margin` es un markup sobre costo, no un margen sobre ingreso.** El margen real
   sobre ingreso es `m/(1+m)` = 18%–23% según SKU, y ese es el **descuento máximo** posible
   sin vender bajo costo.
3. **Dos de las 19 promociones vendieron por debajo del costo** — descuentos de 20.1% y 21.1%
   sobre un SKU cuyo punto de equilibrio es 18.0%.
4. **Durante la ventana de un combo, el 100% de la venta del SKU es promocional.** La
   canibalización es total.
5. **Ninguna de las 19 promociones fue rentable en margen.** La mejor alcanzó el 69% del
   uplift que habría necesitado para pagarse.

## Estructura

```
vemio_case/
├── notebook/vemio_prueba_tecnica.ipynb   # Notebook principal — los 3 retos, ejecutable end-to-end
├── src/
│   ├── data_prep.py                      # Carga, limpieza y economía unitaria por SKU
│   ├── forecasting.py                    # Reto A — modelos y backtesting walk-forward
│   ├── forecasting_final.py              # Reto A — forecast final por escenario promocional
│   ├── elasticity.py                     # Reto B — especificaciones y simulador de precio
│   ├── uplift.py                         # Reto C — contrafactual, uplift y umbral de rentabilidad
│   ├── build_doc.js                      # Genera el documento de hallazgos (.docx)
│   └── 01_eda.py … 03_check_cost_margin.py   # Exploración inicial
├── data/
│   ├── raw/                              # Colocar aquí el CSV original (no versionado)
│   └── *.csv                             # Resultados: forecasts, simulación, uplift por combo
├── report/
│   ├── VEMIO_metodologia_hallazgos.docx  # Metodología, supuestos, trade-offs, recomendaciones
│   ├── VEMIO_metodologia_hallazgos.pdf
│   └── *.png                             # Gráficos del notebook
└── requirements.txt
```

## Cómo correrlo

```bash
pip install -r requirements.txt
cp <ruta>/20260806_prueba_tecnica_dataset.csv data/raw/     # o export VEMIO_DATASET_PATH=...
jupyter notebook notebook/vemio_prueba_tecnica.ipynb        # ejecutar de arriba hacia abajo
```

Los scripts de `src/` también corren de forma independiente y desde cualquier directorio:

```bash
python src/forecasting.py       # backtest de los 3 modelos
python src/elasticity.py        # especificaciones + simulador
python src/uplift.py            # uplift de las 19 promociones
```

El CSV no se versiona (64 MB, data de cliente); `data/raw/` queda con un `.gitkeep`.

## Los tres retos

**Reto A — Forecasting.** Demanda semanal a 10 semanas para los 3 SKUs de mayor volumen.
Validación walk-forward con 5 orígenes temporales, métrica WAPE. Se comparan seasonal-naive,
LightGBM y LightGBM + calendario promocional; gana el último, y se usa un modelo único para
los tres SKUs (11.2% de WAPE promedio vs. 20.7% eligiendo el mejor por SKU sin info promocional).

**Reto B — Sensibilidad al precio.** Regresión log-log con tres especificaciones para acotar
robustez (elasticidad entre −2.98 y −2.58). Simulador precio → demanda / ingreso / margen,
restringido al rango de precios observado. Incluye advertencia de identificación: la variación
de precio proviene casi enteramente de los combos (corr −0.93), así que el coeficiente mide el
efecto de activar un combo, no una elasticidad de precio pura.

**Reto C — Uplift.** Contrafactual estacional entrenado sólo con semanas sin promoción, aplicado
a las 19 promociones. La rentabilidad se evalúa con la descomposición
`margen_incr = I·(P−C) − A_promo·P·d`, que da el umbral de aprobación
`I/A_promo > (1+m)·d/m`, reportado como **cobertura** (uplift obtenido / uplift necesario).
El resultado se valida por dos vías independientes que coinciden dentro de 1.5%.

## Notas sobre el brief

El documento del caso indica en un lugar "~41,300 clientes" y en otro "~52,500", y el periodo
aparece como "ene-2025 a ene-2027 (~24 meses)" y también como "2025-01 a 2026-05, 17 meses".
Los datos reales tienen **41,334 clientes** y van de **2025-01-06 a 2027-01-03**. Se trabajó con
los datos; queda pendiente confirmarlo.

## Uso de IA

Se usó Claude (Anthropic) como asistente para exploración de datos, escritura y depuración de
código, y redacción del documento. Las decisiones de modelado, la selección de SKUs y
promociones, y la interpretación de negocio se validaron manualmente contra los datos. En
particular, una revisión crítica posterior detectó y corrigió tres defectos de la primera
versión: una métrica de ranking que se invertía con denominadores negativos, el uso de costo
histórico global en vez de costo por periodo (sesgo de hasta 44% en el margen de las
promociones), y la ausencia del calendario promocional en el modelo de forecast.
