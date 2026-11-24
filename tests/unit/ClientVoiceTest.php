<?php

use App\Core\Db;
use App\Modules\Inbound\Inbound;
use App\Modules\Outreach\ClientVoice;

return [
    'Klienci: „Co napisał klient” pokazuje zapytanie ze strony, zapytanie ofertowe i wklejoną odpowiedź, tylko w swojej firmie' => function () {
        [$orgId, $uid] = make_org();
        $db = Db::get();
        login_as($uid);
        $now = now_utc();
        $cid = $db->insert('contacts', ['org_id' => $orgId, 'company_name' => 'Klient Pytający sp. z o.o.', 'email' => 'biuro@klient.example', 'basis' => 'rfq', 'created_at' => $now]);
        $quiet = $db->insert('contacts', ['org_id' => $orgId, 'company_name' => 'Klient Milczący sp. z o.o.', 'email' => 'biuro@milczacy.example', 'basis' => 'none', 'created_at' => $now]);

        // Bez słów klienta nie ma pustej sekcji ani pustego rozwinięcia.
        ok(!str_contains(http('GET', '/klienci')->body, 'Co napisał klient'));
        $card = http('GET', '/klienci/' . $cid)->body;
        ok(!str_contains($card, 'id="glos"'), 'karta bez sekcji');
        contains('Panel nie czyta Twojej skrzynki', $card);

        // Zapytanie ze strony: wiadomość z formularza, odpowiedzi na pytania widżetu i pytania do czatu.
        $widget = Inbound::widget($orgId);
        $conv = $db->insert('inbound_conversations', ['org_id' => $orgId, 'widget_id' => (int) $widget['id'], 'token' => 'tok', 'variant' => 'b', 'started_at' => $now]);
        $db->insert('inbound_messages', ['org_id' => $orgId, 'conversation_id' => $conv, 'role' => 'user', 'text' => 'Czy robicie też kampanie na LinkedIn?', 'created_at' => $now]);
        $db->insert('inbound_messages', ['org_id' => $orgId, 'conversation_id' => $conv, 'role' => 'assistant', 'text' => 'Odpowiedź asystenta, której klient nie napisał.', 'created_at' => $now]);
        $lead = $db->insert('inbound_leads', ['org_id' => $orgId, 'widget_id' => (int) $widget['id'], 'conversation_id' => $conv, 'contact_id' => $cid, 'score' => 70, 'level' => 'hot',
            'points_json' => json_out([['label' => 'Potrzeba', 'answer' => 'Pozycjonowanie sklepu', 'points' => 40, 'max' => 40], ['label' => 'Telefon', 'answer' => 'Podany', 'points' => 10, 'max' => 10], ['label' => 'Termin', 'answer' => null, 'points' => 0, 'max' => 30]]),
            'email' => 'biuro@klient.example', 'message' => "Szukamy agencji do sklepu z meblami.\n<b>Budżet</b> 5 tys. zł miesięcznie.", 'created_at' => gmdate('Y-m-d H:i:s', time() - 5 * 86400)]);
        $db->insert('rfqs', ['org_id' => $orgId, 'contact_id' => $cid, 'company_name' => 'Klient Pytający sp. z o.o.', 'subject' => 'Zapytanie o obsługę kampanii Google Ads', 'status' => 'new', 'created_at' => gmdate('Y-m-d H:i:s', time() - 9 * 86400), 'updated_at' => $now]);

        // Wklejona odpowiedź: pusta niczego nie zapisuje, treść zapisuje się i oznacza, że firma odpisała.
        http('POST', '/klienci/' . $cid . '/odpowiedz', ['zapisz' => '1', 'tresc' => "Dzień dobry,\ninteresuje nas oferta. Proszę o wycenę do piątku.\n\nPozdrawiam\nAnna Klient", 'data' => gmdate('Y-m-d', time() - 86400)]);
        eq(1, (int) $db->val('SELECT COUNT(*) FROM contact_replies WHERE org_id = ? AND contact_id = ?', [$orgId, $cid]));
        ok($db->val('SELECT replied_at FROM contacts WHERE id = ?', [$cid]) !== null, 'firma oznaczona jako ta, która odpisała');
        http('POST', '/klienci/' . $cid . '/odpowiedz', ['zapisz' => '1', 'tresc' => '   ']);
        contains('Wklej treść odpowiedzi klienta, żeby ją dodać.', http('GET', '/klienci/' . $cid)->body);
        ok($db->val('SELECT replied_at FROM contacts WHERE id = ?', [$cid]) !== null, 'pusty zapis nie zdejmuje oznaczenia');
        http('POST', '/klienci/' . $cid . '/odpowiedz', ['zapisz' => '1', 'tresc' => str_repeat('a', 20001)]);
        eq(1, (int) $db->val('SELECT COUNT(*) FROM contact_replies WHERE contact_id = ?', [$cid]), 'za długa treść nie jest zapisywana');

        // Kolejność od najnowszego, trzy źródła, bez słów asystenta i bez wiersza „Telefon”.
        $items = ClientVoice::forContacts($orgId, [$cid, $quiet])[$cid];
        eq(['reply', 'lead', 'rfq'], array_column($items, 'kind'));
        eq("Szukamy agencji do sklepu z meblami.\n<b>Budżet</b> 5 tys. zł miesięcznie.\nPotrzeba: Pozycjonowanie sklepu\nPytanie do czatu: Czy robicie też kampanie na LinkedIn?", $items[1]['text']);
        eq([], ClientVoice::forContacts($orgId, [$quiet]));

        // Karta klienta: rozwijane wpisy, pierwszy otwarty, treść jako zwykły tekst.
        $card = http('GET', '/klienci/' . $cid)->body;
        contains('<h2 id="t-voice" style="margin-bottom:4px">Co napisał klient</h2>', $card);
        eq(3, substr_count($card, '<details class="voice"'));
        eq(1, substr_count($card, '<details class="voice" open>'));
        contains('interesuje nas oferta. Proszę o wycenę do piątku.<br', $card);
        contains('&lt;b&gt;Budżet&lt;/b&gt; 5 tys. zł miesięcznie.', $card);
        ok(!str_contains($card, '<b>Budżet</b>') && !str_contains($card, 'Odpowiedź asystenta'), 'bez cudzego HTML i bez słów asystenta');
        contains('href="/zapytania/' . $lead . '">Otwórz zapytanie</a>', $card);
        contains('Dodaj odpowiedź', $card);
        // Lista klientów: rozwinięcie tylko przy firmie, która coś napisała.
        $list = http('GET', '/klienci')->body;
        eq(1, substr_count($list, '<details class="voice in-table">'));
        contains('Co napisał klient (3)', $list);
        contains('Proszę o wycenę do piątku.', $list);
        contains('href="/klienci/' . $cid . '#glos"', $list);

        // Usunięcie wklejonej odpowiedzi i cofnięcie oznaczenia.
        $replyId = (int) $db->val('SELECT id FROM contact_replies WHERE contact_id = ?', [$cid]);
        http('POST', '/klienci/' . $cid . '/odpowiedz', ['cofnij' => '1', 'tresc' => 'tekst, którego nie zapisujemy przy cofaniu']);
        eq([null, 1], [$db->val('SELECT replied_at FROM contacts WHERE id = ?', [$cid]), (int) $db->val('SELECT COUNT(*) FROM contact_replies WHERE contact_id = ?', [$cid])]);

        // Druga firma: nie widzi cudzych wpisów i niczego w nich nie zmieni.
        [$org2, $uid2] = make_org('obcy@example.com', 'Obca Firma sp. z o.o.');
        login_as($uid2);
        eq([], ClientVoice::forContacts($org2, [$cid]));
        eq(404, http('GET', '/klienci/' . $cid)->status);
        eq(404, http('POST', '/klienci/' . $cid . '/odpowiedz', ['usun_odpowiedz' => (string) $replyId])->status);
        eq(404, http('POST', '/klienci/' . $cid . '/odpowiedz', ['zapisz' => '1', 'tresc' => 'Cudza treść'])->status);
        eq(1, (int) $db->val('SELECT COUNT(*) FROM contact_replies WHERE contact_id = ?', [$cid]));
        login_as($uid);
        http('POST', '/klienci/' . $cid . '/odpowiedz', ['usun_odpowiedz' => (string) $replyId]);
        eq(0, (int) $db->val('SELECT COUNT(*) FROM contact_replies WHERE contact_id = ?', [$cid]));
        // Przełącznik z listy przypomnień działa jak dawniej.
        http('POST', '/klienci/' . $cid . '/odpowiedz', ['back' => 'sekwencje']);
        ok($db->val('SELECT replied_at FROM contacts WHERE id = ?', [$cid]) !== null);
    },
];
