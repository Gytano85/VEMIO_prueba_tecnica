/* ==========================================================================
   PromoGuard — simulador en vivo
   Sin dependencias: fetch + DOM. Recalcula contra el endpoint PHP con debounce.
   ========================================================================== */
(function () {
  'use strict';

  var cfg = window.PG_SIM;
  if (!cfg) return;

  var $ = function (id) { return document.getElementById(id); };
  var dRange = $('dRange'), wRange = $('wRange'), uRange = $('uRange'), skuSelect = $('skuSelect');
  if (!dRange) return;

  var userTouchedUplift = false;
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

  // -------------------------------------------------------------- semáforo
  var VERDICT_CLASS = {
    approve: 'v-approve', review: 'v-review', reject: 'v-reject', blocked: 'v-blocked'
  };
  var VERDICT_ICON = {
    approve: '<path d="M20 6L9 17l-5-5"/>',
    review:  '<path d="M12 9v4M12 17h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L14.7 3.9a2 2 0 00-3.4 0z"/>',
    reject:  '<path d="M18 6L6 18M6 6l12 12"/>',
    blocked: '<circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/>'
  };
  function coverageColor(cov, below) {
    if (below) return 'var(--block)';
    if (cov >= 1) return 'var(--good)';
    if (cov >= 0.75) return 'var(--warn)';
    return 'var(--bad)';
  }

  // ------------------------------------------------------- etiquetas locales
  function paintLabels() {
    var d = parseFloat(dRange.value) / 100;
    $('dLabel').textContent = pct(d);
    $('wLabel').textContent = wRange.value + ' sem';
    $('uLabel').textContent = pct(parseFloat(uRange.value) / 100);
    $('curveWeeks').textContent = wRange.value;
    var marker = $('beMarker');
    if (marker) marker.style.left = (Math.min(d, cfg.scaleMax) / cfg.scaleMax * 100).toFixed(1) + '%';
  }

  // ------------------------------------------------------------ render SVG
  function lineChart(points, opts) {
    var w = 900, h = 210, pad = { t: 14, r: 14, b: 26, l: 52 };
    if (!points.length) return '';
    var xs = points.map(function (p) { return p[0]; });
    var ys = points.map(function (p) { return p[1]; });
    var minX = Math.min.apply(null, xs), maxX = Math.max.apply(null, xs);
    var minY = Math.min(0, Math.min.apply(null, ys));
    var maxY = Math.max.apply(null, ys);
    if (maxY - minY < 1e-9) maxY = minY + 1;
    maxY += (maxY - minY) * 0.08;

    var iw = w - pad.l - pad.r, ih = h - pad.t - pad.b;
    var sx = function (x) { return pad.l + (maxX - minX ? (x - minX) / (maxX - minX) : 0.5) * iw; };
    var sy = function (y) { return pad.t + ih - ((y - minY) / (maxY - minY)) * ih; };

    var svg = '<svg class="chart" viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" role="img">';
    for (var i = 0; i <= 4; i++) {
      var gy = pad.t + ih * i / 4;
      var val = maxY - (maxY - minY) * i / 4;
      svg += '<line class="grid-line" x1="' + pad.l + '" y1="' + gy.toFixed(1) + '" x2="' + (w - pad.r) + '" y2="' + gy.toFixed(1) + '"/>';
      svg += '<text class="axis-text" x="' + (pad.l - 8) + '" y="' + (gy + 3).toFixed(1) + '" text-anchor="end">' + shortNum(val) + '</text>';
    }

    // línea del cero
    if (minY < 0) {
      svg += '<line x1="' + pad.l + '" y1="' + sy(0).toFixed(1) + '" x2="' + (w - pad.r) + '" y2="' + sy(0).toFixed(1) +
             '" stroke="var(--text-faint)" stroke-width="1" stroke-dasharray="3 3" opacity=".55"/>';
    }

    var d = '';
    points.forEach(function (p, j) { d += (j === 0 ? 'M' : 'L') + sx(p[0]).toFixed(1) + ' ' + sy(p[1]).toFixed(1) + ' '; });
    var last = points[points.length - 1];
    svg += '<path d="' + d + 'L' + sx(last[0]).toFixed(1) + ' ' + sy(minY).toFixed(1) + ' L' + sx(points[0][0]).toFixed(1) + ' ' + sy(minY).toFixed(1) + ' Z" fill="var(--accent)" opacity=".09"/>';
    svg += '<path d="' + d.trim() + '" fill="none" stroke="var(--accent)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>';

    // marca del punto de equilibrio
    if (opts && opts.breakeven && opts.breakeven * 100 <= maxX) {
      var bx = sx(opts.breakeven * 100);
      svg += '<line x1="' + bx.toFixed(1) + '" y1="' + pad.t + '" x2="' + bx.toFixed(1) + '" y2="' + (pad.t + ih) +
             '" stroke="var(--block)" stroke-width="1.5" stroke-dasharray="4 3"/>';
    }
    // punto actual
    if (opts && typeof opts.current === 'number') {
      var closest = points.reduce(function (a, b) {
        return Math.abs(b[0] - opts.current * 100) < Math.abs(a[0] - opts.current * 100) ? b : a;
      });
      svg += '<circle cx="' + sx(closest[0]).toFixed(1) + '" cy="' + sy(closest[1]).toFixed(1) +
             '" r="4.5" fill="var(--accent)" stroke="var(--bg-elev)" stroke-width="2.5"/>';
    }

    var n = 5;
    for (var k = 0; k < n; k++) {
      var lx = pad.l + (k / (n - 1)) * iw;
      var lv = minX + (maxX - minX) * k / (n - 1);
      var anchor = k === 0 ? 'start' : (k === n - 1 ? 'end' : 'middle');
      svg += '<text class="axis-text" x="' + lx.toFixed(1) + '" y="' + (h - 8) + '" text-anchor="' + anchor + '">' + lv.toFixed(0) + '%</text>';
    }
    return svg + '</svg>';
  }

  function shortNum(v) {
    var a = Math.abs(v), s = v < 0 ? '−' : '';
    if (a >= 1e6) return s + (a / 1e6).toFixed(1) + 'M';
    if (a >= 1e3) return s + (a / 1e3).toFixed(1) + 'k';
    return s + Math.round(a);
  }

  // ------------------------------------------------------------- aplicar
  function apply(data) {
    var sim = data.sim, advice = data.advice;

    // semáforo
    var box = $('verdictBox');
    box.className = 'verdict ' + (VERDICT_CLASS[sim.verdict] || 'v-reject') + ' mb14';
    $('verdictLight').innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">' +
      (VERDICT_ICON[sim.verdict] || VERDICT_ICON.reject) + '</svg>';
    $('verdictTitle').textContent = sim.verdict_label;
    $('verdictNote').textContent = sim.sells_below_cost
      ? 'El precio promocional ($' + sim.promo_price.toFixed(2) + ') queda por debajo del costo unitario ($' + sim.unit_cost.toFixed(2) + ').'
      : (sim.required_uplift_pct !== null
          ? 'Necesita ' + pct(sim.required_uplift_pct / 100) + ' de uplift · proyectado ' + pct(sim.expected_uplift_pct / 100) + ' · cobertura ' + sim.coverage.toFixed(2)
          : '');

    var mb = $('marginBig');
    mb.textContent = money(sim.incremental_margin);
    mb.style.color = sim.incremental_margin < 0 ? 'var(--bad)' : 'var(--good)';

    // waterfall
    var peak = Math.max(Math.abs(sim.volume_gain), Math.abs(sim.discount_cost), Math.abs(sim.incremental_margin), 1);
    setWf('gain', sim.volume_gain, peak, money(sim.volume_gain));
    setWf('cost', sim.discount_cost, peak, '−' + money(sim.discount_cost));
    setWf('net', sim.incremental_margin, peak, money(sim.incremental_margin));
    var netBar = document.querySelector('[data-wf-bar="net"]');
    netBar.className = 'wf-bar ' + (sim.incremental_margin < 0 ? 'wf-net-n' : 'wf-net-p');
    document.querySelector('[data-wf="net"]').style.color = sim.incremental_margin < 0 ? 'var(--block)' : 'var(--good)';

    // métricas
    setText('required_uplift_pct', sim.required_uplift_pct === null ? 'inalcanzable' : pct(sim.required_uplift_pct / 100));
    setText('expected_uplift_pct', pct(sim.expected_uplift_pct / 100));
    setText('coverage', sim.coverage.toFixed(2));
    setText('max_viable_discount', pct(sim.max_viable_discount));
    setText('promo_units', num(sim.promo_units));
    setText('incremental_units', num(sim.incremental_units));

    var cov = $('covBar');
    cov.style.width = (Math.min(1, sim.coverage) * 100).toFixed(0) + '%';
    cov.style.background = coverageColor(sim.coverage, sim.sells_below_cost);

    // curva
    $('curveChart').innerHTML = lineChart(
      data.curve.map(function (c) { return [c.discount * 100, c.incremental_margin]; }),
      { breakeven: cfg.breakeven, current: sim.discount }
    );

    // asesor
    $('adviceHeadline').textContent = advice.headline;
    $('adviceSource').textContent = advice.source;
    fillList('adviceBullets', advice.bullets);
    fillList('adviceActions', advice.actions);

    // hint del modelo
    $('modelHint').textContent = 'modelo: ' + pct(sim.model_uplift_pct / 100);

    // sincronizar el formulario de guardado
    $('saveD').value = dRange.value;
    $('saveW').value = wRange.value;
  }

  function setWf(key, value, peak, label) {
    var bar = document.querySelector('[data-wf-bar="' + key + '"]');
    var txt = document.querySelector('[data-wf="' + key + '"]');
    if (bar) bar.style.height = (Math.abs(value) / peak * 100).toFixed(0) + '%';
    if (txt) txt.textContent = label;
  }
  function setText(field, value) {
    var el = document.querySelector('[data-f="' + field + '"]');
    if (el) el.textContent = value;
  }
  function fillList(id, items) {
    var ul = $(id);
    if (!ul) return;
    ul.innerHTML = '';
    (items || []).forEach(function (t) {
      var li = document.createElement('li');
      li.textContent = t;
      ul.appendChild(li);
    });
  }

  // ------------------------------------------------------------- petición
  function refresh() {
    var results = $('results');
    results.classList.add('is-loading');
    var params = 'r=api/simulate&sku=' + cfg.skuCode + '&d=' + dRange.value + '&w=' + wRange.value +
                 (userTouchedUplift ? '&u=' + uRange.value : '');
    fetch('?' + params, { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.error) {
          if (!userTouchedUplift) uRange.value = Math.round(data.sim.expected_uplift_pct);
          paintLabels();
          apply(data);
        }
      })
      .catch(function () { /* si falla la red, la vista mantiene el último estado válido */ })
      .then(function () { results.classList.remove('is-loading'); });
  }

  function schedule() {
    paintLabels();
    clearTimeout(timer);
    timer = setTimeout(refresh, 130);
  }

  dRange.addEventListener('input', schedule);
  wRange.addEventListener('input', schedule);
  uRange.addEventListener('input', function () { userTouchedUplift = true; schedule(); });
  $('resetModel').addEventListener('click', function () { userTouchedUplift = false; refresh(); });
  skuSelect.addEventListener('change', function () {
    window.location.href = '?r=simulator&sku=' + this.value + '&d=' + dRange.value + '&w=' + wRange.value;
  });

  // primer render de la curva con el punto actual marcado
  refresh();
})();
