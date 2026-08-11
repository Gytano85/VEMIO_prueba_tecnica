# Prueba técnica VEMIO | AI Product Engineer

Este repositorio resuelve los tres retos del caso: pronóstico semanal, sensibilidad al precio
y medición de promociones. Trabajé con 283,533 transacciones, 6 SKUs, 12 bodegas y 19 combos,
entre enero de 2025 y enero de 2027.

**Sistema en línea:** https://darkgrey-ram-842360.hostingersite.com

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
promoción del mismo SKU.

En términos absolutos 17 de las 19 promociones dejaron margen positivo, es decir vendieron
por encima del costo. Dos vendieron a pérdida. Ninguna de las 19 superó lo que habría dejado
vender ese mismo volumen a precio de lista: la mejor, Combo Verano 2, recuperó 61 centavos por
cada peso de margen sacrificado, y la mediana del portafolio 37.

Todo el resultado depende del contrafactual, así que medí su error. Sobre las semanas sin
promoción da entre 6% y 10% de WAPE y subestima la demanda entre 0.3% y 0.8%, un sesgo que
juega a favor de las promociones. Para que la mejor llegara al equilibrio el contrafactual
tendría que estar sobreestimado en 39%.

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

## PromoGuard, el sistema en línea

https://darkgrey-ram-842360.hostingersite.com

El caso pedía un análisis. Al terminarlo quedaba un hueco: el análisis explica 19
promociones que ya pasaron y no impide la número 20. PromoGuard es lo que construí para eso,
y no estaba en el pedido.

Antes de lanzar una promoción valida el descuento contra el punto de equilibrio del producto,
calcula las ventas adicionales que harían falta para pagarla y bloquea las que venden bajo
costo. Está publicada con los datos de este mismo extracto.

### Cuánto vale, con los números del extracto

En 20 meses y sobre 6 SKUs se destruyeron $699,241 de margen frente a no haber promocionado,
con una promoción cada 4.5 semanas y $36,802 de pérdida promedio por campaña.

De esa cifra, $96,106 los detiene una regla dura: las dos promociones que vendieron bajo
costo. No requiere modelo. Los otros $603,135 el sistema los marca antes de aprobar, y la
decisión de bajar la profundidad, acotar el alcance o cancelar queda en el equipo comercial.

De esas 17 campañas, 12 estaban a menos de la mitad del umbral que necesitaban.

### Qué cambia para quien lo usa

Un análisis se lee una vez. El simulador se consulta cada vez que alguien arma una promoción,
y lo opera trade marketing sin saber estadística: el dictamen está en lenguaje de negocio y
cada cifra se puede rastrear hasta el dato.

Cada escenario evaluado queda guardado, así que las decisiones de descuento dejan registro.

### Qué vende VEMIO con cada cosa

Cambia contra qué presupuesto compite.

| | Solo el análisis | Con el sistema |
|---|---|---|
| Qué entrega | Un PDF y un cuaderno | Un lugar donde el cliente decide |
| Cómo se cobra | Por proyecto, una vez | Suscripción, recurrente |
| De qué presupuesto sale | Consultoría | Trade marketing, el que paga los descuentos |
| Cómo crece | Contratando más analistas | Sumando clientes, casi sin costo marginal |
| Cada cuánto se usa | Se lee una vez | En cada promoción |
| Quién lo opera | Un perfil técnico | Trade marketing |
| Cómo se renueva | Hay que volver a vender | El uso mismo es la evidencia |

Un análisis compite por presupuesto de proyecto, contra cualquier consultora que tenga un
data scientist. El sistema sale del presupuesto que paga los descuentos, que en estos 6 SKUs
fue de $1,121,460 en 20 meses con un retorno de 38 centavos por dólar entregado.

Con 6 SKUs hubo una decisión cada 4.5 semanas. Con 200 al mismo ritmo serían unas 380 al año,
y un análisis trimestral no alcanza a cubrirlas.

Cuántas promociones se evaluaron, cuántas bloqueó la regla dura y cuánto margen se protegió
son cifras que salen del propio uso del sistema, sin que nadie las recopile.

### Qué significaría para VEMIO

Trade Promotion Optimization tiene presupuesto propio dentro de un CPG y encaja con lo que
VEMIO ya vende, así que funciona como módulo de la plataforma.

Confundir un markup sobre costo con un margen sobre ingreso es un error contable y no algo
particular de esta empresa, de modo que el mismo hallazgo puede aparecer en otros clientes de
la cartera.

Para una demo sirve directo: un prospecto carga su extracto y ve sus propias cifras en
minutos.

### Una salvedad

El sistema evita pérdida, no genera ganancia. La pantalla lo dice con esas palabras, porque
presentar margen evitado como margen ganado es de las cifras que hacen desconfiar de una
herramienta cuando alguien la audita.

El código está en PHP sobre SQLite, sin framework ni dependencias externas. Vive en el
historial de este repositorio y se recupera con `git show cf53ff2:promoguard`; se retiró del
árbol actual para dejar la entrega centrada en el análisis.

## Uso de IA

Usé Claude y ChatGPT para explorar alternativas, revisar código y mejorar la presentación.
Las decisiones de modelado y las conclusiones se contrastaron con el dataset y con cálculos
independientes.
