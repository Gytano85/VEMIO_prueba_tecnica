<?php
declare(strict_types=1);

namespace PromoGuard;

use PDO;

/**
 * ETL: lee el extracto de transacciones sell-in y construye la base analítica.
 *
 * Reglas de limpieza (idénticas a las del análisis que originó este sistema):
 *  - Tickets cancelados (cantidad = 0) no son demanda: se excluyen.
 *  - Muestras/regalo (monto = 0, cantidad > 0) cuentan en unidades pero no en precio.
 *  - `discount` nulo: 0 si la venta fue orgánica, mediana del combo si fue promocional.
 *  - `bruto` / `product_cost` nulos: se reconstruyen con precio_lista = costo * (1 + markup).
 *  - El costo unitario NO es constante (sube ~5-6% al año): la economía de cada
 *    promoción se ancla a su propia ventana de fechas.
 */
final class Importer
{
    private PDO $pdo;
    /** @var callable */
    private $log;

    public function __construct(PDO $pdo, ?callable $log = null)
    {
        $this->pdo = $pdo;
        $this->log = $log ?? static function (string $m): void {
            fwrite(STDOUT, $m . PHP_EOL);
        };
    }

    private function say(string $message): void
    {
        ($this->log)($message);
    }

    public function run(string $csvPath): void
    {
        if (!is_readable($csvPath)) {
            throw new \RuntimeException("No se puede leer el CSV: {$csvPath}");
        }

        $this->say('  Creando esquema...');
        Schema::apply($this->pdo);

        $this->say('  Leyendo transacciones (esto toma unos segundos)...');
        $data = $this->readCsv($csvPath);

        $this->say(sprintf('  %s transacciones leidas.', number_format($data['rows'])));

        $this->say('  Calculando economia unitaria por SKU...');
        $skus = $this->buildSkus($data);

        $this->say('  Agregando demanda semanal...');
        $weekly = $this->buildWeekly($data, $skus);

        $this->say('  Estimando elasticidad y demanda base...');
        $this->estimateElasticity($skus, $weekly);

        $this->say('  Evaluando promociones historicas...');
        $promos = $this->buildPromotions($data, $skus, $weekly);

        $this->say('  Proyectando demanda...');
        $forecasts = $this->buildForecasts($skus, $weekly);

        $this->say('  Escribiendo base de datos...');
        $this->persist($skus, $weekly, $promos, $forecasts, $data);

        $this->say(sprintf(
            '  Listo: %d SKUs, %d semanas, %d promociones.',
            count($skus),
            count($weekly, COUNT_RECURSIVE) - count($weekly),
            count($promos)
        ));
    }

    // ---------------------------------------------------------------- lectura

