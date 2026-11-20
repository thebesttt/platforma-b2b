<?php

declare(strict_types=1);

namespace App\Modules\Tenders;

use App\Core\Db;
use App\Core\Kv;

/**
 * Wyniki przetargów z BZP: kto wygrał, za ile i ile było ofert.
 * Import ma dwa kroki. Wyszukiwarka BZP daje wynik i wykonawcę każdej części (tanio, 10 ogłoszeń na zapytanie).
 * Ceny, liczba ofert i wartość umowy są tylko w treści ogłoszenia, więc doczytujemy je osobno, z limitem na jedno uruchomienie.
 */
final class Results
{
    public const LABELS = ['contract' => 'Umowa zawarta', 'cancelled' => 'Unieważniono', 'unresolved' => 'Nie rozstrzygnięto'];
    public const PERIODS = [30 => 'Ostatnie 30 dni', 90 => 'Ostatnie 90 dni', 365 => 'Ostatnie 12 miesięcy'];
    public const PER_PAGE = 40;

    /** Po tylu dniach wynik znika z bazy (zadanie dzienne). */
    public const KEEP_DAYS = 400;

    /** Pierwszy import sięga tyle dni wstecz. Starsze wyniki pobiera polecenie „php bin/console results <dni>”. */
    public const FIRST_DAYS = 7;

    /** @return array{rows: int, details: int, alerts: int, errors: string[]} */
    public static function import(?string $sinceUtc = null, int $detailLimit = 400, int $detailSeconds = 240): array
    {
        $errors = [];
        $saved = 0;
        $startedAt = now_utc();
        $from = $sinceUtc ?? (string) (Kv::get('import:bzp:results:until') ?: gmdate('Y-m-d H:i:s', time() - self::FIRST_DAYS * 86400));
        try {
            $bzp = new BzpClient();
            $seen = [];
            for ($round = 0; $round < Importer::MAX_ROUNDS; $round++) {
                $rows = array_values(array_filter($bzp->resultsSince($from), static function (array $r) use (&$seen): bool {
                    $k = $r['external_id'] . '|' . $r['part'];
                    if (isset($seen[$k])) {
                        return false;
                    }
                    return $seen[$k] = true;
                }));
                $saved += self::saveMany($rows);
                if ($bzp->failedRequests > 0) {
                    $errors[] = 'Wyniki BZP: serwer nie odpowiedział na ' . $bzp->failedRequests . ' ' . plural($bzp->failedRequests, 'zapytanie', 'zapytania', 'zapytań') . ' - brakujące wyniki pobierzemy przy następnym imporcie.';
                }
                if ($bzp->continueFrom === null || $bzp->continueFrom <= $from || $bzp->failedRequests > 0) {
                    break;
                }
                $from = $bzp->continueFrom;
            }
            if ($sinceUtc === null) {
                Kv::set('import:bzp:results:until', $bzp->continueFrom ?? gmdate('Y-m-d H:i:s', strtotime($startedAt . ' UTC') - 600));
            }
        } catch (\Throwable $e) {
            $errors[] = 'Wyniki BZP: ' . $e->getMessage();
        }
        $details = 0;
        $alerts = 0;
        try {
            self::backfillOcid();
            $details = self::fetchDetails($detailLimit, $detailSeconds);
            $alerts = self::alertSaved();
        } catch (\Throwable $e) {
            $errors[] = 'Wyniki BZP, treść ogłoszeń: ' . $e->getMessage();
        }
        $out = ['rows' => $saved, 'details' => $details, 'alerts' => $alerts, 'errors' => $errors];
        Kv::set('import:results:last', $out + ['at' => now_utc()]);
        return $out;
    }

