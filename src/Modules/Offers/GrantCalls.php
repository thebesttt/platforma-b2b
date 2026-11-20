<?php

declare(strict_types=1);

namespace App\Modules\Offers;

use App\Core\Db;

/**
 * Nabory dotacji, które firma obserwuje. Serwisy z naborami nie udostępniają danych programom, więc panel pokazuje,
 * gdzie szukać, a użytkownik wpisuje wybrany nabór. Panel pilnuje terminów i zakłada z naboru szkic wniosku.
 */
final class GrantCalls
{
    public const STATUSES = ['watch' => 'obserwuję', 'apply' => 'piszemy wniosek', 'skip' => 'nie dla nas'];

    /** Przewodnik: oficjalne miejsca z naborami. */
    public const SOURCES = [
        [
            'name' => 'Wyszukiwarka dotacji',
            'url' => 'https://www.funduszeunijne.gov.pl/wyszukiwarka/',
            'link' => 'funduszeunijne.gov.pl/wyszukiwarka',
            'what' => 'Kreator: wybierasz, kim jesteś i na co szukasz pieniędzy, a serwis pokazuje pasujące nabory.',
        ],
        [
            'name' => 'Ogłoszenia o naborach',
            'url' => 'https://www.funduszeunijne.gov.pl/strony/skorzystaj/nabory/',
            'link' => 'funduszeunijne.gov.pl/strony/skorzystaj/nabory',
            'what' => 'Lista ogłoszonych naborów z terminami i dokumentami.',
        ],
        [
            'name' => 'Przegląd aktualnych naborów',
            'url' => 'https://funduszeeuropejskie.gov.pl/aktualne-nabory/',
            'link' => 'funduszeeuropejskie.gov.pl/aktualne-nabory',
            'what' => 'Zestawienie naborów publikowane co miesiąc.',
        ],
        [
            'name' => 'Harmonogramy programów',
            'url' => 'https://funduszeeuropejskie.gov.pl/nabory/',
            'link' => 'funduszeeuropejskie.gov.pl/nabory',
            'what' => 'Plany naborów poszczególnych programów: sprawdzisz, co dopiero się otworzy.',
        ],
        [
            'name' => 'Harmonogram naborów PARP',
            'url' => 'https://www.parp.gov.pl/harmonogram-naborow',
            'link' => 'parp.gov.pl/harmonogram-naborow',
            'what' => 'Nabory dla firm prowadzone przez Polską Agencję Rozwoju Przedsiębiorczości.',
        ],
    ];

    /** Przypomnienia w porannym przeglądzie: dokładnie tyle dni przed końcem naboru oraz codziennie od LAST_DAYS dni. */
    public const REMIND_DAYS = [14, 7];
    public const LAST_DAYS = 3;
    /** Pulpit pokazuje nabory, które kończą się w ciągu tylu dni. */
    public const BOARD_DAYS = 14;