    /** @return array<string,mixed> */
    private function readCsv(string $path): array
    {
        $fh = fopen($path, 'r');
        if ($fh === false) {
            throw new \RuntimeException('No se pudo abrir el CSV.');
        }

        $header = fgetcsv($fh);
        if ($header === false) {
            throw new \RuntimeException('El CSV esta vacio.');
        }
        $idx = array_flip(array_map('trim', $header));

        foreach (['product_code', 'product_name', 'date', 'sell_in_quantity', 'sell_in_amount', 'product_margin'] as $needed) {
            if (!isset($idx[$needed])) {
                throw new \RuntimeException("Falta la columna obligatoria '{$needed}' en el CSV.");
            }
        }

        $tx = [];              // transacciones utiles (qty > 0)
        $meta = [];            // metadata por SKU
        $comboDiscounts = [];  // id_combo => lista de descuentos no nulos
        $comboNames = [];      // id_combo => nombre comercial
        $rows = 0;
        $cancelled = 0;
        $gifts = 0;
        $nullDiscount = 0;

        while (($r = fgetcsv($fh)) !== false) {
            if (count($r) < count($header)) {
                continue;
            }
            $rows++;

            $qty = (int) $r[$idx['sell_in_quantity']];
            if ($qty === 0) {          // ticket cancelado: no es demanda
                $cancelled++;
                continue;
            }

            $amount = (float) $r[$idx['sell_in_amount']];
            $code = (int) $r[$idx['product_code']];
            $date = self::normalizeDate($r[$idx['date']]);
            if ($date === null) {
                continue;
            }

            $combo = isset($idx['id_combo']) && $r[$idx['id_combo']] !== '' ? (int) (float) $r[$idx['id_combo']] : null;
            $rawDiscount = isset($idx['discount']) && $r[$idx['discount']] !== '' ? (float) $r[$idx['discount']] : null;
            if ($rawDiscount === null) {
                $nullDiscount++;
            } elseif ($combo !== null) {
                $comboDiscounts[$combo][] = $rawDiscount;
            }
            if ($amount === 0.0) {
                $gifts++;
            }

            if ($combo !== null && !isset($comboNames[$combo]) && isset($idx['combo']) && $r[$idx['combo']] !== '') {
                $comboNames[$combo] = $r[$idx['combo']];
            }

            if (!isset($meta[$code])) {
                $meta[$code] = [
                    'product_name' => $r[$idx['product_name']],
                    'category'     => isset($idx['category'])    && $r[$idx['category']]    !== '' ? $r[$idx['category']]    : null,
                    'subcategory'  => isset($idx['subcategory']) && $r[$idx['subcategory']] !== '' ? $r[$idx['subcategory']] : null,
                    'brand'        => isset($idx['brand'])       && $r[$idx['brand']]       !== '' ? $r[$idx['brand']]       : null,
                    'basket'       => isset($idx['basket'])      && $r[$idx['basket']]      !== '' ? $r[$idx['basket']]      : null,
                    'markup'       => (float) $r[$idx['product_margin']],
                ];
            } else {
                // Recuperar metadata faltante desde otras filas del mismo SKU.
                foreach (['category', 'subcategory', 'brand', 'basket'] as $col) {
                    if ($meta[$code][$col] === null && isset($idx[$col]) && $r[$idx[$col]] !== '') {
                        $meta[$code][$col] = $r[$idx[$col]];
                    }
                }
            }

            $cost = isset($idx['product_cost']) && $r[$idx['product_cost']] !== '' ? (float) $r[$idx['product_cost']] : null;

            $tx[] = [
                'code'     => $code,
                'date'     => $date,
                'week'     => self::weekStart($date),
                'qty'      => $qty,
                'amount'   => $amount,
                'combo'    => $combo,
                'discount' => $rawDiscount,
                'unitCost' => $cost !== null ? $cost / $qty : null,
            ];
        }
        fclose($fh);

        // Imputación de descuento a nivel combo (mediana de los valores conocidos).
        $comboMedian = [];
        foreach ($comboDiscounts as $id => $values) {
            $comboMedian[$id] = self::median($values);
        }
        foreach ($tx as $i => $t) {
            if ($t['discount'] === null) {
                $tx[$i]['discount'] = $t['combo'] !== null ? ($comboMedian[$t['combo']] ?? 0.0) : 0.0;
            }
        }

        return [
            'tx' => $tx, 'meta' => $meta, 'rows' => $rows, 'comboNames' => $comboNames,
            'cancelled' => $cancelled, 'gifts' => $gifts, 'nullDiscount' => $nullDiscount,
        ];
    }

    // ------------------------------------------------------------------ SKUs

    /** @return array<int,array<string,mixed>> */
    private function buildSkus(array $data): array
    {
        $costs = [];   // code => [unitCost,...] del ultimo tramo
        $prices = [];  // code => [precio unitario efectivo,...] (sin regalos)
        $agg = [];

        foreach ($data['tx'] as $t) {
            $code = $t['code'];
            if (!isset($agg[$code])) {
                $agg[$code] = ['units' => 0, 'revenue' => 0.0];
            }
            $agg[$code]['units'] += $t['qty'];
            $agg[$code]['revenue'] += $t['amount'];

            if ($t['unitCost'] !== null) {
                $costs[$code][] = ['d' => $t['date'], 'c' => $t['unitCost']];
            }
            if ($t['amount'] > 0.0) {
                $prices[$code][] = $t['amount'] / $t['qty'];
            }
        }

        $skus = [];
        foreach ($data['meta'] as $code => $m) {
            $markup = $m['markup'];
            $unitCost = self::recentCost($costs[$code] ?? []);
            $listPrice = $unitCost * (1 + $markup);
            $marginOnRevenue = $markup / (1 + $markup);
            $p = $prices[$code] ?? [0.0];
            sort($p);

            $skus[$code] = [
                'product_code'       => $code,
                'product_name'       => $m['product_name'],
                'category'           => $m['category'],
                'subcategory'        => $m['subcategory'],
                'brand'              => $m['brand'],
                'basket'             => $m['basket'],
                'markup'             => $markup,
                'unit_cost'          => $unitCost,
                'list_price'         => $listPrice,
                'margin_on_revenue'  => $marginOnRevenue,
                'breakeven_discount' => $marginOnRevenue,
                'elasticity'         => null,
                'elasticity_r2'      => null,
                'baseline_weekly'    => 0.0,
                'total_units'        => $agg[$code]['units'],
                'total_revenue'      => $agg[$code]['revenue'],
                'price_min'          => $p[0],
                'price_max'          => $p[count($p) - 1],
                'costSeries'         => $costs[$code] ?? [],
            ];
        }
        ksort($skus);
        return $skus;
    }

