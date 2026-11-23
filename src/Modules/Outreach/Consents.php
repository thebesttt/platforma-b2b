<?php

declare(strict_types=1);

namespace App\Modules\Outreach;

use App\Core\Db;
use App\Core\Mailer;
use App\Modules\Inbound\Inbound;

/**
 * Zgoda na ofertę e-mailem zebrana zgodnie z prawem.
 *
 * Prośba o zgodę wysłana e-mailem albo telefonicznie sama wymaga zgody. Dlatego zgodę zbiera kanał, który jej nie wymaga:
 * list pocztą z adresem strony zgody. Firma wchodzi na stronę z własnej woli, wpisuje adres e-mail i zaznacza zgodę
 * (art. 398 ust. 2 Prawa komunikacji elektronicznej: zgodą jest udostępnienie adresu w celu otrzymywania informacji handlowej).
 * Panel zapisuje dowód: dokładną treść zgody, czas i źródło. Od tej chwili oferta e-mailem ma podstawę.
 */
final class Consents
{
    /** Sposoby, w jakie firma mogła zgodzić się poza stroną zgody. Użytkownik zapisuje je ręcznie i musi umieć je wykazać. */
    public const MANUAL_WAYS = [
        'letter_reply' => 'Odpowiedź na nasz list',
        'in_person' => 'Rozmowa osobista, np. na targach albo spotkaniu',
        'their_request' => 'Firma sama zadzwoniła albo napisała z prośbą o ofertę',
        'other' => 'Inaczej',
    ];

    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789'; // bez znaków, które łatwo pomylić przy przepisywaniu z listu

