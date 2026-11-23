<?php

use App\Core\Db;
use App\Modules\Outreach\Consents;
use App\Modules\Outreach\ProspectEvents;
use App\Modules\Outreach\Prospects;

$setup = static function (string $email = 'wlasciciel@example.com', string $name = 'Firma Testowa sp. z o.o.'): array {
    [$orgId, $uid] = make_org($email, $name);
    $db = Db::get();
    $db->update('organizations', ['nip' => '5260000005', 'address' => 'ul. Nadawcy 5, 00-002 Warszawa'], 'id = ?', [$orgId]);
    login_as($uid);
    http('POST', '/klienci/potencjalni/lista', ['lines' => "1234563218 Przykładowa Budowlana\ndruga-firma.example Druga Firma\ntrzecia-firma.example Trzecia Firma"]);
    Prospects::enrichPending($orgId);
    $ids = array_map('intval', array_column($db->all('SELECT id FROM prospects WHERE org_id = ? ORDER BY id', [$orgId]), 'id'));
    return [$orgId, $uid, $ids];
};
$guest = static function (callable $fn) {
    $saved = $_SESSION;
    $_SESSION = [];
    try {
        return $fn();
    } finally {
        $_SESSION = $saved;
    }
};

return [
    'Lejek listów: list, wejście na stronę zgody, zgoda, oferta, odpowiedź i koszt jednej zgody' => function () use ($setup, $guest) {
        [$orgId, $uid, [$a, $b, $c]] = $setup();
        $db = Db::get();
        $url = '/klienci/potencjalni/' . $a;
        $kinds = static fn (int $id): array => array_column($db->all('SELECT kind FROM prospect_events WHERE prospect_id = ? ORDER BY id', [$id]), 'kind');
        $zero = ['letters' => 0, 'firms' => 0, 'visit' => 0, 'report' => 0, 'consent' => 0, 'offer' => 0, 'reply' => 0, 'refused' => 0, 'other_consents' => 0];

        // Przed pierwszym listem lejek jest pusty i mówi, co zrobić.
        eq($zero, ProspectEvents::funnel($orgId));
        $list = http('GET', '/klienci/potencjalni')->body;
        contains('Czy listy działają', $list);
        contains('W tym okresie nie wyszedł żaden list.', $list);

        // Podgląd strony zgody z panelu nie jest wejściem firmy. Wejście z listu liczy się raz na godzinę z jednego adresu.
        $card = http('GET', $url)->body;
        $code = (string) $db->val('SELECT consent_code FROM prospects WHERE id = ?', [$a]);
        contains('/z/' . $code . '?podglad=1"', $card);
        $guest(fn () => http('GET', '/z/' . $code, [], ['podglad' => '1']));
        eq([], $kinds($a));
        eq(303, http('POST', $url . '/list', ['action' => 'sent', 'body' => 'Treść listu.'])->status);
        eq(['letter'], $kinds($a));
        eq($uid, (int) $db->val('SELECT created_by FROM prospect_events WHERE prospect_id = ?', [$a]));
        $guest(fn () => http('GET', '/z/' . $code));
        $guest(fn () => http('GET', '/z/' . $code));
        eq(['letter', 'visit'], $kinds($a), 'odświeżenie strony nie liczy się drugi raz');
        $visit = $db->one("SELECT * FROM prospect_events WHERE kind = 'visit'");
        ok($visit['ip_hash'] !== null && !str_contains((string) $visit['ip_hash'], '127.0.0.1'), 'adres tylko jako skrót');
        $db->update('prospect_events', ['created_at' => gmdate('Y-m-d H:i:s', time() - 7200)], 'id = ?', [(int) $visit['id']]);
        $guest(fn () => http('GET', '/z/' . $code));
        eq(['letter', 'visit', 'visit'], $kinds($a), 'po godzinie to nowe wejście');
        eq(array_merge($zero, ['letters' => 1, 'firms' => 1, 'visit' => 1]), ProspectEvents::funnel($orgId), 'dwa wejścia tej samej firmy to jedna firma w lejku');
        ok(!isset($guest(fn () => http('GET', '/z/' . $code))->headers['Set-Cookie']), 'liczenie wejść bez plików cookie');

        // Błędny formularz nie tworzy zdarzeń. Zgoda, oferta i odpowiedź przesuwają firmę przez lejek.
        $guest(fn () => http('POST', '/z/' . $code, ['_csrf' => 'brak', 'email' => 'zly']));
        eq(3, count($kinds($a)));
        $guest(fn () => http('POST', '/z/' . $code, ['_csrf' => 'brak', 'email' => 'zakupy@przykladowa.example', 'agree' => '1']));
        $guest(fn () => http('POST', '/z/' . $code, ['_csrf' => 'brak', 'email' => 'zakupy@przykladowa.example', 'agree' => '1']));
        eq(['letter', 'visit', 'visit', 'consent'], $kinds($a), 'powtórzony formularz nie dubluje zgody');
        eq('strona zgody', $db->val("SELECT detail FROM prospect_events WHERE kind = 'consent'"));
        $cid = (int) $db->val('SELECT contact_id FROM prospects WHERE id = ?', [$a]);
        http('POST', '/klienci/' . $cid . '/wiadomosc', ['kind' => 'consented', 'action' => 'save', 'subject' => 'Oferta', 'body' => 'Treść oferty.']);
        $mid = (int) $db->val('SELECT id FROM messages ORDER BY id DESC');
        ok($mid > 0, 'wiadomość zapisana');
        http('POST', '/wiadomosci/' . $mid . '/wyslana');
        http('POST', '/wiadomosci/' . $mid . '/wyslana');
        eq(['letter', 'visit', 'visit', 'consent', 'offer'], $kinds($a), 'ponowne kliknięcie „wysłana” nie dubluje oferty');
        http('POST', '/klienci/' . $cid . '/odpowiedz');
        eq('reply', $kinds($a)[5]);
        http('POST', '/klienci/' . $cid . '/odpowiedz');
        eq(5, count($kinds($a)), 'cofnięcie odpowiedzi zdejmuje ją z lejka');
        http('POST', '/klienci/' . $cid . '/odpowiedz');
        eq(array_merge($zero, ['letters' => 1, 'firms' => 1, 'visit' => 1, 'consent' => 1, 'offer' => 1, 'reply' => 1]), ProspectEvents::funnel($orgId));

        // Druga firma: zgoda zapisana ręcznie bez listu nie należy do lejka listów. Trzecia: list i odmowa na stronie zgody.
        http('POST', '/klienci/potencjalni/' . $b . '/zgoda', ['email' => 'biuro@druga-firma.example', 'way' => 'in_person', 'detail' => 'Poprosili o ofertę na targach budowlanych.', 'on' => today_pl(), 'confirm' => '1']);
        eq(['consent'], $kinds($b));
        eq(['zapisana ręcznie', $uid], [$db->val('SELECT detail FROM prospect_events WHERE prospect_id = ?', [$b]), (int) $db->val('SELECT created_by FROM prospect_events WHERE prospect_id = ?', [$b])]);
        Prospects::markContacted($orgId, $c, 'letter', $uid);
        $code3 = Consents::code($db->one('SELECT * FROM prospects WHERE id = ?', [$c]));
        $guest(fn () => http('POST', '/z/' . $code3, ['_csrf' => 'brak', 'action' => 'refuse']));
        eq(['letter', 'refused'], $kinds($c), 'wysłanie formularza nie jest wejściem, odmowa zapisana raz');
        $f = ProspectEvents::funnel($orgId);
        eq([2, 2, 1, 1, 1, 1], [$f['letters'], $f['firms'], $f['visit'], $f['consent'], $f['refused'], $f['other_consents']]);

        // Okres: list sprzed 100 dni nie należy do ostatnich 90 dni, ale jest w lejku „od początku”.
        $db->run("UPDATE prospect_events SET created_at = ? WHERE prospect_id = ? AND kind = 'letter'", [gmdate('Y-m-d H:i:s', time() - 100 * 86400), $c]);
        eq([1, 0], [ProspectEvents::funnel($orgId, 90)['firms'], ProspectEvents::funnel($orgId, 90)['refused']]);
        eq([2, 1], [ProspectEvents::funnel($orgId, 0)['firms'], ProspectEvents::funnel($orgId, 0)['refused']]);
        eq(2, ProspectEvents::funnel($orgId, 365)['firms']);

        // Lista pokazuje lejek z udziałami. Po wpisaniu kosztu listu także koszt jednej zgody.
        $list = http('GET', '/klienci/potencjalni', [], ['okres' => '0'])->body;
        contains('<div class="stat-label">Firmy z listem</div><div class="stat-value">2</div><div class="small muted">2 listy</div>', $list);
        contains('<div class="stat-label">Zostawiły zgodę na ofertę</div><div class="stat-value">1</div><div class="small muted">50% firm z listem</div>', $list);
        contains('Odmówiły korespondencji: <strong>1</strong>', $list);
        contains('Zgody firm bez listu (zapisane ręcznie albo z innych dróg): 1.', $list);
        ok(!str_contains($list, 'Listy kosztowały'), 'bez podanego kosztu panel nie liczy pieniędzy');
        eq(303, http('POST', '/klienci/potencjalni/kryteria', ['letter_cost' => '6,50', 'okres' => '0'])->status);
        eq(6.5, Prospects::criteria($db->one('SELECT * FROM organizations WHERE id = ?', [$orgId]))['letter_cost']);
        $list = html_entity_decode(http('GET', '/klienci/potencjalni', [], ['okres' => '0'])->body);
        contains('Listy kosztowały <strong>13,00 zł</strong>, czyli <strong>13,00 zł</strong> za jedną zgodę.', str_replace("\u{a0}", ' ', $list));
        contains('value="6,50"', $list);
        // Zapis kryteriów nie kasuje kosztu listu, a zapis kosztu nie kasuje kryteriów.
        http('POST', '/klienci/potencjalni/kryteria', ['keywords' => 'budow', 'min_years' => '3']);
        $crit = Prospects::criteria($db->one('SELECT * FROM organizations WHERE id = ?', [$orgId]));
        eq(['budow', 3, 6.5], [$crit['keywords'], $crit['min_years'], $crit['letter_cost']]);
        http('POST', '/klienci/potencjalni/kryteria', ['letter_cost' => 'abc']);
        $crit = Prospects::criteria($db->one('SELECT * FROM organizations WHERE id = ?', [$orgId]));
        eq(['budow', 0.0], [$crit['keywords'], $crit['letter_cost']]);

        // Karta firmy: historia od najnowszych, wejścia z jednego dnia w jednym wierszu.
        $db->run("UPDATE prospect_events SET created_at = ? WHERE kind = 'visit'", [now_utc()]);
        $card = http('GET', $url)->body;
        contains('id="historia"', $card);
        contains('Wejście na stronę zgody (2 razy)', $card);
        ok(strpos($card, 'Firma odpowiedziała') < strpos($card, 'Oferta e-mailem wysłana') && strpos($card, 'Oferta e-mailem wysłana') < strpos($card, 'List wysłany'), 'od najnowszych');
        ok(!str_contains(http('GET', '/klienci/potencjalni/' . $b)->body, 'Wejście na stronę zgody'), 'historia tylko tej firmy');

        // Inna firma w panelu ma własny, pusty lejek. Usunięcie firmy z listy usuwa jej zdarzenia.
        [$org2] = $setup('druga@example.com', 'Druga sp. z o.o.');
        eq($zero, ProspectEvents::funnel($org2));
        ok(!str_contains(http('GET', '/klienci/potencjalni', [], ['okres' => '0'])->body, 'Firmy z listem'), 'cudzy lejek nie jest widoczny');
        login_as($uid);
        Prospects::delete($orgId, $c);
        eq(0, (int) $db->val('SELECT COUNT(*) FROM prospect_events WHERE prospect_id = ?', [$c]));
        eq(1, ProspectEvents::funnel($orgId)['firms']);
    },
    'Lejek listów: prośba o raport, otwarcie raportu i wypisanie z wiadomości' => function () use ($setup, $guest) {
        [$orgId, $uid, [$a]] = $setup();
        $db = Db::get();
        $kinds = static fn (): array => array_column($db->all('SELECT kind FROM prospect_events WHERE prospect_id = ? ORDER BY id', [$a]), 'kind');
        $db->update('prospects', ['website' => 'https://przykladowa.example', 'host' => 'przykladowa.example'], 'id = ?', [$a]);
        $aid = $db->insert('site_audits', ['org_id' => $orgId, 'url' => 'https://przykladowa.example/', 'host' => 'przykladowa.example', 'score' => 50, 'created_by' => $uid, 'created_at' => now_utc(),
            'result_json' => json_out(['host' => 'przykladowa.example', 'final_url' => 'https://przykladowa.example/', 'scanned_at' => gmdate('c'), 'score' => 50, 'grade' => 'poor', 'categories' => [],
                'counts' => [], 'services' => [], 'psi_done' => false, 'meta' => ['prestashop' => ['is' => false]], 'findings' => []])]);
        App\Modules\Outreach\ProspectAudit::attach($orgId, $db->one('SELECT * FROM prospects WHERE id = ?', [$a]), $aid);
        Prospects::markContacted($orgId, $a, 'letter', $uid);
        $card = http('GET', '/klienci/potencjalni/' . $a)->body;
        $p = $db->one('SELECT * FROM prospects WHERE id = ?', [$a]);
        $token = (string) $db->val('SELECT share_token FROM site_audits WHERE id = ?', [$aid]);
        contains('/raport/' . $token . '?podglad=1"', $card);

        // Podgląd raportu z panelu się nie liczy. Prośba o raport i jego otwarcie przez firmę trafiają do historii.
        $guest(fn () => http('GET', '/raport/' . $token, [], ['podglad' => '1']));
        eq(['letter'], $kinds());
        $guest(fn () => http('POST', '/z/' . $p['consent_code'], ['_csrf' => 'brak', 'email' => 'biuro@przykladowa.example', 'want_report' => '1', 'agree' => '1']));
        eq(['letter', 'report', 'consent'], $kinds());
        $guest(fn () => http('GET', '/raport/' . $token));
        $guest(fn () => http('GET', '/raport/' . $token));
        eq(['letter', 'report', 'consent', 'report_view'], $kinds());
        $f = ProspectEvents::funnel($orgId);
        eq([1, 1, 1, 0], [$f['firms'], $f['report'], $f['consent'], $f['visit']]);
        contains('Poprosiły o raport z audytu: <strong>1</strong>', http('GET', '/klienci/potencjalni')->body);

        // Wypisanie linkiem z wiadomości jest odmową w lejku, zapisaną raz.
        preg_match('~/wypisz/([a-f0-9]{40})~', (string) $db->val("SELECT body FROM outbox WHERE kind = 'consent_receipt'"), $m);
        $guest(fn () => http('POST', '/wypisz/' . $m[1], ['_csrf' => 'brak']));
        $guest(fn () => http('POST', '/wypisz/' . $m[1], ['_csrf' => 'brak']));
        eq('refused', $kinds()[4]);
        eq(5, count($kinds()));
        eq('wypisanie z wiadomości', $db->val("SELECT detail FROM prospect_events WHERE kind = 'refused'"));
        eq(1, ProspectEvents::funnel($orgId)['refused']);
        contains('Odmowa korespondencji', http('GET', '/klienci/potencjalni/' . $a)->body);
    },
];
