<?php
declare(strict_types=1);

namespace PromoGuard;

/**
 * Motor de evaluación de promociones.
 *
 * Toda la lógica se apoya en una identidad contable exacta:
 *
 *     margen incremental = I · (P − C)  −  A_promo · P · d
 *                          └ganancia┘      └costo del descuento┘
 *
 * donde I son las unidades incrementales, A_promo las unidades vendidas en promoción,
 * P el precio de lista, C el costo unitario y d la profundidad de descuento.
 *
 * De ahí sale el umbral de aprobación:
 *
 *     I / A_promo  >  P·d / (P − C)  =  (1 + m)·d / m
 *
 * y el descuento de equilibrio del SKU: d_max = m / (1 + m).
 * Cualquier descuento por encima de d_max vende bajo costo, y ningún volumen lo rescata.
 */
final class Simulator
{
    public const VERDICT_BLOCKED  = 'blocked';   // vende bajo costo
    public const VERDICT_REJECT   = 'reject';    // no alcanza ni la mitad del umbral
    public const VERDICT_REVIEW   = 'review';    // se acerca al umbral
    public const VERDICT_APPROVE  = 'approve';   // se paga sola
    public const VERDICT_NONE     = 'none';      // sin descuento: no hay nada que evaluar

    /** Límites del uplift que puede imponer el usuario, en puntos porcentuales. */
    public const UPLIFT_MIN = 0.0;
    public const UPLIFT_MAX = 400.0;

