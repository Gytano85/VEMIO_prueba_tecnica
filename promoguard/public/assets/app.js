/* ===========================================================================
   PromoGuard — interacciones
   =========================================================================== */

/* La barra superior se despega con una sombra cuando hay contenido debajo. */
(function () {
  'use strict';
  var bar = document.getElementById('topbar');
  if (!bar) return;
  var ticking = false;
  function update() {
    bar.classList.toggle('is-stuck', window.scrollY > 4);
    ticking = false;
  }
  window.addEventListener('scroll', function () {
    if (!ticking) { ticking = true; window.requestAnimationFrame(update); }
  }, { passive: true });
  update();
})();

/* Filas de tabla navegables: con onclick sólo funcionaban con ratón. */
(function () {
  'use strict';
  function go(el) {
    var href = el.getAttribute('data-href');
    if (href) window.location.href = href;
  }
  document.addEventListener('click', function (ev) {
    var row = ev.target.closest('tr.linked');
    // No secuestrar clics sobre controles dentro de la fila.
    if (row && !ev.target.closest('a, button, input, select, form')) go(row);
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Enter' && ev.key !== ' ') return;
    var row = ev.target.closest && ev.target.closest('tr.linked');
    if (row && ev.target === row) { ev.preventDefault(); go(row); }
  });
})();

/* ===========================================================================
   PromoGuard — simulador en vivo
   Sin dependencias. Recalcula contra el endpoint PHP con debounce.
   =========================================================================== */
