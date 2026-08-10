<?php
/** Helpers de presentación compartidos por las vistas. */

if (!function_exists('pg_verdict_class')) {

    function pg_verdict_class(string $verdict): string
    {
        return ['approve' => 'v-approve', 'review' => 'v-review',
                'reject' => 'v-reject', 'blocked' => 'v-blocked'][$verdict] ?? 'v-reject';
    }

    /** Clase de etiqueta según cobertura. */
    function pg_tag(float $coverage, bool $belowCost = false): string
    {
        if ($belowCost) return 'tag-block';
        if ($coverage >= 1.0) return 'tag-pos';
        if ($coverage >= 0.75) return 'tag-warn';
        return 'tag-neg';
    }

    function pg_color(float $coverage, bool $belowCost = false): string
    {
        if ($belowCost) return 'var(--neg)';
        if ($coverage >= 1.0) return 'var(--pos)';
        if ($coverage >= 0.75) return 'var(--warn)';
        return 'var(--neg)';
    }

    function pg_short(float $v): string
    {
        $a = abs($v);
        $s = $v < 0 ? '−' : '';
        if ($a >= 1000000) return $s . round($a / 1000000, 1) . 'M';
        if ($a >= 1000)    return $s . round($a / 1000) . 'k';
        return $s . round($a);
    }

    /**
     * Gráfico de línea en SVG. Trazo fino, sin relleno pesado, ejes discretos.
     *
     * @param array<int,array<int,array{0:float,1:float}>> $series
     * @param array<string,mixed> $o
     */
    function pg_chart(array $series, array $o = []): string
    {
        $w = 880;
        $h = $o['height'] ?? 200;
        $pad = ['t' => 10, 'r' => 8, 'b' => 24, 'l' => 46];
        $colors = $o['colors'] ?? ['var(--accent)', 'var(--ink-3)', 'var(--warn)'];
        $dashes = $o['dashes'] ?? [null, '4 4', '2 3'];

        $xs = [];
        $ys = [];
        foreach ($series as $s) {
            foreach ($s as $p) { $xs[] = $p[0]; $ys[] = $p[1]; }
        }
        if ($ys === []) {
            return '<div class="empty">Sin datos suficientes.</div>';
        }

        $minX = min($xs); $maxX = max($xs);
        $minY = min(0.0, min($ys)); $maxY = max($ys);
        if ($maxY - $minY < 1e-9) $maxY = $minY + 1;
        $maxY += ($maxY - $minY) * 0.06;

        $iw = $w - $pad['l'] - $pad['r'];
        $ih = $h - $pad['t'] - $pad['b'];
        $sx = static fn(float $x): float => $pad['l'] + ($maxX > $minX ? ($x - $minX) / ($maxX - $minX) : .5) * $iw;
        $sy = static fn(float $y): float => $pad['t'] + $ih - (($y - $minY) / ($maxY - $minY)) * $ih;

        $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img"'
             . (isset($o['label']) ? ' aria-label="' . htmlspecialchars((string) $o['label'], ENT_QUOTES) . '"' : '') . '>';

        for ($i = 0; $i <= 4; $i++) {
            $gy = $pad['t'] + $ih * $i / 4;
            $val = $maxY - ($maxY - $minY) * $i / 4;
            $svg .= '<line class="grid" x1="' . $pad['l'] . '" y1="' . round($gy, 1)
                  . '" x2="' . ($w - $pad['r']) . '" y2="' . round($gy, 1) . '"/>'
                  . '<text class="tick" x="' . ($pad['l'] - 9) . '" y="' . round($gy + 3.5, 1)
                  . '" text-anchor="end">' . htmlspecialchars(pg_short($val), ENT_QUOTES) . '</text>';
        }
        if ($minY < 0) {
            $svg .= '<line class="zero" x1="' . $pad['l'] . '" y1="' . round($sy(0), 1)
                  . '" x2="' . ($w - $pad['r']) . '" y2="' . round($sy(0), 1) . '"/>';
        }

        if (!empty($o['band'])) {
            [$b0, $b1] = $o['band'];
            $svg .= '<rect x="' . round($sx($b0), 1) . '" y="' . $pad['t']
                  . '" width="' . max(1, round($sx($b1) - $sx($b0), 1)) . '" height="' . $ih
                  . '" fill="var(--accent)" opacity=".055"/>';
        }

        foreach ($series as $i => $s) {
            if ($s === []) continue;
            $c = $colors[$i] ?? 'var(--accent)';
            $d = '';
            foreach ($s as $j => $p) {
                $d .= ($j === 0 ? 'M' : 'L') . round($sx($p[0]), 1) . ' ' . round($sy($p[1]), 1) . ' ';
            }
            if ($i === 0 && ($o['fill'] ?? true)) {
                $svg .= '<path d="' . $d . 'L' . round($sx($s[count($s) - 1][0]), 1) . ' ' . round($sy($minY), 1)
                      . ' L' . round($sx($s[0][0]), 1) . ' ' . round($sy($minY), 1) . ' Z" fill="' . $c . '" opacity=".05"/>';
            }
            $dash = $dashes[$i] ?? null;
            $svg .= '<path d="' . trim($d) . '" fill="none" stroke="' . $c . '" stroke-width="1.75"'
                  . ($dash ? ' stroke-dasharray="' . $dash . '"' : '')
                  . ' stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>';
        }

        if (isset($o['marker'])) {
            [$mx, $my] = $o['marker'];
            $svg .= '<circle cx="' . round($sx($mx), 1) . '" cy="' . round($sy($my), 1)
                  . '" r="3.5" fill="var(--accent)" stroke="var(--surface)" stroke-width="2"/>';
        }
        if (isset($o['vline'])) {
            $svg .= '<line x1="' . round($sx($o['vline']), 1) . '" y1="' . $pad['t']
                  . '" x2="' . round($sx($o['vline']), 1) . '" y2="' . ($pad['t'] + $ih)
                  . '" stroke="var(--neg)" stroke-width="1.25" stroke-dasharray="3 3"/>';
        }

        if (!empty($o['xlabels'])) {
            $n = count($o['xlabels']);
            foreach ($o['xlabels'] as $k => $lbl) {
                $x = $pad['l'] + ($n > 1 ? $k / ($n - 1) : .5) * $iw;
                $anchor = $k === 0 ? 'start' : ($k === $n - 1 ? 'end' : 'middle');
                $svg .= '<text class="tick" x="' . round($x, 1) . '" y="' . ($h - 6)
                      . '" text-anchor="' . $anchor . '">' . htmlspecialchars((string) $lbl, ENT_QUOTES) . '</text>';
            }
        }

        return $svg . '</svg>';
    }
}
