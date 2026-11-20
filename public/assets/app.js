// Drobne udogodnienia bez bibliotek. Strona działa także bez JavaScriptu.
(function () {
  'use strict';

  // Potwierdzenie przed operacjami nieodwracalnymi: <form data-confirm="…"> albo przycisk z data-confirm.
  document.addEventListener('submit', function (e) {
    var form = e.target;
    var sub = e.submitter || null;
    var msg = (sub && sub.getAttribute('data-confirm')) || form.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) {
      e.preventDefault();
      return;
    }
    // Blokada podwójnego wysłania i informacja o dłuższej operacji (AI, rejestry). Dotyczy tylko klikniętego przycisku.
    var btn = sub && sub.hasAttribute('data-busy') ? sub : null;
    if (!btn && !sub) {
      var all = form.querySelectorAll('button[type=submit]');
      if (all.length === 1 && all[0].hasAttribute('data-busy')) btn = all[0];
    }
    if (btn) {
      // Przeglądarka nie wysyła nazwy i wartości wyłączonego przycisku, więc przenosimy je do ukrytego pola.
      if (btn.name) {
        var keep = document.createElement('input');
        keep.type = 'hidden';
        keep.name = btn.name;
        keep.value = btn.value;
        form.appendChild(keep);
      }
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

  // Audyt treści wielu stron: kolejka audytów z listy wykonuje się po kolei, krok po kroku.
  var queueBox = document.querySelector('[data-audit-queue]');
  if (queueBox && window.fetch) {
    var ids = [];
    try { ids = JSON.parse(queueBox.getAttribute('data-audit-queue')) || []; } catch (e) { ids = []; }
    var note = queueBox.querySelector('[data-audit-queue-state]');
    var token = queueBox.getAttribute('data-csrf');
    var at = 0;
    var fails = 0;
    var stepQueue = function () {
      if (at >= ids.length) { window.location.reload(); return; }
      if (note) note.textContent = 'Sprawdzam stronę ' + (at + 1) + ' z ' + ids.length + '. Zostaw tę kartę otwartą.';
      fetch('/audyt/tresc/audyt/' + ids[at] + '/krok', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': token },
        body: '_csrf=' + encodeURIComponent(token)
      }).then(function (r) { return r.json(); }).then(function (j) {
        fails = 0;
        if (j.done) at++;
        stepQueue();
      }).catch(function () {
        if (++fails <= 2) { window.setTimeout(stepQueue, 2000); return; }
        if (note) note.textContent = 'Połączenie z serwerem zostało przerwane. Odśwież stronę, żeby wznowić kolejkę.';
      });
    };
    stepQueue();
  }
})();
