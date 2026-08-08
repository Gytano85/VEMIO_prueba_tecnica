# PromoGuard

**Control de rentabilidad para promociones de consumo masivo.**
PHP puro · SQLite · sin dependencias · asesor de IA integrado.

---

## El problema que resuelve

El análisis del extracto de sell-in de VEMIO (283,533 transacciones, 19 promociones,
24 meses) arrojó un resultado inequívoco:

- **Ninguna de las 19 promociones históricas dejó margen positivo.** La mejor alcanzó
  el 69% del uplift que habría necesitado para pagarse.
- **Dos vendieron por debajo del costo** — descuentos de 20.1% y 21.1% sobre un SKU
  cuyo punto de equilibrio es 18.0%. Y son las de mayor uplift aparente del catálogo:
  cuanto mejor funcionaron, más dinero costaron.
- La causa raíz es un malentendido contable: **`product_margin` es un markup sobre costo,
  no un margen sobre ingreso.** Un markup de 22% deja apenas 18% de margen sobre ingreso,
  y ese es el descuento máximo posible antes de vender a pérdida.

Nadie validaba eso antes de aprobar una promoción. PromoGuard lo convierte en una
compuerta obligatoria.

---

## Qué hace

| Módulo | Qué resuelve |
|---|---|
| **Diagnóstico** | Estado del portafolio: margen acumulado, cuántas se pagaron solas, cuáles violaron su tope de descuento. |
| **Simulador** | El corazón del sistema. Se arma la mecánica (SKU, profundidad, duración) y el sistema dictamina en vivo: semáforo, uplift requerido vs. proyectado, descomposición del margen y bloqueo duro si vende bajo costo. |
| **Campañas** | Histórico ejecutado medido contra su contrafactual, con drill-down por campaña. Registro de escenarios evaluados. |
| **Catálogo** | Economía unitaria y tope de descuento de cada SKU. |
| **Proyección** | Demanda a 10 semanas con y sin promoción activa, para dimensionar reabasto. |

---

## La matemática detrás del veredicto

El margen incremental se descompone de forma exacta:

```
margen incremental  =  I · (P − C)  −  A_promo · P · d
                       └ganancia ┘     └costo del descuento┘
```

donde `I` = unidades incrementales, `A_promo` = unidades vendidas en promoción,
`P` = precio de lista, `C` = costo unitario, `d` = profundidad de descuento.

De ahí salen los tres números que el sistema vigila:

1. **Tope de descuento** — `d_max = m / (1 + m)`.
   Por encima se vende bajo costo y ningún volumen lo rescata.

2. **Umbral de aprobación** — la promoción se paga sola sólo si
   `I / A_promo > (1 + m)·d / m`.
   Se reporta como **cobertura** = uplift obtenido / uplift necesario.

3. **Viabilidad estructural** — en el límite `d → 0`, la condición se reduce a
   `|elasticidad| > (1 + m) / m`.
   Si el SKU no cumple eso, **ninguna profundidad de descuento se paga sola**, y el
   sistema lo dice en vez de sugerir un descuento menor. Con los markups de este
   catálogo (20%–30%) haría falta una elasticidad de −4.3 a −5.6; la observada va
   de −0.5 a −3.2. Descontar es la palanca equivocada en todo el portafolio.

---

## Instalación

Requiere **PHP 8.0+** con PDO SQLite (viene por defecto en prácticamente toda instalación).

```bash
# 1. Importar el extracto de transacciones
php bin/import.php ruta/al/20260806_prueba_tecnica_dataset.csv

# 2. Levantar
php -S localhost:8000 -t public
```

El repositorio incluye `data/promoguard.sqlite` ya construido con los datos reales,
así que el sistema arranca sin correr el importador.

**Vista previa sin PHP:** abrir `docs/preview.html` en cualquier navegador — es una
instantánea estática generada con los datos reales.

### Columnas que espera el importador

Obligatorias: `product_code`, `product_name`, `date`, `sell_in_quantity`,
`sell_in_amount`, `product_margin`.
Opcionales: `id_combo`, `combo`, `discount`, `bruto`, `product_cost`,
`category`, `subcategory`, `brand`, `basket`.

---

## El asesor de IA

Opera en dos modos, y el modo por defecto no requiere configuración ni internet:

- **Local** — motor de reglas determinista que razona sobre la economía del SKU y
  redacta el dictamen en lenguaje de negocio. Cada frase se puede rastrear a una cifra.
- **Claude** — si se define `ANTHROPIC_API_KEY`, el mismo contexto numérico se envía a
  la API para una redacción más rica. Si la llamada falla, degrada al modo local sin
  interrumpir el flujo.

```bash
export ANTHROPIC_API_KEY=sk-ant-...
php -S localhost:8000 -t public
```

La decisión de diseño es deliberada: un sistema que autoriza presupuesto no puede
quedarse mudo porque se cayó un proveedor externo, y un dictamen que bloquea una
campaña tiene que ser explicable.

---

## Arquitectura

```
promoguard/
├── public/
│   ├── index.php            # front controller + endpoint JSON del simulador
│   └── assets/{app.css,app.js}
├── src/
│   ├── App.php              # contenedor, autoload, render, formateo
│   ├── Schema.php           # DDL de SQLite
│   ├── Importer.php         # ETL: limpieza, economía, elasticidad, contrafactual
│   ├── Ols.php              # regresión OLS por ecuaciones normales (sin dependencias)
│   ├── Simulator.php        # motor de evaluación y umbrales
│   ├── Advisor.php          # asesor IA: motor local + Claude opcional
│   └── Repository.php       # acceso a datos
├── views/                   # plantillas
├── bin/import.php           # CLI del importador
├── data/promoguard.sqlite   # base construida
└── docs/preview.html        # instantánea estática
```

Sin Composer, sin framework, sin CDNs. Los gráficos son SVG generados en el servidor
y en el cliente con JavaScript nativo; el sistema funciona sin conexión.

`Ols.php` implementa mínimos cuadrados resolviendo las ecuaciones normales por
eliminación gaussiana con pivoteo parcial, con una regularización mínima para
estabilizar matrices casi singulares. Se usa para estimar elasticidades y para ajustar
el modelo contrafactual de demanda.

---

## Verificación

El motor reproduce el análisis original sobre el mismo extracto:

| Métrica | Análisis original | PromoGuard |
|---|---|---|
| Promociones rentables | 0 de 19 | 0 de 19 |
| Promociones bajo costo | 2 | 2 |
| Mejor cobertura | 0.69 (Combo Verano 2) | 0.69 (Combo Verano 2) |
| Elasticidad Antitranspirante | −2.98 | −2.978 |
| Topes de descuento | 18.0 / 19.4 / 20.6 / 21.3 / 23.1% | idénticos |

La identidad contable `margen = I·(P−C) − A·P·d` se verifica con residuo cero, y el
bloqueo por punto de equilibrio se probó cruzando el umbral en ambos sentidos para
los seis SKUs.
