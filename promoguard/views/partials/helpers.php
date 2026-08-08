<?php
/**
 * Helpers de presentación compartidos por las vistas.
 * Se cargan con require_once desde cada vista que los necesita.
 */
use PromoGuard\App;

if (!function_exists('pg_verdict_class')) {
    /** Clase CSS del semáforo según el veredicto. */
    function pg_verdict_class(string $verdict): string
    {
        return [
            'approve' => 'v-approve',
            'review'  => 'v-review',
            'reject'  => 'v-reject',
            'blocked' => 'v-blocked',
        ][$verdict] ?? 'v-reject';
    }

    /** Pill de color según la cobertura. */
    function pg_coverage_pill(float $coverage, bool $belowCost = false): string
    {
        if ($belowCost) {
            return 'pill-block';
        }
        if ($coverage >= 1.0) {
            return 'pill-good';
        }
        if ($coverage >= 0.75) {
            return 'pill-warn';
        }
        return 'pill-bad';
    }

    function pg_coverage_color(float $coverage, bool $belowCost = false): string
    {
        if ($belowCost) {
            return 'var(--block)';
        }
        if ($coverage >= 1.0) {
            return 'var(--good)';
        }
        if ($coverage >= 0.75) {
            return 'var(--warn)';
        }
        return 'var(--bad)';
    }

    /** Icono SVG del semáforo. */
    function pg_verdict_icon(string $verdict): string
    {
        $paths = [
            'approve' => '<path d="M20 6L9 17l-5-5"/>',
            'review'  => '<path d="M12 9v4M12 17h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L14.7 3.9a2 2 0 00-3.4 0z"/>',
            'reject'  => '<path d="M18 6L6 18M6 6l12 12"/>',
            'blocked' => '<circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/>',
        ];
        $p = $paths[$verdict] ?? $paths['reject'];
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" '
             . 'stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
    }

    /**
     * Genera un gráfico de línea/área en SVG puro.
     *
     * @param array<int,array{0:float,1:float}> ...$series  cada serie es lista de [x, y]
     */
    function pg_line_chart(array $series, array $options = []): string
    {
        $w = $options['width'] ?? 900;
        $h = $options['height'] ?? 240;
        $pad = ['t' => 14, 'r' => 14, 'b' => 26, 'l' => 52];
        $colors = $options['colors'] ?? ['var(--accent)', 'var(--text-faint)', 'var(--good)'];
        $dashes = $options['dashes'] ?? [null, '5 4', '3 3'];
        $fill = $options['fill'] ?? true;

        $allY = [];
        $allX = [];
        foreach ($series as $s) {
            foreach ($s as $pt) {
                $allX[] = $pt[0];
                $allY[] = $pt[1];
            }
        }
        if ($allY === []) {
            return '<div class="empty">Sin datos suficientes.</div>';
        }

        $minX = min($allX);
        $maxX = max($allX);
        $minY = min(0.0, min($allY));
        $maxY = max($allY);
        if ($maxY - $minY < 1e-9) {
            $maxY = $minY + 1;
        }
        $maxY += ($maxY - $minY) * 0.08;

        $iw = $w - $pad['l'] - $pad['r'];
        $ih = $h - $pad['t'] - $pad['b'];
        $sx = static fn(float $x): float => $pad['l'] + ($maxX - $minX > 0 ? ($x - $minX) / ($maxX - $minX) : 0.5) * $iw;
        $sy = static fn(float $y): float => $pad['t'] + $ih - (($y - $minY) / ($maxY - $minY)) * $ih;

        $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img">';

        // rejilla + etiquetas del eje Y
        for ($i = 0; $i <= 4; $i++) {
            $y = $pad['t'] + $ih * $i / 4;
            $val = $maxY - ($maxY - $minY) * $i / 4;
            $svg .= '<line class="grid-line" x1="' . $pad['l'] . '" y1="' . round($y, 1)
                  . '" x2="' . ($w - $pad['r']) . '" y2="' . round($y, 1) . '"/>';
            $svg .= '<text class="axis-text" x="' . ($pad['l'] - 8) . '" y="' . round($y + 3, 1)
                  . '" text-anchor="end">' . htmlspecialchars(pg_short_num($val), ENT_QUOTES) . '</text>';
        }

        foreach ($series as $i => $s) {
            if ($s === []) {
                continue;
            }
            $color = $colors[$i] ?? 'var(--accent)';
            $d = '';
            foreach ($s as $j => $pt) {
                $d .= ($j === 0 ? 'M' : 'L') . round($sx($pt[0]), 1) . ' ' . round($sy($pt[1]), 1) . ' ';
            }
            if ($fill && $i === 0) {
                $area = $d . 'L' . round($sx($s[count($s) - 1][0]), 1) . ' ' . round($sy($minY), 1)
                      . ' L' . round($sx($s[0][0]), 1) . ' ' . round($sy($minY), 1) . ' Z';
                $svg .= '<path d="' . $area . '" fill="' . $color . '" opacity=".09"/>';
            }
            $dash = $dashes[$i] ?? null;
            $svg .= '<path d="' . trim($d) . '" fill="none" stroke="' . $color . '" stroke-width="2"'
                  . ($dash ? ' stroke-dasharray="' . $dash . '"' : '')
                  . ' stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>';
        }

        // marcadores del eje X
        if (!empty($options['xlabels'])) {
            $n = count($options['xlabels']);
            foreach ($options['xlabels'] as $k => $lbl) {
                $x = $pad['l'] + ($n > 1 ? $k / ($n - 1) : 0.5) * $iw;
                $anchor = $k === 0 ? 'start' : ($k === $n - 1 ? 'end' : 'middle');
                $svg .= '<text class="axis-text" x="' . round($x, 1) . '" y="' . ($h - 8)
                      . '" text-anchor="' . $anchor . '">' . htmlspecialchars((string) $lbl, ENT_QUOTES) . '</text>';
            }
        }

        // banda vertical opcional (ventana promocional)
        if (!empty($options['band'])) {
            [$b0, $b1] = $options['band'];
            $svg .= '<rect x="' . round($sx($b0), 1) . '" y="' . $pad['t']
                  . '" width="' . max(1, round($sx($b1) - $sx($b0), 1)) . '" height="' . $ih
                  . '" fill="var(--warn)" opacity=".1"/>';
        }

        return $svg . '</svg>';
    }

    function pg_short_num(float $v): string
    {
        $a = abs($v);
        $sign = $v < 0 ? '−' : '';
        if ($a >= 1_000_000) {
            return $sign . round($a / 1_000_000, 1) . 'M';
        }
        if ($a >= 1_000) {
            return $sign . round($a / 1_000, 1) . 'k';
        }
        return $sign . round($a);
    }
}
