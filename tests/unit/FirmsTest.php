<?php

use App\Core\Auth;
use App\Core\Db;
use App\Core\Http;
use App\Core\Invites;
use App\Core\Kv;
use App\Core\OrgData;
use App\Core\Plans;
use App\Core\Secret;
use App\Core\Totp;
use App\Modules\Ai\Claude;
use App\Modules\Inbound\Inbound;
use App\Modules\Seo\CloudflareBots;
use App\Modules\Seo\Google;
use App\Modules\Seo\GoogleOAuth;
use App\Modules\Seo\Sites;
use App\Modules\Seo\Stats;
use App\Modules\Seo\Sync;

/** Zapytania do modelu AI zapisane w dzienniku klienta HTTP. */
function ai_calls(): array
{
    return array_values(array_filter(Http::$log, static fn (array $l): bool => str_contains($l['url'], 'api.anthropic.com')));
}

return [
    'Pakiety: Podstawowy ma mały przydział AI, po wyczerpaniu panel nie pyta modelu, Rozszerzony ma większy, a firma prowadząca panel nie ma limitów' => function () {
        [$orgId, $uid] = make_org();
        $db = Db::get();
        login_as($uid);
        $org = static fn (int $id): array => Db::get()->one('SELECT * FROM organizations WHERE id = ?', [$id]);
        eq(['basic', 'Podstawowy', 30, 100, 3, 2], [Plans::of($org($orgId)), Plans::name($org($orgId)), Plans::limit($org($orgId), 'ai_ops'), Plans::limit($org($orgId), 'ai_chat'), Plans::limit($org($orgId), 'team'), Plans::limit($org($orgId), 'sites')]);
        $db->update('organizations', ['plan' => 'trial'], 'id = ?', [$orgId]);
        eq('basic', Plans::of($org($orgId)), 'dawna wartość pakietu to Podstawowy');

        with_config(['ANTHROPIC_API_KEY' => 'klucz-testowy', 'AI_MODEL' => 'model-glowny', 'AI_MODEL_BASIC' => ''], function () use ($orgId, $uid, $db, $org) {
            // Limity zmienia administrator. Błędna liczba niczego nie zapisuje.
            eq(1, count(Plans::saveLimits(['basic' => ['ai_ops' => '-5']])));
            eq(1, count(Plans::saveLimits(['basic' => ['ai_ops' => '2', 'team' => 'dużo']])));
            eq(30, Plans::limits()['basic']['ai_ops']);
            eq([], Plans::saveLimits(['basic' => ['ai_ops' => '2', 'ai_chat' => '1', 'team' => '2']]));
            eq([2, 1, 2, 2, 400], [Plans::limits()['basic']['ai_ops'], Plans::limits()['basic']['ai_chat'], Plans::limits()['basic']['team'], Plans::limits()['basic']['sites'], Plans::limits()['extended']['ai_ops']]);

            // Dwa użycia przechodzą i są policzone razem z tokenami. Trzecie nie wychodzi do modelu.
            Http::$log = [];
            ok(Claude::complete('Odpowiadasz jednym słowem.', 'test')['ok']);
            ok(Claude::complete('Odpowiadasz jednym słowem.', 'test')['ok']);
            eq(2, count(ai_calls()));
            eq(['calls' => 2, 'input_tokens' => 42, 'output_tokens' => 6], array_map('intval', $db->one("SELECT calls, input_tokens, output_tokens FROM ai_usage WHERE org_id = ? AND kind = 'ops' AND month = ?", [$orgId, gmdate('Y-m')])));
            $third = Claude::complete('Odpowiadasz jednym słowem.', 'test');
            eq([false, true], [$third['ok'], $third['limit'] ?? false]);
            contains('Przydział AI w pakiecie Podstawowy na ten miesiąc jest wyczerpany (2 użyć)', $third['error']);
            contains('Wersje bez AI działają dalej', $third['error']);
            eq(2, count(ai_calls()), 'po wyczerpaniu przydziału zapytanie nie wychodzi');
            eq(2, Plans::usage($orgId)['ai_ops']);

            // Czat na stronie ma osobny przydział i liczy się do firmy widżetu, choć nikt nie jest zalogowany.
            Auth::logout();
            ok(Claude::as($orgId, 'chat', static fn () => Claude::complete('Odpowiadasz jednym słowem.', 'test'))['ok']);
            eq(false, Claude::as($orgId, 'chat', static fn () => Claude::complete('Odpowiadasz jednym słowem.', 'test'))['ok']);
            eq([2, 1], [Plans::usage($orgId)['ai_ops'], Plans::usage($orgId)['ai_chat']]);
            // Zapytanie bez firmy (wiersz poleceń, test administratora) nie jest liczone ani blokowane.
            ok(Claude::complete('Odpowiadasz jednym słowem.', 'test')['ok']);
            eq(2, Plans::usage($orgId)['ai_ops']);
            login_as($uid);

            // Ustawienia mówią wprost, ile zostało i co dalej.
            $page = http('GET', '/ustawienia')->body;
            contains('Pakiet Podstawowy', $page);
            ok((bool) preg_match('#Użycia AI w miesiącu.*<b class="nowrap">2 z 2</b>#s', $page), 'licznik użyć AI');
            contains('Przydział wyczerpany. Wersje bez AI działają dalej.', $page);
            contains('Więcej AI, osób i witryn jest w pakiecie Rozszerzonym', $page);

            // Zespół i witryny: limit pakietu.
            eq(303, http('POST', '/ustawienia/zespol', ['email' => 'druga@example.com', 'password' => 'haslo-startowe-1', 'name' => 'Druga Osoba'])->status);
            http('POST', '/ustawienia/zespol', ['email' => 'trzecia@example.com', 'password' => 'haslo-startowe-1']);
            eq(2, (int) $db->val('SELECT COUNT(*) FROM users WHERE org_id = ?', [$orgId]));
            contains('Pakiet Podstawowy obejmuje 2 osoby w zespole', http('GET', '/ustawienia')->body);
            ok(Sites::add($orgId, 'pierwsza.example')['ok'] && Sites::add($orgId, 'druga.example')['ok']);
            contains('Pakiet Podstawowy obejmuje 2 witryny', Sites::add($orgId, 'trzecia.example')['error']);

            // Rozszerzony: większy przydział, więc to samo zapytanie przechodzi. Tańszy model tylko dla Podstawowego.
            $db->update('organizations', ['plan' => 'extended'], 'id = ?', [$orgId]);
            ok(Claude::complete('Odpowiadasz jednym słowem.', 'test')['ok']);
            ok(Sites::add($orgId, 'trzecia.example')['ok']);
            contains('Pakiet Rozszerzony', http('GET', '/ustawienia')->body);
            with_config(['AI_MODEL_BASIC' => 'model-tanszy'], function () use ($orgId, $db) {
                eq('model-glowny', Plans::model($orgId));
                $db->update('organizations', ['plan' => 'basic'], 'id = ?', [$orgId]);
                $db->run('DELETE FROM ai_usage');
                Http::$log = [];
                Claude::complete('Odpowiadasz jednym słowem.', 'test');
                eq('model-tanszy', json_decode(ai_calls()[0]['body'], true)['model']);
            });

            // Firma prowadząca panel: bez limitów, niezależnie od zapisu w bazie.
            with_config(['ADMIN_EMAILS' => 'wlasciciel@example.com'], function () use ($orgId, $org) {
                eq(['internal', null, null], [Plans::of($org($orgId)), Plans::limit($org($orgId), 'ai_ops'), Plans::limit($org($orgId), 'team')]);
                for ($i = 0; $i < 4; $i++) {
                    ok(Claude::complete('Odpowiadasz jednym słowem.', 'test')['ok']);
                }
                contains('bez limitów', http('GET', '/ustawienia')->body);
            });
        });
    },

    'Administracja platformy: tylko administrator, zaproszenie firmy z pakietem i stałym adresem, zmiana pakietu i blokada' => function () {
        [$adminOrg, $adminId] = make_org('admin@example.com', 'Operator Panelu sp. z o.o.');
        $db = Db::get();
        with_config(['ADMIN_EMAILS' => 'admin@example.com', 'SIGNUP_OPEN' => '0', 'SIGNUP_CODE' => 'kod-wspolny'], function () use ($db, $adminOrg, $adminId) {
            [$firmOrg, $firmUid] = make_org('firma@example.com', 'Firma Klienta sp. z o.o.');

            // Zwykła firma nie widzi administracji: adresy dla niej nie istnieją.
            login_as($firmUid);
            eq(404, http('GET', '/administracja')->status);
            foreach (['/administracja/firmy/' . $adminOrg, '/administracja/zaproszenia', '/administracja/pakiety', '/administracja/kody', '/administracja/zaproszenia/1/usun'] as $path) {
                eq(404, http('POST', $path, ['plan' => 'extended', 'email' => 'x@example.com', 'limit' => ['basic' => ['ai_ops' => '9999']]])->status, $path);
            }
            eq('basic', $db->val('SELECT plan FROM organizations WHERE id = ?', [$firmOrg]), 'firma nie zmieni sobie pakietu');
            eq(30, Plans::limits()['basic']['ai_ops']);
            ok(!str_contains(http('GET', '/pulpit')->body, 'href="/administracja"'), 'bez linku w menu');

            login_as($adminId);
            $page = http('GET', '/administracja')->body;
            contains('Firma Klienta sp. z o.o.', $page);
            contains('prowadzi panel', $page);
            contains('href="/administracja"', http('GET', '/pulpit')->body);

            // Zaproszenie: błędny adres, adres z kontem, a potem poprawne. Link widać raz.
            http('POST', '/administracja/zaproszenia', ['email' => 'to nie adres', 'plan' => 'extended']);
            http('POST', '/administracja/zaproszenia', ['email' => 'firma@example.com', 'plan' => 'extended']);
            eq(0, (int) $db->val('SELECT COUNT(*) FROM invites'));
            http('POST', '/administracja/zaproszenia', ['email' => ' Nowa@Example.com ', 'company' => 'Nowa Firma', 'plan' => 'extended']);
            $page = http('GET', '/administracja')->body;
            ok((bool) preg_match('#/rejestracja\?zaproszenie=([a-f0-9]{48})#', $page, $m), 'link do skopiowania');
            $token = $m[1];
            ok(!str_contains(http('GET', '/administracja')->body, $token), 'link pokazujemy tylko raz');
            ok(!str_contains((string) json_encode($db->all('SELECT * FROM invites')), $token), 'w bazie jest tylko skrót tokenu');
            contains('nowa@example.com', http('GET', '/administracja')->body);

            // Rejestracja z linku: adres jest stały, pakiet z zaproszenia, link działa raz.
            Auth::logout();
            $_SESSION = [];
            eq(403, http('GET', '/rejestracja', [], ['zaproszenie' => str_repeat('a', 48)])->status);
            eq(403, http('GET', '/rejestracja')->status, 'bez zaproszenia i bez kodu nie ma rejestracji');
            $form = http('GET', '/rejestracja', [], ['zaproszenie' => $token])->body;
            contains('value="nowa@example.com" readonly', $form);
            contains('value="Nowa Firma"', $form);
            $r = http('POST', '/rejestracja', ['zaproszenie' => $token, 'company' => 'Nowa Firma sp. z o.o.', 'email' => 'podmieniony@example.com', 'name' => 'Anna Nowa', 'password' => 'haslo-nowej-firmy', 'terms' => '1']);
            eq(303, $r->status);
            $new = $db->one('SELECT u.email, o.plan, o.id FROM users u JOIN organizations o ON o.id = u.org_id WHERE o.name = ?', ['Nowa Firma sp. z o.o.']);
            eq(['nowa@example.com', 'extended'], [$new['email'], $new['plan']], 'adres z zaproszenia i pakiet z zaproszenia');
            eq(0, (int) $db->val('SELECT COUNT(*) FROM users WHERE email = ?', ['podmieniony@example.com']));
            Auth::logout();
            $_SESSION = [];
            eq(403, http('GET', '/rejestracja', [], ['zaproszenie' => $token])->status, 'link jednorazowy');
            // Zaproszenie po terminie i wycofane nie działa. Nowe na ten sam adres zastępuje stare.
            $a = Invites::create('kolejna@example.com', '', 'basic', $adminId, 'http://localhost');
            $b = Invites::create('kolejna@example.com', '', 'basic', $adminId, 'http://localhost');
            $tokenOf = static fn (array $res): string => substr($res['link'], -48);
            eq([null, true], [Invites::find($tokenOf($a)), Invites::find($tokenOf($b)) !== null]);
            $db->update('invites', ['expires_at' => gmdate('Y-m-d H:i:s', time() - 60)], 'id = ?', [$b['id']]);
            eq(null, Invites::find($tokenOf($b)), 'po terminie');
            $c = Invites::create('wycofana@example.com', '', 'basic', $adminId, 'http://localhost');
            login_as($adminId);
            http('POST', '/administracja/zaproszenia/' . $c['id'] . '/usun');
            eq(null, Invites::find($tokenOf($c)));

            // Pakiet i blokada. Firmy administratora nie da się zablokować.
            http('POST', '/administracja/firmy/' . $firmOrg, ['plan' => 'extended', 'status' => 'active']);
            eq('extended', $db->val('SELECT plan FROM organizations WHERE id = ?', [$firmOrg]));
            http('POST', '/administracja/firmy/' . $firmOrg, ['plan' => 'wymyslony', 'status' => 'active']);
            eq('extended', $db->val('SELECT plan FROM organizations WHERE id = ?', [$firmOrg]));
            http('POST', '/administracja/firmy/' . $adminOrg, ['plan' => 'basic', 'status' => 'blocked']);
            eq('active', $db->val('SELECT status FROM organizations WHERE id = ?', [$adminOrg]));
            $widget = Inbound::widget($firmOrg);
            ok(Inbound::byPublicId((string) $widget['public_id']) !== null);
            http('POST', '/administracja/firmy/' . $firmOrg, ['plan' => 'extended', 'status' => 'blocked']);
            contains('zablokowana', http('GET', '/administracja')->body);
            eq(null, Inbound::byPublicId((string) $widget['public_id']), 'widżet zablokowanej firmy przestaje działać');
            // Osoba z zablokowanej firmy: otwarta sesja kończy się przy najbliższym kliknięciu, a logowanie odmawia.
            login_as($firmUid);
            $r = http('GET', '/pulpit');
            eq([303, '/logowanie'], [$r->status, $r->headers['Location'] ?? '']);
            ok(!Auth::check(), 'sesja zakończona');
            contains('Konto firmy jest zablokowane', Auth::attempt('firma@example.com', 'haslo-testowe-1', '10.0.0.1')['error']);
            login_as($adminId);
            http('POST', '/administracja/firmy/' . $firmOrg, ['plan' => 'basic', 'status' => 'active']);
            ok(Auth::attempt('firma@example.com', 'haslo-testowe-1', '10.0.0.1')['ok'], 'po odblokowaniu logowanie działa');

            // Limity z formularza: zapis i widok.
            login_as($adminId);
            http('POST', '/administracja/pakiety', ['limit' => ['basic' => ['ai_ops' => '45'], 'extended' => ['ai_ops' => '600']]]);
            eq([45, 600], [Plans::limits()['basic']['ai_ops'], Plans::limits()['extended']['ai_ops']]);
            contains('value="45"', http('GET', '/administracja')->body);
            eq(4, (int) $db->val("SELECT COUNT(*) FROM audit_log WHERE action IN ('admin.firm', 'admin.limits') AND user_id = ?", [$adminId]), 'działania administratora są w dzienniku');
        });
    },

    'Zabezpieczenia: logowanie dwuetapowe, unieważnianie sesji, szyfrowanie sekretów' => function () {
        [$orgId, $uid] = make_org();
        $db = Db::get();
        login_as($uid);
        with_config(['APP_SECRET' => 'sekret-testowy-0123456789'], function () use ($db, $orgId, $uid) {
            // Sekrety: w bazie leży szyfrogram, inny klucz i uszkodzona wartość nie dają niczego.
            $enc = Secret::encrypt('token-cloudflare-123');
            ok(str_starts_with($enc, 'v1:') && !str_contains($enc, 'token-cloudflare'), 'wartość zaszyfrowana');
            ok($enc !== Secret::encrypt('token-cloudflare-123'), 'każdy zapis ma własny numer jednorazowy');
            eq('token-cloudflare-123', Secret::decrypt($enc));
            eq([null, null, null], [Secret::decrypt(substr($enc, 0, -4) . 'AAAA'), Secret::decrypt('zwykły tekst'), Secret::decrypt(null)]);
            with_config(['APP_SECRET' => 'inny-sekret-0123456789abc'], fn () => eq(null, Secret::decrypt($enc), 'inny klucz platformy'));
            Secret::put($orgId, 'cloudflare', 'token-firmy', ['label' => 'koniec …rmy'], $uid);
            eq(['token-firmy', 'koniec …rmy'], [Secret::get($orgId, 'cloudflare'), Secret::meta($orgId, 'cloudflare')['label']]);
            ok(!str_contains((string) $db->val('SELECT value_enc FROM org_secrets WHERE org_id = ?', [$orgId]), 'token-firmy'));
            Secret::put($orgId, 'cloudflare', '');
            eq(null, Secret::get($orgId, 'cloudflare'));

            // Kody według RFC 6238 (wartości z dokumentu, klucz „12345678901234567890”).
            $rfc = Totp::base32('12345678901234567890');
            eq(['287082', '081804', '005924'], [Totp::code($rfc, 59), Totp::code($rfc, 1111111109), Totp::code($rfc, 1234567890)]);
            eq('12345678901234567890', Totp::unbase32($rfc));

            // Włączenie: hasło, klucz do zeskanowania, kod z aplikacji. Złe hasło i zły kod niczego nie włączają.
            http('POST', '/ustawienia/logowanie-dwuetapowe/start', ['current' => 'zle-haslo-zle']);
            eq(null, $db->val('SELECT totp_secret FROM users WHERE id = ?', [$uid]));
            http('POST', '/ustawienia/logowanie-dwuetapowe/start', ['current' => 'haslo-testowe-1']);
            $stored = (string) $db->val('SELECT totp_secret FROM users WHERE id = ?', [$uid]);
            $secret = Secret::decrypt($stored);
            ok($secret !== null && !str_contains($stored, $secret), 'klucz w bazie jest zaszyfrowany');
            $page = http('GET', '/ustawienia/logowanie-dwuetapowe')->body;
            contains('data:image/png;base64,', $page);
            contains(trim(chunk_split($secret, 4, ' ')), $page);
            http('POST', '/ustawienia/logowanie-dwuetapowe/wlacz', ['code' => '000000']);
            eq(0, (int) $db->val('SELECT totp_on FROM users WHERE id = ?', [$uid]));
            http('POST', '/ustawienia/logowanie-dwuetapowe/wlacz', ['code' => Totp::code($secret, time())]);
            eq(1, (int) $db->val('SELECT totp_on FROM users WHERE id = ?', [$uid]));
            $page = http('GET', '/ustawienia/logowanie-dwuetapowe')->body;
            ok(preg_match_all('#<code class="copy">([a-z0-9]{5}-[a-z0-9]{5})</code>#', $page, $m) === 8, 'osiem kodów zapasowych');
            $recovery = $m[1];
            ok(!str_contains(http('GET', '/ustawienia/logowanie-dwuetapowe')->body, $recovery[0]), 'kody zapasowe widać tylko raz');
            ok(!str_contains((string) $db->val('SELECT recovery_json FROM users WHERE id = ?', [$uid]), str_replace('-', '', $recovery[0])), 'w bazie są skróty kodów');
            ok(Auth::check(), 'bieżąca sesja działa dalej po włączeniu');

            // Logowanie: samo hasło nie tworzy sesji. Zły kod, potem dobry. Ten sam kod nie działa drugi raz.
            Auth::logout();
            $_SESSION = [];
            $r = http('POST', '/logowanie', ['email' => 'wlasciciel@example.com', 'password' => 'haslo-testowe-1']);
            eq([303, '/logowanie/kod'], [$r->status, $r->headers['Location'] ?? '']);
            ok(!Auth::check(), 'po haśle nie ma jeszcze sesji');
            eq(303, http('GET', '/pulpit')->status, 'panel nadal wymaga logowania');
            eq(422, http('POST', '/logowanie/kod', ['code' => '123456'])->status);
            $code = Totp::code($secret, time() - 30);
            eq(303, http('POST', '/logowanie/kod', ['code' => $code])->status);
            ok(Auth::check() && Auth::id() === $uid, 'zalogowano po kodzie');
            Auth::logout();
            $_SESSION = [];
            http('POST', '/logowanie', ['email' => 'wlasciciel@example.com', 'password' => 'haslo-testowe-1']);
            eq(422, http('POST', '/logowanie/kod', ['code' => $code])->status, 'użyty kod nie działa drugi raz');
            // Kod zapasowy działa raz.
            eq(303, http('POST', '/logowanie/kod', ['code' => strtoupper($recovery[0])])->status);
            ok(Auth::check());
            eq(7, count(json_decode((string) $db->val('SELECT recovery_json FROM users WHERE id = ?', [$uid]), true)));
            Auth::logout();
            $_SESSION = [];
            http('POST', '/logowanie', ['email' => 'wlasciciel@example.com', 'password' => 'haslo-testowe-1']);
            eq(422, http('POST', '/logowanie/kod', ['code' => $recovery[0]])->status, 'kod zapasowy jest jednorazowy');
            // Sześć błędnych kodów kończy próbę: trzeba podać hasło od nowa.
            for ($i = 0; $i < 5; $i++) {
                http('POST', '/logowanie/kod', ['code' => '111111']);
            }
            $r = http('POST', '/logowanie/kod', ['code' => Totp::code($secret, time() + 30)]);
            eq([303, '/logowanie'], [$r->status, $r->headers['Location'] ?? '']);
            ok(!Auth::check(), 'po serii błędów nawet dobry kod nie wpuszcza');
            eq(303, http('GET', '/logowanie/kod')->status, 'bez podanego hasła nie ma ekranu kodu');
            Kv::forget('2fa:' . $uid);

            // Wyłączenie wymaga hasła i kodu.
            login_as($uid);
            http('POST', '/ustawienia/logowanie-dwuetapowe/wylacz', ['current' => 'haslo-testowe-1', 'code' => '000000']);
            eq(1, (int) $db->val('SELECT totp_on FROM users WHERE id = ?', [$uid]));
            http('POST', '/ustawienia/logowanie-dwuetapowe/wylacz', ['current' => 'haslo-testowe-1', 'code' => $recovery[1]]);
            eq([0, null], [(int) $db->val('SELECT totp_on FROM users WHERE id = ?', [$uid]), $db->val('SELECT totp_secret FROM users WHERE id = ?', [$uid])]);

            // Sesje: zmiana hasła wylogowuje inne urządzenia, bieżące zostaje. Nowe hasło z linku wylogowuje wszystkie.
            $before = (int) $db->val('SELECT session_epoch FROM users WHERE id = ?', [$uid]);
            $otherDevice = $_SESSION;
            http('POST', '/ustawienia/haslo', ['current' => 'haslo-testowe-1', 'new' => 'nowe-haslo-testowe-2']);
            eq($before + 1, (int) $db->val('SELECT session_epoch FROM users WHERE id = ?', [$uid]));
            Auth::forget();
            ok(Auth::check(), 'bieżąca sesja działa');
            $_SESSION = $otherDevice;
            Auth::forget();
            ok(!Auth::check(), 'sesja z innego urządzenia przestała działać');
            eq(303, http('GET', '/ustawienia')->status);
            login_as($uid);
            http('POST', '/ustawienia/wyloguj-wszedzie');
            Auth::forget();
            ok(Auth::check());
            $link = \App\Http\AuthController::passwordLink($uid, 600, 'http://localhost');
            $mine = $_SESSION;
            http('POST', '/haslo/' . basename($link), ['password' => 'trzecie-haslo-testowe-3']);
            $_SESSION = $mine;
            Auth::forget();
            ok(!Auth::check(), 'po ustawieniu hasła z linku żadna stara sesja nie działa');

            // Zespół: właściciel usuwa osobę i wyłącza jej kody. Członek zespołu tego nie zrobi.
            login_as($uid);
            http('POST', '/ustawienia/zespol', ['email' => 'czlonek@example.com', 'password' => 'haslo-czlonka-1']);
            $member = (int) $db->val('SELECT id FROM users WHERE email = ?', ['czlonek@example.com']);
            $db->update('users', ['totp_on' => 1, 'totp_secret' => Secret::encrypt(Totp::newSecret())], 'id = ?', [$member]);
            login_as($member);
            http('POST', '/ustawienia/zespol/' . $uid . '/usun');
            http('POST', '/ustawienia/zespol/' . $member . '/kody');
            eq([2, 1], [(int) $db->val('SELECT COUNT(*) FROM users WHERE org_id = ?', [$orgId]), (int) $db->val('SELECT totp_on FROM users WHERE id = ?', [$member])]);
            eq(303, http('GET', '/ustawienia/dane-firmy.zip')->status, 'dane firmy pobiera tylko właściciel');
            login_as($uid);
            http('POST', '/ustawienia/zespol/' . $member . '/kody');
            eq(0, (int) $db->val('SELECT totp_on FROM users WHERE id = ?', [$member]));
            http('POST', '/ustawienia/zespol/' . $uid . '/usun');
            eq(2, (int) $db->val('SELECT COUNT(*) FROM users WHERE org_id = ?', [$orgId]), 'właściciel nie usuwa siebie');
            http('POST', '/ustawienia/zespol/' . $member . '/usun');
            eq(1, (int) $db->val('SELECT COUNT(*) FROM users WHERE org_id = ?', [$orgId]));
            // Osoba z innej firmy jest poza zasięgiem.
            [$org2, $uid2] = make_org('obcy@example.com', 'Obca Firma sp. z o.o.');
            eq(404, http('POST', '/ustawienia/zespol/' . $uid2 . '/usun')->status);
            eq(404, http('POST', '/ustawienia/zespol/' . $uid2 . '/kody')->status);
            eq(1, (int) $db->val('SELECT COUNT(*) FROM users WHERE id = ?', [$uid2]));
        });
    },

    'Dane firmy: eksport bez sekretów i usunięcie konta firmy ze wszystkimi danymi, bez naruszenia innych firm' => function () {
        [$orgId, $uid] = make_org();
        [$org2, $uid2] = make_org('obcy@example.com', 'Obca Firma sp. z o.o.');
        $db = Db::get();
        with_config(['APP_SECRET' => 'sekret-testowy-0123456789'], function () use ($db, $orgId, $uid, $org2, $uid2) {
            // Dane w kilku modułach obu firm.
            foreach ([[$orgId, $uid, 'Mój Kontakt sp. z o.o.'], [$org2, $uid2, 'Cudzy Kontakt sp. z o.o.']] as [$o, $u, $name]) {
                $db->insert('contacts', ['org_id' => $o, 'company_name' => $name, 'email' => 'biuro@' . $o . '.example', 'basis' => 'none', 'created_at' => now_utc()]);
                Sites::add($o, 'witryna-' . $o . '.example');
                Secret::put($o, 'cloudflare', 'tajny-token-' . $o, [], $u);
                Inbound::widget($o);
            }
            ok(in_array('contacts', OrgData::tables(), true) && in_array('seo_sites', OrgData::tables(), true) && in_array('users', OrgData::tables(), true) && !in_array('tenders', OrgData::tables(), true), 'tabele firmy bierzemy z bazy');

            login_as($uid);
            $r = http('GET', '/ustawienia/dane-firmy.zip');
            eq(200, $r->status);
            contains('dane-firmy-', $r->headers['Content-Disposition'] ?? '');
            $path = tempnam(sys_get_temp_dir(), 'exp');
            file_put_contents($path, $r->body);
            $zip = new ZipArchive();
            ok($zip->open($path) === true, 'poprawna paczka ZIP');
            $all = '';
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $all .= $zip->getFromIndex($i);
            }
            contains('Mój Kontakt sp. z o.o.', (string) $zip->getFromName('contacts.json'));
            contains('wlasciciel@example.com', (string) $zip->getFromName('users.json'));
            contains('witryna-' . $orgId . '.example', (string) $zip->getFromName('seo_sites.json'));
            ok($zip->getFromName('firma.json') !== false && $zip->getFromName('CZYTAJ.txt') !== false);
            ok(!str_contains($all, 'Cudzy Kontakt') && !str_contains($all, 'obcy@example.com'), 'bez danych innej firmy');
            foreach (['password_hash', 'value_enc', 'totp_secret', 'recovery_json', '$argon2', 'tajny-token'] as $secret) {
                ok(!str_contains($all, $secret), 'w eksporcie nie ma: ' . $secret);
            }
            $zip->close();

            // Usunięcie: złe hasło albo zła nazwa niczego nie kasują.
            http('POST', '/ustawienia/usun-firme', ['current' => 'zle-haslo-zle', 'confirm' => 'Firma Testowa sp. z o.o.']);
            http('POST', '/ustawienia/usun-firme', ['current' => 'haslo-testowe-1', 'confirm' => 'Inna nazwa']);
            eq(2, (int) $db->val('SELECT COUNT(*) FROM organizations'));
            // Firma prowadząca panel nie usuwa się z panelu.
            with_config(['ADMIN_EMAILS' => 'wlasciciel@example.com'], function () use ($db) {
                http('POST', '/ustawienia/usun-firme', ['current' => 'haslo-testowe-1', 'confirm' => 'Firma Testowa sp. z o.o.']);
                eq(2, (int) $db->val('SELECT COUNT(*) FROM organizations'));
            });
            $r = http('POST', '/ustawienia/usun-firme', ['current' => 'haslo-testowe-1', 'confirm' => ' firma testowa sp. z o.o. ']);
            eq([303, '/logowanie'], [$r->status, $r->headers['Location'] ?? '']);
            ok(!Auth::check(), 'po usunięciu nie ma sesji');
            foreach (OrgData::tables() as $table) {
                // Wpis o samym usunięciu zostaje w dzienniku, bez danych firmy poza nazwą.
                $left = (int) $db->val("SELECT COUNT(*) FROM $table WHERE org_id = ?" . ($table === 'audit_log' ? " AND action <> 'org.deleted'" : ''), [$orgId]);
                eq(0, $left, 'zostały dane w tabeli ' . $table);
            }
            eq([0, 1], [(int) $db->val('SELECT COUNT(*) FROM organizations WHERE id = ?', [$orgId]), (int) $db->val('SELECT COUNT(*) FROM organizations WHERE id = ?', [$org2])]);
            eq(['Cudzy Kontakt sp. z o.o.', 'tajny-token-' . $org2, 1], [$db->val('SELECT company_name FROM contacts WHERE org_id = ?', [$org2]), Secret::get($org2, 'cloudflare'), (int) $db->val('SELECT COUNT(*) FROM seo_sites WHERE org_id = ?', [$org2])], 'druga firma nietknięta');
            eq(false, Auth::attempt('wlasciciel@example.com', 'haslo-testowe-1', '10.0.0.2')['ok'], 'konto usuniętej firmy nie istnieje');
        });
    },

    'Izolacja firm na całym panelu: adresy z danymi jednej firmy nie otwierają się drugiej' => function () {
        require_once APP_ROOT . '/tests/demo_seed.php';
        $seed = demo_seed();
        ok($seed['ok'], 'dane przykładowe');
        $db = Db::get();
        $demoUid = (int) $db->val('SELECT id FROM users WHERE email = ?', ['demo@example.com']);
        [$org2, $uid2] = make_org('obcy@example.com', 'Obca Firma sp. z o.o.');

        // Firma z danymi: przechodzimy po panelu i zbieramy adresy z identyfikatorami.
        login_as($demoUid);
        $queue = ['/pulpit', '/zapytania', '/klienci', '/klienci/potencjalni', '/klienci/potencjalni?stan=picked', '/klienci/zapytania', '/wiadomosci', '/audyt', '/audyt/tresc', '/audyt/widocznosc?lista=1',
            '/przetargi', '/oferty', '/oferty/nabory', '/kontrahenci', '/biblioteka', '/ustawienia'];
        $seen = [];
        $withIds = [];
        while ($queue && count($seen) < 220) {
            $path = array_shift($queue);
            if (isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;
            [$p, $q] = array_pad(explode('?', $path, 2), 2, '');
            parse_str($q, $query);
            $res = http('GET', $p, [], $query);
            if ($res->status !== 200 || !str_contains((string) ($res->headers['Content-Type'] ?? ''), 'text/html')) {
                continue;
            }
            if (preg_match('#/\d+(/|$)#', $p)) {
                $withIds[$p] = true;
            }
            preg_match_all('#href="(/[^"\#]*)#', $res->body, $m);
            foreach ($m[1] as $href) {
                $href = html_entity_decode($href);
                if (!str_starts_with($href, '//') && !isset($seen[$href]) && !preg_match('#^/(wyloguj|assets|w/|z/|raport/|wypisz/|r/)#', $href)) {
                    $queue[] = $href;
                }
            }
        }
        // Przetargi i karty firm z rejestrów to dane publiczne, wspólne dla wszystkich. Reszta należy do firmy.
        $private = array_values(array_filter(array_keys($withIds), static fn (string $p): bool => !preg_match('#^/(przetargi|kontrahenci)/#', $p)));
        ok(count($seen) >= 60 && count($private) >= 12, 'przeszliśmy panel: ' . count($seen) . ' stron, ' . count($private) . ' adresów z danymi firmy');

        login_as($uid2);
        $open = [];
        foreach ($private as $p) {
            $res = http('GET', $p);
            if ($res->status === 200) {
                $open[] = $p;
            }
        }
        eq([], $open, 'adresy drugiej firmy otwarte dla obcej');
        // Publiczne karty nie pokazują prywatnych wpisów cudzej firmy (sprawdzenia KRZ, należności, notatki).
        foreach (array_keys($withIds) as $p) {
            if (preg_match('#^/kontrahenci/\d{10}$#', $p)) {
                $body = http('GET', $p)->body;
                ok(!str_contains($body, 'Twoja Firma Drogowa') && !preg_match('#Sprawdzono w KRZ \d#', $body), 'karta firmy bez cudzych wpisów: ' . $p);
            }
        }
    },

    'Przetargi: jeden klik dodaje przetarg do zakładki Oferty, bez duplikatów i bez AI' => function () {
        [$orgId, $uid] = make_org();
        $db = Db::get();
        import_fixtures();
        login_as($uid);
        $tenders = $db->all('SELECT id, title FROM tenders ORDER BY id LIMIT 3');
        [$a, $b] = [(int) $tenders[0]['id'], (int) $tenders[1]['id']];
        $profile = $db->insert('tender_profiles', ['org_id' => $orgId, 'name' => 'Drogi', 'cpv' => '45233', 'active' => 1, 'created_at' => now_utc()]);
        $db->insert('tender_matches', ['org_id' => $orgId, 'tender_id' => $b, 'profile_id' => $profile, 'score' => 80, 'status' => 'new', 'reasons_json' => '[]', 'created_at' => now_utc()]);

        // Lista ma przycisk przy każdym przetargu.
        $list = http('GET', '/przetargi/szukaj')->body;
        contains('action="/przetargi/' . $a . '/do-ofert"', $list);
        Http::$log = [];
        $r = http('POST', '/przetargi/' . $a . '/do-ofert');
        eq(303, $r->status);
        $offer = $db->one('SELECT * FROM offers WHERE org_id = ? AND tender_id = ?', [$orgId, $a]);
        ok($offer !== null && str_starts_with((string) $offer['title'], 'Oferta: '), 'szkic oferty powstał');
        eq([], ai_calls(), 'szkic powstaje z ogłoszenia i biblioteki, bez AI');
        contains('href="/oferty/' . (int) $offer['id'] . '">Oferta: ' . e(mb_substr((string) $tenders[0]['title'], 0, 40)), http('GET', '/oferty')->body, 'przetarg jest w zakładce Oferty');
        $list = http('GET', '/przetargi/szukaj')->body;
        contains('<a class="chip low" href="/oferty/' . (int) $offer['id'] . '">w ofertach</a>', $list);
        ok(!str_contains($list, 'action="/przetargi/' . $a . '/do-ofert"'), 'przycisk zastąpiony znacznikiem');
        // Drugi klik nie tworzy drugiego szkicu.
        http('POST', '/przetargi/' . $a . '/do-ofert');
        eq(1, (int) $db->val('SELECT COUNT(*) FROM offers WHERE org_id = ? AND tender_id = ?', [$orgId, $a]));

        // „Zapisz” na karcie przetargu robi to samo: zapisuje i dodaje do ofert.
        contains('Zapisz i dodaj do ofert', http('GET', '/przetargi/' . $b)->body);
        http('POST', '/przetargi/' . $b . '/status', ['status' => 'saved']);
        eq(['saved', 1], [$db->val('SELECT status FROM tender_matches WHERE org_id = ? AND tender_id = ?', [$orgId, $b]), (int) $db->val('SELECT COUNT(*) FROM offers WHERE org_id = ? AND tender_id = ?', [$orgId, $b])]);
        contains('Otwórz szkic oferty', http('GET', '/przetargi/' . $b)->body);
        http('POST', '/przetargi/' . $b . '/status', ['status' => 'dismissed']);
        http('POST', '/przetargi/' . $b . '/status', ['status' => 'saved']);
        eq(1, (int) $db->val('SELECT COUNT(*) FROM offers WHERE org_id = ? AND tender_id = ?', [$orgId, $b]), 'ponowne zapisanie nie dubluje szkicu');
        eq(404, http('POST', '/przetargi/999999/do-ofert')->status);

        // Druga firma ma własne oferty: cudzych nie widzi i dostaje własny szkic.
        [$org2, $uid2] = make_org('obcy@example.com', 'Obca Firma sp. z o.o.');
        login_as($uid2);
        ok(!str_contains(http('GET', '/przetargi/szukaj')->body, 'w ofertach'), 'znacznik tylko dla własnych ofert');
        eq(404, http('GET', '/oferty/' . (int) $offer['id'])->status);
        http('POST', '/przetargi/' . $a . '/do-ofert');
        eq([1, 1], [(int) $db->val('SELECT COUNT(*) FROM offers WHERE org_id = ? AND tender_id = ?', [$org2, $a]), (int) $db->val('SELECT COUNT(*) FROM offers WHERE org_id = ? AND tender_id = ?', [$orgId, $a])]);
    },

    'Widoczność: każda firma łączy własne konto Google i własny token Cloudflare, a cudzych połączeń nie użyje' => function () {
        [$orgId, $uid] = make_org();
        $db = Db::get();
        $cfg = ['APP_SECRET' => 'sekret-testowy-0123456789', 'GOOGLE_OAUTH_CLIENT_ID' => 'klient-testowy.apps.googleusercontent.com', 'GOOGLE_OAUTH_CLIENT_SECRET' => 'sekret-klienta',
            'GOOGLE_SERVICE_ACCOUNT' => '', 'CF_ANALYTICS_TOKEN' => '', 'CF_BROWSER_TOKEN' => ''];
        with_config($cfg, function () use ($db, $orgId, $uid) {
            login_as($uid);
            $siteId = (int) Sites::add($orgId, 'sklep-przykladowy.example')['id'];
            $base = '/audyt/widocznosc/' . $siteId;
            $site = static fn (): array => Sites::find($orgId, $siteId);
            $auth = static fn (string $host): array => array_values(array_unique(array_map(static fn (array $l): string => (string) ($l['headers']['Authorization'] ?? ''),
                array_filter(Http::$log, static fn (array $l): bool => str_contains($l['url'], $host)))));
            /** Przejście do Google: zwraca jednorazowy znacznik z adresu ekranu zgody. */
            $start = static function () use ($siteId): array {
                $r = http('GET', '/audyt/widocznosc/google/polacz', [], ['witryna' => $siteId]);
                parse_str((string) parse_url((string) ($r->headers['Location'] ?? ''), PHP_URL_QUERY), $q);
                return [$r, $q];
            };

            // Start: jeden przycisk. Panel prosi tylko o odczyt i nie pokazuje adresu konta usługi.
            ok(GoogleOAuth::enabled() && Google::mode($orgId) === null && !Sync::googleOn($orgId));
            $page = http('GET', $base)->body;
            contains('href="/audyt/widocznosc/google/polacz?witryna=' . $siteId . '"', $page);
            contains('Panel prosi tylko o odczyt', $page);
            [$r, $q] = $start();
            eq(303, $r->status);
            ok(str_starts_with((string) $r->headers['Location'], 'https://accounts.google.com/o/oauth2/v2/auth?'), 'ekran zgody Google');
            eq(['klient-testowy.apps.googleusercontent.com', 'code', 'offline', 'consent', 'https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/analytics.readonly'],
                [$q['client_id'], $q['response_type'], $q['access_type'], $q['prompt'], $q['scope']]);
            ok(str_ends_with((string) $q['redirect_uri'], '/audyt/widocznosc/google/powrot') && strlen((string) $q['state']) === 48, 'adres powrotu i jednorazowy znacznik');
            ok(!str_contains((string) $r->headers['Location'], 'sekret-klienta'), 'tajny klucz klienta nie trafia do przeglądarki');

            // Powrót z podrobionym znacznikiem: nic nie wychodzi do Google i nic się nie zapisuje. Znacznik jest jednorazowy.
            Http::$log = [];
            http('GET', '/audyt/widocznosc/google/powrot', [], ['state' => 'podrobiony', 'code' => 'kod-testowy']);
            eq([[], false], [Http::$log, GoogleOAuth::connected($orgId)]);
            contains('Łączenie z Google wygasło albo zaczęło się w innym oknie', http('GET', $base . '/dane')->body);
            http('GET', '/audyt/widocznosc/google/powrot', [], ['state' => $q['state'], 'code' => 'kod-testowy']);
            eq([[], false], [Http::$log, GoogleOAuth::connected($orgId)], 'po nieudanej próbie stary znacznik już nie działa');
            // Przerwanie na ekranie Google, odrzucony kod, zgoda bez Search Console.
            [, $q] = $start();
            http('GET', '/audyt/widocznosc/google/powrot', [], ['state' => $q['state'], 'error' => 'access_denied']);
            contains('Łączenie przerwane na ekranie Google. Nic się nie zmieniło.', http('GET', $base . '/dane')->body);
            [, $q] = $start();
            http('GET', '/audyt/widocznosc/google/powrot', [], ['state' => $q['state'], 'code' => 'zly-kod']);
            contains('Google nie potwierdził połączenia', http('GET', $base . '/dane')->body);
            [, $q] = $start();
            http('GET', '/audyt/widocznosc/google/powrot', [], ['state' => $q['state'], 'code' => 'kod-bez-search-console']);
            contains('nie zaznaczono dostępu do Search Console', http('GET', $base . '/dane')->body);
            // Znacznik po terminie.
            [, $q] = $start();
            $_SESSION['google_oauth']['at'] = time() - 3600;
            http('GET', '/audyt/widocznosc/google/powrot', [], ['state' => $q['state'], 'code' => 'kod-testowy']);
            ok(!GoogleOAuth::connected($orgId), 'znacznik po terminie');
            // Znacznik innej firmy: osoba z drugiej firmy nie dokończy cudzego łączenia.
            [$org2, $uid2] = make_org('obcy@example.com', 'Obca Firma sp. z o.o.');
            [, $q] = $start();
            $pending = $_SESSION['google_oauth'];
            login_as($uid2);
            $_SESSION['google_oauth'] = $pending;
            http('GET', '/audyt/widocznosc/google/powrot', [], ['state' => $q['state'], 'code' => 'kod-testowy']);
            ok(!GoogleOAuth::connected($orgId) && !GoogleOAuth::connected($org2), 'znacznik jest przypisany do firmy');
            login_as($uid);

            // Udane połączenie: stały dostęp zaszyfrowany w bazie, a dane pobrane od razu tokenem tej firmy.
            [, $q] = $start();
            Http::$log = [];
            $r = http('GET', '/audyt/widocznosc/google/powrot', [], ['state' => $q['state'], 'code' => 'kod-testowy']);
            eq([303, $base], [$r->status, $r->headers['Location'] ?? '']);
            ok(GoogleOAuth::connected($orgId) && Google::mode($orgId) === 'oauth' && Sync::googleOn($orgId));
            $stored = (string) $db->val("SELECT value_enc FROM org_secrets WHERE org_id = ? AND name = 'google'", [$orgId]);
            ok(str_starts_with($stored, 'v1:') && !str_contains($stored, 'staly-dostep'), 'stały dostęp jest zaszyfrowany');
            eq('1//staly-dostep-firmy', Secret::get($orgId, 'google'));
            ok(!str_contains((string) json_encode($db->all("SELECT v FROM kv WHERE k LIKE 'google:oauth:%'")), 'ya29.'), 'token dostępu w pamięci podręcznej też jest zaszyfrowany');
            $exchange = Http::$log[0];
            eq('https://oauth2.googleapis.com/token', $exchange['url']);
            foreach (['grant_type=authorization_code', 'code=kod-testowy', 'client_secret=sekret-klienta', 'redirect_uri=' . rawurlencode((string) $q['redirect_uri'])] as $part) {
                contains($part, $exchange['body']);
            }
            eq(['Bearer ya29.oauth-pierwszy'], $auth('googleapis.com/webmasters'), 'Search Console pytamy tokenem połączonego konta');
            eq(['Bearer ya29.oauth-pierwszy'], $auth('analytics'), 'Analytics też');
            ok(Stats::has($siteId, 'gsc') && Stats::has($siteId, 'ga4_ai'), 'dane są w panelu zaraz po powrocie z Google');
            eq('sc-domain:sklep-przykladowy.example', $site()['gsc_property'], 'usługę panel znalazł sam');
            $page = http('GET', $base . '/dane')->body;
            contains('Połączenie z Google działa', $page);
            contains('konto Google połączone przez: Jan Test', $page);
            contains('Odłącz konto Google', $page);
            ok(!str_contains($page, 'staly-dostep') && !str_contains($page, 'ya29.'), 'tokenów nie ma na stronie');
            eq(1, (int) $db->val("SELECT COUNT(*) FROM audit_log WHERE action = 'seo.google_connected' AND org_id = ?", [$orgId]));

            // Po godzinie panel odnawia dostęp sam. Zadanie dzienne pobiera dane tej firmy jej tokenem.
            Kv::forget('google:oauth:' . $orgId);
            Http::$log = [];
            eq(['sites' => 1, 'google' => 1, 'cloudflare' => 0, 'maps' => 0, 'errors' => 0], Sync::daily());
            contains('grant_type=refresh_token&refresh_token=' . rawurlencode('1//staly-dostep-firmy'), Http::$log[0]['body']);
            eq(['Bearer ya29.oauth-odswiezony'], $auth('googleapis.com/webmasters'));

            // Druga firma z tą samą domeną: nie ma połączenia i nie użyje cudzego.
            $foreign = (int) Sites::add($org2, 'sklep-przykladowy.example')['id'];
            eq([null, false], [Google::mode($org2), Sync::googleOn($org2)]);
            login_as($uid2);
            Http::$log = [];
            http('POST', '/audyt/widocznosc/' . $foreign . '/pobierz', ['zrodlo' => 'google']);
            eq([], Http::$log, 'żadnego zapytania do Google');
            ok(!Stats::has($foreign, 'gsc'), 'cudze dane nie trafiły do drugiej firmy');
            contains('Najpierw połącz konto Google', http('GET', '/audyt/widocznosc/' . $foreign . '/dane')->body);
            // Odłączenie cudzej witryny albo cudzego konta nie działa: druga firma odłącza najwyżej siebie.
            http('POST', '/audyt/widocznosc/google/odlacz', ['witryna' => $siteId]);
            ok(GoogleOAuth::connected($orgId), 'połączenie pierwszej firmy zostaje');
            login_as($uid);

            // Członek zespołu widzi stan, ale nie łączy i nie odłącza.
            $member = $db->insert('users', ['org_id' => $orgId, 'email' => 'czlonek@example.com', 'name' => 'Członek', 'password_hash' => 'x', 'role' => 'member', 'created_at' => now_utc()]);
            login_as($member);
            ok(!str_contains(http('GET', $base . '/dane')->body, 'Odłącz konto Google'));
            http('POST', '/audyt/widocznosc/google/odlacz', ['witryna' => $siteId]);
            http('POST', '/audyt/widocznosc/cloudflare', ['witryna' => $siteId, 'token' => 'cfut_' . str_repeat('a1B2', 9)]);
            ok(GoogleOAuth::connected($orgId) && Secret::get($orgId, 'cloudflare') === null, 'tylko właściciel zmienia połączenia');
            $r = http('GET', '/audyt/widocznosc/google/polacz', [], ['witryna' => $siteId]);
            eq($base . '/dane', $r->headers['Location'] ?? '');
            login_as($uid);

            // Dostęp cofnięty w koncie Google: panel mówi, co zrobić, i nie trzyma nieważnego tokenu.
            Kv::forget('google:oauth:' . $orgId);
            with_config(['FIXTURE_SEO' => 'oauth-cofniety'], function () use ($orgId, $site) {
                $res = Sync::google($site());
                eq(false, $res['ok']);
                contains('Połączenie z Google wygasło albo zostało cofnięte', $res['errors'][0]);
                ok(!GoogleOAuth::connected($orgId), 'nieważny dostęp usunięty');
            });
            ok(Stats::has($siteId, 'gsc'), 'wczytane dane zostają');

            // Ponowne połączenie i odłączenie przez właściciela: cofnięcie po stronie Google, czysty stan w panelu.
            [, $q] = $start();
            http('GET', '/audyt/widocznosc/google/powrot', [], ['state' => $q['state'], 'code' => 'kod-testowy']);
            ok(GoogleOAuth::connected($orgId) && (Sites::lastSync($site(), 'google')['ok'] ?? false));
            Http::$log = [];
            http('POST', '/audyt/widocznosc/google/odlacz', ['witryna' => $siteId]);
            eq(['https://oauth2.googleapis.com/revoke', 'token=' . rawurlencode('1//staly-dostep-firmy')], [Http::$log[0]['url'], Http::$log[0]['body']]);
            eq([false, null, null, null], [GoogleOAuth::connected($orgId), Secret::get($orgId, 'google'), Sites::lastSync($site(), 'google'), Kv::get('google:oauth:' . $orgId)]);
            $page = http('GET', $base . '/dane')->body;
            contains('Połącz z Google', $page);
            ok(!str_contains($page, 'Połączenie z Google działa'), 'panel nie pokazuje połączenia, którego już nie ma');
            ok(Stats::has($siteId, 'gsc'), 'dane wczytane wcześniej zostają');

            // Cloudflare: firma wkleja własny token. Zły format niczego nie zapisuje.
            contains('name="token"', $page);
            http('POST', '/audyt/widocznosc/cloudflare', ['witryna' => $siteId, 'token' => 'za krótki']);
            eq([null, false], [Secret::get($orgId, 'cloudflare'), Sync::cloudflareOn($orgId)]);
            $token = 'cfut_' . str_repeat('a1B2', 9);
            Http::$log = [];
            $r = http('POST', '/audyt/widocznosc/cloudflare', ['witryna' => $siteId, 'token' => ' ' . $token . ' ']);
            eq($base . '/ai', $r->headers['Location'] ?? '');
            eq(['Bearer ' . $token], $auth('api.cloudflare.com'), 'statystyki pobrane tokenem firmy');
            ok(Stats::has($siteId, 'cf_bot') && Sync::cloudflareOn($orgId));
            $stored = (string) $db->val("SELECT value_enc FROM org_secrets WHERE org_id = ? AND name = 'cloudflare'", [$orgId]);
            ok(str_starts_with($stored, 'v1:') && !str_contains($stored, $token), 'token w bazie jest zaszyfrowany');
            $page = http('GET', $base . '/dane')->body;
            contains('końcówka …' . substr($token, -4), $page);
            ok(!str_contains($page, $token) && !str_contains($page, 'name="token"'), 'tokenu nie ma na stronie');
            eq(['', false], [CloudflareBots::tokenFor($org2), Sync::cloudflareOn($org2)], 'druga firma nie korzysta z cudzego tokenu');
            eq(1, Sync::daily()['cloudflare'], 'zadanie dzienne odświeża dane tokenem firmy');
            // Token odrzucony przez Cloudflare: zapisany, ale panel mówi, jakiego uprawnienia brakuje.
            with_config(['FIXTURE_SEO' => 'cf-odmowa'], function () use ($siteId, $base, $token) {
                $r = http('POST', '/audyt/widocznosc/cloudflare', ['witryna' => $siteId, 'token' => $token]);
                eq($base . '/dane#cloudflare', $r->headers['Location'] ?? '');
                contains('Zone → Analytics → Read', http('GET', $base . '/dane')->body);
            });
            http('POST', '/audyt/widocznosc/cloudflare', ['witryna' => $siteId, 'usun' => '1']);
            eq(['', null], [CloudflareBots::tokenFor($orgId), Sites::lastSync($site(), 'cloudflare')]);
            ok(Stats::has($siteId, 'cf_bot'), 'dane wczytane wcześniej zostają');

            // Eksport danych firmy nie zawiera połączeń, a usunięcie firmy cofa dostęp w Google.
            [, $q] = $start();
            http('GET', '/audyt/widocznosc/google/powrot', [], ['state' => $q['state'], 'code' => 'kod-testowy']);
            ok(!str_contains(http('GET', '/ustawienia/dane-firmy.zip')->body, 'staly-dostep'));
            Http::$log = [];
            http('POST', '/ustawienia/usun-firme', ['current' => 'haslo-testowe-1', 'confirm' => 'Firma Testowa sp. z o.o.']);
            eq(0, (int) $db->val('SELECT COUNT(*) FROM organizations WHERE id = ?', [$orgId]));
            ok(in_array('https://oauth2.googleapis.com/revoke', array_column(Http::$log, 'url'), true), 'przy usunięciu firmy panel cofa dostęp do jej konta Google');
        });

        // Bez klienta OAuth nie ma przycisku ani przejścia do Google.
        [$org3, $uid3] = make_org('trzecia@example.com', 'Trzecia Firma sp. z o.o.');
        with_config(['APP_SECRET' => 'sekret-testowy-0123456789', 'GOOGLE_OAUTH_CLIENT_ID' => '', 'GOOGLE_SERVICE_ACCOUNT' => ''], function () use ($org3, $uid3) {
            login_as($uid3);
            $id = (int) Sites::add($org3, 'trzecia.example')['id'];
            ok(!GoogleOAuth::enabled() && !str_contains(http('GET', '/audyt/widocznosc/' . $id)->body, 'google/polacz'));
            $r = http('GET', '/audyt/widocznosc/google/polacz', [], ['witryna' => $id]);
            eq('/audyt/widocznosc/' . $id . '/dane', $r->headers['Location'] ?? '');
        });
    },
];