    /** Zapis rekordów z wyszukiwarki. Danych odczytanych z treści ogłoszenia (ceny, oferty) nie rusza. */
    public static function saveMany(array $rows): int
    {
        $now = now_utc();
        $unique = [];
        foreach ($rows as $r) {
            $r['search'] = search_text(($r['title'] ?? '') . ' ' . ($r['buyer_name'] ?? '') . ' ' . ($r['buyer_city'] ?? '') . ' ' . ($r['winner_name'] ?? ''));
            $r['created_at'] = $now;
            $r['updated_at'] = $now;
            $unique[$r['source'] . '|' . $r['external_id'] . '|' . $r['part']] = $r;
        }
        foreach (array_chunk(array_values($unique), 100) as $chunk) {
            Db::get()->upsertMany('tender_results', $chunk, ['source', 'external_id', 'part'], ['winner_name', 'winner_nip', 'winner_city', 'winner_voivodeship', 'ocid'], ['created_at']);
        }
        return count($unique);
    }

    /**
     * Doczytuje treść ogłoszeń o wyniku: ceny, liczbę ofert, wartość umowy. Najpierw ogłoszenia z branż, które ktoś ma w profilu,
     * potem najnowsze. Zwraca liczbę odczytanych ogłoszeń.
     */
    public static function fetchDetails(int $limit = 400, int $seconds = 240): int
    {
        if ($limit <= 0) {
            return 0;
        }
        $db = Db::get();
        $cand = $db->all(
            "SELECT external_id, MAX(cpv_all) AS cpv_all, MAX(published_at) AS published_at FROM tender_results
              WHERE source = 'bzp' AND details_fetched_at IS NULL GROUP BY external_id ORDER BY MAX(published_at) DESC LIMIT 4000"
        );
        if (!$cand) {
            return 0;
        }
        $prefixes = self::profilePrefixes();
        $wanted = static function (array $c) use ($prefixes): int {
            foreach (explode(' ', (string) $c['cpv_all']) as $code) {
                foreach ($prefixes as $p) {
                    if ($code !== '' && str_starts_with($code, $p)) {
                        return 0;
                    }
                }
            }
            return 1;
        };
        // Sortowanie stabilne: w każdej grupie zostaje kolejność od najnowszych.
        usort($cand, static fn (array $a, array $b) => $wanted($a) <=> $wanted($b));
        $client = new BzpClient();
        $deadline = microtime(true) + $seconds;
        $done = 0;
        foreach (array_slice($cand, 0, $limit) as $c) {
            if (microtime(true) > $deadline) {
                break;
            }
            $html = $client->noticeHtml((string) $c['external_id']);
            if ($html === null) {
                continue; // błąd sieci: spróbujemy przy następnym uruchomieniu
            }
            self::applyDetails((string) $c['external_id'], BzpNoticeParser::results($html));
            $done++;
            usleep(BzpClient::$pauseUs);
        }
        return $done;
    }