    /** Mediana del costo unitario del último 20% del histórico (costo "vigente"). */
    private static function recentCost(array $series): float
    {
        if ($series === []) {
            return 0.0;
        }
        usort($series, static fn(array $a, array $b): int => strcmp($a['d'], $b['d']));
        $take = max(1, (int) floor(count($series) * 0.2));
        $tail = array_slice($series, -$take);
        return self::median(array_column($tail, 'c'));
    }

    /** Costo unitario mediano vigente dentro de una ventana de fechas. */
    public static function costInWindow(array $series, string $from, string $to): float
    {
        $vals = [];
        foreach ($series as $s) {
            if ($s['d'] >= $from && $s['d'] <= $to) {
                $vals[] = $s['c'];
            }
        }
        return $vals === [] ? self::recentCost($series) : self::median($vals);
    }

    // -------------------------------------------------------- demanda semanal

    /** @return array<int,array<string,array<string,mixed>>> */
    private function buildWeekly(array $data, array $skus): array
    {
        $weekly = [];
        foreach ($data['tx'] as $t) {
            $code = $t['code'];
            $wk = $t['week'];
            if (!isset($weekly[$code][$wk])) {
                $weekly[$code][$wk] = [
                    'week' => $wk, 'units' => 0, 'revenue' => 0.0,
                    'promo_units' => 0, 'discount_sum' => 0.0, 'promo_rows' => 0,
                    'priced_units' => 0, 'priced_revenue' => 0.0,
                ];
            }
            $w = &$weekly[$code][$wk];
            $w['units'] += $t['qty'];
            $w['revenue'] += $t['amount'];
            if ($t['amount'] > 0.0) {
                $w['priced_units'] += $t['qty'];
                $w['priced_revenue'] += $t['amount'];
            }
            if ($t['combo'] !== null) {
                $w['promo_units'] += $t['qty'];
                $w['discount_sum'] += $t['discount'];
                $w['promo_rows']++;
            }
            unset($w);
        }

        foreach ($weekly as $code => $weeks) {
            ksort($weeks);
            foreach ($weeks as $wk => $w) {
                $promoShare = $w['units'] > 0 ? $w['promo_units'] / $w['units'] : 0.0;
                $weeks[$wk]['avg_price'] = $w['priced_units'] > 0 ? $w['priced_revenue'] / $w['priced_units'] : null;
                $weeks[$wk]['on_promo'] = $promoShare > 0.01 ? 1 : 0;
                $weeks[$wk]['discount'] = $w['promo_rows'] > 0 ? $w['discount_sum'] / $w['promo_rows'] : 0.0;
                $weeks[$wk]['promo_share'] = $promoShare;
            }
            $weekly[$code] = $weeks;
        }
        return $weekly;
    }

    // ------------------------------------------------------------ elasticidad

