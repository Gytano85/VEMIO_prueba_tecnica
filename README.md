# Prueba técnica VEMIO | AI Product Engineer

Este repositorio resuelve los tres retos del caso: pronóstico semanal, sensibilidad al precio
y medición de promociones. Trabajé con 283,533 transacciones, 6 SKUs, 12 bodegas y 19 combos,
entre enero de 2025 y enero de 2027.

## Resultados principales

### Reto A: pronóstico

Proyecté diez semanas para los tres SKUs con mayor volumen. Comparé un baseline estacional,
LightGBM y LightGBM con calendario promocional. La validación es walk-forward con cinco
cortes temporales; en cada corte el modelo sólo usa información disponible hasta ese momento.

Elegí WAPE porque expresa el error como porcentaje del volumen total. El modelo final obtiene
11.2% de WAPE promedio. En Desodorante, incluir promociones conocidas de antemano reduce el
error de 38.2% a 9.2%.

### Reto B: sensibilidad al precio

Usé una regresión log-log para Antitranspirante 150 ml C. La elasticidad estimada está entre
-2.98 y -2.58, según se controle o no por semanas con combo.

El resultado debe tomarse como una sensibilidad promocional, no como causalidad pura. El
precio cambia principalmente cuando se activa un combo y la correlación entre ambas variables
es -0.93. El simulador respeta el rango de precios observado y calcula demanda, ingreso y
margen. El precio que maximiza ingreso queda por debajo del costo unitario, por lo que no lo
recomiendo.

### Reto C: promociones

Estimé el contrafactual de los 19 combos con un modelo estacional entrenado en semanas sin
promoción del mismo SKU. Ninguna promoción dejó margen incremental positivo. La mejor,
Combo Verano 2, alcanzó 69% del uplift necesario para pagarse. Dos promociones de
Desodorante vendieron por debajo del costo.

## Cómo reproducir el análisis

El CSV original no se versiona porque pesa 64 MB. Puede copiarse a data/raw o indicarse con
la variable VEMIO_DATASET_PATH.

    pip install -r requirements.txt
    cp <ruta>/20260806_prueba_tecnica_dataset.csv data/raw/
    jupyter notebook notebook/vemio_prueba_tecnica.ipynb

El notebook ejecuta los tres retos de principio a fin. Los módulos también pueden correrse por
separado:

    python src/forecasting.py
    python src/elasticity.py
    python src/uplift.py

Las versiones están fijadas en requirements.txt. LightGBM usa deterministic, force_row_wise
y un solo proceso. Esto hace repetibles las corridas dentro del mismo entorno, aunque puede
haber diferencias pequeñas entre versiones de librerías.

## Tratamiento de datos

- Los tickets con cantidad cero no entran en demanda.
- Las muestras con monto cero cuentan en unidades, pero no en precio.
- Los descuentos nulos se completan con cero para ventas orgánicas y con la mediana del combo
  para ventas promocionales.
- Los costos y montos brutos faltantes se reconstruyen con la relación observada por SKU.
- El costo promocional se calcula dentro de cada periodo, porque cambia con el tiempo.

El brief contiene dos cifras distintas para clientes y periodo. El archivo tiene 41,334
clientes y fechas del 6 de enero de 2025 al 3 de enero de 2027; usé esos valores.

## Estructura

    notebook/vemio_prueba_tecnica.ipynb   Análisis completo
    src/                                  Módulos de Python
    data/                                 Resultados en CSV
    report/                               Documento y gráficos
    promoguard/                           Aplicación PHP adicional
    promoguard/tests/                     Prueba del motor de rentabilidad

## PromoGuard

Como complemento construí PromoGuard, una aplicación PHP con SQLite para evaluar una
promoción antes de aprobarla. Calcula el descuento máximo del SKU, el uplift necesario y el
margen esperado.

    cd promoguard
    php -S localhost:8000 -t public

La base ya está incluida y no requiere importación. La documentación técnica está en
promoguard/README.md.

## Uso de IA

Usé Claude y ChatGPT para explorar alternativas, revisar código y mejorar la presentación.
Las decisiones de modelado y las conclusiones se contrastaron con el dataset y con cálculos
independientes.