    /** Zapisuje dane z treści ogłoszenia przy każdej jego części. */
    public static function applyDetails(string $externalId, array $parsed): void
    {
        $db = Db::get();
        $now = now_utc();
        $rows = [];
        foreach ($db->all("SELECT * FROM tender_results WHERE source = 'bzp' AND external_id = ?", [$externalId]) as $r) {
            $rows[(int) $r['part']] = $r;
        }
        if (!$rows) {
            return;
        }
        if (!$parsed['parts']) {
            // Nieznany układ ogłoszenia: zostają dane z wyszukiwarki, a ogłoszenia nie pobieramy w kółko.
            error_log('[bzp] nie rozpoznano układu ogłoszenia o wyniku ' . $externalId);
            $db->update('tender_results', ['details_fetched_at' => $now, 'updated_at' => $now], "source = 'bzp' AND external_id = ?", [$externalId]);
            return;
        }
        $template = reset($rows);
        foreach ($parsed['parts'] as $p) {
            $no = (int) $p['part'];
            $w = $p['winners'][0] ?? [];
            $upd = [
                'result' => $p['result'],
                'base_notice' => $parsed['base_notice'],
                'offers' => $p['offers'] ?? null,
                'price_lowest' => $p['price_lowest'] ?? null,
                'price_highest' => $p['price_highest'] ?? null,
                'price_winner' => $p['price_winner'] ?? null,
                'contract_value' => $p['contract_value'] ?? null,
                'est_value' => $p['est_value'] ?? null,
                'currency' => $p['currency'] ?? null,
                'contract_date' => $p['contract_date'] ?? null,
                'details_json' => json_out(array_filter([
                    'consortium' => !empty($p['consortium']),
                    'partners' => $p['partners'] ?? null,
                    'size' => $w['size'] ?? null,
                    'period' => $p['period'] ?? null,
                    'offers_sme' => $p['offers_sme'] ?? null,
                    'offers_rejected' => $p['offers_rejected'] ?? null,
                    'cancel_basis' => $p['cancel_basis'] ?? null,
                    'cancel_reason' => isset($p['cancel_reason']) ? truncate((string) $p['cancel_reason'], 600) : null,
                    'more_winners' => count($p['winners']) > 1 ? array_slice(array_column($p['winners'], 'name'), 1, 10) : null,
                ], static fn ($v) => $v !== null && $v !== false)),
                'details_fetched_at' => $now,
                'updated_at' => $now,
            ];
            if (!empty($w['name'])) {
                $nip = \App\Core\Nip::clean((string) ($w['nip'] ?? ''));
                $upd['winner_name'] = truncate((string) $w['name'], 300);
                if (\App\Core\Nip::valid($nip)) {
                    $upd['winner_nip'] = $nip;
                }
                if (!empty($w['city'])) {
                    $upd['winner_city'] = $w['city'];
                }
                if (!empty($w['voivodeship'])) {
                    $upd['winner_voivodeship'] = $w['voivodeship'];
                }
            }
            if (isset($rows[$no])) {
                if (isset($upd['winner_name'])) {
                    $upd['search'] = search_text($rows[$no]['title'] . ' ' . $rows[$no]['buyer_name'] . ' ' . $rows[$no]['buyer_city'] . ' ' . $upd['winner_name']);
                }
                $db->update('tender_results', $upd, 'id = ?', [(int) $rows[$no]['id']]);
                unset($rows[$no]);
                continue;
            }
            // Część jest w treści, a wyszukiwarka jej nie podała: dopisujemy ją z danymi ogłoszenia.
            $new = array_diff_key($template, ['id' => 1]);
            foreach (['winner_name', 'winner_nip', 'winner_city', 'winner_voivodeship'] as $k) {
                $new[$k] = null;
            }
            $new = array_merge($new, $upd, ['part' => $no, 'created_at' => $now]);
            $new['search'] = search_text($new['title'] . ' ' . $new['buyer_name'] . ' ' . $new['buyer_city'] . ' ' . ($new['winner_name'] ?? ''));
            $db->insert('tender_results', $new);
        }
        // Części z wyszukiwarki, których nie ma w treści: oznaczamy jako sprawdzone, żeby nie wracały do kolejki.
        foreach ($rows as $r) {
            $db->update('tender_results', ['details_fetched_at' => $now, 'base_notice' => $parsed['base_notice'], 'updated_at' => $now], 'id = ?', [(int) $r['id']]);
        }
    }

    /** Prefiksy CPV ze wszystkich aktywnych profili (do wyboru, które wyniki doczytać najpierw). */
    private static function profilePrefixes(): array
    {
        $out = [];
        foreach (Db::get()->all('SELECT cpv FROM tender_profiles WHERE active = 1') as $p) {
            foreach (self::prefixes((string) $p['cpv']) as $x) {
                $out[$x] = true;
            }
        }
        return array_keys($out);
    }

