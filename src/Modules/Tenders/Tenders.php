<?php

declare(strict_types=1);

namespace App\Modules\Tenders;

use App\Core\Db;

/** Zapis i odczyt przetargów oraz profili. */
final class Tenders
{
    public static function save(array $t): int
    {
        $now = now_utc();
        $t['search'] = search_text(($t['title'] ?? '') . ' ' . ($t['buyer_name'] ?? '') . ' ' . ($t['buyer_city'] ?? ''));
        $t['updated_at'] = $now;
        $db = Db::get();
        $existing = $db->one('SELECT id FROM tenders WHERE source = ? AND external_id = ?', [$t['source'], $t['external_id']]);
        if ($existing) {
            // Nie nadpisujemy pobranej treści ogłoszenia ani adresu dokumentów, jeśli nowy rekord ich nie ma.
            $upd = $t;
            unset($upd['source'], $upd['external_id']);
            foreach (self::KEEP_IF_EMPTY as $k) {
                if (array_key_exists($k, $upd) && ($upd[$k] === null || $upd[$k] === '')) {
                    unset($upd[$k]);
                }
            }
            $db->update('tenders', $upd, 'id = ?', [(int) $existing['id']]);
            return (int) $existing['id'];
        }
        $t['created_at'] = $now;
        return $db->insert('tenders', $t);
    }

    /** Kolumny rekordu z importu. */
    private const IMPORT_COLS = ['source', 'external_id', 'notice_number', 'notice_type', 'ocid', 'title', 'order_type', 'buyer_name', 'buyer_nip', 'buyer_city',
        'voivodeship', 'cpv_main', 'cpv_all', 'value_estimated', 'currency', 'published_at', 'deadline_at', 'url', 'documents_url', 'raw_json'];

    /** Kolumny, których zapisanej wartości nie nadpisujemy pustą (np. wartość odczytana później z treści ogłoszenia). */
    private const KEEP_IF_EMPTY = ['documents_url', 'value_estimated', 'currency', 'buyer_nip', 'ocid'];

    /**
     * Zapis wielu ogłoszeń z importu: jedno zapytanie na 100 ogłoszeń zamiast dwóch na każde. Import działa często
     * daleko od bazy (GitHub Actions a baza w innym regionie), więc liczba zapytań decyduje o czasie importu.
     *
     * @param string[] $skipped opisy ogłoszeń, których nie udało się zapisać (dopisywane)
     * @return int liczba zapisanych ogłoszeń
     */
    public static function saveMany(array $rows, array &$skipped = [], string $label = ''): int
    {
        $now = now_utc();
        $unique = [];
        foreach ($rows as $t) {
            $row = [];
            foreach (self::IMPORT_COLS as $c) {
                $v = $t[$c] ?? null;
                $row[$c] = ($v === '' && in_array($c, self::KEEP_IF_EMPTY, true)) ? null : $v;
            }
            $row['search'] = search_text(($row['title'] ?? '') . ' ' . ($row['buyer_name'] ?? '') . ' ' . ($row['buyer_city'] ?? ''));
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            // To samo ogłoszenie bywa na dwóch stronach wyników - zostaje ostatnia wersja.
            $unique[$row['source'] . '|' . $row['external_id']] = $row;
        }
        $saved = 0;
        foreach (array_chunk(array_values($unique), 100) as $chunk) {
            try {
                Db::get()->upsertMany('tenders', $chunk, ['source', 'external_id'], self::KEEP_IF_EMPTY, ['created_at']);
                $saved += count($chunk);
            } catch (\Throwable) {
                // Jedno wadliwe ogłoszenie nie może zatrzymać całej setki: zapisujemy pojedynczo i pomijamy tylko wadliwe.
                foreach ($chunk as $row) {
                    try {
                        self::save(array_diff_key($row, ['search' => 1, 'created_at' => 1, 'updated_at' => 1]));
                        $saved++;
                    } catch (\Throwable $e) {
                        $skipped[] = trim($label . ' ' . $row['external_id']) . ': ' . truncate($e->getMessage(), 160);
                    }
                }
            }
        }
        return $saved;
    }

    public static function find(int $id): ?array
    {
        return Db::get()->one('SELECT * FROM tenders WHERE id = ?', [$id]);
    }

    /** Dane szczegółowe z treści ogłoszenia (BZP), pobierane przy pierwszym otwarciu i zapamiętywane. */
    public static function details(array $tender, bool $fetch = true): array
    {
        $d = json_in($tender['details_json'] ?? null);
        if ($d || !$fetch || $tender['source'] !== 'bzp') {
            return $d;
        }
        $html = (new BzpClient())->noticeHtml((string) $tender['external_id']);
        if ($html === null) {
            return [];
        }
        $d = BzpNoticeParser::parse($html);
        if (($d['form'] ?? '') !== 'small' && empty($d['deadline']) && empty($d['descriptions']) && empty($d['criteria'])) {
            // Nieznany układ ogłoszenia: nie zapamiętujemy pustego wyniku, żeby poprawka parsera zadziałała od razu.
            error_log('[bzp] nie rozpoznano układu ogłoszenia ' . $tender['external_id']);
            return [];
        }
        $upd = ['details_json' => json_out($d), 'details_fetched_at' => now_utc()];
        if (!empty($d['value_estimated']) && empty($tender['value_estimated'])) {
            $upd['value_estimated'] = (float) $d['value_estimated'];
            $upd['currency'] = 'PLN';
        }
        if (!empty($d['documents_url'])) {
            $upd['documents_url'] = $d['documents_url'];
        }
        if (!empty($d['buyer']['nip']) && empty($tender['buyer_nip'])) {
            $upd['buyer_nip'] = $d['buyer']['nip'];
        }
        Db::get()->update('tenders', $upd, 'id = ?', [(int) $tender['id']]);
        return $d;
    }

    public static function profiles(int $orgId, bool $onlyActive = false): array
    {
        return Db::get()->all(
            'SELECT * FROM tender_profiles WHERE org_id = ?' . ($onlyActive ? ' AND active = 1' : '') . ' ORDER BY id',
            [$orgId]
        );
    }

    public static function profile(int $orgId, int $id): ?array
    {
        return Db::get()->one('SELECT * FROM tender_profiles WHERE org_id = ? AND id = ?', [$orgId, $id]);
    }

    public static function orderTypeLabel(?string $t): string
    {
        return match ($t) {
            'works' => 'roboty budowlane',
            'services' => 'usługi',
            'supplies' => 'dostawy',
            default => '',
        };
    }
}
