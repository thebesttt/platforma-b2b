<?php

use App\Core\Db;
use App\Core\Http;
use App\Core\Kv;
use App\Modules\Companies\Ceidg;
use App\Modules\Companies\Companies;
use App\Modules\Outreach\Prospects;

return [
    'CEIDG: wyszukiwanie po PKD i regionie, dodanie do listy z adresem z rejestru i obsługa błędów usługi' => function () {
        [$orgId, $uid] = make_org('wlasciciel@example.com', 'Firma Testowa sp. z o.o.');
        $db = Db::get();
        $db->update('organizations', ['nip' => '5260000005'], 'id = ?', [$orgId]);
        login_as($uid);
        $urls = static fn (): array => array_values(array_filter(array_column(Http::$log, 'url'), static fn ($u) => str_contains($u, 'dane.biznes.gov.pl')));

        // Bez tokenu moduł jest wyłączony: panel pokazuje, jak go zdobyć, i nie pyta CEIDG.
        with_config(['CEIDG_TOKEN' => ''], function () use ($urls) {
            ok(!Ceidg::enabled(), 'bez tokenu wyszukiwanie jest wyłączone');
            $page = http('GET', '/klienci/potencjalni', [], ['ceidg' => '1', 'pkd' => '4120Z'])->body;
            contains('Wniosek o dostęp do raportów CEIDG', $page);
            contains('CEIDG_TOKEN', $page);
            ok(!str_contains($page, 'name="pkd"'), 'bez tokenu nie ma formularza');
            eq(false, Ceidg::search(['pkd' => '4120Z'])['ok']);
            eq([], $urls());
        });

        with_config(['CEIDG_TOKEN' => ' token-testowy '], function () use ($orgId, $uid, $db, $urls) {
            // Zapis kodu PKD: z kropkami albo bez. Błędny kod, brak kodu i więcej niż pięć kodów nie wychodzą do API.
            eq(['6201Z', '4120Z', null, null], [Ceidg::pkd('62.01.Z'), Ceidg::pkd(' 4120z '), Ceidg::pkd('62.01'), Ceidg::pkd('budowa')]);
            contains('ma zły zapis', Ceidg::search(['pkd' => '41.20'])['error']);
            contains('co najmniej jeden kod PKD', Ceidg::search(['pkd' => ' , '])['error']);
            contains('Najwyżej 5 kodów', Ceidg::search(['pkd' => '4120Z 4211Z 4221Z 4299Z 4311Z 4312Z'])['error']);
            eq([], $urls());

            // Zapytanie według dokumentacji: tylko aktywne, kody bez kropek, województwo, miasto, data od, strona 25 wyników.
            $page = http('GET', '/klienci/potencjalni', [], ['ceidg' => '1', 'pkd' => '41.20.Z, 4331z', 'cwoj' => 'mazowieckie', 'miasto' => 'Piaseczno', 'od' => '90'])->body;
            $u = $urls()[0];
            contains('https://dane.biznes.gov.pl/api/ceidg/v3/firmy?status=AKTYWNY&pkd=4120Z&pkd=4331Z&wojewodztwo=MAZOWIECKIE&miasto=Piaseczno&dataod=' . date('Y-m-d', time() - 90 * 86400) . '&limit=25&page=0', $u);
            eq(1, count($urls()), 'jedno zapytanie na wyszukiwanie');

            // Wynik: dwie nowe firmy z adresem złożonym z pól rejestru. Zawieszona i z błędnym NIP-em pominięte.
            contains('<strong>3 firmy</strong> spoza Twojej listy', $page);
            contains('CEIDG ma 31 pasujących wpisów, pokazujemy pierwszą stronę', $page);
            contains('Usługi Budowlane Jan Kowalski', $page);
            contains('ul. Polna 3 lok. 5, 05-500 Piaseczno', $page);
            contains('Wólka 12, 21-100 Wólka', $page, 'wieś bez ulic i kod bez łącznika');
            contains('Sklep &lt;b&gt;Ogrodnik&lt;/b&gt; Ewa Zielona', $page);
            ok(!str_contains($page, '<b>Ogrodnik</b>') && !str_contains($page, 'Zawieszona Firma') && !str_contains($page, 'Błędny NIP'), 'nazwa jako tekst, bez nieaktywnych i błędnych');
            contains('ich dane chroni RODO', $page);
            ok(!preg_match('/@|tel\./', (string) json_encode(Kv::get('ceidg-wynik:' . $orgId))), 'z CEIDG nie bierzemy adresów e-mail ani telefonów');

            // Dodanie: tylko firmy z ostatniego wyniku (dane z rejestru, nie z formularza). Obcy NIP jest pomijany.
            eq(303, http('POST', '/klienci/potencjalni/z-ceidg', ['nips' => ['5252344078']])->status);
            eq(0, (int) $db->val('SELECT COUNT(*) FROM prospects'));
            eq(303, http('POST', '/klienci/potencjalni/z-ceidg', ['nips' => ['841-000-00-15', '8410000021', '5252344078']])->status);
            $rows = $db->all('SELECT * FROM prospects ORDER BY id');
            eq(2, count($rows));
            $p = $rows[0];
            eq(['Usługi Budowlane Jan Kowalski', '8410000015', 'ceidg', 'Piaseczno', 'mazowieckie', 'new', $uid], [$p['name'], $p['nip'], $p['source'], $p['city'], $p['voivodeship'], $p['status'], (int) $p['created_by']]);
            eq('Jan Kowalski', Prospects::facts($p)['ceidg']['owner']);
            $company = Companies::find((int) $p['company_id']);
            eq(['ul. Polna 3 lok. 5, 05-500 Piaseczno', Prospects::PERSON_FORM, '140000001'], [$company['address'], $company['legal_form'], $company['regon']]);
            ok(Prospects::isPerson($company), 'firma z CEIDG to osoba prowadząca działalność');
            // Nowa firma (20 dni) ma powód kontaktu, stara (2015) nie.
            eq([20, 'nowa firma: działa od 20 dni'], [json_in($p['score_json'])[4]['points'], json_in($p['score_json'])[4]['note']]);
            eq(0, json_in($rows[1]['score_json'])[4]['points']);

            // Kolejka rejestrów: firmy nie ma w wykazie VAT, a adres i forma z CEIDG zostają.
            Prospects::enrichPending($orgId);
            $company = Companies::find((int) $p['company_id']);
            eq(['ul. Polna 3 lok. 5, 05-500 Piaseczno', Prospects::PERSON_FORM], [$company['address'], $company['legal_form']], 'dane z CEIDG przetrwały pytanie do wykazu VAT');
            ok($db->val('SELECT enriched_at FROM prospects WHERE id = ?', [(int) $p['id']]) !== null, 'firma zeszła z kolejki');

            // Karta i list: źródło, termin na informację o danych, list z informacją RODO i otwarciem dla nowej firmy.
            $card = http('GET', '/klienci/potencjalni/' . $p['id'])->body;
            contains('źródło: CEIDG', $card);
            contains('To osoba prowadząca działalność', $card);
            contains('Wyślij list pocztą z prośbą o zgodę na e-mail', $card);
            $letter = html_entity_decode(http('GET', '/klienci/potencjalni/' . $p['id'] . '/list')->body);
            contains('ul. Polna 3 lok. 5, 05-500 Piaseczno', $letter);
            contains('piszemy, ponieważ niedawno rozpoczęli Państwo działalność.', $letter);
            contains('Informacja o danych osobowych: administratorem danych jest Firma Testowa sp. z o.o.', $letter);
            ok(!str_contains(html_entity_decode(http('GET', '/klienci/potencjalni/' . $rows[1]['id'] . '/list')->body), 'niedawno rozpoczęli'), 'stara firma nie dostaje zdania o nowej działalności');

            // Ponowne wyszukiwanie: firmy z listy nie wracają, panel mówi, ile ich było. Powtórne dodanie nic nie robi.
            $page = http('GET', '/klienci/potencjalni', [], ['ceidg' => '1', 'pkd' => '4120Z'])->body;
            contains('<strong>1 firma</strong> spoza Twojej listy', $page);
            contains('Na liście masz już 2 z tej strony wyników', $page);
            http('POST', '/klienci/potencjalni/z-ceidg', ['nips' => ['8410000015']]);
            eq(2, (int) $db->val('SELECT COUNT(*) FROM prospects'));

            // Firma znana z wykazu VAT: wpis z rejestrów ma pierwszeństwo, CEIDG uzupełnia tylko braki.
            http('POST', '/klienci/potencjalni/z-ceidg', ['nips' => ['1234563218']]);
            $third = $db->one("SELECT * FROM prospects WHERE nip = '1234563218'");
            Prospects::enrichPending($orgId);
            $known = Companies::byNip('1234563218');
            contains('PRZYKŁADOWA', $known['name']);
            eq((int) $known['id'], (int) $db->val('SELECT company_id FROM prospects WHERE id = ?', [(int) $third['id']]));

            // Brak wyników: przy województwie panel próbuje drugiego zapisu nazwy. Pusty wynik to nie błąd.
            Http::$log = [];
            $page = http('GET', '/klienci/potencjalni', [], ['ceidg' => '1', 'pkd' => '4120Z', 'cwoj' => 'lubelskie'])->body;
            $two = $urls();
            eq(2, count($two));
            contains('wojewodztwo=LUBELSKIE', $two[0]);
            contains('wojewodztwo=lubelskie', $two[1]);
            contains('3 już jest na Twojej liście', $page);
            Http::$log = [];
            contains('Przy tych filtrach CEIDG nie ma nowych firm', http('GET', '/klienci/potencjalni', [], ['ceidg' => '1', 'pkd' => '9999Z'])->body);
            eq(1, count($urls()), 'bez województwa nie ma drugiego podejścia');

            // Błędy usługi: zły token, limit (z przerwą 3 minuty bez kolejnych zapytań) i awaria.
            contains('CEIDG odrzucił token.', http('GET', '/klienci/potencjalni', [], ['ceidg' => '1', 'pkd' => '4120Z', 'miasto' => 'Odmowa'])->body);
            contains('CEIDG nie odpowiada.', http('GET', '/klienci/potencjalni', [], ['ceidg' => '1', 'pkd' => '4120Z', 'miasto' => 'Awaria'])->body);
            contains('CEIDG wstrzymał zapytania', http('GET', '/klienci/potencjalni', [], ['ceidg' => '1', 'pkd' => '4120Z', 'miasto' => 'Limit'])->body);
            Http::$log = [];
            contains('CEIDG wstrzymał zapytania', http('GET', '/klienci/potencjalni', [], ['ceidg' => '1', 'pkd' => '4120Z'])->body);
            eq([], $urls(), 'po przekroczeniu limitu panel sam wstrzymuje zapytania');
            Kv::forget('ceidg:blocked');

            // Własny licznik: po 40 zapytaniach w oknie 3 minut panel odmawia, zanim zrobi to CEIDG.
            Kv::set('ceidg:okno:' . intdiv(time(), 180), 40, 180);
            contains('50 zapytań w 3 minuty', Ceidg::search(['pkd' => '4120Z'])['error']);
            Kv::forget('ceidg:okno:' . intdiv(time(), 180));

            // Ustawienia: test połączenia dostępny dla administratora platformy.
            with_config(['ADMIN_EMAILS' => 'wlasciciel@example.com'], function () {
                eq(303, http('POST', '/ustawienia/test-ceidg')->status);
                contains('CEIDG działa: token przyjęty.', http('GET', '/ustawienia')->body);
            });
            eq(404, http('POST', '/ustawienia/test-ceidg')->status, 'test tylko dla administratora platformy');

            // Druga firma w panelu ma własny wynik wyszukiwania: nie doda firm z cudzego.
            [$org2, $uid2] = make_org('druga@example.com', 'Druga sp. z o.o.');
            login_as($uid2);
            http('POST', '/klienci/potencjalni/z-ceidg', ['nips' => ['8410000015']]);
            eq(0, (int) $db->val('SELECT COUNT(*) FROM prospects WHERE org_id = ?', [$org2]));
            // Własna firma nie trafia do wyników (druga firma testowa ma NIP jednego z wpisów).
            eq('1234563218', $db->val('SELECT nip FROM organizations WHERE id = ?', [$org2]));
            contains('<strong>2 firmy</strong> spoza Twojej listy', http('GET', '/klienci/potencjalni', [], ['ceidg' => '1', 'pkd' => '4120Z'])->body);
        });
    },
];
