<?php

use App\Core\Auth;
use App\Core\Brand;
use App\Core\Config;
use App\Core\Db;
use App\Core\Http;
use App\Core\Mailer;
use App\Http\Nav;

/** Ustawia zmienne na czas jednego testu i zawsze je przywraca. */
function with_config(array $set, callable $fn): void
{
    $old = [];
    foreach ($set as $k => $v) {
        $old[$k] = Config::get($k);
        Config::set($k, $v);
    }
    try {
        $fn();
    } finally {
        foreach ($old as $k => $v) {
            Config::set($k, $v);
        }
    }
}

return [
    'Poczta: tryb lokalny zapisuje wiadomość i niczego nie wysyła' => function () {
        eq('log', Mailer::driver());
        Http::$log = [];
        $res = Mailer::send('Ktos@Example.com', "Temat\r\nBcc: obcy@example.com", 'Treść', null, 'test');
        eq(['ok' => true, 'status' => 'logged'], $res);
        eq([], Http::$log, 'żadnego połączenia z usługą wysyłkową');
        $row = Db::get()->one('SELECT * FROM outbox');
        eq('ktos@example.com', $row['recipient']);
        eq('Temat Bcc: obcy@example.com', $row['subject'], 'znaki nowej linii nie dopisują nagłówków');
        eq(false, Mailer::send('to-nie-adres', 'Temat', 'Treść')['ok']);
        eq(1, (int) Db::get()->val('SELECT COUNT(*) FROM outbox'));
    },
    'Poczta: wysyłka przez Resend, błąd usługi i brak konfiguracji' => function () {
        with_config(['RESEND_API_KEY' => 're_test', 'MAIL_FROM' => 'Panel <panel@perfectdata.pl>'], function () {
            eq('resend', Mailer::driver());
            Http::$log = [];
            eq('sent', Mailer::send('klient@example.com', 'Temat', 'Treść', null, 'test')['status']);
            $sent = json_decode(Http::$log[0]['body'], true);
            eq('https://api.resend.com/emails', Http::$log[0]['url']);
            eq(['from' => 'Panel <panel@perfectdata.pl>', 'to' => ['klient@example.com'], 'subject' => 'Temat', 'text' => 'Treść'], $sent);
            $fail = Mailer::send('odrzucony@example.com', 'Temat', 'Treść', null, 'test');
            eq(false, $fail['ok']);
            eq('failed', $fail['status']);
            contains('Domena nadawcy nie jest zweryfikowana', $fail['error']);
            eq('failed', Db::get()->val("SELECT status FROM outbox WHERE recipient = 'odrzucony@example.com'"), 'nieudana wysyłka też zostaje w dzienniku');
        });
        with_config(['APP_ENV' => 'production'], function () {
            eq('none', Mailer::driver());
            $res = Mailer::send('klient@example.com', 'Temat', 'Treść');
            eq(false, $res['ok']);
            contains('RESEND_API_KEY', $res['error']);
        });
    },
    'Hasło: link z e-maila ustawia nowe hasło i działa tylko raz' => function () {
        [, $uid] = make_org('wlasciciel@example.com');
        Auth::logout();
        eq(200, http('GET', '/haslo')->status);
        $r = http('POST', '/haslo', ['email' => 'Wlasciciel@Example.com']);
        eq('/logowanie', $r->headers['Location']);
        $mail = Db::get()->one("SELECT * FROM outbox WHERE kind = 'password_reset'");
        eq('wlasciciel@example.com', $mail['recipient']);
        ok((bool) preg_match('#/haslo/([a-f0-9]{48})#', $mail['body'], $m), 'link w wiadomości');
        ok(!str_contains((string) Db::get()->val("SELECT k FROM kv WHERE k LIKE 'pwreset:%'"), $m[1]), 'w bazie jest skrót tokenu, nie token');
        eq(200, http('GET', '/haslo/' . $m[1])->status);
        eq(422, http('POST', '/haslo/' . $m[1], ['password' => 'krotkie'])->status);
        eq(303, http('POST', '/haslo/' . $m[1], ['password' => 'nowe-dlugie-haslo-1'])->status);
        eq(404, http('GET', '/haslo/' . $m[1])->status, 'link działa raz');
        eq(404, http('GET', '/haslo/' . str_repeat('a', 48))->status);
        eq(422, http('POST', '/logowanie', ['email' => 'wlasciciel@example.com', 'password' => 'haslo-testowe-1'])->status, 'stare hasło nie działa');
        eq(303, http('POST', '/logowanie', ['email' => 'wlasciciel@example.com', 'password' => 'nowe-dlugie-haslo-1'])->status);
        eq($uid, Auth::id());
    },
    'Hasło: nieznany adres dostaje tę samą odpowiedź, a próby mają limit' => function () {
        make_org('wlasciciel@example.com');
        Auth::logout();
        $known = http('POST', '/haslo', ['email' => 'wlasciciel@example.com']);
        $_SESSION = [];
        $unknown = http('POST', '/haslo', ['email' => 'nie-ma@example.com']);
        eq($known->headers['Location'], $unknown->headers['Location']);
        eq(1, (int) Db::get()->val('SELECT COUNT(*) FROM outbox'), 'do nieznanego adresu nic nie wychodzi');
        for ($i = 0; $i < 7; $i++) {
            http('POST', '/haslo', ['email' => 'nie-ma@example.com']);
        }
        eq('/haslo', http('POST', '/haslo', ['email' => 'wlasciciel@example.com'])->headers['Location'], '10. próba w godzinie');
        eq(1, (int) Db::get()->val('SELECT COUNT(*) FROM outbox'));
    },
    'Zespół: zaproszenie bez hasła wysyła link do ustawienia własnego' => function () {
        [$org, $uid] = make_org();
        login_as($uid);
        eq(303, http('POST', '/ustawienia/zespol', ['name' => 'Ola Nowa', 'email' => 'ola@example.com', 'password' => ''])->status);
        $u = Db::get()->one("SELECT * FROM users WHERE email = 'ola@example.com'");
        eq($org, (int) $u['org_id']);
        eq('member', $u['role']);
        $mail = Db::get()->one("SELECT * FROM outbox WHERE kind = 'invite'");
        contains('dodaje Cię do zespołu', $mail['body']);
        ok((bool) preg_match('#/haslo/([a-f0-9]{48})#', $mail['body'], $m));
        Auth::logout();
        eq(303, http('POST', '/haslo/' . $m[1], ['password' => 'haslo-oli-12345'])->status);
        eq(303, http('POST', '/logowanie', ['email' => 'ola@example.com', 'password' => 'haslo-oli-12345'])->status);
    },
    'Wygląd: motyw PerfectData z ustawienia serwera i z ustawień firmy' => function () {
        [$org, $uid] = make_org();
        login_as($uid);
        $plain = http('GET', '/pulpit')->body;
        ok(!str_contains($plain, 'theme-perfectdata.css'), 'domyślnie wygląd standardowy');
        with_config(['APP_THEME' => 'perfectdata', 'APP_NAME' => 'PerfectData'], function () use ($org) {
            $page = http('GET', '/pulpit')->body;
            contains('/assets/theme-perfectdata.css', $page);
            contains('Perfect<b>Data</b>', $page);
            // Firma może wrócić do wyglądu standardowego mimo ustawienia serwera.
            Brand::save($org, ['theme' => 'default']);
            eq('default', Brand::theme(Db::get()->one('SELECT settings_json FROM organizations WHERE id = ?', [$org])));
            Auth::logout();
            contains('theme-perfectdata.css', http('GET', '/logowanie')->body, 'strona logowania bierze wygląd z ustawienia serwera');
        });
        login_as($uid);
        eq(303, http('POST', '/ustawienia/wyglad', ['theme' => 'perfectdata', 'modules' => ['zapytania', 'klienci', 'audyt']])->status);
        Auth::logout();
        login_as($uid);
        Nav::reset();
        $page = http('GET', '/pulpit')->body;
        contains('/assets/theme-perfectdata.css', $page);
        preg_match('#<nav class="nav".*?</nav>#s', $page, $nav);
        contains('href="/zapytania"', $nav[0]);
        ok(!str_contains($nav[0], 'href="/przetargi"') && !str_contains($nav[0], 'href="/kontrahenci"'), 'wyłączone moduły znikają z menu');
        eq(200, http('GET', '/przetargi')->status, 'wyłączenie w menu nie usuwa danych ani strony');
        eq(['zapytania', 'klienci', 'audyt'], Brand::modules(Auth::org()));
    },
    'Wygląd: ustawia go tylko właściciel' => function () {
        [$org, $uid] = make_org();
        $member = Db::get()->insert('users', ['org_id' => $org, 'email' => 'czlonek@example.com', 'name' => 'Członek', 'password_hash' => 'x', 'role' => 'member', 'created_at' => now_utc()]);
        login_as($member);
        http('POST', '/ustawienia/wyglad', ['theme' => 'perfectdata', 'modules' => []]);
        eq([], Brand::settings(Db::get()->one('SELECT settings_json FROM organizations WHERE id = ?', [$org])));
    },
];