    /** „45233000 72” → ['45233', '72'] */
    public static function prefixes(string $cpv): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', $cpv) ?: [] as $x) {
            $x = Matcher::cpvPrefix($x);
            if ($x !== '') {
                $out[] = $x;
            }
        }
        return array_values(array_unique($out));
    }

    /** Starsze przetargi nie miały zapisanego identyfikatora postępowania: uzupełniamy go z zapisanej odpowiedzi BZP. */
    public static function backfillOcid(int $limit = 2000): int
    {
        $db = Db::get();
        $n = 0;
        foreach ($db->all("SELECT id, raw_json FROM tenders WHERE source = 'bzp' AND ocid IS NULL AND raw_json IS NOT NULL ORDER BY id DESC LIMIT " . $limit) as $t) {
            $ocid = (string) (json_in($t['raw_json'])['tenderId'] ?? '');
            // Pusty tekst zamiast NULL przy braku identyfikatora: wiersz nie wraca do kolejki.
            $db->update('tenders', ['ocid' => $ocid], 'id = ?', [(int) $t['id']]);
            $n++;
        }
        return $n;
    }

    /** Alert dla firm, które zapisały przetarg, a jego wynik właśnie się pojawił. Jeden alert na przetarg i firmę. */
    public static function alertSaved(): int
    {
        $db = Db::get();
        $rows = $db->all(
            "SELECT m.org_id, t.id AS tender_id, t.title, t.ocid
               FROM tender_matches m JOIN tenders t ON t.id = m.tender_id
              WHERE m.status = 'saved' AND t.ocid IS NOT NULL AND t.ocid <> ''
                AND EXISTS (SELECT 1 FROM tender_results r WHERE r.ocid = t.ocid)
                AND NOT EXISTS (SELECT 1 FROM alerts a WHERE a.org_id = m.org_id AND a.tender_id = t.id AND a.company_id IS NULL)
              GROUP BY m.org_id, t.id, t.title, t.ocid"
        );
        foreach ($rows as $x) {
            $parts = self::byOcid((string) $x['ocid']);
            $db->insert('alerts', [
                'org_id' => (int) $x['org_id'],
                'tender_id' => (int) $x['tender_id'],
                'level' => 'info',
                'title' => 'Rozstrzygnięto przetarg: ' . truncate((string) $x['title'], 110),
                'detail' => self::summary($parts),
                'created_at' => now_utc(),
            ]);
        }
        return count($rows);
    }

    /** Jedno zdanie o wyniku, do alertu i e-maila. */
    public static function summary(array $parts): string
    {
        $won = array_values(array_filter($parts, static fn (array $p) => $p['result'] === 'contract' && $p['winner_name']));
        if (!$won) {
            $r = $parts[0]['result'] ?? 'unresolved';
            return $r === 'cancelled' ? 'Postępowanie unieważniono.' : 'Postępowania nie rozstrzygnięto.';
        }
        $first = $won[0];
        $value = $first['contract_value'] ?? $first['price_winner'] ?? null;
        $s = 'Wygrał: ' . truncate((string) $first['winner_name'], 90) . ($value ? ', ' . money_pl($value) : '');
        if ($first['offers']) {
            $s .= ', ' . (int) $first['offers'] . ' ' . plural((int) $first['offers'], 'oferta', 'oferty', 'ofert');
        }
        if (count($parts) > 1) {
            $s .= '. Części w ogłoszeniu: ' . count($parts);
        }
        return $s . '.';
    }

    /** Wynik postępowania po identyfikatorze (wszystkie części). */
    public static function byOcid(string $ocid): array
    {
        return $ocid === '' ? [] : Db::get()->all('SELECT * FROM tender_results WHERE ocid = ? ORDER BY published_at DESC, part', [$ocid]);
    }

    /** Wynik dla przetargu z naszej bazy. Gdy otwiera go użytkownik, a treści jeszcze nie odczytaliśmy, robimy to od razu. */
    public static function forTender(array $tender, bool $fetch = true): array
    {
        $ocid = (string) ($tender['ocid'] ?? '') ?: (string) (json_in($tender['raw_json'] ?? null)['tenderId'] ?? '');
        $rows = self::byOcid($ocid);
        if ($fetch && $rows) {
            $pending = array_unique(array_column(array_filter($rows, static fn (array $r) => !$r['details_fetched_at']), 'external_id'));
            foreach (array_slice($pending, 0, 2) as $ext) {
                $html = (new BzpClient())->noticeHtml((string) $ext);
                if ($html !== null) {
                    self::applyDetails((string) $ext, BzpNoticeParser::results($html));
                }
            }
            if ($pending) {
                $rows = self::byOcid($ocid);
            }
        }
        return $rows;
    }

    /**
     * Warunek SQL z filtrów listy wyników.
     * @param array{q?: string, woj?: string, rodzaj?: string, cpv?: string, dni?: int, profile?: ?array, wynik?: string} $f
     * @return array{0: string, 1: array}
     */
    public static function where(array $f): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (!empty($f['dni'])) {
            $where[] = 'r.published_at > ?';
            $params[] = gmdate('Y-m-d H:i:s', time() - (int) $f['dni'] * 86400);
        }
        foreach (array_filter(explode(' ', search_text((string) ($f['q'] ?? '')))) as $word) {
            $where[] = 'r.search LIKE ?';
            $params[] = '%' . $word . '%';
        }
        if (!empty($f['woj'])) {
            $where[] = 'r.voivodeship = ?';
            $params[] = $f['woj'];
        }
        if (!empty($f['rodzaj'])) {
            $where[] = 'r.order_type = ?';
            $params[] = $f['rodzaj'];
        }
        if (!empty($f['wynik'])) {
            $where[] = 'r.result = ?';
            $params[] = $f['wynik'];
        }
        $cpv = self::prefixes((string) ($f['cpv'] ?? ''));
        $p = $f['profile'] ?? null;
        if ($p) {
            // Profil: branża (kod CPV albo słowo kluczowe) oraz województwa i rodzaje zamówień z profilu.
            $any = [];
            foreach (self::prefixes((string) ($p['cpv'] ?? '')) as $x) {
                $any[] = "(' ' || r.cpv_all) LIKE ?";
                $params[] = '% ' . $x . '%';
            }
            foreach (Matcher::lines($p['keywords'] ?? '') as $kw) {
                if ($kw !== '') {
                    $any[] = 'r.search LIKE ?';
                    $params[] = '%' . $kw . '%';
                }
            }
            if ($any) {
                $where[] = '(' . implode(' OR ', $any) . ')';
            }
            foreach (['voivodeships' => 'r.voivodeship', 'order_types' => 'r.order_type'] as $col => $sqlCol) {
                $vals = array_values(array_filter(preg_split('/\s+/', trim((string) ($p[$col] ?? ''))) ?: []));
                if ($vals) {
                    $where[] = $sqlCol . ' IN (' . implode(', ', array_fill(0, count($vals), '?')) . ')';
                    array_push($params, ...$vals);
                }
            }
        }
        foreach ($cpv as $x) {
            $where[] = "(' ' || r.cpv_all) LIKE ?";
            $params[] = '% ' . $x . '%';
        }
        return [implode(' AND ', $where), $params];
    }

    /** Lista wyników (jedna pozycja na część) i ich liczba. */
    public static function search(array $f, int $page = 1): array
    {
        [$w, $params] = self::where($f);
        $db = Db::get();
        $total = (int) $db->val("SELECT COUNT(*) FROM tender_results r WHERE $w", $params);
        $rows = $db->all(
            "SELECT r.* FROM tender_results r WHERE $w ORDER BY r.published_at DESC, r.id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . ((max(1, $page) - 1) * self::PER_PAGE),
            $params
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Kto wygrywa przy tych filtrach: wykonawcy według liczby zawartych umów.
     * Wartość to wartość umowy, a gdy jej nie znamy (treść jeszcze nieodczytana), cena wybranej oferty.
     */
    public static function ranking(array $f, int $limit = 15): array
    {
        [$w, $params] = self::where(['wynik' => 'contract'] + $f);
        return Db::get()->all(
            "SELECT MAX(r.winner_name) AS winner_name, r.winner_nip, MAX(r.winner_city) AS winner_city, COUNT(*) AS wins,
                    SUM(COALESCE(r.contract_value, r.price_winner, 0)) AS total, COUNT(COALESCE(r.contract_value, r.price_winner)) AS valued,
                    AVG(r.offers) AS avg_offers, MAX(r.published_at) AS last_at
               FROM tender_results r
              WHERE $w AND r.winner_name IS NOT NULL
              GROUP BY COALESCE(r.winner_nip, r.winner_name), r.winner_nip
              ORDER BY wins DESC, total DESC LIMIT " . max(1, $limit),
            $params
        );
    }

    /** Liczby do nagłówka listy: umowy, unieważnienia, mediana liczby ofert liczona z odczytanych ogłoszeń. */
    public static function totals(array $f): array
    {
        [$w, $params] = self::where($f);
        $db = Db::get();
        $by = [];
        foreach ($db->all("SELECT r.result, COUNT(*) AS n FROM tender_results r WHERE $w GROUP BY r.result", $params) as $x) {
            $by[$x['result']] = (int) $x['n'];
        }
        $offers = array_map('intval', array_column($db->all("SELECT r.offers FROM tender_results r WHERE $w AND r.result = 'contract' AND r.offers IS NOT NULL ORDER BY r.offers LIMIT 5000", $params), 'offers'));
        $median = null;
        if ($offers) {
            $mid = intdiv(count($offers), 2);
            $median = count($offers) % 2 ? $offers[$mid] : ($offers[$mid - 1] + $offers[$mid]) / 2;
        }
        return [
            'contract' => $by['contract'] ?? 0,
            'cancelled' => $by['cancelled'] ?? 0,
            'unresolved' => $by['unresolved'] ?? 0,
            'median_offers' => $median,
            'with_details' => count($offers),
        ];
    }

    /** Wcześniejsze rozstrzygnięcia tego zamawiającego: kto u niego wygrywa. Najpierw z tej samej branży (3 cyfry CPV). */
    public static function buyerHistory(?string $buyerNip, ?string $cpvMain, int $limit = 6, ?string $skipOcid = null): array
    {
        if (!$buyerNip) {
            return [];
        }
        $rows = Db::get()->all(
            "SELECT * FROM tender_results WHERE buyer_nip = ? AND result = 'contract' AND winner_name IS NOT NULL" . ($skipOcid ? ' AND (ocid IS NULL OR ocid <> ?)' : '') . ' ORDER BY published_at DESC LIMIT 60',
            $skipOcid ? [$buyerNip, $skipOcid] : [$buyerNip]
        );
        $prefix = substr((string) $cpvMain, 0, 3);
        usort($rows, static fn (array $a, array $b) => (int) ($prefix !== '' && str_starts_with((string) $b['cpv_main'], $prefix)) <=> (int) ($prefix !== '' && str_starts_with((string) $a['cpv_main'], $prefix)));
        return array_slice($rows, 0, $limit);
    }

    /** Wygrane tej firmy: liczba, suma i ostatnie umowy. */
    public static function wins(string $nip, int $limit = 8): array
    {
        $db = Db::get();
        $sum = $db->one(
            "SELECT COUNT(*) AS n, SUM(COALESCE(contract_value, price_winner, 0)) AS total, MIN(published_at) AS first_at FROM tender_results WHERE winner_nip = ? AND result = 'contract'",
            [$nip]
        ) ?? ['n' => 0, 'total' => 0, 'first_at' => null];
        $rows = (int) $sum['n'] > 0
            ? $db->all("SELECT * FROM tender_results WHERE winner_nip = ? AND result = 'contract' ORDER BY published_at DESC LIMIT " . max(1, $limit), [$nip])
            : [];
        return ['count' => (int) $sum['n'], 'total' => (float) $sum['total'], 'first_at' => $sum['first_at'], 'rows' => $rows];
    }

    /** Od kiedy mamy wyniki w bazie (do opisu, jaki okres obejmują liczby). */
    public static function coverage(): array
    {
        $r = Db::get()->one('SELECT COUNT(*) AS n, MIN(published_at) AS first_at, MAX(published_at) AS last_at FROM tender_results') ?? [];
        return ['count' => (int) ($r['n'] ?? 0), 'first_at' => $r['first_at'] ?? null, 'last_at' => $r['last_at'] ?? null];
    }
}