    /**
     * Evalúa una promoción propuesta.
     *
     * @param array<string,mixed> $sku      Fila de la tabla `skus`.
     * @param float               $discount Profundidad de descuento (0-1).
     * @param int                 $weeks    Duración en semanas.
     * @param float|null          $expectedUpliftPct Uplift esperado; si es null se estima con la elasticidad.
     * @return array<string,mixed>
     */
    public static function evaluate(array $sku, float $discount, int $weeks, ?float $expectedUpliftPct = null): array
    {
        $discount = max(0.0, min(0.95, $discount));
        $weeks = max(1, min(52, $weeks));

        $cost       = (float) $sku['unit_cost'];
        $markup     = (float) $sku['markup'];
        $listPrice  = (float) $sku['list_price'];
        $breakeven  = (float) $sku['breakeven_discount'];
        $baseline   = max(1.0, (float) $sku['baseline_weekly']);
        $elasticity = $sku['elasticity'] !== null ? (float) $sku['elasticity'] : -2.0;

        $promoPrice = $listPrice * (1 - $discount);
        $unitMargin = $listPrice - $cost;
        $promoUnitMargin = $promoPrice - $cost;
        $belowCost = $promoPrice < $cost;

        // Uplift esperado: por elasticidad constante, salvo que el usuario imponga uno.
        // Se acota: un uplift negativo produciría unidades y costos negativos, y el endpoint
        // acepta el parámetro por querystring.
        $modelUpliftPct = ((1 - $discount) ** $elasticity - 1) * 100;
        $upliftPct = $expectedUpliftPct !== null
            ? max(self::UPLIFT_MIN, min(self::UPLIFT_MAX, $expectedUpliftPct))
            : $modelUpliftPct;
        $upliftClamped = $expectedUpliftPct !== null && abs($expectedUpliftPct - $upliftPct) > 1e-9;

        $baselineUnits = $baseline * $weeks;
        $incrementalUnits = $baselineUnits * ($upliftPct / 100);
        $promoUnits = $baselineUnits + $incrementalUnits;   // canibalización total: todo se vende con descuento

        $discountCost = $promoUnits * $listPrice * $discount;
        $volumeGain   = $incrementalUnits * $unitMargin;
        $incrementalMargin = $volumeGain - $discountCost;

        // Umbral: unidades incrementales necesarias para que la promo se pague.
        if ($belowCost || $unitMargin <= 0) {
            $requiredUnits = null;
            $requiredUpliftPct = null;
            $coverage = 0.0;
        } else {
            // I·(P−C) = A_promo·P·d, con A_promo = baseline + I
            //  =>  I = baseline·P·d / ((P−C) − P·d)
            $denom = $unitMargin - $listPrice * $discount;
            if ($denom <= 0) {
                $requiredUnits = null;
                $requiredUpliftPct = null;
                $coverage = 0.0;
            } else {
                $requiredUnits = $baselineUnits * $listPrice * $discount / $denom;
                $requiredUpliftPct = $requiredUnits / $baselineUnits * 100;
                $coverage = $requiredUnits > 0 ? $incrementalUnits / $requiredUnits : 0.0;
            }
        }

        $noPromo = $discount <= 1e-9;
        $verdict = $noPromo ? self::VERDICT_NONE : self::verdict($belowCost, $coverage);

        // Descuento máximo que todavía se pagaría con el uplift esperado del modelo.
        $maxViableDiscount = self::maxViableDiscount($markup, $elasticity, $breakeven);

        // Elasticidad mínima para que ALGÚN descuento sea rentable en este SKU.
        // En el límite d -> 0 la condición u·m > (1+u)(1+m)d se reduce a |e| > (1+m)/m.
        $requiredElasticity = (1 + $markup) / $markup;
        $structurallyViable = abs($elasticity) > $requiredElasticity;

        return [
            'discount'            => $discount,
            'weeks'               => $weeks,
            'unit_cost'           => $cost,
            'list_price'          => $listPrice,
            'promo_price'         => $promoPrice,
            'unit_margin'         => $unitMargin,
            'promo_unit_margin'   => $promoUnitMargin,
            'breakeven_discount'  => $breakeven,
            'sells_below_cost'    => $belowCost,
            'elasticity'          => $elasticity,
            'expected_uplift_pct' => $upliftPct,
            'model_uplift_pct'    => $modelUpliftPct,
            'required_uplift_pct' => $requiredUpliftPct,
            'baseline_units'      => $baselineUnits,
            'incremental_units'   => $incrementalUnits,
            'promo_units'         => $promoUnits,
            'required_units'      => $requiredUnits,
            'coverage'            => $coverage,
            'revenue'             => $promoUnits * $promoPrice,
            'baseline_revenue'    => $baselineUnits * $listPrice,
            'volume_gain'         => $volumeGain,
            'discount_cost'       => $discountCost,
            'incremental_margin'  => $incrementalMargin,
            'baseline_margin'     => $baselineUnits * $unitMargin,
            'promo_margin'        => $promoUnits * $promoUnitMargin,
            'no_promo'            => $noPromo,
            'uplift_clamped'      => $upliftClamped,
            'elasticity_missing'  => $sku['elasticity'] === null,
            'elasticity_r2'       => $sku['elasticity_r2'] !== null ? (float) $sku['elasticity_r2'] : null,
            'elasticity_quality'  => self::elasticityQuality($sku),
            'verdict'             => $verdict,
            'verdict_label'       => self::verdictLabel($verdict),
            'max_viable_discount' => $maxViableDiscount,
            'required_elasticity' => $requiredElasticity,
            'structurally_viable' => $structurallyViable,
        ];
    }

    private static function verdict(bool $belowCost, float $coverage): string
    {
        if ($belowCost) {
            return self::VERDICT_BLOCKED;
        }
        if ($coverage >= 1.0) {
            return self::VERDICT_APPROVE;
        }
        if ($coverage >= 0.75) {
            return self::VERDICT_REVIEW;
        }
        return self::VERDICT_REJECT;
    }

    public static function verdictLabel(string $verdict): string
    {
        return [
            self::VERDICT_BLOCKED => 'Bloqueada · vende bajo costo',
            self::VERDICT_REJECT  => 'No recomendada · destruye margen',
            self::VERDICT_REVIEW  => 'Revisar · cerca del umbral',
            self::VERDICT_APPROVE => 'Aprobada · se paga sola',
            self::VERDICT_NONE    => 'Sin descuento · no hay promoción que evaluar',
        ][$verdict] ?? $verdict;
    }

