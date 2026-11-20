<?php

declare(strict_types=1);

namespace App\Modules\Outreach;

use App\Core\Db;
use App\Core\Nip;
use App\Modules\Companies\Companies;
use App\Modules\Tenders\Tenders;

/**
 * Zapytania ofertowe ogłaszane przez firmy. Miejsca, w których firmy je publikują, nie udostępniają danych programom,
 * więc panel pokazuje, gdzie szukać, a użytkownik wpisuje znalezione zapytanie. Od tej chwili panel pilnuje terminu,
 * zakłada kontakt z podstawą „zapytanie ofertowe” i przygotowuje odpowiedź.
 */
final class Rfqs
{
    public const STATUSES = ['new' => 'do odpowiedzi', 'replied' => 'oferta złożona', 'skipped' => 'pominięte'];

    /** Skąd pochodzi zapytanie (klucz zapisujemy w bazie). */
    public const SOURCE_LABELS = [
        'bk' => 'Baza Konkurencyjności',
        'pz' => 'Platforma zakupowa',
        'www' => 'Strona firmy',
        'mail' => 'E-mail albo telefon od firmy',
        'other' => 'Inne źródło',
    ];

    /** Przewodnik: gdzie firmy publikują zapytania i jak się tam składa ofertę. */
    public const SOURCES = [
        [
            'name' => 'Baza Konkurencyjności',
            'url' => 'https://bazakonkurencyjnosci.funduszeeuropejskie.gov.pl/',
            'link' => 'bazakonkurencyjnosci.funduszeeuropejskie.gov.pl',
            'what' => 'Zapytania firm i instytucji, które kupują w projektach z funduszy unijnych. Trafiają tu zamówienia powyżej 50 000 zł netto.',
            'how' => 'Ogłoszenia przeglądasz bez konta. Ofertę składa się w samej Bazie (zakładka „Oferty”), a pytania zadaje w zakładce „Pytania”, więc do odpowiedzi potrzebne jest konto.',
        ],
        [
            'name' => 'Platforma zakupowa',
            'url' => 'https://platformazakupowa.pl/all',
            'link' => 'platformazakupowa.pl/all',
            'what' => 'Bieżące postępowania zakupowe firm i urzędów.',
            'how' => 'Ofertę składa się zwykle przez formularz na stronie postępowania.',
        ],
        [
            'name' => 'Strony firm',
            'url' => null,
            'link' => null,
            'what' => 'Duże firmy i spółki komunalne publikują zapytania u siebie, w zakładkach „Zapytania ofertowe”, „Przetargi”, „Zakupy” albo „Dla dostawców”.',
            'how' => 'W zapytaniu jest adres e-mail do składania ofert. Wpisz go w formularzu poniżej, a panel przygotuje odpowiedź.',
        ],
    ];

    /** Na tyle dni przed terminem zapytanie trafia do porannego przeglądu i na pulpit. */
    public const SOON_DAYS = 3;