    private function estimateElasticity(array &$skus, array $weekly): void
    {
        foreach ($skus as $code => $sku) {
            $weeks = $weekly[$code] ?? [];
            if (count($weeks) < 30) {
                continue;
            }

            $X = [];
            $y = [];
            $t = 0;
            foreach ($weeks as $w) {
                $t++;
                if ($w['units'] <= 0 || $w['avg_price'] === null || $w['avg_price'] <= 0) {
                    continue;
                }
                $month = (int) date('n', strtotime($w['week']));
                $row = [log($w['avg_price']), (float) $t];
                for ($m = 2; $m <= 12; $m++) {   // dummies de mes, base = enero
                    $row[] = $month === $m ? 1.0 : 0.0;
                }
                $X[] = $row;
                $y[] = log((float) $w['units']);
            }

            if (count($y) < 20) {
                continue;
            }
            try {
                $ols = new Ols($X, $y);
                $skus[$code]['elasticity'] = $ols->coefficient(1); // 0 = intercepto
                $skus[$code]['elasticity_r2'] = $ols->rSquared();
            } catch (\InvalidArgumentException $e) {
                $skus[$code]['elasticity'] = null;
            }

            // Demanda base: promedio de las últimas 12 semanas SIN promoción.
            $clean = array_values(array_filter($weeks, static fn(array $w): bool => $w['promo_share'] < 0.01));
            $tail = array_slice($clean, -12);
            if ($tail !== []) {
                $skus[$code]['baseline_weekly'] = array_sum(array_column($tail, 'units')) / count($tail);
            }
        }
    }

    // ------------------------------------------------------------ promociones

    /**
     * Contrafactual: modelo estacional (tendencia + mes) ajustado SÓLO con semanas
     * sin promoción, usado para predecir qué se habría vendido durante la ventana.
     *
     * @return array<int,array<string,mixed>>
     */
    private function buildPromotions(array $data, array $skus, array &$weekly): array
    {
        // Agrupar transacciones por combo.
        $combos = [];
        $comboNames = [];
        foreach ($data['tx'] as $t) {
            if ($t['combo'] === null) {
                continue;
            }
            $id = $t['combo'];
            if (!isset($combos[$id])) {
                $combos[$id] = [
                    'code' => $t['code'], 'units' => 0, 'revenue' => 0.0,
                    'discount_sum' => 0.0, 'rows' => 0,
                    'start' => $t['date'], 'end' => $t['date'],
                ];
            }
            $c = &$combos[$id];
            $c['units'] += $t['qty'];
            $c['revenue'] += $t['amount'];
            $c['discount_sum'] += $t['discount'];
            $c['rows']++;
            if ($t['date'] < $c['start']) {
                $c['start'] = $t['date'];
            }
            if ($t['date'] > $c['end']) {
                $c['end'] = $t['date'];
            }
            unset($c);
        }

        $comboNames = $data['comboNames'] ?? [];

        // Baseline contrafactual por SKU.
        $baselines = [];
        foreach ($skus as $code => $sku) {
            $baselines[$code] = $this->fitBaseline($weekly[$code] ?? []);
            foreach (($weekly[$code] ?? []) as $wk => $w) {
                $weekly[$code][$wk]['baseline'] = $baselines[$code][$wk] ?? null;
            }
        }

        $promos = [];
        foreach ($combos as $id => $c) {
            $code = $c['code'];
            $sku = $skus[$code];
            $discount = $c['rows'] > 0 ? $c['discount_sum'] / $c['rows'] : 0.0;

            // Economía anclada a la ventana de la promoción.
            $unitCost = self::costInWindow($sku['costSeries'], $c['start'], $c['end']);
            $listPrice = $unitCost * (1 + $sku['markup']);
            $unitMargin = $listPrice - $unitCost;
            $breakeven = $sku['breakeven_discount'];
            $belowCost = ($listPrice * (1 - $discount)) < $unitCost;

            // Ventana en semanas.
            $from = self::weekStart(date('Y-m-d', strtotime($c['start'] . ' -6 days')));
            $actual = 0;
            $baseline = 0.0;
            $weeks = 0;
            foreach (($weekly[$code] ?? []) as $wk => $w) {
                if ($wk >= $from && $wk <= $c['end']) {
                    $actual += $w['units'];
                    $baseline += (float) ($w['baseline'] ?? $w['units']);
                    $weeks++;
                }
            }
            if ($baseline <= 0) {
                continue;
            }

            $incremental = $actual - $baseline;
            $discountCost = $c['units'] * $listPrice * $discount;
            $volumeGain = $incremental * $unitMargin;
            $incrementalMargin = $volumeGain - $discountCost;

            if ($belowCost || $unitMargin <= 0) {
                $requiredUplift = null;
                $coverage = 0.0;
            } else {
                $requiredUnits = $c['units'] * $listPrice * $discount / $unitMargin;
                $requiredUplift = $requiredUnits / $baseline * 100;
                $coverage = $requiredUnits > 0 ? $incremental / $requiredUnits : 0.0;
            }

            $promos[$id] = [
                'id_combo'           => $id,
                'combo'              => $comboNames[$id] ?? ('Combo ' . $id),
                'product_code'       => $code,
                'start_date'         => $c['start'],
                'end_date'           => $c['end'],
                'weeks'              => $weeks,
                'discount'           => $discount,
                'breakeven_discount' => $breakeven,
                'sells_below_cost'   => $belowCost ? 1 : 0,
                'promo_units'        => $c['units'],
                'actual_units'       => $actual,
                'baseline_units'     => (int) round($baseline),
                'incremental_units'  => (int) round($incremental),
                'uplift_obs_pct'     => $incremental / $baseline * 100,
                'uplift_req_pct'     => $requiredUplift,
                'coverage'           => $coverage,
                'revenue'            => $c['revenue'],
                'volume_gain'        => $volumeGain,
                'discount_cost'      => $discountCost,
                'incremental_margin' => $incrementalMargin,
            ];
        }

        uasort($promos, static fn(array $a, array $b): int => $b['coverage'] <=> $a['coverage']);
        return $promos;
    }