    /** Kod strony zgody dla firmy z listy. Powstaje przy pierwszym użyciu i się nie zmienia, bo jest wydrukowany w liście. */
    public static function code(array $p): string
    {
        if (!empty($p['consent_code'])) {
            return (string) $p['consent_code'];
        }
        $db = Db::get();
        do {
            $code = '';
            for ($i = 0; $i < 10; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while ($db->val('SELECT 1 FROM prospects WHERE consent_code = ?', [$code]));
        $db->update('prospects', ['consent_code' => $code], 'id = ?', [(int) $p['id']]);
        return $code;
    }

    /** Adres strony zgody do wydrukowania w liście. Pusty, gdy nie znamy publicznego adresu panelu. */
    public static function url(array $p, string $base): string
    {
        return $base !== '' ? rtrim($base, '/') . '/z/' . self::code($p) : '';
    }

    /** Adres w druku: bez „https://” jest krótszy do przepisania, a przeglądarka i tak go otworzy. */
    public static function printed(string $url): string
    {
        return (string) preg_replace('~^https://~', '', $url);
    }

    /** Firma z listy razem z danymi nadawcy, po kodzie z listu. */
    public static function byCode(string $code): ?array
    {
        if (!preg_match('/^[a-z2-9]{10}$/', $code)) {
            return null;
        }
        return Db::get()->one(
            'SELECT p.*, o.name AS org_name, o.nip AS org_nip, o.address AS org_address FROM prospects p JOIN organizations o ON o.id = p.org_id WHERE p.consent_code = ?',
            [$code]
        );
    }

    /**
     * Dokładna treść oświadczeń pokazywana firmie. Zapisujemy ją razem ze zgodą.
     * Prośba o raport i zgoda na ofertę to dwa osobne pola: raportu nie uzależniamy od zgody na ofertę.
     * @return array{email: string, phone: string, report: string}
     */
    public static function wording(string $orgName, string $host = ''): array
    {
        return [
            'email' => 'Zgadzam się na przesłanie przez firmę ' . $orgName . ' oferty handlowej na podany adres e-mail.',
            'phone' => 'Zgadzam się na kontakt telefoniczny firmy ' . $orgName . ' w sprawie tej oferty pod podanym numerem.',
            'report' => 'Proszę o przesłanie raportu z audytu strony' . ($host !== '' ? ' ' . $host : '') . ' na podany adres e-mail.',
        ];
    }

    /** Czy wpis obejmuje dany kanał: email (zgoda na ofertę), phone albo report (prośba o raport). */
    public static function has(?array $row, string $channel): bool
    {
        return $row !== null && in_array($channel, explode(',', (string) ($row['channels'] ?? '')), true);
    }

    /**
     * Oświadczenie firmy na stronie zgody: zgoda na ofertę, prośba o raport z audytu albo jedno i drugie.
     * @param array{email?: string, person?: string, phone?: string, agree?: string, agree_phone?: string, want_report?: string} $in
     * @return array{ok: bool, error?: string, email?: string, repeated?: bool, offer?: bool, report?: bool}
     */
    public static function give(array $p, array $in, string $ip, string $userAgent, string $panelBase = ''): array
    {
        $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 200) {
            return ['ok' => false, 'error' => 'Wpisz poprawny adres e-mail.'];
        }
        $report = ProspectAudit::report($p, $panelBase);
        $offer = ($in['agree'] ?? '') === '1';
        $wantReport = $report !== null && ($in['want_report'] ?? '') === '1';
        if (!$offer && !$wantReport) {
            return ['ok' => false, 'error' => $report !== null
                ? 'Zaznacz, że chcą Państwo dostać raport. Bez tego nie możemy go wysłać.'
                : 'Zaznacz zgodę. Bez niej nie możemy wysłać oferty.'];
        }
        // Zgoda na telefon dotyczy rozmowy o ofercie, więc ma sens tylko razem ze zgodą na ofertę.
        $phone = preg_replace('/[^\d+ ]/', '', (string) ($in['phone'] ?? '')) ?? '';
        $phoneOk = $offer && ($in['agree_phone'] ?? '') === '1';
        if ($phoneOk && strlen(preg_replace('/\D/', '', $phone) ?? '') < 9) {
            return ['ok' => false, 'error' => 'Żeby zgodzić się na telefon, wpisz numer.'];
        }
        $words = self::wording((string) $p['org_name'], (string) ($report['host'] ?? ''));
        $res = self::apply($p, [
            'email' => $email, 'person' => mb_substr(utrim((string) ($in['person'] ?? '')), 0, 120) ?: null, 'phone' => $phoneOk ? mb_substr(trim($phone), 0, 40) : null,
            'channels' => implode(',', array_keys(array_filter(['report' => $wantReport, 'email' => $offer, 'phone' => $phoneOk]))),
            'wording' => implode(' ', array_filter([$wantReport ? $words['report'] : null, $offer ? $words['email'] : null, $phoneOk ? $words['phone'] : null])),
            'source' => 'page', 'detail' => 'Strona zgody z listu', 'ip_hash' => Inbound::ipHash($ip), 'user_agent' => mb_substr($userAgent, 0, 300) ?: null, 'created_by' => null,
        ]);
        if ($res['ok']) {
            $res += ['offer' => $offer, 'report' => $wantReport];
            if (empty($res['repeated'])) {
                self::notify($p, $res, $panelBase, $wantReport ? $report : null);
            }
        }
        return $res;
    }

    /**
     * Zgoda zapisana ręcznie przez użytkownika, gdy firma zgodziła się inaczej niż na stronie zgody.
     * @return array{ok: bool, error?: string, contact_id?: int}
     */
    public static function manual(array $p, int $userId, array $in): array
    {
        $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
        $way = (string) ($in['way'] ?? '');
        $detail = mb_substr(utrim((string) ($in['detail'] ?? '')), 0, 500);
        $on = form_date((string) ($in['on'] ?? '')) ?? today_pl();
        $error = match (true) {
            !filter_var($email, FILTER_VALIDATE_EMAIL) => 'Podaj adres e-mail, na który firma zgodziła się dostać ofertę.',
            !isset(self::MANUAL_WAYS[$way]) => 'Wybierz, w jaki sposób firma wyraziła zgodę.',
            mb_strlen($detail) < 10 => 'Opisz zgodę: kto, kiedy i co dokładnie powiedział albo napisał. To Twój dowód w razie sporu.',
            $on > today_pl() => 'Data zgody nie może być z przyszłości.',
            default => null,
        };
        if ($error !== null) {
            return ['ok' => false, 'error' => $error];
        }
        $org = Db::get()->one('SELECT name FROM organizations WHERE id = ?', [(int) $p['org_id']]) ?? [];
        return self::apply($p + ['org_name' => (string) ($org['name'] ?? '')], [
            'email' => $email, 'person' => mb_substr(utrim((string) ($in['person'] ?? '')), 0, 120) ?: null, 'phone' => null, 'channels' => 'email',
            'wording' => self::MANUAL_WAYS[$way] . ' (' . date('d.m.Y', strtotime($on)) . '): ' . $detail,
            'source' => 'manual', 'detail' => self::MANUAL_WAYS[$way], 'ip_hash' => null, 'user_agent' => null, 'created_by' => $userId,
        ], $on);
    }

    /** Zapis dowodu, kontakt z podstawą „zgoda” i powiązanie z firmą na liście. */
    private static function apply(array $p, array $c, ?string $on = null): array
    {
        $db = Db::get();
        $orgId = (int) $p['org_id'];
        $contact = $db->one('SELECT * FROM contacts WHERE org_id = ? AND LOWER(email) = ? ORDER BY id LIMIT 1', [$orgId, $c['email']]);
        if ($contact && !empty($contact['unsubscribed_at'])) {
            return ['ok' => false, 'error' => $c['source'] === 'page'
                ? 'Ten adres jest na liście rezygnacji. Żeby znów dostawać wiadomości, prosimy o kontakt z nadawcą listu.'
                : 'Ten adres wypisał się z wiadomości. Zgodę może zostawić tylko sama firma, na stronie zgody.'];
        }
        // Sprzeciw cofa tylko sama firma, na stronie zgody. Użytkownik nie może go nadpisać ręcznym wpisem.
        if ($p['status'] === 'objected' && $c['source'] !== 'page') {
            return ['ok' => false, 'error' => 'Firma zgłosiła sprzeciw. Zgodę może zostawić tylko sama, na stronie zgody.'];
        }
        // Powtórne wysłanie tego samego formularza nie tworzy drugiego dowodu. Nowy powstaje, gdy firma dodaje coś, czego wcześniej nie zaznaczyła.
        $offer = in_array('email', explode(',', $c['channels']), true);
        if ($c['source'] === 'page') {
            $had = [];
            $contactWas = 0;
            foreach ($db->all('SELECT channels, contact_id FROM consents WHERE org_id = ? AND prospect_id = ? AND email = ? AND source = ? AND withdrawn_at IS NULL',
                [$orgId, (int) $p['id'], $c['email'], 'page']) as $row) {
                $had = array_merge($had, explode(',', (string) $row['channels']));
                $contactWas = (int) $row['contact_id'];
            }
            if ($had && !array_diff(explode(',', $c['channels']), $had)) {
                return ['ok' => true, 'email' => $c['email'], 'contact_id' => $contactWas, 'repeated' => true];
            }
        }
        $now = now_utc();
        // Sama prośba o raport nie jest zgodą na ofertę: kontakt powstaje, ale bez podstawy do wysyłki ofert.
        $basis = !$offer ? [] : [
            'basis' => 'consent',
            'basis_detail' => mb_substr($c['source'] === 'page' ? 'Zgoda na stronie zgody z listu: „' . $c['wording'] . '”' : 'Zgoda zapisana ręcznie. ' . $c['wording'], 0, 500),
            'basis_at' => $on ?? today_pl(),
            'marketing_consent_at' => $on !== null ? gmdate('Y-m-d H:i:s', strtotime($on . ' 12:00:00')) : $now,
            'marketing_consent_text' => mb_substr($c['wording'], 0, 1000),
        ];
        if ($contact) {
            // Relacja z klientem jest mocniejszą podstawą niż pojedyncza zgoda, więc jej nie nadpisujemy.
            $upd = $contact['basis'] === 'client' ? array_diff_key($basis, ['basis' => 1, 'basis_detail' => 1, 'basis_at' => 1]) : $basis;
            // Wcześniejsza zgoda z innego źródła (np. z rozmowy na stronie) zostaje nietknięta razem ze swoją treścią.
            if (!empty($contact['marketing_consent_at'])) {
                unset($upd['marketing_consent_at'], $upd['marketing_consent_text']);
            }
            foreach (['person' => $c['person'], 'phone' => $c['phone'], 'nip' => $p['nip'] ?? null, 'website' => $p['website'] ?? null, 'company_id' => $p['company_id'] ?? null] as $k => $v) {
                if (empty($contact[$k]) && !empty($v)) {
                    $upd[$k] = $v;
                }
            }
            if ($upd) {
                $db->update('contacts', $upd, 'id = ?', [(int) $contact['id']]);
            }
            $contactId = (int) $contact['id'];
        } else {
            $contactId = $db->insert('contacts', $basis + [
                'org_id' => $orgId, 'company_name' => mb_substr((string) $p['name'], 0, 200), 'nip' => $p['nip'] ?? null, 'email' => $c['email'], 'person' => $c['person'],
                'phone' => $c['phone'], 'website' => $p['website'] ?? null, 'company_id' => $p['company_id'] ?? null,
                'notes' => $offer ? 'Z listy potencjalnych klientów. Firma sama zostawiła adres i zgodę.'
                    : 'Z listy potencjalnych klientów. Firma poprosiła o raport z audytu strony. Zgody na ofertę nie zaznaczyła.',
                'created_at' => $now,
            ] + ($offer ? [] : ['basis' => 'none']));
        }
        $db->insert('consents', array_intersect_key($c, array_flip(['email', 'phone', 'person', 'channels', 'wording', 'source', 'detail', 'ip_hash', 'user_agent', 'created_by']))
            + ['org_id' => $orgId, 'prospect_id' => (int) $p['id'], 'contact_id' => $contactId, 'created_at' => $now]);
        $facts = Prospects::facts($p);
        if ($offer) {
            $facts['consent'] = ['at' => $now, 'email' => $c['email'], 'source' => $c['source'], 'phone' => $c['phone'] !== null];
        }
        if (in_array('report', explode(',', $c['channels']), true)) {
            $facts['report_request'] = ['at' => $now, 'email' => $c['email']];
        }
        // Firma prosi o ofertę albo raport, więc wraca do wybranych, także po wysłanym liście i po wcześniejszym sprzeciwie.
        $db->update('prospects', ['contact_id' => $contactId, 'facts_json' => json_out($facts), 'status' => 'picked', 'updated_at' => $now], 'id = ?', [(int) $p['id']]);
        return ['ok' => true, 'email' => $c['email'], 'contact_id' => $contactId];
    }

    /**
     * Wycofanie zgody bez sprzeciwu wobec całej korespondencji: firma odwołała zgodę albo wpis był pomyłką.
     * Kontakt traci podstawę „zgoda”, dowód zostaje w bazie z datą wycofania.
     */
    public static function withdraw(int $orgId, array $p): bool
    {
        $db = Db::get();
        $rows = $db->all('SELECT id, contact_id FROM consents WHERE org_id = ? AND prospect_id = ? AND withdrawn_at IS NULL', [$orgId, (int) $p['id']]);
        if (!$rows) {
            return false;
        }
        $db->update('consents', ['withdrawn_at' => now_utc()], 'org_id = ? AND prospect_id = ? AND withdrawn_at IS NULL', [$orgId, (int) $p['id']]);
        foreach (array_unique(array_filter(array_map(static fn ($r) => (int) $r['contact_id'], $rows))) as $contactId) {
            // Zgoda z innego źródła (np. z widżetu na stronie) albo inna ważna zgoda z listy zostaje.
            if ($db->val("SELECT 1 FROM consents WHERE org_id = ? AND contact_id = ? AND withdrawn_at IS NULL AND channels LIKE '%email%' LIMIT 1", [$orgId, $contactId])) {
                continue;
            }
            $db->run("UPDATE contacts SET basis = 'none', basis_detail = NULL, basis_at = NULL WHERE org_id = ? AND id = ? AND basis = 'consent'", [$orgId, $contactId]);
            $db->run('UPDATE contacts SET marketing_consent_at = NULL, marketing_consent_text = NULL WHERE org_id = ? AND id = ? AND marketing_consent_text IN (SELECT wording FROM consents WHERE org_id = ? AND contact_id = ?)',
                [$orgId, $contactId, $orgId, $contactId]);
        }
        $facts = Prospects::facts($p);
        unset($facts['consent'], $facts['report_request']);
        $db->update('prospects', ['facts_json' => json_out($facts), 'updated_at' => now_utc()], 'id = ? AND org_id = ?', [(int) $p['id'], $orgId]);
        return true;
    }

    /**
     * Powiadomienie zespołu i potwierdzenie dla firmy. Potwierdzenie nie zawiera oferty: mówi, co zapisaliśmy i jak to cofnąć.
     * Gdy firma poprosiła o raport, potwierdzenie niesie jego adres, bo o tę wiadomość sama poprosiła.
     */
    private static function notify(array $p, array $res, string $panelBase, ?array $report = null): void
    {
        try {
            $orgId = (int) $p['org_id'];
            $offer = !empty($res['offer']);
            $card = ($panelBase !== '' ? rtrim($panelBase, '/') : '') . '/klienci/potencjalni/' . (int) $p['id'];
            Mailer::notifyOrg($orgId, ($offer ? 'Zgoda na ofertę e-mailem: ' : 'Prośba o raport z audytu: ') . truncate((string) $p['name'], 80), implode("\n", array_filter([
                $offer ? 'Firma z listy potencjalnych klientów sama zostawiła adres e-mail i zgodę na ofertę.'
                    : 'Firma z listy potencjalnych klientów poprosiła o raport z audytu swojej strony. Zgody na ofertę nie zaznaczyła.',
                '',
                'Firma: ' . $p['name'],
                'Adres: ' . $res['email'],
                $report !== null ? 'Raport wysłaliśmy automatycznie: ' . $report['url'] : null,
                '',
                $offer ? 'Oferta na ten adres ma teraz podstawę. Karta firmy: ' . $card
                    : 'Oferta e-mailem nadal wymaga zgody. Raport prowadzi z powrotem na stronę zgody. Karta firmy: ' . $card,
            ], static fn ($l) => $l !== null)), 'consent');
            $contact = Outreach::contact($orgId, (int) $res['contact_id']);
            $unsub = $contact ? Outreach::unsubUrl($contact, $panelBase) : '';
            $subject = $report !== null ? 'Raport z audytu strony ' . $report['host'] : 'Potwierdzenie zgody na ofertę od firmy ' . $p['org_name'];
            Mailer::send((string) $res['email'], $subject, implode("\n", array_filter([
                'Dzień dobry,',
                '',
                $report !== null ? 'raport z audytu strony ' . $report['host'] . ', o który Państwo poprosili, jest tutaj: ' . $report['url'] : null,
                $report !== null ? '' : null,
                $offer
                    ? ($report !== null ? 'Potwierdzamy też' : 'potwierdzamy') . ' zapisanie zgody na przesłanie oferty firmy ' . $p['org_name'] . ' na ten adres e-mail. Ofertę wyślemy osobną wiadomością.'
                    : 'Zgody na ofertę Państwo nie zaznaczyli, więc nie wyślemy żadnej innej wiadomości. Raport przygotowała firma ' . $p['org_name'] . '.',
                '',
                $unsub === '' ? 'Jeśli to pomyłka albo zmienili Państwo zdanie, wystarczy odpisać „rezygnuję”.'
                    : ($offer ? 'Jeśli to pomyłka albo zmienili Państwo zdanie, zgodę można wycofać tutaj: ' : 'Jeśli to pomyłka, adres można wypisać tutaj: ') . $unsub,
            ], static fn ($l) => $l !== null)), $orgId, 'consent_receipt');
        } catch (\Throwable $e) {
            error_log('[consents] powiadomienie: ' . $e->getMessage());
        }
    }

    /** Ostatnia ważna zgoda firmy z listy na ofertę e-mailem (dowód do pokazania na karcie). Sama prośba o raport zgodą nie jest. */
    public static function latest(int $orgId, int $prospectId): ?array
    {
        return Db::get()->one("SELECT * FROM consents WHERE org_id = ? AND prospect_id = ? AND withdrawn_at IS NULL AND channels LIKE '%email%' ORDER BY id DESC LIMIT 1", [$orgId, $prospectId]);
    }

    /** Ostatnia prośba firmy o raport z audytu. */
    public static function reportRequest(int $orgId, int $prospectId): ?array
    {
        return Db::get()->one("SELECT * FROM consents WHERE org_id = ? AND prospect_id = ? AND withdrawn_at IS NULL AND channels LIKE '%report%' ORDER BY id DESC LIMIT 1", [$orgId, $prospectId]);
    }

    /** Zgody ze strony zgody od podanej chwili, do porannego przeglądu. */
    public static function since(int $orgId, string $sinceUtc): array
    {
        return Db::get()->all(
            "SELECT c.*, p.name AS company_name FROM consents c LEFT JOIN prospects p ON p.id = c.prospect_id
              WHERE c.org_id = ? AND c.source = 'page' AND c.withdrawn_at IS NULL AND c.created_at > ? ORDER BY c.id DESC LIMIT 20",
            [$orgId, $sinceUtc]
        );
    }

    /** Firmy, które zostawiły zgodę, a oferta jeszcze nie wyszła. */
    public static function waiting(int $orgId): array
    {
        return Db::get()->all(
            "SELECT p.id, p.name, MAX(c.created_at) AS consent_at FROM consents c JOIN prospects p ON p.id = c.prospect_id
              WHERE c.org_id = ? AND c.withdrawn_at IS NULL AND c.channels LIKE '%email%' AND p.status IN ('new', 'picked') GROUP BY p.id, p.name ORDER BY consent_at DESC LIMIT 10",
            [$orgId]
        );
    }

    /** Wycofanie zgody: kontakt się wypisał albo firma zgłosiła sprzeciw. */
    public static function withdrawForContact(int $orgId, int $contactId): void
    {
        $db = Db::get();
        $db->update('consents', ['withdrawn_at' => now_utc()], 'org_id = ? AND contact_id = ? AND withdrawn_at IS NULL', [$orgId, $contactId]);
        foreach ($db->all('SELECT * FROM prospects WHERE org_id = ? AND contact_id = ?', [$orgId, $contactId]) as $p) {
            $facts = Prospects::facts($p);
            if (isset($facts['consent']) || isset($facts['report_request'])) {
                unset($facts['consent'], $facts['report_request']);
                $db->update('prospects', ['facts_json' => json_out($facts)], 'id = ?', [(int) $p['id']]);
            }
        }
    }
}