    /** @return array{ok: bool, id?: int, error?: string} */
    public static function save(int $orgId, int $userId, array $in, int $id = 0): array
    {
        $db = Db::get();
        if ($id && !self::find($orgId, $id)) {
            return ['ok' => false, 'error' => 'Nie ma takiego naboru.'];
        }
        $d = self::clean($in);
        $error = match (true) {
            $d['name'] === '' => 'Podaj nazwę naboru albo programu.',
            $d['url_raw'] !== '' && $d['url'] === null => 'Link do naboru musi zaczynać się od http:// albo https://.',
            $d['opens_raw'] !== '' && $d['opens_at'] === null, $d['closes_raw'] !== '' && $d['closes_at'] === null => 'Data ma nieczytelny zapis.',
            $d['opens_at'] !== null && $d['closes_at'] !== null && $d['closes_at'] < $d['opens_at'] => 'Koniec naboru wypada przed jego początkiem. Popraw daty.',
            default => null,
        };
        if ($error !== null) {
            return ['ok' => false, 'error' => $error];
        }
        unset($d['url_raw'], $d['opens_raw'], $d['closes_raw']);
        $now = now_utc();
        if ($id) {
            $db->update('grant_calls', $d + ['updated_at' => $now], 'id = ? AND org_id = ?', [$id, $orgId]);
        } else {
            $id = $db->insert('grant_calls', $d + ['org_id' => $orgId, 'status' => 'watch', 'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now]);
        }
        return ['ok' => true, 'id' => $id];
    }

    /** Pola formularza po oczyszczeniu. Klucze *_raw służą tylko do komunikatów o błędach. */
    public static function clean(array $in): array
    {
        $s = static fn (string $k, int $max) => mb_substr(utrim((string) ($in[$k] ?? '')), 0, $max);
        $url = $s('url', 500);
        return [
            'name' => $s('name', 200),
            'institution' => $s('institution', 200) ?: null,
            'url' => preg_match('#^https?://#i', $url) && filter_var($url, FILTER_VALIDATE_URL) ? $url : null,
            'opens_at' => form_date($s('opens_at', 10)),
            'closes_at' => form_date($s('closes_at', 10)),
            'amount_note' => $s('amount_note', 300) ?: null,
            'audience_note' => $s('audience_note', 300) ?: null,
            'notes' => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 2000) ?: null,
            'url_raw' => $url, 'opens_raw' => $s('opens_at', 20), 'closes_raw' => $s('closes_at', 20),
        ];
    }

    public static function find(int $orgId, int $id): ?array
    {
        return Db::get()->one('SELECT * FROM grant_calls WHERE id = ? AND org_id = ?', [$id, $orgId]);
    }

    private const LIST_SQL = 'SELECT g.*, o.title AS offer_title, o.status AS offer_status FROM grant_calls g LEFT JOIN offers o ON o.id = g.offer_id AND o.org_id = g.org_id';

    /** Nabory aktualne: obserwowane albo z rozpoczętym wnioskiem, przed końcem. Najbliższy koniec najpierw. */
    public static function current(int $orgId): array
    {
        return Db::get()->all(
            self::LIST_SQL . " WHERE g.org_id = ? AND g.status <> 'skip' AND (g.closes_at IS NULL OR g.closes_at >= ?)
              ORDER BY CASE WHEN g.closes_at IS NULL THEN 1 ELSE 0 END, g.closes_at, g.id DESC LIMIT 200",
            [$orgId, today_pl()]
        );
    }

    /** Nabory zakończone albo odrzucone. */
    public static function past(int $orgId, int $limit = 30): array
    {
        return Db::get()->all(
            self::LIST_SQL . " WHERE g.org_id = ? AND (g.status = 'skip' OR (g.closes_at IS NOT NULL AND g.closes_at < ?)) ORDER BY g.updated_at DESC, g.id DESC LIMIT " . max(1, $limit),
            [$orgId, today_pl()]
        );
    }

    public static function currentCount(int $orgId): int
    {
        return (int) Db::get()->val("SELECT COUNT(*) FROM grant_calls WHERE org_id = ? AND status <> 'skip' AND (closes_at IS NULL OR closes_at >= ?)", [$orgId, today_pl()]);
    }

    /** Aktualne nabory, które kończą się w ciągu podanej liczby dni. */
    public static function closingWithin(int $orgId, int $days): array
    {
        $today = today_pl();
        return Db::get()->all(
            self::LIST_SQL . " WHERE g.org_id = ? AND g.status <> 'skip' AND g.closes_at IS NOT NULL AND g.closes_at >= ? AND g.closes_at <= ? ORDER BY g.closes_at, g.id",
            [$orgId, $today, date('Y-m-d', strtotime($today . ' +' . max(0, $days) . ' days'))]
        );
    }

    /** Nabory do porannego przeglądu: 14 i 7 dni przed końcem, a potem codziennie od 3 dni. */
    public static function toRemind(int $orgId): array
    {
        return array_values(array_filter(self::closingWithin($orgId, max(self::REMIND_DAYS)), static function (array $g): bool {
            $n = (int) days_to_date($g['closes_at']);
            return $n <= self::LAST_DAYS || in_array($n, self::REMIND_DAYS, true);
        }));
    }

    public static function setStatus(int $orgId, int $id, string $status): bool
    {
        if (!isset(self::STATUSES[$status])) {
            return false;
        }
        return Db::get()->update('grant_calls', ['status' => $status, 'updated_at' => now_utc()], 'id = ? AND org_id = ?', [$id, $orgId]) > 0;
    }

    public static function delete(int $orgId, int $id): bool
    {
        return Db::get()->run('DELETE FROM grant_calls WHERE id = ? AND org_id = ?', [$id, $orgId])->rowCount() > 0;
    }

    /**
     * Szkic wniosku z naboru. Drugi raz nie powstaje: zwracamy istniejący.
     * @return array{offer_id: int, created: bool}
     */
    public static function startOffer(int $orgId, int $userId, array $call): array
    {
        $db = Db::get();
        if (!empty($call['offer_id']) && $db->val('SELECT id FROM offers WHERE id = ? AND org_id = ?', [(int) $call['offer_id'], $orgId])) {
            return ['offer_id' => (int) $call['offer_id'], 'created' => false];
        }
        // Godziny zamknięcia naboru nie znamy, więc termin wniosku to koniec ostatniego dnia. Dokładną godzinę podaje regulamin.
        $deadline = $call['closes_at'] ? gmdate('Y-m-d H:i:s', strtotime(substr((string) $call['closes_at'], 0, 10) . ' 23:59:00 Europe/Warsaw')) : null;
        $offerId = Offers::create($orgId, $userId, null, 'grant', ['call_name' => (string) $call['name'], 'call_url' => $call['url'], 'deadline_at' => $deadline]);
        $db->update('grant_calls', ['offer_id' => $offerId, 'status' => 'apply', 'updated_at' => now_utc()], 'id = ? AND org_id = ?', [(int) $call['id'], $orgId]);
        return ['offer_id' => $offerId, 'created' => true];
    }
}
