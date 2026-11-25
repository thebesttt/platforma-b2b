<?php

declare(strict_types=1);

namespace App\Modules\Tenders;

use App\Core\Db;

/**
 * Plany zamówień: zapis planów z BZP i zapowiedzi z TED oraz zapytania dla ekranu „Plany zamówień”.
 * Pozycja planu to zamówienie, którego zamawiający jeszcze nie ogłosił. Firma ma czas, żeby się przygotować,
 * a urząd często robi wcześniej rozeznanie rynku i zbiera oferty orientacyjne.
 */
final class Plans
{
    public const CATEGORIES = ['works' => 'Roboty budowlane', 'supplies' => 'Dostawy', 'services' => 'Usługi'];

    public const PER_PAGE = 30;

    /** Linie informacji dodatkowych, które każdy plan powtarza i które nie mówią nic o przedmiocie. */
    private const BOILERPLATE = '/^(Zamówienie jest odpowiednie dla MŚP|Zamówienie strategiczne|Zamówienie zastrzeżone|Charakter zamówienia)/u';

    /**
     * Zapisuje plan i jego pozycje. Starsza albo ta sama wersja niczego nie zmienia. Nowa wersja aktualizuje pozycje po numerze,
     * a pozycje, których w niej nie ma, oznacza jako wycofane (zapisane dopasowania zostają, ale znikają z listy do przejrzenia).
     * @return array{id: int, items: int, changed: bool}
     */
    public static function save(array $plan, array $items): array
    {
        $db = Db::get();
        return $db->transaction(static function (Db $db) use ($plan, $items): array {
            $now = now_utc();
            $old = $db->one('SELECT id, version, items_count FROM procurement_plans WHERE source = ? AND plan_key = ?', [$plan['source'], $plan['plan_key']]);
            if ($old && (int) $old['version'] >= (int) $plan['version'] && (int) $old['items_count'] > 0) {
                return ['id' => (int) $old['id'], 'items' => 0, 'changed' => false];
            }
            $row = [
                'source' => $plan['source'], 'plan_key' => $plan['plan_key'], 'notice_number' => $plan['notice_number'],
                'version' => (int) $plan['version'], 'year' => $plan['year'] ?? null, 'buyer_name' => $plan['buyer_name'] ?? null,
                'buyer_nip' => $plan['buyer_nip'] ?? null, 'buyer_city' => $plan['buyer_city'] ?? null, 'voivodeship' => $plan['voivodeship'] ?? null,
                'url' => $plan['url'] ?? null, 'published_at' => $plan['published_at'] ?? null, 'items_count' => 0, 'updated_at' => $now,
            ];
            if ($old) {
                $id = (int) $old['id'];
                $db->update('procurement_plans', $row, 'id = ?', [$id]);
            } else {
                $id = $db->insert('procurement_plans', $row + ['created_at' => $now]);
            }
            $existing = [];
            foreach ($db->all('SELECT id, position FROM plan_items WHERE plan_id = ?', [$id]) as $r) {
                $existing[(string) $r['position']] = (int) $r['id'];
            }
            $seen = [];
            $active = 0;
            foreach ($items as $i => $it) {
                $pos = trim((string) ($it['position'] ?? '')) ?: (string) ($i + 1);
                if (isset($seen[$pos])) {
                    $pos .= '#' . ($i + 1); // ten sam numer pozycji dwa razy w planie: zachowujemy obie
                }
                $seen[$pos] = true;
                $data = [
                    'section' => $it['section'] ?? null, 'category' => $it['category'] ?? null, 'subject' => (string) $it['subject'],
                    'mode' => $it['mode'] ?? null, 'value_estimated' => $it['value_estimated'] ?? null, 'currency' => $it['currency'] ?? null,
                    'term' => $it['term'] ?? null, 'quarter' => $it['quarter'] ?? null, 'month' => $it['month'] ?? null,
                    'info' => $it['info'] ?? null, 'sme' => $it['sme'] ?? null, 'cpv' => $it['cpv'] ?? null,
                    'search' => self::searchText((string) $it['subject'], (string) ($it['info'] ?? '')),
                    'status' => ($it['status'] ?? 'active') === 'removed' ? 'removed' : 'active',
                    'update_note' => $it['update_note'] ?? null, 'updated_at' => $now,
                ];
                $active += $data['status'] === 'active' ? 1 : 0;
                if (isset($existing[$pos])) {
                    $db->update('plan_items', $data, 'id = ?', [$existing[$pos]]);
                } else {
                    $db->insert('plan_items', $data + ['plan_id' => $id, 'position' => mb_substr($pos, 0, 40), 'created_at' => $now]);
                }
            }
            $gone = array_diff_key($existing, $seen);
            if ($gone) {
                $db->run("UPDATE plan_items SET status = 'removed', update_note = 'brak w nowej wersji planu', updated_at = ? WHERE id IN ("
                    . implode(', ', array_fill(0, count($gone), '?')) . ')', [$now, ...array_values($gone)]);
            }
            $db->update('procurement_plans', ['items_count' => $active], 'id = ?', [$id]);
            return ['id' => $id, 'items' => count($items), 'changed' => true];
        });
    }

