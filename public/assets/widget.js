/* Widżet zapytań. Serwer podaje ten plik pod adresem /w/<identyfikator>.js i wstawia ustawienia firmy w ostatnim wierszu.
   Bez bibliotek, bez plików cookie i bez pamięci przeglądarki. Cały wygląd siedzi w Shadow DOM, więc style strony
   nie psują widżetu, a widżet nie psuje strony. Punkty i progi oceny nie trafiają do przeglądarki: liczy je serwer. */
(function (CFG) {
  'use strict';
  if (!CFG || window.__pdWidget || !window.fetch || !window.Promise || !Element.prototype.attachShadow) return;
  window.__pdWidget = true;

  var CSS = [
    ':host{all:initial}',
    '*{box-sizing:border-box}',
    '[hidden]{display:none!important}',
    '.root{--bg:#fff;--raised:#f5f6f8;--line:#d9dee7;--text:#0f172a;--muted:#475467;--accent:#2347c5;--accent-hi:#1a3596;--on-accent:#fff;--bad:#b42318;--good:#067647;--field:#fff;',
    'font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;color:var(--text);-webkit-text-size-adjust:100%}',
    '.root.dark{--bg:#0f1524;--raised:#151c2e;--line:#232d45;--text:#f2f3f7;--muted:#9aa3b8;--accent:#d4a94a;--accent-hi:#e8c97a;--on-accent:#14100a;--bad:#f87171;--good:#4ade80;--field:#0a0e1a;color-scheme:dark}',
    'button,input,textarea{font:inherit;color:inherit}',
    '.launch{position:fixed;right:20px;bottom:20px;z-index:2147483000;display:inline-flex;align-items:center;gap:10px;min-height:48px;padding:0 20px;border:0;border-radius:999px;background:var(--accent);color:var(--on-accent);font-weight:600;cursor:pointer;box-shadow:0 8px 28px rgba(0,0,0,.28)}',
    '.dark .launch{background:linear-gradient(135deg,#e8c97a,#d4a94a 55%,#a87f2c)}',
    '.launch:hover{filter:brightness(1.07)}',
    '.launch svg{width:18px;height:18px;flex:none}',
    '.panel{background:var(--bg);border:1px solid var(--line);border-radius:14px;display:flex;flex-direction:column;overflow:hidden}',
    '.float .panel{position:fixed;right:20px;bottom:20px;z-index:2147483001;width:384px;max-width:calc(100vw - 16px);max-height:min(680px,calc(100vh - 40px));box-shadow:0 18px 60px rgba(0,0,0,.4)}',
    '.inline .panel{max-width:480px}',
    '.head{display:flex;align-items:center;gap:12px;padding:14px 16px;border-bottom:1px solid var(--line)}',
    '.head b{flex:1;min-width:0;font-size:15px;font-weight:600;overflow-wrap:anywhere}',
    '.x{flex:none;width:36px;height:36px;border:1px solid var(--line);border-radius:9px;background:transparent;cursor:pointer;display:grid;place-items:center}',
    '.x:hover{border-color:var(--accent)}',
    '.body{padding:16px;overflow-y:auto;overscroll-behavior:contain}',
    '.step{font-size:13px;color:var(--muted);margin:0 0 6px}',
    'h2{font-size:18px;line-height:1.3;font-weight:650;letter-spacing:-.01em;margin:0 0 12px;overflow-wrap:anywhere}',
    'h2:focus{outline:none}',
    'p{margin:0 0 12px}',
    '.muted{color:var(--muted)}',
    '.small{font-size:13px}',
    '.opts{display:flex;flex-direction:column;gap:8px;margin:0;padding:0;list-style:none}',
    '.opt{width:100%;text-align:left;min-height:46px;padding:10px 14px;border:1px solid var(--line);border-radius:10px;background:var(--raised);cursor:pointer;overflow-wrap:anywhere}',
    '.opt:hover{border-color:var(--accent)}',
    '.opt[aria-pressed=true]{border-color:var(--accent);box-shadow:inset 0 0 0 1px var(--accent)}',
    '.back{margin-top:14px;padding:6px 0;border:0;background:none;color:var(--muted);cursor:pointer;text-decoration:underline;text-underline-offset:3px;font-size:13px}',
    '.back:hover{color:var(--text)}',
    'form{display:flex;flex-direction:column;gap:12px;margin:0}',
    'label{display:block;font-size:13px;font-weight:600;margin:0 0 4px}',
    'label span{font-weight:400;color:var(--muted)}',
    'input[type=text],input[type=email],input[type=tel],textarea{display:block;width:100%;min-height:44px;padding:10px 12px;border:1px solid var(--line);border-radius:9px;background:var(--field);font-size:16px}',
    'textarea{min-height:76px;resize:vertical}',
    'input[aria-invalid=true]{border-color:var(--bad)}',
    '.hint{font-size:12.5px;color:var(--muted);margin:4px 0 0}',
    '.err{font-size:13px;color:var(--bad);margin:4px 0 0}',
    '.alert{border:1px solid var(--bad);border-radius:9px;padding:10px 12px;font-size:14px;margin:0}',
    '.check{display:flex;gap:10px;align-items:flex-start;font-weight:400;font-size:13px;margin:0;cursor:pointer}',
    '.check input{flex:none;width:18px;height:18px;margin:1px 0 0;accent-color:var(--accent)}',
    '.go{min-height:48px;padding:0 18px;border:0;border-radius:10px;background:var(--accent);color:var(--on-accent);font-weight:600;cursor:pointer}',
    '.dark .go{background:linear-gradient(135deg,#e8c97a,#d4a94a 55%,#a87f2c)}',
    '.go:hover{filter:brightness(1.07)}',
    '.go[disabled]{opacity:.6;cursor:default;filter:none}',
    '.legal{font-size:12px;color:var(--muted);margin:0}',
    'a{color:var(--accent-hi);text-underline-offset:3px}',
    '.light a{color:var(--accent)}',
    '.chat{border-top:1px solid var(--line);padding:12px 16px 14px;background:var(--raised)}',
    '.log{display:flex;flex-direction:column;gap:8px;margin:0 0 10px;max-height:190px;overflow-y:auto}',
    '.msg{max-width:92%;padding:8px 12px;border-radius:12px;font-size:14px;overflow-wrap:anywhere;white-space:pre-wrap}',
    '.msg.user{align-self:flex-end;background:var(--accent);color:var(--on-accent)}',
    '.msg.bot{align-self:flex-start;background:var(--bg);border:1px solid var(--line)}',
    '.ask{display:flex;flex-direction:row;gap:8px}',
    '.ask input{flex:1;min-width:0}',
    '.ask button{flex:none;min-height:44px;padding:0 14px;border:1px solid var(--line);border-radius:9px;background:var(--bg);font-weight:600;cursor:pointer}',
    '.ask button:hover{border-color:var(--accent)}',
    '.ai{font-size:12px;color:var(--muted);margin:8px 0 0}',
    '.hp{position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden}',
    '.done{display:flex;gap:12px;align-items:flex-start}',
    '.done svg{flex:none;width:24px;height:24px;color:var(--good);margin-top:2px}',
    'button:focus-visible,input:focus-visible,textarea:focus-visible,a:focus-visible{outline:2px solid var(--accent);outline-offset:2px}',
    '@media (max-width:480px){.float .panel{left:8px;right:8px;bottom:8px;width:auto;max-width:none;max-height:calc(100vh - 16px);max-height:calc(100dvh - 16px)}.launch{right:12px;bottom:12px}}',
    '@media (prefers-reduced-motion:no-preference){.float .panel.enter{animation:in .16s ease-out}}',
    '@keyframes in{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}'
  ].join('');

  var S = { token: null, variant: 'b', questions: [], answers: [], step: 0, chat: false, texts: {}, screen: 'loading', error: '', open: false, starting: null, pending: [], failed: {}, log: [], asking: false, sending: false, form: {} };
  var inlineHost = document.querySelector('[data-pd-widget]');
  var host = inlineHost || document.createElement('div');
  var mode = inlineHost ? 'inline' : 'float';
  if (!inlineHost) document.body.appendChild(host);
  var shadow = host.attachShadow({ mode: 'open' });
  try {
    var sheet = new CSSStyleSheet();
    sheet.replaceSync(CSS);
    shadow.adoptedStyleSheets = [sheet];
  } catch (e) {
    shadow.appendChild(el('style', null, CSS));
  }
  var root = el('div', { class: 'root ' + (CFG.theme === 'dark' ? 'dark' : 'light') + ' ' + mode });
  shadow.appendChild(root);
  var launcher = null;
  var panel = null;

  /** Element z atrybutami i dziećmi. Teksty trafiają przez textContent, nigdy jako HTML. */
  function el(tag, attrs, kids) {
    var n = document.createElement(tag);
    var k;
    for (k in attrs || {}) {
      if (attrs[k] === false || attrs[k] == null) continue;
      if (k.slice(0, 2) === 'on') n.addEventListener(k.slice(2), attrs[k]);
      else if (k === 'value') n.value = attrs[k];
      else if (k === 'checked') n.checked = !!attrs[k];
      else n.setAttribute(k, attrs[k] === true ? '' : attrs[k]);
    }
    [].concat(kids == null ? [] : kids).forEach(function (c) {
      if (c == null || c === false) return;
      n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return n;
  }

  function icon(d) {
    var ns = 'http://www.w3.org/2000/svg';
    var s = document.createElementNS(ns, 'svg');
    s.setAttribute('viewBox', '0 0 24 24');
    s.setAttribute('fill', 'none');
    s.setAttribute('stroke', 'currentColor');
    s.setAttribute('stroke-width', '2');
    s.setAttribute('stroke-linecap', 'round');
    s.setAttribute('stroke-linejoin', 'round');
    s.setAttribute('aria-hidden', 'true');
    var p = document.createElementNS(ns, 'path');
    p.setAttribute('d', d);
    s.appendChild(p);
    return s;
  }

  /** Zapytanie do panelu. Treść idzie jako text/plain, więc przeglądarka nie wysyła zapytania wstępnego. */
  function api(action, data) {
    return fetch(CFG.base + '/w/' + CFG.id + '/' + action, {
      method: 'POST',
      mode: 'cors',
      credentials: 'omit',
      headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
      body: JSON.stringify(data || {})
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        j.status = r.status;
        if (!j.ok && !j.error) j.error = 'Coś poszło nie tak. Spróbuj ponownie za chwilę.';
        return j;
      });
    }, function () {
      return { ok: false, status: 0, error: 'Brak połączenia. Sprawdź internet i spróbuj ponownie.' };
    });
  }

  function start() {
    if (S.starting) return S.starting;
    S.screen = 'loading';
    render();
    S.starting = api('start', { page: location.href.split('#')[0].slice(0, 500) }).then(function (j) {
      if (!j.ok) {
        S.starting = null;
        S.screen = 'error';
        S.error = j.error;
      } else {
        S.token = j.token;
        S.variant = j.variant;
        S.questions = j.questions || [];
        S.chat = !!j.chat;
        S.texts = j.texts || {};
        S.step = 0;
        S.screen = S.variant === 'b' && S.questions.length ? 'question' : 'form';
      }
      render(true);
    });
    return S.starting;
  }

  function saveAnswer(q, a) {
    var p = api('answer', { token: S.token, q: q, a: a }).then(function (j) {
      if (j.ok) delete S.failed[q];
      else S.failed[q] = a;
    });
    S.pending.push(p);
    return p;
  }

  function pick(q, a) {
    S.answers[q] = a;
    saveAnswer(q, a);
    if (q + 1 < S.questions.length) {
      S.step = q + 1;
    } else {
      S.screen = 'form';
    }
    render(true);
  }

  function ask(text) {
    text = text.trim();
    if (!text || S.asking) return;
    S.asking = true;
    S.log.push({ role: 'user', text: text });
    S.log.push({ role: 'bot', text: 'Piszę odpowiedź…', wait: true });
    renderChat(true);
    api('ask', { token: S.token, question: text.slice(0, 500) }).then(function (j) {
      S.log.pop();
      S.log.push({ role: 'bot', text: j.ok ? j.answer : j.error });
      S.asking = false;
      renderChat(true);
    });
  }

  function send(form) {
    if (S.sending) return;
    var f = S.form;
    var email = (f.email || '').trim();
    S.fieldError = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email) ? '' : 'Podaj adres e-mail, żebyśmy mogli odpowiedzieć.';
    S.error = '';
    if (S.fieldError) {
      render();
      var bad = panel.querySelector('#pd-email');
      if (bad) bad.focus();
      return;
    }
    S.sending = true;
    render();
    Promise.all(S.pending).then(function () {
      // Odpowiedzi, których nie udało się zapisać, wysyłamy jeszcze raz: ocena powstaje z zapisu na serwerze.
      var again = Object.keys(S.failed).map(function (q) { return saveAnswer(+q, S.failed[q]); });
      return Promise.all(again);
    }).then(function () {
      if (Object.keys(S.failed).length) {
        return { ok: false, error: 'Nie udało się zapisać odpowiedzi. Sprawdź połączenie i wyślij jeszcze raz.' };
      }
      return api('lead', {
        token: S.token, name: f.name || '', email: email, phone: f.phone || '', site: f.site || '', nip: f.nip || '',
        message: f.message || '', consent: !!f.consent, hp: form.elements.company_site ? form.elements.company_site.value : ''
      });
    }).then(function (j) {
      S.sending = false;
      if (j.ok) {
        S.screen = 'done';
        S.thanks = j.thanks || S.texts.thanks || 'Dziękujemy. Odezwiemy się wkrótce.';
        render(true);
      } else {
        S.error = j.error;
        render();
        var a = panel.querySelector('.alert');
        if (a) a.focus();
      }
    });
  }

  function field(id, label, optional, attrs, hint, error) {
    var key = id;
    var a = { id: 'pd-' + id, name: id, value: S.form[key] || '', oninput: function (e) { S.form[key] = e.target.value; } };
    var k;
    for (k in attrs) a[k] = attrs[k];
    if (error) {
      a['aria-invalid'] = 'true';
      a['aria-describedby'] = 'pd-' + id + '-e';
    } else if (hint) {
      a['aria-describedby'] = 'pd-' + id + '-h';
    }
    var input = a.rows ? el('textarea', a) : el('input', a);
    if (a.rows) input.value = S.form[key] || '';
    return el('div', null, [
      el('label', { for: 'pd-' + id }, [label, optional ? el('span', null, ' (opcjonalnie)') : null]),
      input,
      error ? el('p', { class: 'err', id: 'pd-' + id + '-e' }, error) : (hint ? el('p', { class: 'hint', id: 'pd-' + id + '-h' }, hint) : null)
    ]);
  }

  function screen() {
    var t = S.texts;
    if (S.screen === 'loading') {
      return [el('h2', { tabindex: '-1' }, 'Wczytuję formularz…')];
    }
    if (S.screen === 'error') {
      return [
        el('h2', { tabindex: '-1' }, 'Formularz się nie wczytał'),
        el('p', null, S.error),
        el('button', { class: 'go', type: 'button', onclick: start }, 'Spróbuj ponownie')
      ];
    }
    if (S.screen === 'question') {
      var q = S.questions[S.step];
      return [
        S.step === 0 && t.greeting ? el('p', { class: 'muted' }, t.greeting) : null,
        el('p', { class: 'step' }, 'Pytanie ' + (S.step + 1) + ' z ' + S.questions.length),
        el('h2', { tabindex: '-1' }, q.text),
        el('ul', { class: 'opts' }, q.options.map(function (o, i) {
          return el('li', null, el('button', { class: 'opt', type: 'button', 'aria-pressed': S.answers[S.step] === i ? 'true' : 'false', onclick: function () { pick(S.step, i); } }, o));
        })),
        S.step > 0 ? el('button', { class: 'back', type: 'button', onclick: function () { S.step--; render(true); } }, 'Wróć do poprzedniego pytania') : null
      ];
    }
    if (S.screen === 'form') {
      var withQuestions = S.variant === 'b' && S.questions.length;
      return [
        withQuestions ? el('p', { class: 'step' }, 'Ostatni krok') : el('p', { class: 'muted' }, 'Napisz, czego potrzebujesz. Odpowiemy na podany adres.'),
        el('h2', { tabindex: '-1' }, withQuestions ? 'Gdzie mamy odpowiedzieć?' : 'Zapytaj o ofertę'),
        el('form', { novalidate: true, onsubmit: function (e) { e.preventDefault(); send(e.target); } }, [
          S.error ? el('p', { class: 'alert', role: 'alert', tabindex: '-1' }, S.error) : null,
          field('name', 'Imię i nazwisko', true, { type: 'text', autocomplete: 'name', maxlength: '80' }),
          field('email', 'E-mail', false, { type: 'email', autocomplete: 'email', maxlength: '160', required: true, inputmode: 'email' }, null, S.fieldError),
          field('phone', 'Telefon', true, { type: 'tel', autocomplete: 'tel', maxlength: '30', inputmode: 'tel' }, 'Z numerem oddzwonimy szybciej.'),
          field('site', 'Adres Twojej strony', true, { type: 'text', autocomplete: 'url', maxlength: '200', inputmode: 'url', placeholder: 'twojafirma.pl' }, t.url_prompt || null),
          field('nip', 'NIP firmy', true, { type: 'text', inputmode: 'numeric', maxlength: '13' }),
          field('message', 'Wiadomość', withQuestions, { rows: '3', maxlength: '2000' }),
          el('div', { class: 'hp', 'aria-hidden': 'true' }, [
            el('label', { for: 'pd-cs' }, 'Zostaw to pole puste'),
            el('input', { id: 'pd-cs', type: 'text', name: 'company_site', tabindex: '-1', autocomplete: 'off' })
          ]),
          el('label', { class: 'check' }, [
            el('input', { type: 'checkbox', name: 'consent', checked: !!S.form.consent, onchange: function (e) { S.form.consent = e.target.checked; } }),
            el('span', null, 'Chcę dostawać oferty e-mailem. Zgodę mogę wycofać w każdej chwili.')
          ]),
          el('button', { class: 'go', type: 'submit', disabled: S.sending }, S.sending ? 'Wysyłam…' : 'Wyślij zapytanie'),
          el('p', { class: 'legal' }, [
            (t.legal || '') + ' ',
            t.privacy_url ? el('a', { href: t.privacy_url, target: '_blank', rel: 'noopener' }, 'Polityka prywatności') : null
          ])
        ]),
        withQuestions ? el('button', { class: 'back', type: 'button', onclick: function () { S.screen = 'question'; S.step = S.questions.length - 1; render(true); } }, 'Wróć do pytań') : null
      ];
    }
    return [
      el('div', { class: 'done' }, [
        icon('M20 6 9 17l-5-5'),
        el('div', null, [el('h2', { tabindex: '-1' }, 'Zapytanie wysłane'), el('p', null, S.thanks)])
      ])
    ];
  }

  function renderChat(scroll) {
    var box = panel && panel.querySelector('.chat');
    if (!box) return;
    var log = box.querySelector('.log');
    log.textContent = '';
    S.log.forEach(function (m) {
      log.appendChild(el('div', { class: 'msg ' + (m.role === 'user' ? 'user' : 'bot') }, m.text));
    });
    log.hidden = !S.log.length;
    box.querySelector('.ask button').disabled = S.asking;
    if (scroll) log.scrollTop = log.scrollHeight;
  }

  function render(focus) {
    if (mode === 'float' && !S.open) {
      if (panel) { panel.remove(); panel = null; }
      if (!launcher) {
        launcher = el('button', { class: 'launch', type: 'button', onclick: open }, [icon('M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z'), CFG.button || 'Zapytaj o ofertę']);
        root.appendChild(launcher);
      }
      launcher.hidden = false;
      return;
    }
    if (launcher) launcher.hidden = true;
    var draft = panel && panel.querySelector('.ask input') ? panel.querySelector('.ask input').value : '';
    var next = el('section', { class: 'panel', role: mode === 'float' ? 'dialog' : 'region', 'aria-label': 'Zapytanie do firmy ' + (CFG.company || '') }, [
      el('div', { class: 'head' }, [
        el('b', null, CFG.company || 'Zapytanie'),
        mode === 'float' ? el('button', { class: 'x', type: 'button', 'aria-label': 'Zamknij', onclick: close }, icon('M18 6 6 18M6 6l12 12')) : null
      ]),
      el('div', { class: 'body' }, screen()),
      S.chat && S.token && S.screen !== 'loading' && S.screen !== 'error' ? el('div', { class: 'chat' }, [
        el('div', { class: 'log', role: 'log', 'aria-live': 'polite', 'aria-label': 'Rozmowa z asystentem', hidden: true }),
        el('form', { class: 'ask', onsubmit: function (e) {
          e.preventDefault();
          var i = e.target.querySelector('input');
          ask(i.value);
          i.value = '';
        } }, [
          el('input', { type: 'text', maxlength: '500', 'aria-label': 'Twoje pytanie', placeholder: 'Masz pytanie? Napisz', value: draft }),
          el('button', { type: 'submit' }, 'Zapytaj')
        ]),
        el('p', { class: 'ai' }, 'Na pytania odpowiada asystent AI na podstawie informacji od firmy' + (CFG.company ? ' ' + CFG.company : '') + '. Może się mylić.')
      ]) : null
    ]);
    // Animacja tylko przy otwarciu. Przy kolejnych krokach okno podmienia się bez mrugania.
    if (panel) root.replaceChild(next, panel);
    else { next.classList.add('enter'); root.appendChild(next); }
    panel = next;
    renderChat(true);
    if (focus) {
      var h = panel.querySelector('h2');
      if (h && (mode === 'float' || S.screen !== 'loading')) h.focus({ preventScroll: mode === 'inline' });
    }
  }

  function open() {
    S.open = true;
    if (S.token || S.starting) render(true);
    else start();
  }

  function close() {
    S.open = false;
    render();
    if (launcher) launcher.focus();
  }

  root.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && mode === 'float' && S.open) close();
  });

  if (mode === 'inline') {
    S.open = true;
    // Rozmowa zaczyna się, gdy formularz pojawi się na ekranie: tak liczymy wyświetlenia w wynikach.
    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (entries) {
        if (entries.some(function (x) { return x.isIntersecting; })) {
          io.disconnect();
          start();
        }
      });
      io.observe(host);
    } else {
      start();
    }
  } else {
    render();
  }
})(/*CFG*/null);
