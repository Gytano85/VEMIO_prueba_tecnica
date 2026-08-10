# PromoGuard

PromoGuard evalúa la rentabilidad de una promoción antes de aprobarla. Está construido con
PHP y SQLite, sin framework ni dependencias externas.

## Qué resuelve

El análisis histórico mostró que ninguna de las 19 promociones recuperó el costo del
descuento y dos vendieron por debajo del costo. PromoGuard convierte ese análisis en una
regla operativa.

La aplicación tiene tres pantallas:

- **Diagnóstico:** resume el portafolio y muestra el límite de descuento de cada SKU.
- **Simulador:** calcula uplift, margen y demanda para un producto, descuento y duración.
- **Campañas:** presenta el histórico y guarda escenarios evaluados.

Si el precio promocional queda por debajo del costo, el escenario se bloquea.

## Cómo ejecutarlo

Requiere PHP 8.0 o superior con PDO SQLite.

    php -S localhost:8000 -t public

La base data/promoguard.sqlite ya contiene los resultados. Para regenerarla desde el CSV:

    php bin/import.php ruta/al/20260806_prueba_tecnica_dataset.csv

El importador usa hasta 512 MB de memoria porque procesa las 283 mil transacciones en una sola
corrida. También puede abrirse docs/preview.html para revisar el diseño sin iniciar PHP.

## Cálculo

El margen incremental se calcula como:

    margen = unidades_incrementales * margen_unitario
           - unidades_promocionales * descuento_unitario

De este cálculo salen tres controles:

1. **Límite de descuento:** markup / (1 + markup).
2. **Uplift requerido:** volumen adicional necesario para cubrir el descuento.
3. **Viabilidad:** comparación entre la respuesta estimada y la requerida.

El descuento se aplica a todas las unidades de la promoción, no sólo a las incrementales.

## Asesor

El modo predeterminado usa reglas locales y funciona sin internet. Si se define
ANTHROPIC_API_KEY, Claude redacta el mismo dictamen con el contexto numérico del escenario.
Si la llamada falla, la aplicación vuelve al motor local.

    set ANTHROPIC_API_KEY=sk-ant-...
    php -S localhost:8000 -t public

## Estructura

    public/index.php        Rutas y API del simulador
    public/assets/          CSS, JavaScript y favicon
    src/Simulator.php       Cálculo de escenarios
    src/Advisor.php         Reglas y asesor opcional
    src/Repository.php      Acceso a SQLite
    src/Importer.php        Limpieza e importación
    views/                  Pantallas

## Verificación

| Control | Resultado |
|---|---:|
| Promociones rentables | 0 de 19 |
| Promociones bajo costo | 2 |
| Mejor cobertura | 0.69 |
| Elasticidad de Antitranspirante | -2.978 |

También verifiqué las rutas, el simulador, el guardado de escenarios y la protección CSRF con
PHP 8.4.