    /**
     * Zapis zapytania. Gdy jest adres e-mail, powstaje albo aktualizuje się kontakt z podstawą „zapytanie ofertowe”.
     * @return array{ok: bool, id?: int, contact?: ?string, error?: string}
     */
    public static function save(int $orgId, int $userId, array $in, int $id = 0): array
    {
        $db = Db::get();
        $old = $id ? self::find($orgId, $id) : null;
        if ($id && !$old) {
            return ['ok' => false, 'error' => 'Nie ma takiego zapytania.'];
        }
        $d = self::clean($in);
        $error = match (true) {
            $d['company_name'] === '' => 'Podaj nazwę firmy, która ogłosiła zapytanie.',
            $d['subject'] === '' => 'Wpisz, czego dotyczy zapytanie.',
            $d['email_raw'] !== '' && $d['email'] === null => 'Adres e-mail ma błąd. Popraw go albo zostaw pole puste.',
            $d['url_raw'] !== '' && $d['url'] === null => 'Link do zapytania musi zaczynać się od http:// albo https://.',
            $d['nip_raw'] !== '' && $d['nip'] === null => 'NIP ma błąd. Popraw go albo zostaw pole puste.',
            $d['deadline_raw'] !== '' && $d['deadline_at'] === null, $d['published_raw'] !== '' && $d['published_at'] === null => 'Data ma nieczytelny zapis.',
            $d['email'] === null && $d['url'] === null => 'Podaj link do zapytania albo adres e-mail z ogłoszenia. Bez nich nie będzie jak wrócić do zapytania ani odpowiedzieć.',
            default => null,
        };
        if ($error !== null) {
            return ['ok' => false, 'error' => $error];
        }
        unset($d['email_raw'], $d['url_raw'], $d['nip_raw'], $d['deadline_raw'], $d['published_raw']);
        [$contactId, $contact] = self::linkContact($orgId, $d);
        $now = now_utc();
        $row = $d + ['contact_id' => $contactId, 'updated_at' => $now];
        if ($old) {
            $db->update('rfqs', $row, 'id = ? AND org_id = ?', [$id, $orgId]);
        } else {
            $id = $db->insert('rfqs', $row + ['org_id' => $orgId, 'status' => 'new', 'created_by' => $userId, 'created_at' => $now]);
        }
        return ['ok' => true, 'id' => $id, 'contact' => $contact];
    }

