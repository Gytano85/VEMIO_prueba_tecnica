<?php
declare(strict_types=1);

namespace PromoGuard;

/**
 * Asesor de IA.
 *
 * Opera en dos modos:
 *
 *  1. LOCAL (por defecto, sin llaves ni internet): un motor de reglas que razona sobre
 *     los números del simulador y del histórico, y redacta un dictamen en lenguaje de
 *     negocio. Es determinista y auditable — cada frase se puede rastrear a una cifra.
 *
 *  2. CLAUDE (opcional): si se configura una API key, el mismo contexto numérico se envía
 *     a la API de Claude para obtener una redacción más rica. Si la llamada falla por
 *     cualquier motivo, se degrada silenciosamente al modo local.
 *
 * El diseño es deliberado: un sistema comercial no puede quedarse mudo porque se cayó
 * un proveedor externo, y un dictamen que decide presupuesto debe ser explicable.
 */
final class Advisor
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function mode(): string
    {
        return !empty($this->config['api_key']) ? 'claude' : 'local';
    }

    /**
     * Genera el dictamen sobre una simulación.
     *
     * @param array<string,mixed> $sku
     * @param array<string,mixed> $sim       Resultado de Simulator::evaluate()
     * @param array<int,array>    $analogs   Promociones históricas comparables del mismo SKU
     * @return array{headline:string,verdict:string,bullets:string[],actions:string[],source:string}
     */
    public function analyze(array $sku, array $sim, array $analogs = []): array
    {
        $local = $this->localAnalysis($sku, $sim, $analogs);

        if ($this->mode() === 'claude') {
            $remote = $this->askClaude($sku, $sim, $analogs, $local);
            if ($remote !== null) {
                return $remote;
            }
        }
        return $local;
    }

    // ------------------------------------------------------------ motor local

    /** @return array{headline:string,verdict:string,bullets:string[],actions:string[],source:string} */
    private function localAnalysis(array $sku, array $sim, array $analogs): array
    {
        $name      = (string) $sku['product_name'];
        $d         = (float) $sim['discount'];
        $breakeven = (float) $sim['breakeven_discount'];
        $coverage  = (float) $sim['coverage'];
        $margin    = (float) $sim['incremental_margin'];
        $maxViable = (float) $sim['max_viable_discount'];
        $verdict   = (string) $sim['verdict'];

        $bullets = [];
        $actions = [];

        // Sin descuento no hay promoción que juzgar.
        if (!empty($sim['no_promo'])) {
            return [
                'headline' => 'Sin descuento no hay promoción que evaluar.',
                'verdict'  => $verdict,
                'bullets'  => [
                    sprintf('A precio de lista, %s deja %s de margen por unidad (%s sobre ingreso).',
                        $name, self::money((float) $sim['unit_margin']), self::pct((float) $sku['margin_on_revenue'])),
                    sprintf('El tope de este SKU es %s: por encima de esa profundidad se vende bajo costo.',
                        self::pct($breakeven)),
                ],
                'actions'  => ['Mueve la profundidad de descuento para evaluar una mecánica concreta.'],
                'source'   => 'Motor local · reglas deterministas sobre la economia del SKU',
            ];
        }

        // ¿Existe ALGUNA profundidad rentable en este SKU? Si el markup es delgado frente a la
        // elasticidad, la respuesta es no, y decirlo vale más que sugerir un descuento menor.
        $structural = (bool) ($sim['structurally_viable'] ?? true);
        $reqElast = (float) ($sim['required_elasticity'] ?? 0);

        // 1. Diagnóstico principal
        if ($sim['sells_below_cost']) {
            $headline = sprintf(
                'Bloqueada: a %s de descuento, %s se vende por debajo de su costo.',
                self::pct($d),
                $name
            );
            $bullets[] = sprintf(
                'El precio promocional queda en %s y el costo unitario es %s. Cada unidad vendida pierde %s.',
                self::money((float) $sim['promo_price']),
                self::money((float) $sim['unit_cost']),
                self::money(abs((float) $sim['promo_unit_margin']))
            );
            $bullets[] = sprintf(
                'Este SKU tiene un markup de %s sobre costo, lo que deja un margen de %s sobre ingreso. '
                . 'Ese es el descuento maximo tecnicamente posible: %s.',
                self::pct((float) $sku['markup']),
                self::pct($breakeven),
                self::pct($breakeven)
            );
            $bullets[] = 'Ninguna cantidad de volumen adicional rescata una venta bajo costo: '
                . 'mientras mejor funcione la promocion, mas dinero cuesta.';
            if ($structural) {
                $actions[] = sprintf('Bajar la profundidad a %s o menos para volver a terreno rentable.', self::pct($maxViable));
            } else {
                $actions[] = sprintf('Bajar la profundidad por debajo de %s para al menos dejar de vender a perdida.', self::pct($breakeven));
            }
            $actions[] = 'Si el objetivo es volumen, usar una mecanica que preserve el precio unitario '
                . '(bundle multi-SKU, regalo por compra, exhibicion pagada).';
        } elseif ($verdict === Simulator::VERDICT_APPROVE) {
            $headline = sprintf(
                'Aprobada: la promocion se paga sola y deja %s de margen incremental.',
                self::money($margin)
            );
            $bullets[] = sprintf(
                'Necesita %s de uplift para cubrir el descuento, y el modelo proyecta %s. Cobertura: %s.',
                self::pct(((float) $sim['required_uplift_pct']) / 100),
                self::pct(((float) $sim['expected_uplift_pct']) / 100),
                number_format($coverage, 2)
            );
            $actions[] = 'Ejecutar segun lo planeado y medir el uplift real contra el proyectado al cierre.';
        } else {
            $gap = $sim['required_uplift_pct'] !== null
                ? ((float) $sim['required_uplift_pct'] - (float) $sim['expected_uplift_pct'])
                : 0.0;
            $headline = sprintf(
                'No recomendada: destruye %s de margen. Le falta %s de uplift para justificarse.',
                self::money(abs($margin)),
                self::pct($gap / 100)
            );
            $bullets[] = sprintf(
                'A %s de descuento hacen falta %s de unidades incrementales, pero la elasticidad estimada (%s) '
                . 'solo sostiene %s.',
                self::pct($d),
                self::pct(((float) $sim['required_uplift_pct']) / 100),
                number_format((float) $sim['elasticity'], 2),
                self::pct(((float) $sim['expected_uplift_pct']) / 100)
            );

            if (!$structural) {
                // Hallazgo estructural: con este markup NINGUNA profundidad se paga sola.
                $bullets[] = sprintf(
                    'Hallazgo de fondo: con un markup de %s, para que cualquier descuento se pagara solo '
                    . 'la demanda tendria que reaccionar con una elasticidad de al menos %s. La estimada es %s. '
                    . 'No hay profundidad rentable en este SKU: el problema no es cuanto se descuenta, '
                    . 'sino que descontar es la palanca equivocada.',
                    self::pct((float) $sku['markup']),
                    number_format($reqElast, 1),
                    number_format(abs((float) $sim['elasticity']), 2)
                );
                $actions[] = 'No usar descuento en este SKU. Sustituir por mecanicas que no toquen el precio '
                    . 'unitario: bundle multi-SKU, regalo por compra, exhibicion pagada o volumen negociado.';
                $actions[] = 'Si la promocion persigue distribucion o espacio en anaquel y no margen, '
                    . 'declararlo como inversion comercial y presupuestarla como tal.';
            } else {
                $actions[] = sprintf(
                    'Bajar la profundidad a %s: es el descuento mas alto que todavia se paga solo en este SKU.',
                    self::pct($maxViable)
                );
            }
        }

        // 2. Descomposición del margen — de dónde sale el número
        $bullets[] = sprintf(
            'Descomposicion: %s de ganancia por volumen adicional contra %s de costo del descuento. '
            . 'El descuento se aplica a las %s unidades, no solo a las incrementales.',
            self::money((float) $sim['volume_gain']),
            self::money((float) $sim['discount_cost']),
            number_format((float) $sim['promo_units'], 0)
        );

        // 3. Evidencia histórica del mismo SKU
        if ($analogs !== []) {
            $best = $analogs[0];
            $bullets[] = sprintf(
                'Evidencia historica: "%s" corrio a %s de descuento en este mismo SKU, genero %s de uplift real '
                . 'y dejo %s de margen.',
                $best['combo'],
                self::pct((float) $best['discount']),
                self::pct(((float) $best['uplift_obs_pct']) / 100),
                self::money((float) $best['incremental_margin'])
            );
            $overOptimistic = ((float) $sim['expected_uplift_pct']) > ((float) $best['uplift_obs_pct']) * 1.3;
            if ($overOptimistic) {
                $bullets[] = sprintf(
                    'Advertencia: el uplift proyectado (%s) supera con holgura lo que este SKU ha logrado '
                    . 'historicamente (%s como maximo). Conviene tratar la proyeccion como optimista.',
                    self::pct(((float) $sim['expected_uplift_pct']) / 100),
                    self::pct(max(array_map(static fn(array $a): float => (float) $a['uplift_obs_pct'], $analogs)) / 100)
                );
            }
        } else {
            $bullets[] = 'No hay promociones historicas de este SKU para contrastar; la proyeccion se apoya '
                . 'unicamente en la elasticidad estimada.';
        }

        // 4. Confiabilidad de la elasticidad: el uplift proyectado depende enteramente de ella.
        $quality = $sim['elasticity_quality'] ?? null;
        if (is_array($quality) && in_array($quality['level'], ['weak', 'none'], true)) {
            $bullets[] = sprintf(
                'Cuidado con la proyeccion: la elasticidad de este SKU es una %s. %s',
                $quality['label'],
                $quality['note']
            );
            $actions[] = 'Antes de decidir con este numero, contrastar contra el uplift real de las '
                . 'promociones pasadas del SKU, o correr una prueba de precio en pocas bodegas.';
        }

        if (!empty($sim['uplift_clamped'])) {
            $bullets[] = sprintf(
                'El uplift solicitado quedaba fuera del rango admitido y se acoto a %s.',
                self::pct(((float) $sim['expected_uplift_pct']) / 100)
            );
        }

        // 5. Acción transversal siempre presente
        $actions[] = sprintf(
            'Fijar %s como tope duro de descuento para %s en el sistema de aprobaciones.',
            self::pct($breakeven),
            $name
        );
        if (!in_array($verdict, [Simulator::VERDICT_APPROVE], true)) {
            $actions[] = 'Correrla como prueba controlada en un subconjunto de bodegas antes de un despliegue completo.';
        }

        return [
            'headline' => $headline,
            'verdict'  => $verdict,
            'bullets'  => $bullets,
            'actions'  => $actions,
            'source'   => 'Motor local · reglas deterministas sobre la economia del SKU',
        ];
    }

    // ------------------------------------------------------------ modo Claude

    /** @return array{headline:string,verdict:string,bullets:string[],actions:string[],source:string}|null */
    private function askClaude(array $sku, array $sim, array $analogs, array $fallback): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $context = [
            'sku' => [
                'nombre'               => $sku['product_name'],
                'markup_sobre_costo'   => round((float) $sku['markup'], 4),
                'costo_unitario'       => round((float) $sku['unit_cost'], 2),
                'precio_lista'         => round((float) $sku['list_price'], 2),
                'descuento_equilibrio' => round((float) $sku['breakeven_discount'], 4),
                'elasticidad'          => $sku['elasticity'] !== null ? round((float) $sku['elasticity'], 2) : null,
                'demanda_semanal_base' => round((float) $sku['baseline_weekly'], 1),
            ],
            'simulacion' => array_map(
                static fn($v) => is_float($v) ? round($v, 4) : $v,
                array_intersect_key($sim, array_flip([
                    'discount', 'weeks', 'promo_price', 'sells_below_cost', 'expected_uplift_pct',
                    'required_uplift_pct', 'coverage', 'volume_gain', 'discount_cost',
                    'incremental_margin', 'max_viable_discount', 'verdict',
                ]))
            ),
            'promociones_historicas_mismo_sku' => array_map(static fn(array $a): array => [
                'nombre'     => $a['combo'],
                'descuento'  => round((float) $a['discount'], 3),
                'uplift_pct' => round((float) $a['uplift_obs_pct'], 1),
                'margen'     => round((float) $a['incremental_margin'], 0),
                'cobertura'  => round((float) $a['coverage'], 2),
            ], array_slice($analogs, 0, 5)),
        ];

        $prompt = "Eres analista de trade marketing para una empresa de consumo masivo. "
            . "Con los siguientes datos de una promocion propuesta, escribe un dictamen breve y directo "
            . "para el equipo comercial, en espanol, sin jerga tecnica.\n\n"
            . "Reglas del negocio que debes respetar:\n"
            . "- product_margin es un markup sobre costo; el margen sobre ingreso es m/(1+m) y ese es el "
            . "descuento maximo antes de vender bajo costo.\n"
            . "- El descuento se aplica a TODAS las unidades del periodo, no solo a las incrementales.\n"
            . "- Si vende bajo costo, ningun volumen la rescata.\n\n"
            . "Datos:\n" . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n"
            . "Responde SOLO con JSON valido con esta forma exacta:\n"
            . '{"headline":"una frase con el veredicto","bullets":["3 a 5 observaciones"],'
            . '"actions":["2 a 4 acciones concretas"]}';

        $payload = json_encode([
            'model'      => $this->config['model'] ?? 'claude-sonnet-5',
            'max_tokens' => 1200,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]);

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => (int) ($this->config['timeout'] ?? 20),
            CURLOPT_HTTPHEADER     => [
                'content-type: application/json',
                'x-api-key: ' . $this->config['api_key'],
                'anthropic-version: 2023-06-01',
            ],
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $status !== 200) {
            return null;
        }

        $body = json_decode((string) $response, true);
        $text = $body['content'][0]['text'] ?? null;
        if (!is_string($text)) {
            return null;
        }
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $text = $m[0];
        }
        $parsed = json_decode($text, true);
        if (!is_array($parsed) || !isset($parsed['headline'])) {
            return null;
        }

        return [
            'headline' => (string) $parsed['headline'],
            'verdict'  => (string) $sim['verdict'],
            'bullets'  => array_map('strval', $parsed['bullets'] ?? $fallback['bullets']),
            'actions'  => array_map('strval', $parsed['actions'] ?? $fallback['actions']),
            'source'   => 'Claude (' . ($this->config['model'] ?? 'claude-sonnet-5') . ') sobre el contexto numerico del simulador',
        ];
    }

    // ------------------------------------------------------- resumen de cartera

    /**
     * Dictamen sobre todo el portafolio de promociones históricas.
     *
     * @param array<int,array> $promotions
     * @param array<int,array> $skus
     * @return array{headline:string,bullets:string[],actions:string[]}
     */
    public function portfolio(array $promotions, array $skus): array
    {
        $total = count($promotions);
        if ($total === 0) {
            return ['headline' => 'Sin promociones cargadas.', 'bullets' => [], 'actions' => []];
        }

        $lost = 0.0;
        $belowCost = [];
        $profitable = 0;
        $bestCoverage = null;
        $worst = null;
        foreach ($promotions as $p) {
            $lost += (float) $p['incremental_margin'];
            if ((int) $p['sells_below_cost'] === 1) {
                $belowCost[] = $p;
            }
            if ((float) $p['incremental_margin'] > 0) {
                $profitable++;
            }
            if ($bestCoverage === null || (float) $p['coverage'] > (float) $bestCoverage['coverage']) {
                $bestCoverage = $p;
            }
            if ($worst === null || (float) $p['incremental_margin'] < (float) $worst['incremental_margin']) {
                $worst = $p;
            }
        }

        $bullets = [];
        $actions = [];

        $bullets[] = sprintf(
            '%d de %d promociones dejaron margen positivo. El acumulado del portafolio es %s.',
            $profitable,
            $total,
            self::money($lost)
        );

        if ($belowCost !== []) {
            $names = implode('", "', array_map(static fn(array $p): string => (string) $p['combo'], $belowCost));
            $bullets[] = sprintf(
                '%d promocion(es) vendieron por debajo del costo: "%s". Su descuento supero el punto de equilibrio del SKU.',
                count($belowCost),
                $names
            );
            $actions[] = 'Suspender de inmediato las mecanicas que superan el descuento de equilibrio de su SKU.';
        }

        if ($bestCoverage !== null) {
            $bullets[] = sprintf(
                'La de mejor desempeno es "%s" (%s de descuento): logro %s de uplift, %s de lo que necesitaba.',
                $bestCoverage['combo'],
                self::pct((float) $bestCoverage['discount']),
                self::pct(((float) $bestCoverage['uplift_obs_pct']) / 100),
                self::pct((float) $bestCoverage['coverage'])
            );
            $actions[] = sprintf(
                'Reformular "%s" con menor profundidad y correrla como prueba controlada.',
                $bestCoverage['combo']
            );
        }

        if ($worst !== null) {
            $bullets[] = sprintf(
                'La mas costosa es "%s": %s de margen destruido con apenas %s de uplift.',
                $worst['combo'],
                self::money((float) $worst['incremental_margin']),
                self::pct(((float) $worst['uplift_obs_pct']) / 100)
            );
        }

        $topes = [];
        foreach ($skus as $s) {
            $topes[] = sprintf('%s %s', $s['product_name'], self::pct((float) $s['breakeven_discount']));
        }
        $actions[] = 'Cargar el tope de descuento por SKU como regla dura de aprobacion: ' . implode(' · ', $topes) . '.';
        $actions[] = 'Medir cada campana nueva con el mismo contrafactual antes de renovarla.';

        return [
            'headline' => $profitable === 0
                ? sprintf('Ninguna de las %d promociones historicas se pago sola.', $total)
                : sprintf('%d de %d promociones se pagaron solas.', $profitable, $total),
            'bullets' => $bullets,
            'actions' => $actions,
        ];
    }

    // ------------------------------------------------------------- formateo

    public static function money(float $v): string
    {
        $sign = $v < 0 ? '-' : '';
        return $sign . '$' . number_format(abs($v), 0, '.', ',');
    }

    public static function pct(float $v, int $dec = 1): string
    {
        return number_format($v * 100, $dec) . '%';
    }
}
