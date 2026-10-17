(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var store = {
    get: function (k, d) { try { var v = localStorage.getItem(k); return v ? JSON.parse(v) : d; } catch (e) { return d; } },
    set: function (k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
  };

  // ---- theme
  var tbtn = $('.theme-toggle');
  if (tbtn) tbtn.addEventListener('click', function () {
    var root = document.documentElement;
    var dark = root.dataset.theme ? root.dataset.theme === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
    root.dataset.theme = dark ? 'light' : 'dark';
    try { localStorage.setItem('theme', root.dataset.theme); } catch (e) {}
    if (window.__redrawCharts) window.__redrawCharts();
  });

  // ---- mobile nav
  var nt = $('.nav-toggle'), nav = $('.nav');
  if (nt) nt.addEventListener('click', function () { var o = nav.classList.toggle('open'); nt.setAttribute('aria-expanded', o); });

  // ---- search autocomplete
  $$('.hdr .search').forEach(function (form) {
    var input = $('input', form), pop = $('.search-pop', form), timer, idx = -1;
    function close() { pop.hidden = true; idx = -1; }
    input.addEventListener('input', function () {
      clearTimeout(timer);
      var q = input.value.trim();
      if (!q) return close();
      timer = setTimeout(function () {
        fetch('/api/search?q=' + encodeURIComponent(q)).then(function (r) { return r.json(); }).then(function (list) {
          if (!list.length) return close();
          pop.innerHTML = list.map(function (c) {
            var img = c.image ? '<img src="' + c.image + '" width="20" height="20" alt="" style="border-radius:50%">' : '';
            return '<a href="' + c.url + '">' + img + '<b>' + esc(c.name) + '</b> <span class="muted">' + esc(c.symbol) + '</span><span class="r">' + (c.rank ? '#' + c.rank : '') + '</span></a>';
          }).join('');
          pop.hidden = false;
        });
      }, 150);
    });
    input.addEventListener('keydown', function (e) {
      var items = $$('a', pop);
      if (pop.hidden || !items.length) return;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        idx = (idx + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
        items.forEach(function (a, i) { a.classList.toggle('act', i === idx); });
      } else if (e.key === 'Enter' && idx >= 0) { e.preventDefault(); location.href = items[idx].href; }
      else if (e.key === 'Escape') close();
    });
    document.addEventListener('click', function (e) { if (!form.contains(e.target)) close(); });
  });
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  // ---- account state (cache-safe: the HTML is identical for everyone; state arrives via /api/me)
  var auth = { csrf: null };
  function post(url, data) {
    return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': auth.csrf || '' }, body: JSON.stringify(data) }).catch(function () {});
  }
  function hasSession() { return document.cookie.indexOf('cmcsid=') >= 0; }

  // ---- watchlist
  var wl = store.get('watchlist', []);
  function paintStars() { $$('.star').forEach(function (b) { var on = wl.indexOf(b.dataset.coin) >= 0; b.classList.toggle('on', on); b.textContent = on ? '★' : '☆'; }); }
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('.star');
    if (!b) return;
    e.preventDefault();
    var id = b.dataset.coin, i = wl.indexOf(id);
    if (i >= 0) wl.splice(i, 1); else wl.push(id);
    store.set('watchlist', wl);
    paintStars();
    if (auth.csrf) post('/api/watchlist/', { coin: id, on: i < 0 });
  });
  paintStars();
  var wbox = $('#watchlist');
  if (wbox) {
    if (!wl.length) wbox.innerHTML = '<div class="card empty">Your watchlist is empty. <a href="/">Browse cryptocurrencies</a> and tap ☆ to add coins.</div>';
    else fetch('/api/coins?ids=' + encodeURIComponent(wl.join(','))).then(function (r) { return r.json(); }).then(function (d) { wbox.innerHTML = d.html; paintStars(); });
  }

  // ---- converters (two linked inputs, rate = value of A in B)
  $$('[data-rate]').forEach(function (box) {
    var rate = parseFloat(box.dataset.rate), a = $('[data-conv=a]', box), b = $('[data-conv=b]', box);
    if (!a || !b || !rate) return;
    function fmt(v) { if (!isFinite(v)) return ''; var d = v >= 1000 ? 2 : v >= 1 ? 4 : 8; return String(parseFloat(v.toFixed(d))); }
    a.addEventListener('input', function () { b.value = fmt(parseFloat(a.value || 0) * rate); });
    b.addEventListener('input', function () { a.value = fmt(parseFloat(b.value || 0) / rate); });
  });

  // ---- read more
  $$('.expand').forEach(function (btn) {
    var box = btn.previousElementSibling;
    if (box && box.scrollHeight <= box.clientHeight + 10) { btn.remove(); box.classList.add('open'); return; }
    btn.addEventListener('click', function () { var o = box.classList.toggle('open'); btn.textContent = o ? 'Show less' : 'Read more'; });
  });

  // ---- charts (TradingView lightweight-charts, loaded only on chart pages)
  function css(v) { return getComputedStyle(document.documentElement).getPropertyValue(v).trim(); }
  function fmtPrice(v) {
    var a = Math.abs(v), d = a >= 1000 ? 2 : a >= 1 ? 4 : a >= 0.01 ? 5 : Math.min(12, Math.ceil(-Math.log10(a || 1e-12)) + 3);
    return '$' + v.toLocaleString('en-US', { minimumFractionDigits: Math.min(d, 2), maximumFractionDigits: d });
  }
  function fmtBig(v) {
    var u = [[1e12, 'T'], [1e9, 'B'], [1e6, 'M']];
    for (var i = 0; i < u.length; i++) if (Math.abs(v) >= u[i][0]) return '$' + (v / u[i][0]).toFixed(2) + u[i][1];
    return '$' + v.toFixed(2);
  }
  var charts = [];
  function makeChart(el, formatter, intraday) {
    var LW = window.LightweightCharts;
    var chart = LW.createChart(el, {
      autoSize: true,
      layout: { background: { color: 'transparent' }, textColor: css('--muted'), fontFamily: css('--font') },
      grid: { vertLines: { visible: false }, horzLines: { color: css('--line') } },
      rightPriceScale: { borderVisible: false },
      timeScale: { borderVisible: false, timeVisible: intraday, secondsVisible: false },
      crosshair: { mode: 1 },
      localization: { priceFormatter: formatter },
      handleScroll: false, handleScale: false
    });
    var series = chart.addAreaSeries({ lineWidth: 2, priceLineVisible: false, lastValueVisible: true });
    var tip = document.createElement('div'); tip.className = 'chart-tip'; el.appendChild(tip);
    chart.subscribeCrosshairMove(function (p) {
      if (!p || !p.time || !p.seriesData) { tip.innerHTML = ''; return; }
      var d = p.seriesData.get(series); if (!d) return;
      tip.innerHTML = '<b>' + formatter(d.value) + '</b> &nbsp;' + new Date(p.time * 1000).toLocaleString('en-US', { dateStyle: 'medium', timeStyle: intraday ? 'short' : undefined });
    });
    charts.push({ chart: chart, series: series });
    return { chart: chart, series: series };
  }
  function color(series, data) {
    var up = data.length < 2 || data[data.length - 1].value >= data[0].value, c = up ? css('--up') : css('--down');
    series.applyOptions({ lineColor: c, topColor: c + '40', bottomColor: c + '00' });
  }
  function toData(pts, idx) {
    var seen = {}, out = [];
    pts.forEach(function (p) { if (!seen[p[0]] && p[idx] != null) { seen[p[0]] = 1; out.push({ time: p[0], value: p[idx] }); } });
    return out.sort(function (a, b) { return a.time - b.time; });
  }
  function whenLW(cb, n) { n = n || 0; if (window.LightweightCharts) cb(); else if (n < 100) setTimeout(function () { whenLW(cb, n + 1); }, 60); }

  var coinEl = $('#chart');
  if (coinEl) whenLW(function () {
    coinEl.innerHTML = '';
    var cur = '7d', c = makeChart(coinEl, fmtPrice, true);
    function load(range) {
      cur = range;
      fetch('/api/chart?coin=' + encodeURIComponent(coinEl.dataset.coin) + '&range=' + range).then(function (r) { return r.json(); }).then(function (d) {
        var data = toData(d.prices || [], 1);
        c.chart.applyOptions({ timeScale: { timeVisible: ['1d', '7d', '1m'].indexOf(range) >= 0 } });
        c.series.setData(data); color(c.series, data); c.chart.timeScale().fitContent();
      });
    }
    $$('.seg button').forEach(function (b) {
      b.addEventListener('click', function () { $$('.seg button').forEach(function (x) { x.classList.remove('on'); }); b.classList.add('on'); load(b.dataset.range); });
    });
    load(cur);
  });

  var gEls = $$('#gchart,#dchart');
  if (gEls.length) whenLW(function () {
    fetch('/api/global-chart').then(function (r) { return r.json(); }).then(function (d) {
      gEls.forEach(function (el) {
        var idx = +el.dataset.series, isDom = idx === 3;
        var c = makeChart(el, isDom ? function (v) { return v.toFixed(2) + '%'; } : fmtBig, false);
        var data = toData(d.points || [], idx);
        c.series.setData(data); color(c.series, data); c.chart.timeScale().fitContent();
      });
    });
  });

  window.__redrawCharts = function () {
    charts.forEach(function (c) {
      c.chart.applyOptions({ layout: { textColor: css('--muted') }, grid: { horzLines: { color: css('--line') } } });
    });
  };

  // ---- logged-in UI + server-synced watchlist (only when a session cookie exists)
  if (hasSession()) {
    fetch('/api/me/', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (m) {
      if (!m.loggedIn) return;
      auth.csrf = m.csrf;
      $$('[data-auth=guest]').forEach(function (n) { n.hidden = true; });
      $$('[data-auth=user]').forEach(function (n) { n.hidden = false; });
      var changed = false;
      m.watchlist.forEach(function (s) { if (wl.indexOf(s) < 0) { wl.push(s); changed = true; } });
      wl.forEach(function (s) { if (m.watchlist.indexOf(s) < 0) post('/api/watchlist/', { coin: s, on: true }); });
      store.set('watchlist', wl); paintStars();
      if (changed && wbox) location.reload();
    }).catch(function () {});
  }

  // ---- ads: slots are empty containers in cached HTML; filled here so impressions are counted per view
  $$('.ad-slot').forEach(function (el) {
    fetch('/api/ad/?slot=' + encodeURIComponent(el.dataset.slot), { credentials: 'omit' }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.html) { el.remove(); return; }
      el.innerHTML = d.html;
      $$('script', el).forEach(function (o) { var n = document.createElement('script'); for (var i = 0; i < o.attributes.length; i++) n.setAttribute(o.attributes[i].name, o.attributes[i].value); n.text = o.text; o.replaceWith(n); });
    }).catch(function () { el.remove(); });
  });

  // ---- Google Analytics, only after consent
  var gaMeta = document.querySelector('meta[name=cmc-ga]');
  if (gaMeta) {
    var banner = $('#cookie-banner'), consent = store.get('ga_consent', null);
    var loadGA = function () {
      var id = gaMeta.content; window.dataLayer = window.dataLayer || [];
      window.gtag = function () { dataLayer.push(arguments); }; gtag('js', new Date()); gtag('config', id, { anonymize_ip: true });
      var sc = document.createElement('script'); sc.async = true; sc.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(id); document.head.appendChild(sc);
    };
    if (consent === 'yes') loadGA(); else if (consent === null && banner) banner.hidden = false;
    if (banner) banner.addEventListener('click', function (e) {
      var b = e.target.closest('[data-cookie]'); if (!b) return;
      store.set('ga_consent', b.dataset.cookie); banner.hidden = true; if (b.dataset.cookie === 'yes') loadGA();
    });
  }

  // ---- Core Web Vitals field data (tiny, sampled, no libraries)
  try {
    if ('PerformanceObserver' in window && Math.random() < 0.25) {
      var vit = {}, sent = false;
      var po = function (type, cb) { try { new PerformanceObserver(function (l) { l.getEntries().forEach(cb); }).observe({ type: type, buffered: true }); } catch (e) {} };
      po('largest-contentful-paint', function (en) { vit.LCP = en.startTime; });
      po('layout-shift', function (en) { if (!en.hadRecentInput) vit.CLS = (vit.CLS || 0) + en.value; });
      po('event', function (en) { if (en.interactionId) vit.INP = Math.max(vit.INP || 0, en.duration); });
      var nav = performance.getEntriesByType('navigation')[0]; if (nav) vit.TTFB = nav.responseStart;
      var flush = function () {
        if (sent) return; sent = true;
        Object.keys(vit).forEach(function (k) { navigator.sendBeacon('/api/vitals/', JSON.stringify({ m: k, v: Math.round(vit[k] * 1000) / 1000, p: location.pathname })); });
      };
      document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') flush(); });
      addEventListener('pagehide', flush);
    }
  } catch (e) {}
})();
