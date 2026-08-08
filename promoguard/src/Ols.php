<?php
declare(strict_types=1);

namespace PromoGuard;

/**
 * Regresión lineal por mínimos cuadrados ordinarios, resuelta con las ecuaciones
 * normales (X'X)b = X'y mediante eliminación gaussiana con pivoteo parcial.
 *
 * Se implementa a mano y sin dependencias porque el sistema debe correr en
 * cualquier hosting PHP sin extensiones adicionales.
 */
final class Ols
{
    /** @var float[] */
    private array $coefficients = [];
    private float $rSquared = 0.0;
    private int $n = 0;
    private int $k = 0;

    /**
     * @param float[][] $X Matriz de diseño SIN la columna de intercepto (se agrega sola).
     * @param float[]   $y Vector de respuesta.
     */
    public function __construct(array $X, array $y)
    {
        $this->n = count($y);
        if ($this->n === 0) {
            throw new \InvalidArgumentException('OLS: el vector de respuesta esta vacio.');
        }
        if (count($X) !== $this->n) {
            throw new \InvalidArgumentException('OLS: X e y tienen distinto numero de filas.');
        }

        $design = [];
        foreach ($X as $row) {
            $design[] = array_merge([1.0], array_map('floatval', array_values($row)));
        }
        $this->k = count($design[0]);

        if ($this->n <= $this->k) {
            throw new \InvalidArgumentException('OLS: se necesitan mas observaciones que parametros.');
        }

        $this->fit($design, array_map('floatval', array_values($y)));
    }

    /**
     * @param float[][] $X
     * @param float[]   $y
     */
    private function fit(array $X, array $y): void
    {
        $k = $this->k;
        $n = $this->n;

        $A = [];
        for ($i = 0; $i < $k; $i++) {
            $A[$i] = array_fill(0, $k + 1, 0.0);
        }
        for ($r = 0; $r < $n; $r++) {
            $row = $X[$r];
            for ($i = 0; $i < $k; $i++) {
                $xi = $row[$i];
                if ($xi === 0.0) {
                    continue;
                }
                for ($j = $i; $j < $k; $j++) {
                    $A[$i][$j] += $xi * $row[$j];
                }
                $A[$i][$k] += $xi * $y[$r];
            }
        }
        for ($i = 0; $i < $k; $i++) {
            for ($j = 0; $j < $i; $j++) {
                $A[$i][$j] = $A[$j][$i];
            }
        }

        // Regularizacion minima para estabilizar matrices casi singulares
        // (ocurre cuando hay dummies de mes sin variacion en la muestra).
        $trace = 0.0;
        for ($i = 0; $i < $k; $i++) {
            $trace += $A[$i][$i];
        }
        $ridge = ($trace / max(1, $k)) * 1e-9;
        for ($i = 1; $i < $k; $i++) {
            $A[$i][$i] += $ridge;
        }

        $this->coefficients = self::solve($A, $k);
        $this->rSquared = $this->computeR2($X, $y);
    }

    /**
     * Eliminacion gaussiana con pivoteo parcial sobre la matriz aumentada [A|b].
     *
     * @param float[][] $A
     * @return float[]
     */
    private static function solve(array $A, int $k): array
    {
        for ($col = 0; $col < $k; $col++) {
            $pivot = $col;
            $best = abs($A[$col][$col]);
            for ($r = $col + 1; $r < $k; $r++) {
                if (abs($A[$r][$col]) > $best) {
                    $best = abs($A[$r][$col]);
                    $pivot = $r;
                }
            }
            if ($best < 1e-12) {
                continue;
            }
            if ($pivot !== $col) {
                $tmp = $A[$col];
                $A[$col] = $A[$pivot];
                $A[$pivot] = $tmp;
            }
            $diag = $A[$col][$col];
            for ($j = $col; $j <= $k; $j++) {
                $A[$col][$j] /= $diag;
            }
            for ($r = 0; $r < $k; $r++) {
                if ($r === $col) {
                    continue;
                }
                $factor = $A[$r][$col];
                if ($factor === 0.0) {
                    continue;
                }
                for ($j = $col; $j <= $k; $j++) {
                    $A[$r][$j] -= $factor * $A[$col][$j];
                }
            }
        }

        $beta = [];
        for ($i = 0; $i < $k; $i++) {
            $beta[$i] = $A[$i][$k];
        }
        return $beta;
    }

    /**
     * @param float[][] $X
     * @param float[]   $y
     */
    private function computeR2(array $X, array $y): float
    {
        $mean = array_sum($y) / $this->n;
        $ssRes = 0.0;
        $ssTot = 0.0;
        for ($r = 0; $r < $this->n; $r++) {
            $pred = 0.0;
            for ($i = 0; $i < $this->k; $i++) {
                $pred += $this->coefficients[$i] * $X[$r][$i];
            }
            $ssRes += ($y[$r] - $pred) ** 2;
            $ssTot += ($y[$r] - $mean) ** 2;
        }
        return $ssTot > 0 ? 1.0 - ($ssRes / $ssTot) : 0.0;
    }

    public function coefficient(int $index): float
    {
        return $this->coefficients[$index] ?? 0.0;
    }

    /** @return float[] */
    public function coefficients(): array
    {
        return $this->coefficients;
    }

    public function rSquared(): float
    {
        return $this->rSquared;
    }

    /** Prediccion para una fila SIN intercepto (se agrega solo). */
    public function predict(array $row): float
    {
        $pred = $this->coefficients[0];
        $values = array_values($row);
        $limit = min(count($values), $this->k - 1);
        for ($i = 0; $i < $limit; $i++) {
            $pred += $this->coefficients[$i + 1] * (float) $values[$i];
        }
        return $pred;
    }
}
