// Drobne udogodnienia bez bibliotek. Strona działa także bez JavaScriptu.
(function () {
  'use strict';

  // Potwierdzenie przed operacjami nieodwracalnymi: <form data-confirm="…">.
  document.addEventListener('submit', function (e) {
    var form = e.target;
    var msg = form.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) {
      e.preventDefault();
      return;
    }
    // Blokada podwójnego wysłania i informacja o dłuższej operacji (AI, rejestry).
    var btn = form.querySelector('button[type=submit][data-busy]');
    if (btn) {
      btn.disabled = true;
      btn.textContent = btn.getAttribute('data-busy');
    }
  });

  // Przycisk „Potwierdź” aktywny dopiero po zaznaczeniu oświadczenia o ryzyku.
  document.querySelectorAll('[data-ack-for]').forEach(function (box) {
    var target = document.getElementById(box.getAttribute('data-ack-for'));
    if (!target) return;
    var sync = function () { target.disabled = !box.checked; };
    box.addEventListener('change', sync);
    sync();
  });

  // Wybór rodzaju wiadomości przeładowuje szkic (formularz GET).
  document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () { el.form.submit(); });
  });

  // Drukowanie (inline onclick blokuje polityka CSP).
  document.querySelectorAll('[data-print]').forEach(function (b) {
    b.addEventListener('click', function () { window.print(); });
  });

  // Licznik zdań bez źródła w edytorze sekcji oferty.
  document.querySelectorAll('textarea[data-markers]').forEach(function (ta) {
    var out = document.getElementById(ta.getAttribute('data-markers'));
    if (!out) return;
    var count = function () {
      var n = (ta.value.match(/⟦sprawdź⟧/g) || []).length;
      out.textContent = n === 0 ? 'Każde zdanie ma źródło' : n + (n === 1 ? ' zdanie' : (n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14) ? ' zdania' : ' zdań')) + ' bez źródła';
      out.className = 'chip ' + (n === 0 ? 'low' : 'high');
    };
    ta.addEventListener('input', count);
    count();
  });

  // Audyt treści: wykonuje kolejne kroki, aż audyt się skończy, i pokazuje postęp. Bez JavaScript działa przycisk „Dokończ audyt teraz”.
  var run = document.querySelector('[data-audit-run]');
  if (run && window.fetch) {
    var fallback = run.querySelector('[data-audit-fallback]');
    var errorBox = run.querySelector('[data-audit-error]');
    var words = { pending: 'czeka', done: 'gotowe', failed: 'bez pomiaru', running: 'w toku' };
    var paint = function (steps) {
      var marked = false;
      steps.forEach(function (s) {
        var li = run.querySelector('[data-step="' + s.key + '"]');
        if (!li) return;
        var state = s.state;
        if (state === 'pending' && !marked) { state = 'running'; marked = true; }
        li.className = state;
        li.querySelector('.ca-step-state').textContent = words[state] || state;
      });
    };
    var current = [].map.call(run.querySelectorAll('[data-step]'), function (li) { return { key: li.getAttribute('data-step'), state: li.className }; });
    paint(current);
    if (fallback) fallback.hidden = true;
    var tries = 0;
    var next = function () {
      fetch(run.getAttribute('data-audit-run'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': run.getAttribute('data-csrf') },
        body: '_csrf=' + encodeURIComponent(run.getAttribute('data-csrf'))
      }).then(function (r) { return r.json(); }).then(function (j) {
        tries = 0;
        if (j.steps) paint(j.steps);
        if (j.done) { window.location.reload(); return; }
        next();
      }).catch(function () {
        if (++tries <= 2) { window.setTimeout(next, 2000); return; }
        if (errorBox) { errorBox.hidden = false; errorBox.textContent = 'Połączenie z serwerem zostało przerwane. Audyt nie przepadł: kliknij przycisk, żeby go dokończyć.'; }
        if (fallback) fallback.hidden = false;
      });
    };
    next();
  }
})();
