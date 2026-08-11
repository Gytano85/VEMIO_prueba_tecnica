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
Ese archivo se genera desde las mismas vistas del sistema, así que hay que rehacerlo
cuando cambie la interfaz:

    php bin/preview.php

## Publicarlo en un hosting

Hay dos formas y ambas funcionan.

**La sencilla.** Subir la carpeta `promoguard/` completa a `public_html` y entrar al
dominio. El `index.php` de la raíz arranca el sistema y sirve los estáticos desde
`public/`. No hay que configurar nada.

**La correcta.** Apuntar la raíz del dominio a `promoguard/public`. En Hostinger se hace
en hPanel → Sitios web → Avanzado → Administrador de dominios. Así el código nunca queda
bajo la raíz publicada.

En cualquiera de las dos, los `.htaccess` incluidos bloquean el acceso web a `src/`,
`views/`, `bin/`, `tests/`, `data/`, `docs/` y a `config.php`. Importan: sin ellos,
`data/promoguard.sqlite` sería descargable desde el navegador, y contiene el análisis del
extracto de un cliente.

Requisitos: PHP 8.0 o superior con PDO SQLite, y permiso de escritura en `data/` porque
ahí se guardan los escenarios y SQLite crea archivos temporales al escribir.

Después de subirlo conviene comprobar dos direcciones:

    https://tu-dominio/                        debe abrir el diagnóstico
    https://tu-dominio/data/promoguard.sqlite  debe responder 403, no descargar

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

El motor tiene una prueba de las rutas de rentabilidad. No necesita dependencias:

    php tests/SimulatorProfitPathsTest.php

Cubre cuatro casos: la brecha a cubrir y el reparto entre aporte y focalización, que un
escenario ya rentable se conserve, que por debajo del costo no se ofrezca volumen como
salida, y que no se proponga un aumento de ventas fuera del rango que el motor admite.

