<?php

declare(strict_types=1);

namespace App\Modules\Notify;

use App\Core\Brand;
use App\Core\Config;
use App\Core\Db;
use App\Core\Kv;
use App\Core\Mailer;
use App\Modules\Inbound\Rules;

/**
 * Poranny przegląd e-mailem: nowe dopasowane przetargi, nowe zapytania ze strony i alerty o kontrahentach od poprzedniego
 * przeglądu. Wysyłany po codziennym imporcie. Gdy nie ma nic nowego, e-mail nie wychodzi. Firma może go wyłączyć w Ustawieniach.
 */
final class Digest
{
    /** @return array{orgs: int, sent: int, skipped?: string} */
    public static function run(?string $baseUrl = null): array
    {
        if (!Mailer::enabled()) {
            return ['orgs' => 0, 'sent' => 0, 'skipped' => 'Wysyłka e-maili nie jest skonfigurowana.'];
        }
        $baseUrl = rtrim($baseUrl ?? (string) (Config::get('APP_URL') ?: Config::get('RENDER_EXTERNAL_URL', '')), '/');
        $db = Db::get();
        $orgs = 0;
        $sent = 0;
        foreach ($db->all('SELECT id, name, settings_json FROM organizations ORDER BY id') as $org) {
            if ((Brand::settings($org)['digest'] ?? true) === false) {
                continue;
            }
            $key = 'digest:last:' . $org['id'];
            $since = (string) (Kv::get($key) ?? gmdate('Y-m-d H:i:s', time() - 86400));
            $mail = self::compose($org, $since, $baseUrl);
            Kv::set($key, now_utc());
            if ($mail === null) {
                continue;
            }
            $orgs++;
            $sent += Mailer::notifyOrg((int) $org['id'], $mail['subject'], $mail['body'], 'digest');
        }
        return ['orgs' => $orgs, 'sent' => $sent];
    }

    /** „dziś”, „jutro”, „za 3 dni”. */
    public static function daysLabel(int $n): string
    {
        return $n <= 0 ? 'dziś' : ($n === 1 ? 'jutro' : 'za ' . $n . ' dni');
    }

