# Prueba técnica VEMIO · AI Product Engineer

Forecasting de demanda, elasticidad de precio y uplift promocional sobre el extracto de
sell-in: 283,533 transacciones, 6 SKUs, 12 bodegas, 19 combos, de enero 2025 a enero 2027.

## Lo que salió del análisis

Lo primero que revisé fue la economía unitaria, y ahí apareció el problema de fondo.
`product_margin` viene entre 0.20 y 0.30, pero es un markup sobre costo, no un margen sobre
ingreso. Si el precio de lista es `costo × (1+m)`, el margen sobre ingreso es `m/(1+m)`, o sea
entre 18% y 23% según el SKU. Ese número es también el descuento máximo que aguanta el
producto antes de venderse a pérdida.

Con eso en la mano, dos de las 19 promociones resultaron ser ventas bajo costo: descuentos de
20.1% y 21.1% sobre un SKU cuyo punto de equilibrio está en 18.0%. Las dos exhiben los uplifts
más altos del catálogo (+86% y +128%), así que en un reporte de volumen se ven como las
mejores campañas del año.

Ninguna de las 19 dejó margen positivo. La mejor llegó al 69% del uplift que habría necesitado
para pagarse. No es un artefacto del método: durante la ventana de un combo el 100% de la
venta del SKU es promocional, así que el descuento se aplica también a las unidades que se
habrían vendido igual.

En forecasting el hallazgo fue más simple. El SKU más promocionado daba 38%-59% de error con
cualquier modelo, porque sus combos duplican la demanda y el modelo no tenía forma de saber
cuándo ocurrían. Agregar el calendario promocional como regresor lo baja a 9%. No es fuga de
información: el equipo comercial define ese calendario con anticipación, así que en producción
se conoce para todo el horizonte.

## Cómo correrlo

```bash
pip install -r requirements.txt
cp <ruta>/20260806_prueba_tecnica_dataset.csv data/raw/
python -m jupyter notebook notebook/vemio_prueba_tecnica.ipynb
```

El notebook se ejecuta de arriba hacia abajo. Si prefieres no abrirlo, los módulos de `src/`
corren solos y desde cualquier directorio:

```bash
python src/forecasting.py    # backtest de los tres modelos
python src/elasticity.py     # especificaciones y simulador de precio
python src/uplift.py         # las 19 promociones
```

El CSV no está versionado porque pesa 64 MB y es data de cliente. Va en `data/raw/`, o se
apunta con `VEMIO_DATASET_PATH`.

### Sobre reproducir las cifras exactas

`requirements.txt` fija las versiones del entorno donde se generaron los resultados que están
en el repo (Python 3.10.12). Vale aclarar el alcance de eso.

LightGBM está configurado con `deterministic=True`, `force_row_wise=True` y `n_jobs=1`. Eso
garantiza que dos corridas en la misma máquina y con las mismas versiones den exactamente el
mismo WAPE, cosa que antes no pasaba. Lo que no garantiza es igualdad entre entornos
distintos: probando con LightGBM 4.6.0 y pandas 2.2.0 el error de Desodorante da 9.4% en vez
de 9.2%. Las conclusiones no se mueven, pero los decimales sí. Si necesitas los números
idénticos a los del documento, instala las versiones fijadas.

## Los tres retos

**Reto A.** Demanda semanal a 10 semanas para los tres SKUs de mayor volumen. La validación es
walk-forward con 5 orígenes: en cada uno se entrena sólo con lo anterior al corte y se proyecta
hacia adelante. Métrica WAPE, porque no se dispara en semanas de bajo volumen y se lee directo
como porcentaje de error sobre el volumen total.

Comparé tres modelos: seasonal-naive como baseline, LightGBM con calendario y lags, y el mismo
LightGBM agregando el calendario promocional. Gana el tercero. Terminé usando un solo modelo
para los tres SKUs (11.2% de WAPE promedio) en vez de elegir el mejor por SKU sin información
promocional (20.7%). Sale más preciso, es más simple de operar, y elegir ganador por SKU sobre
5 observaciones de backtest es sobreajustar.

**Reto B.** Regresión log-log de cantidad semanal contra precio efectivo, con tendencia y
estacionalidad. La elasticidad da −2.98, y −2.58 si controlo por "hay combo activo". Reporto el
rango porque la precisión del primer número no está respaldada.