    /**
     * Ajusta log(unidades) ~ tendencia + dummies de mes usando sólo semanas limpias.
     *
     * @return array<string,float> week => unidades contrafactuales
     */
    private function fitBaseline(array $weeks): array
    {
        if (count($weeks) < 20) {
            return [];
        }
        $X = [];
        $y = [];
        $allX = [];
        $keys = [];
        $t = 0;
        foreach ($weeks as $wk => $w) {
            $t++;
            $month = (int) date('n', strtotime($wk));
            $row = [(float) $t];
            for ($m = 2; $m <= 12; $m++) {
                $row[] = $month === $m ? 1.0 : 0.0;
            }
            $allX[] = $row;
            $keys[] = $wk;
            if ($w['promo_share'] < 0.01 && $w['units'] > 0) {
                $X[] = $row;
                $y[] = log((float) $w['units']);
            }
        }
        if (count($y) < 15) {
            return [];
        }
        try {
            $ols = new Ols($X, $y);
        } catch (\InvalidArgumentException $e) {
            return [];
        }

        $out = [];
        foreach ($allX as $i => $row) {
            $out[$keys[$i]] = exp($ols->predict($row));
        }
        return $out;
    }

    // -------------------------------------------------------------- forecasts

    /**
     * Proyección a 10 semanas: nivel base reciente modulado por el índice estacional
     * del mes correspondiente, en dos escenarios (con y sin promoción).
     *
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function buildForecasts(array $skus, array $weekly): array
    {
        $out = [];
        foreach ($skus as $code => $sku) {
            $weeks = $weekly[$code] ?? [];
            if (count($weeks) < 20) {
                continue;
            }
            $keys = array_keys($weeks);
            $lastWeek = end($keys);

            // Índice estacional por mes usando sólo semanas sin promoción.
            $byMonth = [];
            $clean = [];
            foreach ($weeks as $wk => $w) {
                if ($w['promo_share'] < 0.01 && $w['units'] > 0) {
                    $byMonth[(int) date('n', strtotime($wk))][] = $w['units'];
                    $clean[] = $w['units'];
                }
            }
            if ($clean === []) {
                continue;
            }
            $grand = array_sum($clean) / count($clean);
            $index = [];
            for ($m = 1; $m <= 12; $m++) {
                $index[$m] = isset($byMonth[$m]) && $byMonth[$m] !== []
                    ? (array_sum($byMonth[$m]) / count($byMonth[$m])) / $grand
                    : 1.0;
            }

            $tail = array_slice($clean, -8);
            $level = array_sum($tail) / count($tail);

            $elasticity = $sku['elasticity'] ?? -2.0;
            $promoLift = ((1 - 0.15) ** $elasticity);   // escenario promo 15%

            $rows = [];
            for ($h = 1; $h <= 10; $h++) {
                $wk = date('Y-m-d', strtotime($lastWeek . ' +' . (7 * $h) . ' days'));
                $seasonal = $level * $index[(int) date('n', strtotime($wk))];
                $rows[] = ['week' => $wk, 'scenario' => 'base',  'units' => $seasonal];
                $rows[] = ['week' => $wk, 'scenario' => 'promo', 'units' => $seasonal * $promoLift];
            }
            $out[$code] = $rows;
        }
        return $out;
    }

    // ------------------------------------------------------------ persistencia

    private function persist(array $skus, array $weekly, array $promos, array $forecasts, array $data): void
    {
        $this->pdo->beginTransaction();

        $ins = $this->pdo->prepare(
            'INSERT INTO skus (product_code, product_name, category, subcategory, brand, basket,
                markup, unit_cost, list_price, margin_on_revenue, breakeven_discount,
                elasticity, elasticity_r2, baseline_weekly, total_units, total_revenue, price_min, price_max)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        foreach ($skus as $s) {
            $ins->execute([
                $s['product_code'], $s['product_name'], $s['category'], $s['subcategory'],
                $s['brand'], $s['basket'], $s['markup'], $s['unit_cost'], $s['list_price'],
                $s['margin_on_revenue'], $s['breakeven_discount'], $s['elasticity'],
                $s['elasticity_r2'], $s['baseline_weekly'], $s['total_units'],
                $s['total_revenue'], $s['price_min'], $s['price_max'],
            ]);
        }

        $insW = $this->pdo->prepare(
            'INSERT INTO weekly_demand (product_code, week, units, revenue, avg_price, on_promo, discount, baseline)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        foreach ($weekly as $code => $weeks) {
            foreach ($weeks as $w) {
                $insW->execute([
                    $code, $w['week'], $w['units'], $w['revenue'], $w['avg_price'],
                    $w['on_promo'], $w['discount'], $w['baseline'] ?? null,
                ]);
            }
        }

        $insP = $this->pdo->prepare(
            'INSERT INTO promotions (id_combo, combo, product_code, start_date, end_date, weeks,
                discount, breakeven_discount, sells_below_cost, promo_units, actual_units,
                baseline_units, incremental_units, uplift_obs_pct, uplift_req_pct, coverage,
                revenue, volume_gain, discount_cost, incremental_margin)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        foreach ($promos as $p) {
            $insP->execute([
                $p['id_combo'], $p['combo'], $p['product_code'], $p['start_date'], $p['end_date'],
                $p['weeks'], $p['discount'], $p['breakeven_discount'], $p['sells_below_cost'],
                $p['promo_units'], $p['actual_units'], $p['baseline_units'], $p['incremental_units'],
                $p['uplift_obs_pct'], $p['uplift_req_pct'], $p['coverage'], $p['revenue'],
                $p['volume_gain'], $p['discount_cost'], $p['incremental_margin'],
            ]);
        }

        $insF = $this->pdo->prepare('INSERT INTO forecasts (product_code, week, scenario, units) VALUES (?,?,?,?)');
        foreach ($forecasts as $code => $rows) {
            foreach ($rows as $r) {
                $insF->execute([$code, $r['week'], $r['scenario'], $r['units']]);
            }
        }

        $insM = $this->pdo->prepare('INSERT OR REPLACE INTO meta (key, value) VALUES (?,?)');
        foreach ([
            'imported_at'      => date('c'),
            'rows_total'       => (string) $data['rows'],
            'rows_cancelled'   => (string) $data['cancelled'],
            'rows_gift'        => (string) $data['gifts'],
            'rows_null_disc'   => (string) $data['nullDiscount'],
        ] as $k => $v) {
            $insM->execute([$k, $v]);
        }

        $this->pdo->commit();
    }

    // ------------------------------------------------------------- utilidades

    /** Convierte dd/mm/YYYY o YYYY-mm-dd a YYYY-mm-dd. */
    public static function normalizeDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})#', $raw, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $raw, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        return null;
    }

    /** Lunes de la semana ISO a la que pertenece la fecha. */
    public static function weekStart(string $date): string
    {
        $ts = strtotime($date);
        $dow = (int) date('N', $ts);          // 1 = lunes
        return date('Y-m-d', $ts - ($dow - 1) * 86400);
    }

    /** @param float[] $values */
    public static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