    /** @return ?array{subject: string, body: string} null, gdy nie ma nic nowego */
    public static function compose(array $org, string $since, string $baseUrl): ?array
    {
        $db = Db::get();
        $id = (int) $org['id'];
        $lines = [];
        $parts = [];

        if (Brand::has($org, 'przetargi')) {
            $total = (int) $db->val("SELECT COUNT(DISTINCT m.tender_id) FROM tender_matches m JOIN tenders t ON t.id = m.tender_id
                WHERE m.org_id = ? AND m.status = 'new' AND m.created_at > ? AND (t.deadline_at IS NULL OR t.deadline_at > ?)", [$id, $since, now_utc()]);
            if ($total > 0) {
                $rows = $db->all("SELECT t.id, t.title, t.buyer_name, t.deadline_at, MAX(m.score) AS score FROM tender_matches m JOIN tenders t ON t.id = m.tender_id
                    WHERE m.org_id = ? AND m.status = 'new' AND m.created_at > ? AND (t.deadline_at IS NULL OR t.deadline_at > ?)
                    GROUP BY t.id, t.title, t.buyer_name, t.deadline_at ORDER BY score DESC, t.deadline_at LIMIT 8", [$id, $since, now_utc()]);
                $parts[] = $total . ' ' . plural($total, 'nowy przetarg', 'nowe przetargi', 'nowych przetargów');
                $lines[] = 'NOWE PRZETARGI DOPASOWANE DO PROFILU (' . $total . ')';
                foreach ($rows as $r) {
                    $lines[] = '- ' . (int) $r['score'] . ' pkt: ' . truncate((string) $r['title'], 110) . ($r['buyer_name'] ? ' (' . truncate((string) $r['buyer_name'], 60) . ')' : '')
                        . ($r['deadline_at'] ? ', termin ' . local_dt($r['deadline_at'], 'd.m.Y') : '');
                    $lines[] = '  ' . $baseUrl . '/przetargi/' . (int) $r['id'];
                }
                if ($total > count($rows)) {
                    $lines[] = 'Pozostałe: ' . $baseUrl . '/przetargi?status=new';
                }
                $lines[] = '';
            }
        }

        if (Brand::has($org, 'zapytania')) {
            $leads = $db->all('SELECT id, name, email, score, level, summary FROM inbound_leads WHERE org_id = ? AND created_at > ? ORDER BY score DESC, id DESC LIMIT 8', [$id, $since]);
            if ($leads) {
                $n = (int) $db->val('SELECT COUNT(*) FROM inbound_leads WHERE org_id = ? AND created_at > ?', [$id, $since]);
                $parts[] = $n . ' ' . plural($n, 'zapytanie', 'zapytania', 'zapytań');
                $lines[] = 'ZAPYTANIA ZE STRONY (' . $n . ')';
                foreach ($leads as $l) {
                    $lines[] = '- ' . (int) $l['score'] . '/100 (' . (Rules::LEVELS[$l['level']]['label'] ?? $l['level']) . '): ' . ($l['name'] ?: $l['email']) . ($l['summary'] ? '. ' . truncate((string) $l['summary'], 140) : '');
                    $lines[] = '  ' . $baseUrl . '/zapytania/' . (int) $l['id'];
                }
                $lines[] = '';
            }
        }

        if (Brand::has($org, 'przetargi')) {
            // Zapisane przetargi, które właśnie rozstrzygnięto.
            $done = $db->all('SELECT tender_id, title, detail FROM alerts WHERE org_id = ? AND created_at > ? AND company_id IS NULL AND tender_id IS NOT NULL ORDER BY id DESC LIMIT 6', [$id, $since]);
            if ($done) {
                $parts[] = count($done) . ' ' . plural(count($done), 'rozstrzygnięty przetarg', 'rozstrzygnięte przetargi', 'rozstrzygniętych przetargów');
                $lines[] = 'ROZSTRZYGNIĘTE PRZETARGI Z TWOJEJ LISTY (' . count($done) . ')';
                foreach ($done as $a) {
                    $lines[] = '- ' . truncate(preg_replace('/^Rozstrzygnięto przetarg: /u', '', (string) $a['title']) ?? '', 90);
                    $lines[] = '  ' . truncate((string) $a['detail'], 200);
                    $lines[] = '  ' . $baseUrl . '/przetargi/' . (int) $a['tender_id'];
                }
                $lines[] = '';
            }
        }

        if (Brand::has($org, 'klienci')) {
            // Przypomnienia, na które dziś przypada termin. Wysyłkę i tak wykonuje człowiek ze swojej skrzynki.
            $due = \App\Modules\Outreach\Sequences::due($id, 6);
            if ($due) {
                $n = \App\Modules\Outreach\Sequences::dueCount($id);
                $parts[] = $n . ' ' . plural($n, 'przypomnienie do wysłania', 'przypomnienia do wysłania', 'przypomnień do wysłania');
                $lines[] = 'PRZYPOMNIENIA DO WYSŁANIA DZIŚ (' . $n . ')';
                foreach ($due as $d) {
                    $lines[] = '- ' . truncate((string) $d['company_name'], 80) . ': ' . mb_strtolower(\App\Modules\Outreach\Sequences::STEPS[(int) $d['step'] + 1][1] ?? 'kolejna wiadomość');
                }
                $lines[] = '  ' . $baseUrl . '/klienci/przypomnienia';
                $lines[] = '';
            }
        }

        if (Brand::has($org, 'klienci')) {
            // Zapytania ofertowe wpisane do rejestru, których termin mija w najbliższych dniach.
            $soon = \App\Modules\Outreach\Rfqs::dueSoon($id);
            if ($soon) {
                $n = count($soon);
                $parts[] = $n . ' ' . plural($n, 'zapytanie ofertowe z bliskim terminem', 'zapytania ofertowe z bliskim terminem', 'zapytań ofertowych z bliskim terminem');
                $lines[] = 'ZAPYTANIA OFERTOWE: KOŃCZY SIĘ TERMIN (' . $n . ')';
                foreach (array_slice($soon, 0, 8) as $q) {
                    $lines[] = '- ' . self::daysLabel((int) days_to_date($q['deadline_at'])) . ': ' . truncate((string) $q['subject'], 100) . ' (' . truncate((string) $q['company_name'], 60) . ')';
                }
                $lines[] = '  ' . $baseUrl . '/klienci/zapytania';
                $lines[] = '';
            }
        }

        if (Brand::has($org, 'oferty')) {
            // Obserwowane nabory dotacji: 14 i 7 dni przed końcem, a potem codziennie od 3 dni.
            $calls = \App\Modules\Offers\GrantCalls::toRemind($id);
            if ($calls) {
                $n = count($calls);
                $parts[] = $n . ' ' . plural($n, 'nabór dotacji przed końcem', 'nabory dotacji przed końcem', 'naborów dotacji przed końcem');
                $lines[] = 'NABORY DOTACJI: ZBLIŻA SIĘ KONIEC (' . $n . ')';
                foreach (array_slice($calls, 0, 8) as $g) {
                    $lines[] = '- ' . self::daysLabel((int) days_to_date($g['closes_at'])) . ': ' . truncate((string) $g['name'], 110)
                        . ($g['offer_title'] === null ? ' (wniosek nierozpoczęty)' : ' (wniosek: ' . mb_strtolower(\App\Http\OfferController::STATUSES[$g['offer_status']]['label'] ?? 'szkic') . ')');
                }
                $lines[] = '  ' . $baseUrl . '/oferty/nabory';
                $lines[] = '';
            }
        }

        if (Brand::has($org, 'kontrahenci')) {
            $alerts = $db->all('SELECT title, detail FROM alerts WHERE org_id = ? AND created_at > ? AND (company_id IS NOT NULL OR tender_id IS NULL) ORDER BY id DESC LIMIT 8', [$id, $since]);
            if ($alerts) {
                $n = (int) $db->val('SELECT COUNT(*) FROM alerts WHERE org_id = ? AND created_at > ? AND (company_id IS NOT NULL OR tender_id IS NULL)', [$id, $since]);
                $parts[] = $n . ' ' . plural($n, 'alert o kontrahencie', 'alerty o kontrahentach', 'alertów o kontrahentach');
                $lines[] = 'ZMIANY U OBSERWOWANYCH KONTRAHENTÓW (' . $n . ')';
                foreach ($alerts as $a) {
                    $lines[] = '- ' . truncate((string) $a['title'], 80) . ': ' . truncate((string) $a['detail'], 160);
                }
                $lines[] = '  ' . $baseUrl . '/kontrahenci';
                $lines[] = '';
            }
        }

        if (!$parts) {
            return null;
        }
        $lines[] = 'Ten przegląd wyłączysz w panelu: Ustawienia, sekcja „Poczta z panelu”.';
        return ['subject' => 'Poranny przegląd: ' . implode(', ', $parts), 'body' => "Dzień dobry,\n\nod ostatniego przeglądu w panelu " . Config::get('APP_NAME') . " pojawiło się:\n\n" . implode("\n", $lines)];
    }
}
