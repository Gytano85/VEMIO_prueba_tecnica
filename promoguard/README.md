# PromoGuard

Control de rentabilidad para promociones de consumo masivo. PHP sobre SQLite, sin
dependencias externas.

## Por qué existe

Analizando el extracto de sell-in de VEMIO salió que ninguna de las 19 promociones del
histórico dejó margen positivo. La mejor llegó al 69% del uplift que necesitaba para pagarse, y
dos se vendieron por debajo del costo.

La causa no era falta de datos sino un malentendido contable. `product_margin` es un markup
sobre costo, no un margen sobre ingreso. Un markup de 22% deja 18% de margen sobre ingreso, y
ese es el descuento máximo antes de perder dinero en cada unidad. Nadie estaba validando eso
antes de aprobar una campaña, porque no había dónde hacerlo.

PromoGuard lo convierte en un paso obligatorio: se arma la promoción en el simulador y el
sistema dice si se paga sola, con el número detrás.

## Qué trae

Son tres pantallas.

El **simulador** es la principal. Eliges SKU, profundidad y duración, y va recalculando en
vivo: veredicto, uplift necesario contra el proyectado, de dónde sale el margen, la curva de
rentabilidad por profundidad y la demanda proyectada a 10 semanas. Si el precio queda bajo
costo, lo bloquea.

El **diagnóstico** muestra el estado del portafolio con el tope de descuento de cada SKU:
margen acumulado, cuántas campañas se pagaron solas, cuáles cruzaron su límite.

**Campañas** tiene el histórico medido contra su contrafactual, con detalle por campaña, más
el registro de escenarios que se van guardando.

## La matemática del veredicto

Todo sale de descomponer el margen incremental:

```
margen = I·(P−C) − A_promo·P·d
```

`I` son las unidades incrementales, `A_promo` las vendidas en promoción, `P` el precio de
lista, `C` el costo unitario y `d` la profundidad de descuento. El primer término es lo que
ganas por vender más; el segundo es lo que cuesta el descuento, aplicado sobre todas las
unidades del periodo y no sólo sobre las incrementales.

De ahí salen los tres números que el sistema vigila:

**Tope de descuento**, `d_max = m/(1+m)`. Por encima se vende bajo costo y ningún volumen lo
rescata.

**Umbral de aprobación**. La promoción se paga sola sólo si `I/A_promo > (1+m)·d/m`. Se reporta
como cobertura, que es el uplift obtenido dividido entre el necesario.

**Viabilidad estructural**. Llevando la condición al límite `d → 0` queda
`|elasticidad| > (1+m)/m`. Si el SKU no cumple eso, no existe ninguna profundidad de descuento
que se pague sola, y el sistema lo dice en vez de sugerir un descuento más suave. Con los
markups de este catálogo haría falta una elasticidad entre −4.3 y −5.6; las observadas van de
−0.5 a −3.2. En todo el portafolio, descontar es la palanca equivocada.

Esa última parte apareció probando el motor. La primera versión devolvía "descuento máximo
rentable: 0%" para todos los SKUs, que como recomendación no sirve de nada. El número
interesante era el otro.

## Instalación

Necesitas PHP 8.0 o superior con PDO SQLite, que viene por defecto en casi cualquier
instalación.

```bash
php -S localhost:8000 -t public
```

`data/promoguard.sqlite` ya viene construida con los datos reales, así que arranca sin más. Si
quieres regenerarla desde el CSV:

```bash
php bin/import.php ruta/al/20260806_prueba_tecnica_dataset.csv
```

El importador sube el `memory_limit` a 512M porque mantiene el extracto limpio en memoria para
reutilizarlo entre modelos, y 283 mil filas no caben en el default de 128M.

Para ver el diseño sin levantar nada, abre `docs/preview.html`.

### Columnas que espera el importador

Obligatorias: `product_code`, `product_name`, `date`, `sell_in_quantity`, `sell_in_amount`,
`product_margin`. Opcionales: `id_combo`, `combo`, `discount`, `bruto`, `product_cost`,
`category`, `subcategory`, `brand`, `basket`.

## El asesor

Funciona en dos modos y el default no necesita configuración ni internet.

En modo local es un motor de reglas determinista que razona sobre la economía del SKU y escribe
el dictamen en lenguaje de negocio. Cada frase se puede rastrear a una cifra concreta.

Si defines `ANTHROPIC_API_KEY`, el mismo contexto numérico se manda a la API de Claude para una
redacción más rica. Si esa llamada falla por lo que sea, cae al modo local sin interrumpir
nada.

```bash
export ANTHROPIC_API_KEY=sk-ant-...
php -S localhost:8000 -t public
```

La decisión es a propósito. Un sistema que autoriza presupuesto no puede quedarse mudo porque
se cayó un proveedor externo, y un dictamen que bloquea una campaña tiene que poder explicarse.

## Cómo está armado

```
public/index.php          Front controller y endpoint JSON del simulador
public/assets/            CSS y JS, sin dependencias
src/App.php               Contenedor, autoload, render y formateo
src/Schema.php            DDL de SQLite
src/Importer.php          ETL: limpieza, economía, elasticidad, contrafactual
src/Ols.php               Regresión OLS por ecuaciones normales
src/Simulator.php         Motor de evaluación y umbrales
src/Advisor.php           Asesor local con Claude opcional
src/Repository.php        Acceso a datos
views/                    Plantillas
bin/import.php            CLI del importador
```

Los gráficos son SVG generados en el servidor y en el cliente con JavaScript nativo, así que el
sistema funciona sin conexión.

`Ols.php` resuelve mínimos cuadrados por ecuaciones normales con eliminación gaussiana y
pivoteo parcial, más una regularización mínima para matrices casi singulares. Está escrito a
mano para que el sistema corra en cualquier hosting PHP sin extensiones adicionales. Se usa
para estimar elasticidades y para ajustar el contrafactual de demanda.

## Verificación

El motor reproduce el análisis original sobre el mismo extracto:

| | Análisis | PromoGuard |
|---|---|---|
| Promociones rentables | 0 de 19 | 0 de 19 |
| Bajo costo | 2 | 2 |
| Mejor cobertura | 0.69 (Combo Verano 2) | 0.69 (Combo Verano 2) |
| Elasticidad Antitranspirante | −2.98 | −2.978 |
| Topes de descuento | 18.0 / 19.4 / 20.6 / 21.3 / 23.1% | idénticos |

La identidad `margen = I·(P−C) − A·P·d` se verifica con residuo cero, y el bloqueo por punto de
equilibrio se probó cruzando el umbral en ambos sentidos para los seis SKUs.