    /**
     * Qué tan confiable es la elasticidad del SKU. El uplift proyectado depende por completo
     * de ella, así que el veredicto no puede presentarse con la misma seguridad cuando la
     * estimación es débil.
     *
     * @return array{level:string,label:string,note:string}
     */
    public static function elasticityQuality(array $sku): array
    {
        if ($sku['elasticity'] === null) {
            return [
                'level' => 'none',
                'label' => 'sin estimar',
                'note'  => 'No hay elasticidad estimada para este SKU; se asume −2.0 por defecto. '
                         . 'Trata la proyección como un supuesto, no como una medición.',
            ];
        }
        $e = abs((float) $sku['elasticity']);
        $r2 = $sku['elasticity_r2'] !== null ? (float) $sku['elasticity_r2'] : 0.0;

        // Un R2 alto no basta: en estas series la tendencia y la estacionalidad explican
        // casi toda la varianza, así que el ajuste puede ser bueno con el coeficiente de
        // precio mal identificado. Una elasticidad por debajo de 1 es, además, un hecho de
        // negocio por sí mismo: la demanda apenas reacciona al precio.
        if ($e < 1.0) {
            return [
                'level' => $r2 >= 0.85 ? 'medium' : 'weak',
                'label' => 'demanda poco elástica',
                'note'  => sprintf(
                    'La elasticidad estimada es %.2f: la demanda de este SKU apenas reacciona al precio. '
                    . 'Descontar mueve poco volumen%s.',
                    $e,
                    $r2 >= 0.85
                        ? ', y aunque el modelo ajusta bien, ese ajuste viene de la estacionalidad más que del precio'
                        : ', y encima el modelo explica poco de la variación semanal'
                ),
            ];
        }
        if ($r2 >= 0.85) {
            return ['level' => 'strong', 'label' => 'estimación firme',
                    'note'  => 'El modelo de demanda explica buena parte de la variación semanal y el precio sí se movió.'];
        }
        return ['level' => 'medium', 'label' => 'estimación moderada',
                'note'  => 'La elasticidad tiene respaldo parcial; el uplift proyectado puede desviarse.'];
    }

    /**
     * Descuento máximo cuyo uplift esperado (según elasticidad) todavía cubre su costo.
     * Se busca por bisección sobre [0, descuento de equilibrio).
     */
    public static function maxViableDiscount(float $markup, float $elasticity, float $breakeven): float
    {
        $viable = static function (float $d) use ($markup, $elasticity): float {
            if ($d <= 0) {
                return 1.0;
            }
            $P = 1 + $markup;            // costo normalizado a 1
            $unitMargin = $markup;
            $uplift = (1 - $d) ** $elasticity - 1;
            $promoUnits = 1 + $uplift;
            return $uplift * $unitMargin - $promoUnits * $P * $d;
        };

        $lo = 0.0;
        $hi = max(0.0, $breakeven - 1e-6);
        if ($viable($hi) > 0) {
            return $hi;
        }
        for ($i = 0; $i < 60; $i++) {
            $mid = ($lo + $hi) / 2;
            if ($viable($mid) > 0) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }
        return $lo;
    }

    /**
     * Curva de margen incremental en función de la profundidad de descuento.
     * Alimenta el gráfico del simulador.
     *
     * @return array<int,array<string,float>>
     */
    public static function curve(array $sku, int $weeks, int $steps = 40): array
    {
        $out = [];
        $maxD = min(0.40, (float) $sku['breakeven_discount'] + 0.12);
        for ($i = 0; $i <= $steps; $i++) {
            $d = $maxD * $i / $steps;
            $r = self::evaluate($sku, $d, $weeks);
            $out[] = [
                'discount'           => $d,
                'incremental_margin' => $r['incremental_margin'],
                'coverage'           => $r['coverage'],
                'units'              => $r['promo_units'],
                'revenue'            => $r['revenue'],
                'below_cost'         => $r['sells_below_cost'] ? 1.0 : 0.0,
            ];
        }
        return $out;
    }
}
