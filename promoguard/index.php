<?php
declare(strict_types=1);

/**
 * Punto de entrada para hosting compartido.
 *
 * Lo correcto es apuntar la raíz del dominio a public/. Cuando eso no se puede, esta
 * carpeta se sube tal cual y el sistema arranca desde aquí. Los archivos .htaccess
 * siguen bloqueando el acceso web a src/, views/, data/ y demás, que en ese escenario
 * quedan bajo la raíz publicada.
 *
 * PG_BASE le dice al layout desde dónde servir los estáticos, porque en este modo
 * viven un nivel más abajo.
 */

define('PG_BASE', 'public/');

require __DIR__ . '/public/index.php';
