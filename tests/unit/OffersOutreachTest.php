<?php

use App\Core\Db;
use App\Modules\Offers\Docx;
use App\Modules\Offers\Offers;
use App\Modules\Outreach\Outreach;
use App\Modules\Outreach\RiskPolicy;
use App\Modules\Tenders\Importer;

return [
    'Szkic oferty z ogłoszenia i biblioteki - znaczniki źródeł' => function () {
        [$org, $uid] = make_org();
        import_fixtures();
        Db::get()->insert('library_items', ['org_id' => $org, 'kind' => 'reference', 'title' => 'Przebudowa drogi gminnej w Przykładowie', 'body' => 'Roboty drogowe z chodnikiem', 'meta_json' => json_out(['client' => 'Gmina Przykładowo', 'value' => 1800000, 'finished' => '09.2025']), 'created_at' => now_utc(), 'updated_at' => now_utc()]);
        $tender = Db::get()->one("SELECT * FROM tenders WHERE source = 'bzp' ORDER BY id LIMIT 1");
        $id = Offers::create($org, $uid, $tender);
        $sections = Offers::sections($id);
        eq(array_keys(Offers::SECTIONS), array_column($sections, 'key'));
        $byKey = array_column($sections, 'body', 'key');
        contains('⟦źródło: ogłoszenie, pkt 6.4⟧', $byKey['formularz'], 'wadium ze źródłem');
        contains('Gmina Przykładowo', $byKey['doswiadczenie']);
        contains("1\u{00A0}800\u{00A0}000,00\u{00A0}zł", $byKey['doswiadczenie']);
        ok(Offers::unsourced($sections) >= 1, 'zdania do sprawdzenia oznaczone');
    },
    'Sekcja przez AI: zdania bez źródła dostają ⟦sprawdź⟧' => function () {
        [$org, $uid] = make_org();
        $id = Offers::create($org, $uid, null);
        $offer = Offers::find($org, $id);
        $section = Offers::sections($id)[1];
        $res = Offers::aiSection($org, $offer, $section);
        ok($res['ok'], $res['error'] ?? '');
        contains('⟦źródło: biblioteka: Metodyka robót drogowych⟧', $res['body']);
        contains('mas bitumicznych. ⟦sprawdź⟧', $res['body']);
    },
    'Wniosek o dotację ma własne sekcje' => function () {
        [$org, $uid] = make_org();
        $id = Offers::create($org, $uid, null, 'grant', ['call_name' => 'Nabór testowy']);
        $o = Offers::find($org, $id);
        eq('grant', $o['kind']);
        eq(array_keys(Offers::GRANT_SECTIONS), array_column(Offers::sections($id), 'key'));
    },
    'Eksport .docx: poprawne archiwum bez znaczników źródeł' => function () {
        $bin = Docx::build('Oferta', [['title' => 'Formularz', 'body' => "Cena 100 zł ⟦źródło: SWZ⟧\nZdanie ⟦sprawdź⟧ & <tag>"]]);
        $tmp = tempnam(sys_get_temp_dir(), 'dx');
        file_put_contents($tmp, $bin);
        $z = new ZipArchive();
        ok($z->open($tmp) === true, 'ZIP');
        $xml = (string) $z->getFromName('word/document.xml');
        $z->close();
        unlink($tmp);
        ok(simplexml_load_string($xml) !== false, 'poprawny XML');
        contains('Cena 100 zł', $xml);
        ok(!str_contains($xml, 'źródło'), 'bez znacznika źródła');
        contains('[SPRAWDŹ]', $xml);
        contains('&amp; &lt;tag&gt;', $xml);
    },
    'Ryzyko wiadomości zależy od rodzaju i podstawy kontaktu' => function () {
        $rfq = ['basis' => 'rfq', 'basis_detail' => 'zapytanie', 'unsubscribed_at' => null];
        $none = ['basis' => 'none', 'unsubscribed_at' => null];
        $consent = ['basis' => 'consent', 'basis_detail' => 'formularz', 'unsubscribed_at' => null];
        eq('low', RiskPolicy::assess('rfq_reply', $rfq)['level']);
        eq('high', RiskPolicy::assess('rfq_reply', $none)['level']);
        // Prośba o zgodę bez podstawy to też kontakt bez zgody: stanowisko Ministerstwa Cyfryzacji trafia na ekran ryzyka.
        $ask = RiskPolicy::assess('consent_request', $none);
        eq(['very_high', true], [$ask['level'], $ask['needs_ack']]);
        contains('interpelację nr 12337', implode(' ', $ask['law']));
        contains('list pocztą', implode(' ', $ask['reasons']));
        eq('low', RiskPolicy::assess('consent_request', $consent)['level']);
        // Adres opublikowany przez firmę do przesyłania ofert jest formą zgody, ale tylko w zakresie zaproszenia.
        $invite = ['basis' => 'invite', 'basis_detail' => '„Oferty handlowe prosimy kierować na adres oferty@firma.example”', 'basis_url' => 'https://firma.example/kontakt', 'unsubscribed_at' => null];
        foreach (['consented', 'cold_offer'] as $kind) {
            $a = RiskPolicy::assess($kind, $invite);
            eq(['low', false], [$a['level'], $a['needs_ack']], $kind);
            contains('opublikowała adres do przesyłania ofert', $a['lead']);
            contains('Zgoda obejmuje oferty, o jakie firma prosi', implode(' ', $a['reasons']));
            contains('Art. 398 ust. 2', implode(' ', $a['law']));
        }
        ok(RiskPolicy::assess('consented', ['unsubscribed_at' => '2026-11-01 10:00:00'] + $invite)['blocked'], 'sprzeciw blokuje także po zaproszeniu');
        eq('very_high', RiskPolicy::assess('cold_offer', $none)['level']);
        eq('low', RiskPolicy::assess('cold_offer', $consent)['level']);
        eq('very_high', RiskPolicy::assess('consented', $none)['level']);
        ok(RiskPolicy::assess('cold_offer', $none)['needs_ack']);
        ok(!RiskPolicy::assess('rfq_reply', $rfq)['needs_ack']);
        $unsub = ['unsubscribed_at' => '2026-11-01 10:00:00'] + $consent;
        ok(RiskPolicy::assess('consented', $unsub)['blocked'], 'sprzeciw blokuje marketing');
        ok(!RiskPolicy::assess('rfq_reply', $unsub)['blocked'], 'odpowiedź na zapytanie nadal możliwa');
    },
    'Prośba o zgodę nie zawiera oferty, a .eml jest poprawnie zakodowany' => function () {
        [$org] = make_org();
        $orgRow = Db::get()->one('SELECT * FROM organizations WHERE id = ?', [$org]);
        $d = Outreach::draft('consent_request', ['person' => 'Anna Nowak', 'basis_detail' => ''], $orgRow, ['name' => 'Jan']);
        contains('Dzień dobry, Anna Nowak', $d['body']);
        ok(!str_contains($d['body'], 'Cena'), 'bez cen');
        $eml = Outreach::eml(['email' => 'a@example.com', 'person' => 'Anna Nowak', 'subject' => 'Zażółć gęślą', 'body' => "Linia 1\nLinia 2"], 'jan@example.com', 'Jan Łoś');
        contains('Subject: =?UTF-8?B?' . base64_encode('Zażółć gęślą') . '?=', $eml);
        contains('X-Unsent: 1', $eml);
        contains(base64_encode("Linia 1\r\nLinia 2"), str_replace("\r\n", '', $eml));
    },
    'Wiadomość z listy kontaktów: rodzaj dobiera się sam do podstawy kontaktu' => function () {
        [$orgId, $uid] = make_org();
        login_as($uid);
        $db = App\Core\Db::get();
        $add = static fn (string $basis, array $extra = []): int => $db->insert('contacts', $extra + ['org_id' => $orgId, 'company_name' => 'Firma ' . $basis,
            'email' => $basis . '@example.com', 'basis' => $basis, 'created_at' => now_utc()]);
        $checked = static function (int $cid, array $query = []): string {
            $html = http('GET', '/klienci/' . $cid . '/wiadomosc', [], $query)->body;
            return preg_match('/name="kind" value="([a-z_]+)" checked/', $html, $m) ? $m[1] : '';
        };
        // Zapytanie ofertowe: odpowiedź na nie. Zgoda, klient i adres podany do ofert: oferta za zgodą. Bez podstawy: oferta bez zgody.
        eq('rfq_reply', $checked($add('rfq')));
        eq('consented', $checked($add('consent')));
        eq('consented', $checked($add('client')));
        eq('consented', $checked($add('invite')));
        eq('cold_offer', $checked($add('none')), 'prośba o zgodę nie jest domyślna, bo sama wymaga zgody');
        eq('consented', $checked($add('none', ['email' => 'widzet@example.com', 'marketing_consent_at' => now_utc()])), 'zgoda zaznaczona w widżecie też się liczy');
        // Rodzaj wybrany świadomie w adresie ma pierwszeństwo, a nieznany jest pomijany.
        $rfq = $add('rfq', ['email' => 'drugi@example.com']);
        eq('consent_request', $checked($rfq, ['kind' => 'consent_request']));
        eq('rfq_reply', $checked($rfq, ['kind' => 'zmyslony']));
        // Lista kontaktów prowadzi do wiadomości bez narzuconego rodzaju.
        contains('href="/klienci/' . $rfq . '/wiadomosc">Napisz</a>', http('GET', '/klienci')->body);
    },
];