    public static function searchText(string $subject, string $info): string
    {
        $lines = array_filter(preg_split('/\R/u', $info) ?: [], static fn ($l) => !preg_match(self::BOILERPLATE, trim($l)));
        return search_text($subject . ' ' . implode(' ', $lines));
    }

    /**
     * Dopasowane pozycje planów dla firmy.
     * @param array{profile?: int, status?: string, q?: string, when?: string} $f
     * @return array{rows: array, total: int}
     */
    public static function matches(int $orgId, array $f, int $page = 1): array
    {
        $db = Db::get();
        $where = ['m.org_id = ?', "i.status = 'active'"];
        $params = [$orgId];
        $status = $f['status'] ?? 'active';
        if ($status === 'active') {
            $where[] = "m.status <> 'dismissed'";
        } elseif (in_array($status, ['new', 'saved', 'dismissed'], true)) {
            $where[] = 'm.status = ?';
            $params[] = $status;
        }
        if (!empty($f['profile'])) {
            $where[] = 'm.profile_id = ?';
            $params[] = (int) $f['profile'];
        }
        if (($f['q'] ?? '') !== '') {
            $terms = self::likeTerms((string) $f['q']);
            $where[] = '((' . implode(' AND ', array_fill(0, count($terms), 'i.search LIKE ?')) . ') OR p.buyer_name LIKE ?)';
            array_push($params, ...$terms);
            $params[] = '%' . str_replace(['%', '_'], '', (string) $f['q']) . '%';
        }
        self::whenFilter($f['when'] ?? 'upcoming', $where, $params);
        $sqlWhere = implode(' AND ', $where);
        $from = 'FROM plan_matches m JOIN plan_items i ON i.id = m.item_id JOIN procurement_plans p ON p.id = i.plan_id';
        $total = (int) $db->val("SELECT COUNT(DISTINCT i.id) $from WHERE $sqlWhere", $params);
        $rows = $db->all(
            "SELECT i.*, p.buyer_name, p.buyer_nip, p.buyer_city, p.voivodeship, p.year, p.url, p.source, p.published_at, p.notice_number,
                    MAX(m.score) AS score, MAX(m.status) AS match_status, MAX(m.reasons_json) AS reasons_json
             $from WHERE $sqlWhere
             GROUP BY i.id, p.id
             ORDER BY COALESCE(p.year, 0) DESC, CASE WHEN i.quarter IS NULL THEN 5 ELSE i.quarter END, MAX(m.score) DESC, i.id DESC
             LIMIT " . self::PER_PAGE . ' OFFSET ' . (max(0, $page - 1) * self::PER_PAGE),
            $params
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Wyszukiwanie we wszystkich planach (bez profilu).
     * @param array{q?: string, woj?: string, cat?: string, when?: string} $f
     * @return array{rows: array, total: int}
     */
    public static function search(array $f, int $page = 1): array
    {
        $db = Db::get();
        $where = ["i.status = 'active'", 'p.year >= ?'];
        $params = [(int) gmdate('Y')];
        foreach (self::likeTerms((string) ($f['q'] ?? '')) as $term) {
            $where[] = 'i.search LIKE ?';
            $params[] = $term;
        }
        if (($f['woj'] ?? '') !== '') {
            $where[] = 'p.voivodeship = ?';
            $params[] = (string) $f['woj'];
        }
        if (isset(self::CATEGORIES[$f['cat'] ?? ''])) {
            $where[] = 'i.category = ?';
            $params[] = (string) $f['cat'];
        }
        self::whenFilter($f['when'] ?? 'upcoming', $where, $params);
        $sqlWhere = implode(' AND ', $where);
        $from = 'FROM plan_items i JOIN procurement_plans p ON p.id = i.plan_id';
        $total = (int) $db->val("SELECT COUNT(*) $from WHERE $sqlWhere", $params);
        $rows = $db->all(
            "SELECT i.*, p.buyer_name, p.buyer_nip, p.buyer_city, p.voivodeship, p.year, p.url, p.source, p.published_at, p.notice_number
             $from WHERE $sqlWhere
             ORDER BY COALESCE(p.year, 0) DESC, CASE WHEN i.quarter IS NULL THEN 5 ELSE i.quarter END, i.value_estimated DESC, i.id DESC
             LIMIT " . self::PER_PAGE . ' OFFSET ' . (max(0, $page - 1) * self::PER_PAGE),
            $params
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Prosty rdzeń polskiego słowa do wyszukiwania: „fotowoltaika” → „fotowolta” (znajdzie „fotowoltaiczna”), „odpady” → „odpad”,
     * „komputer” → „komput”. Słowo 4-literowe zakończone samogłoską traci ją, ale dopuszcza tylko krótką końcówkę („hala” → „hali”).
     * @return array{0: string, 1: bool} rdzeń i to, czy końcówka ma być krótka (do 3 liter)
     */
    public static function stem(string $w): array
    {
        $len = strlen($w);
        if ($len <= 3) {
            return [$w, false];
        }
        if ($len === 4) {
            return preg_match('/[aeiouy]$/', $w) ? [substr($w, 0, 3), true] : [$w, false];
        }
        $s = preg_replace('/[aeiouy]{1,2}$/', '', $w) ?? $w;
        if (strlen($s) > 6) {
            $s = substr($s, 0, max(6, strlen($s) - 2));
        }
        return [strlen($s) >= 4 ? $s : substr($w, 0, 4), false];
    }

    /**
     * Szukane słowa jako warunki LIKE z prostą odmianą: „hala sportowa” znajdzie „hali sportowej”.
     * @return string[]
     */
    public static function likeTerms(string $q): array
    {
        $out = [];
        foreach (array_filter(explode(' ', search_text($q))) as $w) {
            $out[] = '%' . self::stem($w)[0] . '%';
        }
        return array_slice($out, 0, 8);
    }

    /** „upcoming”: pozycje z bieżącego kwartału i późniejszych (oraz bez terminu), „all”: cały rok. */
    private static function whenFilter(string $when, array &$where, array &$params): void
    {
        if ($when !== 'upcoming') {
            return;
        }
        $year = (int) gmdate('Y');
        $quarter = intdiv((int) gmdate('n') - 1, 3) + 1;
        $where[] = '(p.year > ? OR (p.year = ? AND (i.quarter IS NULL OR i.quarter >= ?)))';
        array_push($params, $year, $year, $quarter);
    }

    /** Liczby do nagłówka ekranu: ile planów i pozycji jest w bazie na bieżący rok i kiedy był ostatni import. */
    public static function coverage(): array
    {
        $db = Db::get();
        $year = (int) gmdate('Y');
        return [
            'plans' => (int) $db->val('SELECT COUNT(*) FROM procurement_plans WHERE year >= ?', [$year]),
            'items' => (int) $db->val("SELECT COUNT(*) FROM plan_items i JOIN procurement_plans p ON p.id = i.plan_id WHERE p.year >= ? AND i.status = 'active'", [$year]),
            'last' => \App\Core\Kv::get('import:plans:last'),
        ];
    }

    public static function newCount(int $orgId): int
    {
        $year = (int) gmdate('Y');
        $quarter = intdiv((int) gmdate('n') - 1, 3) + 1;
        return (int) Db::get()->val(
            "SELECT COUNT(DISTINCT i.id) FROM plan_matches m JOIN plan_items i ON i.id = m.item_id JOIN procurement_plans p ON p.id = i.plan_id
             WHERE m.org_id = ? AND m.status = 'new' AND i.status = 'active' AND (p.year > ? OR (p.year = ? AND (i.quarter IS NULL OR i.quarter >= ?)))",
            [$orgId, $year, $year, $quarter]
        );
    }

    /** Termin z planu po polsku: „II kwartał 2026”, „marzec 2026”. */
    public static function termLabel(array $item): string
    {
        $year = $item['year'] ?? null;
        if (!empty($item['month'])) {
            $names = [1 => 'styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];
            return $names[(int) $item['month']] . ($year ? ' ' . $year : '');
        }
        if (!empty($item['quarter'])) {
            return ['', 'I', 'II', 'III', 'IV'][(int) $item['quarter']] . ' kwartał' . ($year ? ' ' . $year : '');
        }
        return utrim((string) ($item['term'] ?? '')) ?: 'termin nieznany';
    }
}