(function () {
  'use strict';

  var cfg = window.PG;
  if (!cfg) return;

  var $ = function (id) { return document.getElementById(id); };
  var dRange = $('dRange'), wRange = $('wRange'), uRange = $('uRange'), skuSel = $('skuSelect');
  if (!dRange) return;

  var manualUplift = false;
  var timer = null;

  // ---------------------------------------------------------------- formato
  function money(v) {
    var s = v < 0 ? '−' : '', a = Math.abs(v);
    if (a >= 1e6) return s + '$' + (a / 1e6).toFixed(2) + 'M';
    if (a >= 1e3) return s + '$' + (a / 1e3).toFixed(1) + 'k';
    return s + '$' + Math.round(a).toLocaleString('en-US');
  }
  function pct(v, d) { return (v * 100).toFixed(d === undefined ? 1 : d) + '%'; }
  function num(v) { return Math.round(v).toLocaleString('en-US'); }
  function short(v) {
    var a = Math.abs(v), s = v < 0 ? '−' : '';
    if (a >= 1e6) return s + (a / 1e6).toFixed(1) + 'M';
    if (a >= 1e3) return s + Math.round(a / 1e3) + 'k';
    return s + Math.round(a);
  }

  var CLASS = { approve: 'v-approve', review: 'v-review', reject: 'v-reject', blocked: 'v-blocked' };
  function covColor(c, below) {
    if (below) return 'var(--neg)';
    if (c >= 1) return 'var(--pos)';
    if (c >= 0.75) return 'var(--warn)';
    return 'var(--neg)';
  }

  // -------------------------------------------------------- etiquetas locales
  function paint() {
    var d = parseFloat(dRange.value) / 100;
    $('dLabel').textContent = pct(d);
    $('wLabel').textContent = wRange.value + ' sem';
    $('uLabel').textContent = pct(parseFloat(uRange.value) / 100);
    $('curveWeeks').textContent = wRange.value;
    $('pin').style.left = (Math.min(d, cfg.scale) / cfg.scale * 100).toFixed(1) + '%';
  }

  // ------------------------------------------------------------- gráfico SVG
  function chart(points, opts) {
    var w = 880, h = 176, pad = { t: 10, r: 8, b: 24, l: 46 };
    if (!points.length) return '';
    var xs = points.map(function (p) { return p[0]; });
    var ys = points.map(function (p) { return p[1]; });
    var minX = Math.min.apply(null, xs), maxX = Math.max.apply(null, xs);
    var minY = Math.min(0, Math.min.apply(null, ys));
    var maxY = Math.max.apply(null, ys);
    if (maxY - minY < 1e-9) maxY = minY + 1;
    maxY += (maxY - minY) * 0.06;

    var iw = w - pad.l - pad.r, ih = h - pad.t - pad.b;
    var sx = function (x) { return pad.l + (maxX > minX ? (x - minX) / (maxX - minX) : .5) * iw; };
    var sy = function (y) { return pad.t + ih - ((y - minY) / (maxY - minY)) * ih; };

    var s = '<svg class="chart" viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" role="img"' +
            ' aria-label="Margen incremental segun la profundidad de descuento">';
    for (var i = 0; i <= 4; i++) {
      var gy = pad.t + ih * i / 4, val = maxY - (maxY - minY) * i / 4;
      s += '<line class="grid" x1="' + pad.l + '" y1="' + gy.toFixed(1) + '" x2="' + (w - pad.r) + '" y2="' + gy.toFixed(1) + '"/>' +
           '<text class="tick" x="' + (pad.l - 9) + '" y="' + (gy + 3.5).toFixed(1) + '" text-anchor="end">' + short(val) + '</text>';
    }
    if (minY < 0) {
      s += '<line class="zero" x1="' + pad.l + '" y1="' + sy(0).toFixed(1) + '" x2="' + (w - pad.r) + '" y2="' + sy(0).toFixed(1) + '"/>';
    }

    var d = '';
    points.forEach(function (p, j) { d += (j === 0 ? 'M' : 'L') + sx(p[0]).toFixed(1) + ' ' + sy(p[1]).toFixed(1) + ' '; });
    var last = points[points.length - 1];
    s += '<path d="' + d + 'L' + sx(last[0]).toFixed(1) + ' ' + sy(minY).toFixed(1) + ' L' + sx(points[0][0]).toFixed(1) + ' ' + sy(minY).toFixed(1) +
         ' Z" fill="var(--accent)" opacity=".05"/>';
    s += '<path d="' + d.trim() + '" fill="none" stroke="var(--accent)" stroke-width="1.75" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>';

    if (opts && opts.vline !== undefined && opts.vline <= maxX) {
      var bx = sx(opts.vline);
      s += '<line x1="' + bx.toFixed(1) + '" y1="' + pad.t + '" x2="' + bx.toFixed(1) + '" y2="' + (pad.t + ih) +
           '" stroke="var(--neg)" stroke-width="1.25" stroke-dasharray="3 3"/>';
    }
    if (opts && typeof opts.marker === 'number') {
      var m = points.reduce(function (a, b) {
        return Math.abs(b[0] - opts.marker) < Math.abs(a[0] - opts.marker) ? b : a;
      });
      s += '<circle cx="' + sx(m[0]).toFixed(1) + '" cy="' + sy(m[1]).toFixed(1) +
           '" r="3.5" fill="var(--accent)" stroke="var(--surface)" stroke-width="2"/>';
    }
    for (var k = 0; k <= 3; k++) {
      var lx = pad.l + (k / 3) * iw, lv = minX + (maxX - minX) * k / 3;
      var anc = k === 0 ? 'start' : (k === 3 ? 'end' : 'middle');
      s += '<text class="tick" x="' + lx.toFixed(1) + '" y="' + (h - 6) + '" text-anchor="' + anc + '">' + lv.toFixed(0) + '%</text>';
    }
    return s + '</svg>';
  }

  // -------------------------------------------------------------- aplicación
  function apply(data) {
    var sim = data.sim, adv = data.advice;

    var box = $('verdictBox');
    box.className = 'verdict ' + (CLASS[sim.verdict] || 'v-reject');
    $('verdictTitle').textContent = sim.verdict_label;
    $('verdictNote').textContent = sim.sells_below_cost
      ? 'El precio promocional ($' + sim.promo_price.toFixed(2) + ') queda debajo del costo unitario ($' + sim.unit_cost.toFixed(2) + ').'
      : (sim.required_uplift_pct !== null
          ? 'Necesita ' + pct(sim.required_uplift_pct / 100) + ' de uplift y el modelo proyecta ' + pct(sim.expected_uplift_pct / 100) + '.'
          : '');
    var big = $('marginBig');
    var next = money(sim.incremental_margin);
    if (big.textContent !== next) { big.textContent = next; flash(big); }

    var peak = Math.max(Math.abs(sim.volume_gain), Math.abs(sim.discount_cost), Math.abs(sim.incremental_margin), 1);
    bar('gain', sim.volume_gain, peak, money(sim.volume_gain));
    bar('cost', sim.discount_cost, peak, '−' + money(sim.discount_cost));
    bar('net', sim.incremental_margin, peak, money(sim.incremental_margin));
    var netBar = document.querySelector('[data-bar="net"]');
    if (netBar) netBar.className = 'bar ' + (sim.incremental_margin < 0 ? 'bar-net-neg' : 'bar-net-pos');
    var netVal = document.querySelector('[data-b="net"]');
    if (netVal) netVal.className = 'bar-val n ' + (sim.incremental_margin < 0 ? 'neg' : 'pos');

    set('required_uplift_pct', sim.required_uplift_pct === null ? '—' : pct(sim.required_uplift_pct / 100));
    set('expected_uplift_pct', pct(sim.expected_uplift_pct / 100));
    set('coverage', sim.coverage.toFixed(2));
    set('max_viable_discount', pct(sim.max_viable_discount));
    set('promo_units', num(sim.promo_units));
    set('incremental_units', num(sim.incremental_units));

    $('curveChart').innerHTML = chart(
      data.curve.map(function (c) { return [c.discount * 100, c.incremental_margin]; }),
      { vline: cfg.breakeven * 100, marker: sim.discount * 100 }
    );

    $('adviceHeadline').textContent = adv.headline;
    $('adviceSource').textContent = adv.source;
    list('adviceBullets', adv.bullets);
    list('adviceActions', adv.actions);
    $('modelHint').textContent = 'modelo: ' + pct(sim.model_uplift_pct / 100);

    $('saveD').value = dRange.value;
    $('saveW').value = wRange.value;
  }

  function bar(key, value, peak, label) {
    var b = document.querySelector('[data-bar="' + key + '"]');
    var t = document.querySelector('[data-b="' + key + '"]');
    if (b) b.style.height = (Math.abs(value) / peak * 100).toFixed(0) + '%';
    if (t) t.textContent = label;
  }
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* Marca el elemento sólo si su contenido cambió: destellar lo que no cambia
     es ruido. */
  function flash(el) {
    if (!el || reduced) return;
    el.classList.remove('is-fresh');
    void el.offsetWidth;            // reinicia la animación
    el.classList.add('is-fresh');
  }

  function set(field, value) {
    var el = document.querySelector('[data-f="' + field + '"]');
    if (!el) return;
    if (el.textContent !== value) { el.textContent = value; flash(el); }
  }
  function list(id, items) {
    var ul = $(id);
    if (!ul) return;
    ul.textContent = '';
    (items || []).forEach(function (t) {
      var li = document.createElement('li');
      li.textContent = t;
      ul.appendChild(li);
    });
  }

  // --------------------------------------------------------------- petición
  function refresh() {
    var box = $('results');
    box.classList.add('loading');
    var q = 'r=api/simulate&sku=' + cfg.sku + '&d=' + dRange.value + '&w=' + wRange.value +
            (manualUplift ? '&u=' + uRange.value : '');
    fetch('?' + q, { headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.error) return;
        if (!manualUplift) uRange.value = Math.round(data.sim.expected_uplift_pct);
        paint();
        apply(data);
      })
      .catch(function () { /* si falla la red se conserva el último estado válido */ })
      .then(function () { box.classList.remove('loading'); });
  }

  function schedule() {
    paint();
    clearTimeout(timer);
    timer = setTimeout(refresh, 130);
  }

  // Con JS el recálculo es en vivo, así que el envío del formulario sobra.
  var form = document.getElementById('controls');
  if (form) form.addEventListener('submit', function (ev) { ev.preventDefault(); });

  dRange.addEventListener('input', schedule);
  wRange.addEventListener('input', schedule);
  uRange.addEventListener('input', function () { manualUplift = true; schedule(); });
  $('resetModel').addEventListener('click', function () { manualUplift = false; refresh(); });
  skuSel.addEventListener('change', function () {
    window.location.href = '?r=simulator&sku=' + this.value + '&d=' + dRange.value + '&w=' + wRange.value;
  });

  refresh();
})();
