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

## PromoGuard, el sistema en línea

https://darkgrey-ram-842360.hostingersite.com

El caso pedía un análisis y está entregado. Pero al terminarlo quedaba un problema abierto:
el análisis explica 19 promociones que ya ocurrieron y no impide la número 20. Por eso
construí PromoGuard, que no se pedía.

Es una compuerta de aprobación. Antes de lanzar una promoción valida la profundidad de
descuento contra el punto de equilibrio del producto, calcula las ventas adicionales que
harían falta para pagarla y bloquea las que venden por debajo del costo. Está publicada y
corriendo con los datos de este mismo extracto.

### Cuánto vale, con los números del extracto

En 20 meses y sobre 6 SKUs se destruyeron **$699,241** de margen: una promoción cada 4.5
semanas, con una pérdida promedio de $36,802 por campaña. Es una decisión recurrente, no un
error aislado.

Esa pérdida se separa en dos y la distinción importa:

- **$96,106 los bloquea una regla dura.** Dos promociones vendieron por debajo del costo. No
  hace falta criterio ni modelo: es aritmética, el sistema la aplica solo.
- **$603,135 requieren una decisión.** El sistema las marca antes de aprobarlas; bajar la
  profundidad, acotar el alcance o cancelar es del equipo comercial.

No eran decisiones al filo. De esas 17 campañas, 12 estaban a menos de la mitad del umbral
que necesitaban para pagarse.

### Qué cambia para el cliente

Pasa de diagnóstico a control. Un análisis se lee una vez; el simulador se consulta cada vez
que alguien arma una promoción.

No necesita un analista de por medio. Este análisis toma horas de un perfil técnico. El
simulador lo usa trade marketing en menos de un minuto, sin saber estadística: el dictamen
está escrito en lenguaje de negocio y cada cifra se puede rastrear.

Deja registro. Cada escenario evaluado se guarda, así que descontar deja de ser una decisión
de pasillo y se vuelve auditable.

### Qué vende VEMIO con cada cosa

Es la diferencia entre un servicio y un producto, y cambia contra qué presupuesto compite.

| | Solo el análisis | Con el sistema |
|---|---|---|
| Qué entrega | Un PDF y un cuaderno | Un lugar donde el cliente decide |
| Cómo se cobra | Por proyecto, una vez | Suscripción, recurrente |
| De qué presupuesto sale | Consultoría o proyecto puntual | Trade marketing, el mismo que financia los descuentos |
| Cómo crece | Contratando más analistas | Sumando clientes, casi sin costo marginal |
| Cada cuánto se usa | Se lee una vez y se archiva | Cada vez que arman una promoción |
| Quién lo opera | Un perfil técnico | Trade marketing, sin saber estadística |
| Cómo se renueva | Hay que volver a vender | El propio uso es la evidencia |
| Qué tan defendible es | Cualquier consultora con un analista | El método queda dentro del producto |

El punto de fondo está en la tercera fila. Un análisis compite por un presupuesto de
proyecto, y ahí VEMIO pelea contra cualquier consultora que tenga un data scientist. El
sistema compite contra otra cosa: en este extracto, **6 SKUs entregaron $1,121,460 en
descuentos en 20 meses, y por cada dólar entregado sólo regresaron 38 centavos**. Ese es
el presupuesto que el sistema gobierna, y es de otro orden de magnitud.

La frecuencia lo refuerza. Con 6 SKUs hubo una decisión cada 4.5 semanas. Un catálogo de
200 SKUs al mismo ritmo son unas 380 decisiones al año, cada una comprometiendo dinero de
descuento. Un análisis trimestral no alcanza a tocarlas; una herramienta en el flujo de
aprobación las toca todas.

Y hay algo que el sistema hace y el análisis no: reportarse solo. Cuántas promociones se
evaluaron, cuántas bloqueó la regla dura y cuánto margen se protegió son cifras que salen
del propio uso. Ese es el caso de renovación escrito sin que nadie lo redacte.

### Qué significaría para VEMIO

Es un módulo, no un entregable. Trade Promotion Optimization es una categoría con
presupuesto propio dentro de un CPG, y encaja con lo que VEMIO ya vende.

El hallazgo probablemente no es de este cliente. Confundir un markup sobre costo con un
margen sobre ingreso es un error contable, no un error de esta empresa. Si se repite en
otros clientes de la cartera, el módulo tiene mercado más allá de este caso.

Se explica solo en una demo. Un prospecto carga su propio extracto y ve su propio problema
en minutos, con sus cifras y no con un ejemplo.

### Una salvedad

El sistema no genera esa ganancia: evita la pérdida. Son cosas distintas y la pantalla lo dice
con esas palabras, porque presentar margen evitado como margen ganado es la clase de cifra que
destruye la confianza en una herramienta.

El código está en PHP sobre SQLite, sin framework ni dependencias externas. Vive en el
historial de este repositorio y se recupera con `git show cf53ff2:promoguard`; se retiró del
árbol actual para dejar la entrega centrada en el análisis.

## Uso de IA

Usé Claude y ChatGPT para explorar alternativas, revisar código y mejorar la presentación.
Las decisiones de modelado y las conclusiones se contrastaron con el dataset y con cálculos
independientes.
