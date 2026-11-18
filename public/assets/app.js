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
})();
