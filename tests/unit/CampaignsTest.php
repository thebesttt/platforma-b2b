<?php

use App\Core\Auth;
use App\Core\Db;
use App\Core\Http;
use App\Core\Kv;
use App\Modules\Outreach\Campaigns;

/**
 * Pięć kontaktów: zgoda (z projektem UE na liście potencjalnych), klient (z zapytaniem ze strony), bez podstawy,
 * wypisany i zapytanie ofertowe bez zgody na oferty.
 * @return array{0: int, 1: int, 2: array<string, int>}
 */
$setup = static function (): array {
    [$org, $uid] = make_org();
    $db = Db::get();
    $add = static fn (array $row): int => $db->insert('contacts', $row + ['org_id' => $org, 'created_at' => now_utc(), 'basis' => 'none']);
    $ids = [
        'alfa' => $add(['company_name' => 'Alfa sp. z o.o.', 'email' => 'anna@alfa.example', 'person' => 'Anna Nowak', 'basis' => 'consent', 'basis_detail' => 'formularz na targach', 'basis_at' => '2026-10-01']),
        'beta' => $add(['company_name' => 'Beta S.A.', 'email' => 'biuro@beta.example', 'basis' => 'client']),
        'gamma' => $add(['company_name' => 'Gamma sp. z o.o.', 'email' => 'kontakt@gamma.example']),
        'delta' => $add(['company_name' => 'Delta sp. z o.o.', 'email' => 'delta@delta.example', 'basis' => 'consent', 'unsubscribed_at' => now_utc()]),
        'epsilon' => $add(['company_name' => 'Epsilon sp. z o.o.', 'email' => 'zakupy@epsilon.example', 'basis' => 'rfq', 'basis_detail' => 'Dostawa 20 laptopów dla biura']),
    ];
    $db->insert('prospects', ['org_id' => $org, 'contact_id' => $ids['alfa'], 'name' => 'Alfa sp. z o.o.', 'search' => 'alfa', 'source' => 'eu', 'status' => 'contacted',
        'facts_json' => json_out(['eu' => ['title' => 'Wdrożenie sklepu internetowego', 'program' => 'FEdŁ', 'end' => gmdate('Y-m-d', time() + 300 * 86400)]]),
        'created_at' => now_utc(), 'updated_at' => now_utc()]);
    $db->insert('inbound_leads', ['org_id' => $org, 'score' => 80, 'level' => 'hot', 'email' => 'BIURO@beta.example', 'summary' => "Potrzeba: nowa strona internetowa.\nBudżet: 20 tys.", 'status' => 'new', 'created_at' => '2026-11-02 10:00:00']);
    return [$org, $uid, $ids];
};

$campaign = static function (int $org, int $uid, array $ids, array $who = ['alfa', 'beta', 'gamma', 'delta', 'epsilon']): array {
    $id = Campaigns::create($org, $uid, 'Przegląd stron', 'Propozycja dla {firma}', Campaigns::defaultBody());
    $c = Campaigns::find($org, $id);
    $res = Campaigns::setRecipients($org, $c, array_map(static fn ($k) => $ids[$k], $who), Db::get()->one('SELECT * FROM organizations WHERE id = ?', [$org]), Db::get()->one('SELECT * FROM users WHERE id = ?', [$uid]));
    return [$c, $res];
};

$version = static fn (int $campaignId, int $contactId): array => Db::get()->one('SELECT * FROM campaign_recipients WHERE campaign_id = ? AND contact_id = ?', [$campaignId, $contactId]) ?? [];

