<?php

declare(strict_types=1);

namespace App\Modules\Outreach;

use App\Core\Db;

/**
 * „Co napisał klient”: własne słowa klienta zebrane z miejsc, które panel naprawdę widzi.
 *
 * Źródła: zapytanie ze strony (odpowiedzi na pytania, wiadomość z formularza, pytania zadane czatowi), zapytania ofertowe
 * wpisane w „Zapytaniach z rynku” oraz odpowiedzi e-mail wklejone przez użytkownika. Skrzynki pocztowej panel nie czyta,
 * bo wiadomości wychodzą z programu pocztowego użytkownika.
 */
final class ClientVoice
{
    public const MAX_BODY = 20000;

    /**
     * Wpisy dla wielu kontaktów naraz (lista kontaktów), od najnowszego.
     * @param int[] $contactIds
     * @return array<int, array<int, array{at: string, source: string, kind: string, text: string, link: ?string, id: ?int}>>
     */
    public static function forContacts(int $orgId, array $contactIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $contactIds))));
        if (!$ids) {
            return [];
        }
        $db = Db::get();
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        $add = static function (int $contact, string $at, string $source, string $kind, string $text, ?string $link = null, ?int $id = null) use (&$out): void {
            $text = trim($text);
            if ($text !== '') {
                $out[$contact][] = ['at' => $at, 'source' => $source, 'kind' => $kind, 'text' => $text, 'link' => $link, 'id' => $id];
            }
        };
        foreach ($db->all("SELECT id, contact_id, body, received_at, created_at FROM contact_replies WHERE org_id = ? AND contact_id IN ($in) ORDER BY id DESC", array_merge([$orgId], $ids)) as $r) {
            $add((int) $r['contact_id'], (string) ($r['received_at'] ?: $r['created_at']), 'Odpowiedź e-mail (wklejona)', 'reply', (string) $r['body'], null, (int) $r['id']);
        }
        $leads = $db->all("SELECT id, contact_id, conversation_id, message, points_json, created_at FROM inbound_leads WHERE org_id = ? AND contact_id IN ($in) ORDER BY id DESC", array_merge([$orgId], $ids));
        $convIds = array_values(array_filter(array_map(static fn (array $l): int => (int) $l['conversation_id'], $leads)));
        $questions = [];
        if ($convIds) {
            $cin = implode(',', array_fill(0, count($convIds), '?'));
            foreach ($db->all("SELECT conversation_id, text FROM inbound_messages WHERE org_id = ? AND role = 'user' AND conversation_id IN ($cin) ORDER BY id", array_merge([$orgId], $convIds)) as $m) {
                $questions[(int) $m['conversation_id']][] = (string) $m['text'];
            }
        }
        foreach ($leads as $l) {
            $parts = [];
            if (trim((string) $l['message']) !== '') {
                $parts[] = trim((string) $l['message']);
            }
            // Odpowiedzi na pytania widżetu: „pytanie: wybrana odpowiedź”. Wiersz „Telefon” to ocena, a nie słowa klienta.
            foreach (json_in((string) $l['points_json']) as $row) {
                if (is_array($row) && !empty($row['answer']) && ($row['label'] ?? '') !== 'Telefon') {
                    $parts[] = $row['label'] . ': ' . $row['answer'];
                }
            }
            foreach ($questions[(int) $l['conversation_id']] ?? [] as $q) {
                $parts[] = 'Pytanie do czatu: ' . $q;
            }
            $add((int) $l['contact_id'], (string) $l['created_at'], 'Zapytanie ze strony', 'lead', implode("\n", $parts), '/zapytania/' . (int) $l['id']);
        }
        foreach ($db->all("SELECT id, contact_id, subject, notes, published_at, created_at FROM rfqs WHERE org_id = ? AND contact_id IN ($in) ORDER BY id DESC", array_merge([$orgId], $ids)) as $r) {
            $add((int) $r['contact_id'], (string) ($r['published_at'] ?: $r['created_at']), 'Zapytanie ofertowe', 'rfq', (string) $r['subject'], '/klienci/zapytania#z' . (int) $r['id']);
        }
        foreach ($out as &$items) {
            usort($items, static fn (array $a, array $b): int => strcmp($b['at'], $a['at']));
        }
        return $out;
    }

    /** @return array{ok: bool, error?: string} */
    public static function addReply(int $orgId, int $contactId, int $userId, string $body, string $date = ''): array
    {
        $body = trim(str_replace(["\r\n", "\r"], "\n", $body));
        if ($body === '') {
            return ['ok' => false, 'error' => 'Wklej treść odpowiedzi klienta.'];
        }
        if (mb_strlen($body) > self::MAX_BODY) {
            return ['ok' => false, 'error' => 'Odpowiedź jest za długa. Wklej samą treść wiadomości, bez cytowanej korespondencji (do ' . num_pl(self::MAX_BODY) . ' znaków).'];
        }
        $received = form_date($date);
        if ($received !== null && $received > gmdate('Y-m-d', time() + 86400)) {
            $received = null;
        }
        Db::get()->insert('contact_replies', ['org_id' => $orgId, 'contact_id' => $contactId, 'body' => $body, 'received_at' => $received, 'created_by' => $userId, 'created_at' => now_utc()]);
        return ['ok' => true];
    }

    public static function deleteReply(int $orgId, int $contactId, int $id): void
    {
        Db::get()->run('DELETE FROM contact_replies WHERE org_id = ? AND contact_id = ? AND id = ?', [$orgId, $contactId, $id]);
    }
}