Hay un problema de identificación que conviene decir de frente: en este dataset el precio casi
no se mueve por decisiones de pricing, se mueve porque hay o no un combo (correlación −0.93).
El coeficiente mide el efecto de activar un combo a profundidad *d*, con todo lo que eso trae
(precio, visibilidad, mecánica de bundle). No es una elasticidad de manual. Sigue siendo útil
porque esa es la palanca que el equipo realmente controla.

El simulador toma un precio dentro del rango observado y devuelve demanda, ingreso y margen.
Un dato que resume la tensión: el precio que maximiza ingreso ($46.36) está por debajo del
costo unitario ($46.85).

**Reto C.** Para cada uno de los 19 combos ajusté un modelo estacional entrenado únicamente con
semanas sin promoción de ese SKU, y lo usé como contrafactual durante la ventana.

La rentabilidad sale de descomponer el margen incremental:

```
margen = I·(P−C) − A_promo·P·d
```

con `I` = unidades incrementales, `A_promo` = unidades vendidas en promoción, `P` = precio de
lista, `C` = costo unitario, `d` = profundidad. De ahí sale el umbral de aprobación
`I/A_promo > (1+m)·d/m`, que reporto como cobertura: uplift obtenido sobre uplift necesario.

Calculé el margen por dos vías independientes, la empírica con montos facturados y la
analítica con esa descomposición. Coinciden dentro de 1.5%. Ese chequeo fue el que destapó que
usar el costo mediano histórico del SKU en vez del costo del periodo sesgaba el resultado hasta
en 44%, porque el costo unitario sube 5-6% al año.

## Estructura

```
├── notebook/vemio_prueba_tecnica.ipynb   Los tres retos, ejecutable end-to-end
├── src/
│   ├── data_prep.py                      Carga, limpieza y economía por SKU
│   ├── forecasting.py                    Modelos y backtesting walk-forward
│   ├── forecasting_final.py              Forecast final por escenario promocional
│   ├── elasticity.py                     Especificaciones y simulador de precio
│   ├── uplift.py                         Contrafactual y umbral de rentabilidad
│   ├── build_doc.js                      Genera el .docx de hallazgos
│   └── 01_eda.py ... 03_check_cost_margin.py
├── data/raw/                             Aquí va el CSV original (no versionado)
├── report/                               Documento de metodología y gráficos
└── promoguard/                           El sistema PHP (ver abajo)
```

## PromoGuard

`promoguard/` es un sistema web que convierte el hallazgo en una compuerta de aprobación.
Antes de lanzar una promoción valida la profundidad contra el punto de equilibrio del SKU,
calcula el uplift que haría falta y bloquea las que venden bajo costo.

```bash
cd promoguard
php -S localhost:8000 -t public
```

La base ya viene construida, así que arranca sin importar nada. PHP puro sobre SQLite, sin
Composer ni framework ni CDNs. Trae un asesor que funciona con un motor de reglas local y se
enriquece con Claude si configuras `ANTHROPIC_API_KEY`. Para ver el diseño sin levantar PHP,
abre `promoguard/docs/preview.html`.

Los detalles están en `promoguard/README.md`.

## Dudas sobre el brief

El documento del caso se contradice en dos puntos. Dice "~41,300 clientes" en un lugar y
"~52,500" en otro, y el periodo aparece como "ene-2025 a ene-2027 (~24 meses)" y también como
"2025-01 a 2026-05, 17 meses". Los datos reales traen 41,334 clientes y van del 2025-01-06 al
2027-01-03. Trabajé con los datos, pero vale confirmarlo.

## Uso de IA

Usé Claude para explorar el dataset, escribir y depurar código, y redactar el documento. Las
decisiones de modelado, la selección de SKUs y promociones y la lectura de negocio las revisé
contra los datos.

Vale la pena mencionar qué encontró la revisión crítica, porque son los errores típicos de este
tipo de análisis. Primero, una métrica de ranking que se invertía cuando el ingreso incremental
era negativo, y que ponía las peores promociones arriba. Segundo, el costo histórico global en
lugar del costo del periodo. Tercero, el calendario promocional ausente del modelo de forecast.
Una cuarta revisión encontró que el importador PHP incluía una semana de más en la ventana
promocional y que LightGBM no era reproducible entre corridas.