return [
    'Kampanie: każdy odbiorca dostaje wersję z faktów o swojej firmie' => function () use ($setup, $campaign, $version) {
        [$org, $uid, $ids] = $setup();
        [$c] = $campaign($org, $uid, $ids);
        $alfa = $version((int) $c['id'], $ids['alfa']);
        eq('Propozycja dla Alfa sp. z o.o.', $alfa['subject']);
        contains("Dzień dobry, Anna Nowak,\n\nWidzimy, że realizują Państwo projekt „Wdrożenie sklepu internetowego” z funduszy europejskich.", $alfa['body']);
        contains("Z poważaniem\nJan Test\nFirma Testowa sp. z o.o.", $alfa['body'], 'podpis z konta i firmy');
        eq('eu', json_in($alfa['facts_json'])['reason_source']);
        contains('Nawiązujemy do Państwa zapytania z 02.11.2026: nowa strona internetowa.', $version((int) $c['id'], $ids['beta'])['body'], 'zapytanie ze strony z tym samym adresem');
        contains('Nawiązujemy do Państwa zapytania ofertowego: Dostawa 20 laptopów dla biura.', $version((int) $c['id'], $ids['epsilon'])['body']);
        $gamma = $version((int) $c['id'], $ids['gamma'])['body'];
        ok(!str_contains($gamma, '{') && str_starts_with($gamma, "Dzień dobry,\n\n[Napisz"), 'bez faktów linia {powod} znika: ' . $gamma);
        $footer = Campaigns::footer(Db::get()->one('SELECT * FROM contacts WHERE id = ?', [$ids['alfa']]), 'https://panel.example');
        contains('Piszemy, bo wyrazili Państwo zgodę na informacje handlowe e-mailem.', $footer);
        ok((bool) preg_match('#https://panel\.example/wypisz/[a-f0-9]{40}#', $footer), 'link do wypisania');
    },
    'Kampanie: ryzyko dla każdego odbiorcy, wypisani poza kampanią, przerwa między kampaniami' => function () use ($setup, $campaign, $version) {
        [$org, $uid, $ids] = $setup();
        [$c, $res] = $campaign($org, $uid, $ids);
        eq(['added' => 4, 'removed' => 0, 'blocked' => 1, 'paused' => 0], $res);
        eq(['panel', 'panel', 'own', 'own'], [$version((int) $c['id'], $ids['alfa'])['route'], $version((int) $c['id'], $ids['beta'])['route'], $version((int) $c['id'], $ids['gamma'])['route'], $version((int) $c['id'], $ids['epsilon'])['route']]);
        eq(['low', 'very_high'], [$version((int) $c['id'], $ids['alfa'])['risk'], $version((int) $c['id'], $ids['epsilon'])['risk']], 'zapytanie ofertowe to nie zgoda na kampanię');
        eq([], $version((int) $c['id'], $ids['delta']), 'wypisana firma nie trafia do kampanii');
        // Odznaczenie: firma wypada, jeśli nie dostała jeszcze wiadomości.
        $again = Campaigns::setRecipients($org, $c, [$ids['alfa'], $ids['beta'], $ids['gamma']], Auth::org() ?? Db::get()->one('SELECT * FROM organizations WHERE id = ?', [$org]), null);
        eq(1, $again['removed']);
        // Alfa dostała wiadomość z tej kampanii: druga kampania jej nie obejmie przez 14 dni.
        Db::get()->run("UPDATE campaign_recipients SET status = 'sent', sent_at = ? WHERE campaign_id = ? AND contact_id = ?", [now_utc(), (int) $c['id'], $ids['alfa']]);
        [, $second] = $campaign($org, $uid, $ids, ['alfa', 'beta']);
        eq(['added' => 1, 'removed' => 0, 'blocked' => 0, 'paused' => 1], $second);
    },
    'Kampanie: panel wysyła tylko do zgód, z nazwą firmy, adresem do odpowiedzi i nagłówkiem wypisania' => function () use ($setup, $campaign) {
        [$org, $uid, $ids] = $setup();
        [$c] = $campaign($org, $uid, $ids);
        $orgRow = Db::get()->one('SELECT * FROM organizations WHERE id = ?', [$org]);
        $user = Db::get()->one('SELECT * FROM users WHERE id = ?', [$uid]);
        with_config(['RESEND_API_KEY' => 're_test', 'MAIL_FROM' => 'Panel <panel@perfectdata.pl>'], function () use ($org, $c, $orgRow, $user, $ids) {
            Http::$log = [];
            $res = Campaigns::sendBatch($org, $c, $orgRow, $user, 'https://panel.example');
            eq(['sent' => 2, 'failed' => 0, 'left' => 0, 'limit' => false], $res);
            $sent = array_map(static fn ($l) => json_decode($l['body'], true), array_values(array_filter(Http::$log, static fn ($l) => str_contains($l['url'], 'resend'))));
            eq(['anna@alfa.example', 'biuro@beta.example'], array_map(static fn ($m) => $m['to'][0], $sent), 'tylko firmy ze zgodą albo klienci');
            eq('"Firma Testowa sp. z o.o." <panel@perfectdata.pl>', $sent[0]['from']);
            eq('wlasciciel@example.com', $sent[0]['reply_to']);
            ok((bool) preg_match('#^<https://panel\.example/wypisz/[a-f0-9]{40}>$#', $sent[0]['headers']['List-Unsubscribe']));
            eq('List-Unsubscribe=One-Click', $sent[0]['headers']['List-Unsubscribe-Post']);
            contains('Piszemy, bo wyrazili Państwo zgodę', $sent[0]['text']);
            contains('Piszemy, bo współpracujemy z Państwa firmą.', $sent[1]['text']);
            eq(2, (int) Db::get()->val("SELECT COUNT(*) FROM messages WHERE org_id = ? AND kind = 'campaign'", [$org]), 'historia na karcie kontaktu');
            eq(2, (int) Db::get()->val("SELECT COUNT(*) FROM outbox WHERE kind = 'campaign' AND status = 'sent'"));
            eq('sending', Db::get()->val('SELECT status FROM campaigns WHERE id = ?', [(int) $c['id']]), 'wiadomości ze skrzynki jeszcze czekają');
            eq(0, App\Modules\Outreach\Sequences::sentToday($org), 'kampania nie zjada limitu skrzynki użytkownika');
        });
    },
    'Kampanie: dzienny limit z pakietu i zgoda cofnięta przed wysyłką' => function () use ($setup, $campaign, $version) {
        [$org, $uid, $ids] = $setup();
        [$c] = $campaign($org, $uid, $ids);
        Kv::set('plans:limits', ['basic' => ['campaign_day' => 2]]);
        // Beta przestała być klientem, zanim kampania wyszła: wiadomość przechodzi do wysłania ze skrzynki, po potwierdzeniu ryzyka.
        Db::get()->run("UPDATE contacts SET basis = 'none' WHERE id = ?", [$ids['beta']]);
        $orgRow = Db::get()->one('SELECT * FROM organizations WHERE id = ?', [$org]);
        with_config(['RESEND_API_KEY' => 're_test', 'MAIL_FROM' => 'panel@perfectdata.pl'], function () use ($org, $c, $orgRow, $ids, $version) {
            $res = Campaigns::sendBatch($org, $c, $orgRow, null, 'https://panel.example');
            eq([1, 0], [$res['sent'], $res['left']]);
            eq(['own', 'very_high'], [$version((int) $c['id'], $ids['beta'])['route'], $version((int) $c['id'], $ids['beta'])['risk']]);
            eq(1, Campaigns::roomToday($orgRow));
            // Beta znowu jest klientem, ale limit na dziś to już jedna wysłana wiadomość.
            Kv::set('plans:limits', ['basic' => ['campaign_day' => 1]]);
            Db::get()->run("UPDATE contacts SET basis = 'client' WHERE id = ?", [$ids['beta']]);
            Db::get()->run("UPDATE campaign_recipients SET route = 'panel', risk = 'low' WHERE contact_id = ?", [$ids['beta']]);
            eq(0, Campaigns::roomToday($orgRow));
            $next = Campaigns::sendBatch($org, $c, $orgRow, null, 'https://panel.example');
            eq([0, true, 1], [$next['sent'], $next['limit'], $next['left']], 'limit na dziś wyczerpany');
        });
        Kv::forget('plans:limits');
    },
    'Kampanie: wiadomości bez zgody tylko po potwierdzeniu ryzyka, jako pliki .eml do wysłania ze swojej skrzynki' => function () use ($setup, $campaign, $version) {
        [$org, $uid, $ids] = $setup();
        [$c] = $campaign($org, $uid, $ids);
        login_as($uid);
        $base = '/klienci/kampanie/' . (int) $c['id'];
        $page = http('GET', $base)->body;
        contains('Ze swojej skrzynki: 2', $page);
        contains('Panel nie wyśle tych wiadomości sam.', $page);
        contains('Art. 398 Prawa komunikacji elektronicznej', $page);
        eq($base . '#wysylka', http('POST', $base . '/pobierz')->headers['Location'], 'bez potwierdzenia nie ma pliku');
        http('POST', $base . '/potwierdz', []);
        eq(null, $version((int) $c['id'], $ids['gamma'])['ack_at'], 'bez zaznaczenia oświadczenia nic się nie zapisuje');
        http('POST', $base . '/potwierdz', ['ack' => '1']);
        $g = $version((int) $c['id'], $ids['gamma']);
        ok($g['ack_at'] !== null && $g['ack_text'] === App\Modules\Outreach\RiskPolicy::ACK_TEXT);
        $r = http('POST', $base . '/pobierz');
        eq('application/zip', $r->headers['Content-Type'] ?? '');
        $tmp = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($tmp, $r->body);
        $zip = new ZipArchive();
        eq(true, $zip->open($tmp));
        eq(2, $zip->numFiles);
        $eml = (string) $zip->getFromIndex(0);
        $zip->close();
        unlink($tmp);
        contains('To: <kontakt@gamma.example>', $eml);
        contains('From: ', $eml);
        $body = base64_decode(substr($eml, strpos($eml, "\r\n\r\n") + 4));
        contains('/wypisz/', $body, 'stopka z wypisaniem także w wiadomości ze skrzynki');
        eq('downloaded', $version((int) $c['id'], $ids['gamma'])['status']);
        eq(2, (int) Db::get()->val("SELECT COUNT(*) FROM messages WHERE org_id = ? AND kind = 'campaign' AND ack_text IS NOT NULL", [$org]));
    },
    'Kampanie: AI dopasowuje wersje, liczy użycia i nie rusza poprawionych ręcznie' => function () use ($setup, $campaign, $version) {
        [$org, $uid, $ids] = $setup();
        [$c] = $campaign($org, $uid, $ids);
        $gamma = $version((int) $c['id'], $ids['gamma']);
        ok(Campaigns::updateVersion($org, (int) $gamma['id'], 'Mój temat', "Dzień dobry,\n\nmoja treść."));
        $orgRow = Db::get()->one('SELECT * FROM organizations WHERE id = ?', [$org]);
        $res = Campaigns::aiBatch($org, $c, $orgRow, null, 5);
        eq(['done' => 3, 'left' => 0], $res);
        $alfa = $version((int) $c['id'], $ids['alfa']);
        eq(['ai', 'Strona Państwa firmy - propozycja'], [$alfa['source'], $alfa['subject']], 'długi myślnik zamieniony na łącznik');
        ok(!str_contains($alfa['body'], "\u{2014}"));
        eq(['manual', 'Mój temat'], [$version((int) $c['id'], $ids['gamma'])['source'], $version((int) $c['id'], $ids['gamma'])['subject']]);
        eq(3, (int) Db::get()->val("SELECT calls FROM ai_usage WHERE org_id = ? AND kind = 'ops'", [$org]), 'jeden odbiorca to jedno użycie AI');
        $prompt = (string) (array_values(array_filter(Http::$log, static fn ($l) => str_contains($l['url'], 'anthropic')))[0]['body'] ?? '');
        contains('Projekt z funduszy UE: Wdrożenie sklepu internetowego', $prompt, 'AI dostaje fakty o odbiorcy');
    },
    'Kampanie: ekran tworzenia i edycji, a inna firma nie widzi kampanii' => function () use ($setup) {
        [$org, $uid, $ids] = $setup();
        login_as($uid);
        contains('Nie masz jeszcze kampanii', http('GET', '/klienci/kampanie')->body);
        contains('href="/klienci/kampanie"', http('GET', '/klienci')->body);
        $r = http('POST', '/klienci/kampanie', ['name' => 'Jesień', 'subject' => 'Oferta dla {firma}', 'body' => "{powitanie}\n\n{powod}\n\nTreść.\n\n{podpis}"]);
        $id = (int) Db::get()->val('SELECT id FROM campaigns WHERE org_id = ?', [$org]);
        eq('/klienci/kampanie/' . $id, $r->headers['Location']);
        $page = http('GET', '/klienci/kampanie/' . $id)->body;
        contains('Alfa sp. z o.o.', $page);
        contains('wypisana', $page, 'wypisana firma bez pola wyboru');
        http('POST', '/klienci/kampanie/' . $id . '/odbiorcy', ['contacts' => [(string) $ids['alfa'], (string) $ids['gamma']]]);
        $page = http('GET', '/klienci/kampanie/' . $id)->body;
        contains('Wersje (2)', $page);
        contains('Fakty, które zna panel:', $page);
        contains('Projekt z funduszy UE: Wdrożenie sklepu internetowego', $page);
        http('POST', '/klienci/kampanie/' . $id, ['name' => 'Jesień', 'subject' => 'Nowy temat dla {firma}', 'body' => "{powitanie}\n\nNowa treść.\n\n{podpis}"]);
        eq('Nowy temat dla Alfa sp. z o.o.', Db::get()->val('SELECT subject FROM campaign_recipients WHERE contact_id = ?', [$ids['alfa']]), 'zmiana szablonu przebudowuje wersje z reguł');
        // Inna firma.
        [, $other] = make_org('inna@example.com', 'Inna Firma sp. z o.o.');
        login_as($other);
        eq(404, http('GET', '/klienci/kampanie/' . $id)->status);
        $rid = (int) Db::get()->val('SELECT id FROM campaign_recipients WHERE contact_id = ?', [$ids['alfa']]);
        eq(404, http('POST', '/klienci/kampanie/' . $id . '/wersja/' . $rid, ['subject' => 'x', 'body' => 'y'])->status);
        eq(404, http('POST', '/klienci/kampanie/' . $id . '/wyslij')->status);
        ok(!str_contains(http('GET', '/klienci/kampanie')->body, 'Jesień'));
    },
    'Kampanie: wypisanie jednym kliknięciem z nagłówka wiadomości' => function () use ($setup, $campaign) {
        [$org, $uid, $ids] = $setup();
        [$c] = $campaign($org, $uid, $ids, ['alfa']);
        $orgRow = Db::get()->one('SELECT * FROM organizations WHERE id = ?', [$org]);
        Campaigns::sendBatch($org, $c, $orgRow, null, 'https://panel.example');
        $token = (string) Db::get()->val('SELECT unsub_token FROM contacts WHERE id = ?', [$ids['alfa']]);
        Auth::logout();
        $_SESSION = [];
        eq(200, http('POST', '/wypisz/' . $token, ['List-Unsubscribe' => 'One-Click', '_csrf' => ''])->status);
        ok(Db::get()->val('SELECT unsubscribed_at FROM contacts WHERE id = ?', [$ids['alfa']]) !== null);
        Db::get()->run('UPDATE contacts SET unsubscribed_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() + 5), $ids['alfa']]);
        eq(1, Campaigns::stats($org, (int) $c['id'])['unsubscribed']);
    },
];