    /** Pola formularza po oczyszczeniu. Klucze *_raw służą tylko do komunikatów o błędach. */
    public static function clean(array $in): array
    {
        $s = static fn (string $k, int $max) => mb_substr(utrim((string) ($in[$k] ?? '')), 0, $max);
        $email = mb_strtolower($s('email', 200));
        $url = $s('url', 500);
        $nip = Nip::clean($s('nip', 20));
        $source = (string) ($in['source'] ?? '');
        return [
            'company_name' => $s('company_name', 200),
            'nip' => Nip::valid($nip) ? $nip : null,
            'person' => $s('person', 120) ?: null,
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
            'subject' => $s('subject', 300),
            'url' => preg_match('#^https?://#i', $url) && filter_var($url, FILTER_VALIDATE_URL) ? $url : null,
            'source' => isset(self::SOURCE_LABELS[$source]) ? $source : 'other',
            'published_at' => form_date($s('published_at', 10)),
            'deadline_at' => form_date($s('deadline_at', 10)),
            'notes' => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 2000) ?: null,
            'email_raw' => $email, 'url_raw' => $url, 'nip_raw' => $nip, 'deadline_raw' => $s('deadline_at', 20), 'published_raw' => $s('published_at', 20),
        ];
    }

    /**
     * Kontakt do odpowiedzi e-mailem. Nowy dostaje podstawę „zapytanie ofertowe” z tematem, linkiem i datą jako dowodem.
     * U istniejącego zapytanie zastępuje tylko brak podstawy albo starsze zapytanie: zgoda i relacja z klientem zostają.
     * @return array{0: ?int, 1: ?string} [id kontaktu, 'created' | 'linked' | null]
     */
    private static function linkContact(int $orgId, array $d): array
    {
        if ($d['email'] === null) {
            return [null, null];
        }
        $db = Db::get();
        $basis = [
            'basis' => 'rfq',
            'basis_detail' => mb_substr($d['subject'], 0, 500),
            'basis_url' => $d['url'],
            'basis_at' => $d['published_at'] ?? today_pl(),
        ];
        $c = $db->one('SELECT * FROM contacts WHERE org_id = ? AND LOWER(email) = ? ORDER BY id LIMIT 1', [$orgId, $d['email']]);
        if (!$c) {
            $company = $d['nip'] ? Companies::byNip($d['nip']) : null;
            $id = $db->insert('contacts', [
                'org_id' => $orgId, 'company_name' => $d['company_name'], 'nip' => $d['nip'], 'person' => $d['person'], 'email' => $d['email'],
                'company_id' => $company ? (int) $company['id'] : null, 'created_at' => now_utc(),
            ] + $basis);
            return [$id, 'created'];
        }
        $upd = in_array($c['basis'], ['none', 'rfq'], true) ? $basis : [];
        if (empty($c['person']) && $d['person']) {
            $upd['person'] = $d['person'];
        }
        if (empty($c['nip']) && $d['nip']) {
            $upd['nip'] = $d['nip'];
        }
        if ($upd) {
            $db->update('contacts', $upd, 'id = ?', [(int) $c['id']]);
        }
        return [(int) $c['id'], 'linked'];
    }

    public static function find(int $orgId, int $id): ?array
    {
        return Db::get()->one('SELECT * FROM rfqs WHERE id = ? AND org_id = ?', [$id, $orgId]);
    }

    private const LIST_SQL = "SELECT r.*, c.unsubscribed_at, c.replied_at,
            (SELECT MAX(m.id) FROM messages m WHERE m.org_id = r.org_id AND m.contact_id = r.contact_id AND m.kind = 'rfq_reply' AND m.status = 'draft') AS draft_id
       FROM rfqs r LEFT JOIN contacts c ON c.id = r.contact_id";

    /** Zapytania czekające na odpowiedź: najbliższy termin najpierw, bez terminu na końcu. */
    public static function open(int $orgId): array
    {
        return Db::get()->all(self::LIST_SQL . " WHERE r.org_id = ? AND r.status = 'new' ORDER BY CASE WHEN r.deadline_at IS NULL THEN 1 ELSE 0 END, r.deadline_at, r.id DESC LIMIT 200", [$orgId]);
    }

    /** Zamknięte: oferta złożona albo pominięte. */
    public static function closed(int $orgId, int $limit = 30): array
    {
        return Db::get()->all(self::LIST_SQL . " WHERE r.org_id = ? AND r.status <> 'new' ORDER BY r.updated_at DESC, r.id DESC LIMIT " . max(1, $limit), [$orgId]);
    }

    public static function openCount(int $orgId): int
    {
        return (int) Db::get()->val("SELECT COUNT(*) FROM rfqs WHERE org_id = ? AND status = 'new'", [$orgId]);
    }

    /** Zapytania do odpowiedzi, których termin mija w ciągu kilku dni (dzisiejsze też). */
    public static function dueSoon(int $orgId, int $days = self::SOON_DAYS): array
    {
        $today = today_pl();
        return Db::get()->all(
            "SELECT * FROM rfqs WHERE org_id = ? AND status = 'new' AND deadline_at IS NOT NULL AND deadline_at >= ? AND deadline_at <= ? ORDER BY deadline_at, id",
            [$orgId, $today, date('Y-m-d', strtotime($today . ' +' . max(0, $days) . ' days'))]
        );
    }

    public static function setStatus(int $orgId, int $id, string $status): bool
    {
        if (!isset(self::STATUSES[$status])) {
            return false;
        }
        return Db::get()->update('rfqs', ['status' => $status, 'updated_at' => now_utc()], 'id = ? AND org_id = ?', [$id, $orgId]) > 0;
    }

    public static function delete(int $orgId, int $id): bool
    {
        return Db::get()->run('DELETE FROM rfqs WHERE id = ? AND org_id = ?', [$id, $orgId])->rowCount() > 0;
    }

    /** Po wysłaniu odpowiedzi do kontaktu jego otwarte zapytania przechodzą do „oferta złożona”. */
    public static function markReplied(int $orgId, int $contactId): int
    {
        return Db::get()->update('rfqs', ['status' => 'replied', 'updated_at' => now_utc()], "org_id = ? AND contact_id = ? AND status = 'new'", [$orgId, $contactId]);
    }

    /** Słowa z profili przetargowych firmy: te same wpisuje się w wyszukiwarkach zapytań. */
    public static function keywords(int $orgId): array
    {
        $words = [];
        foreach (Tenders::profiles($orgId, true) as $p) {
            foreach (preg_split('/[\r\n,;]+/', (string) ($p['keywords'] ?? '')) ?: [] as $w) {
                $w = utrim($w);
                if (mb_strlen($w) >= 3) {
                    $words[mb_strtolower($w)] = $w;
                }
            }
        }
        return array_slice(array_values($words), 0, 12);
    }
}
