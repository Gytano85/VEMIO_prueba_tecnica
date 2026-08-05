# VEMIO — Prueba Técnica AI Product Engineer

Forecasting, elasticidad de precio y uplift promocional sobre el dataset simulado de
transacciones sell-in de VEMIO (283,533 filas, ene-2025 a ene-2027, 6 SKUs, 12 bodegas).

## Estructura

```
vemio_case/
├── notebook/vemio_prueba_tecnica.ipynb   # Notebook principal (los 3 retos, ejecutable de punta a punta)
├── src/                                  # Módulos reutilizables que importa el notebook
│   ├── data_prep.py                      # Carga y limpieza de datos
│   ├── forecasting.py / forecasting_final.py   # Reto A
│   ├── elasticity.py                     # Reto B
│   ├── uplift.py / uplift_all.py         # Reto C
│   └── 01_eda.py, 02_explore_promos.py, 03_check_cost_margin.py  # exploración inicial
├── data/
│   ├── raw/                              # Colocar aquí el CSV original (no versionado, ver abajo)
│   └── *.csv                             # Resultados intermedios (forecast, simulación, uplift)
├── report/
│   ├── VEMIO_metodologia_hallazgos.docx  # Documento de metodología, supuestos y recomendaciones (1-2 pág.)
│   ├── VEMIO_metodologia_hallazgos.pdf
│   └── *.png                             # Gráficos usados en el notebook
└── requirements.txt
```

## Cómo correrlo

1. `pip install -r requirements.txt`
2. Colocar el archivo `20260806_prueba_tecnica_dataset.csv` (provisto por VEMIO) en `data/raw/`,
   o exportar `VEMIO_DATASET_PATH` apuntando a su ubicación. El CSV no se versiona en este
   repo por ser data de cliente.
3. Abrir y correr `notebook/vemio_prueba_tecnica.ipynb` de arriba hacia abajo.

## Los tres retos

- **Reto A — Forecasting:** demanda semanal a 10 semanas para 3 SKUs, con validación
  walk-forward (sin fuga de información) y selección del mejor modelo por SKU
  (LightGBM vs. seasonal-naive), métrica WAPE.
- **Reto B — Elasticidad:** elasticidad precio-demanda para Antitranspirante 150ml C
  vía regresión log-log, con simulador precio → demanda / ingreso / margen dentro del
  rango de precio observado.
- **Reto C — Uplift:** venta incremental estimada para las 19 promociones del histórico
  usando un modelo contrafactual (baseline sin promo), con recomendación de cuál
  replicar y cuál no.

El documento `report/VEMIO_metodologia_hallazgos.docx` resume supuestos, metodología,
trade-offs y las recomendaciones de negocio en lenguaje no técnico.

## Uso de IA

Se usó Claude (Anthropic) como asistente de desarrollo para EDA exploratoria, escritura
y depuración de código, y redacción del documento de metodología. Todas las decisiones
de modelado, la selección de SKUs/promociones y la interpretación de negocio fueron
revisadas y validadas manualmente contra los datos.
