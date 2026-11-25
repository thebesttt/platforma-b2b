<?php

declare(strict_types=1);

namespace App\Modules\Outreach;

use App\Core\Brand;
use App\Core\Db;

/**
 * Przypomnienia po wysłanej wiadomości. Platforma niczego nie wysyła sama: pilnuje terminów, przygotowuje treść kolejnego kroku
 * i zatrzymuje sekwencję, gdy odbiorca odpisał albo się wypisał. Wiadomość zawsze wychodzi ze skrzynki użytkownika.
 */
final class Sequences
{
    /** krok => [dzień od pierwszej wiadomości, nazwa] */
    public const STEPS = [0 => [0, 'Pierwsza wiadomość'], 1 => [4, 'Przypomnienie'], 2 => [10, 'Ostatnia wiadomość']];

    /** Rodzaje wiadomości, po których wolno planować przypomnienia. Prośba o zgodę obiecuje, że nie napiszemy ponownie. */
    public const KINDS = ['rfq_reply', 'consented', 'cold_offer'];

    public const STOPS = ['replied' => 'odbiorca odpisał', 'unsubscribed' => 'odbiorca się wypisał', 'manual' => 'zatrzymana ręcznie', 'finished' => 'wysłano wszystkie kroki',
        'deadline' => 'minął termin składania ofert'];
    public const DEFAULT_LIMIT = 30;

    /** Dzienny limit wiadomości z jednej skrzynki, ustawiany przez firmę. */
    public static function limit(?array $org): int
    {
        $n = (int) (Brand::settings($org)['outreach_limit'] ?? self::DEFAULT_LIMIT);
        return max(1, min(200, $n ?: self::DEFAULT_LIMIT));
    }

    /** Ile wiadomości firma oznaczyła dziś jako wysłane (doba według czasu polskiego). */
    public static function sentToday(int $orgId): int
    {
        $start = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Warsaw')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        // Wiadomości kampanii wysyła panel, a nie skrzynka użytkownika, więc nie liczą się do tego limitu.
        return (int) Db::get()->val("SELECT COUNT(*) FROM messages WHERE org_id = ? AND status = 'sent' AND sent_at >= ? AND kind <> 'campaign'", [$orgId, $start]);
    }

    public static function find(int $orgId, int $id): ?array
    {
        return Db::get()->one('SELECT * FROM sequences WHERE id = ? AND org_id = ?', [$id, $orgId]);
    }

    /** Aktywna sekwencja kontaktu (jedna naraz). */
    public static function activeFor(int $orgId, int $contactId): ?array
    {
        return Db::get()->one("SELECT * FROM sequences WHERE org_id = ? AND contact_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1", [$orgId, $contactId]);
    }

    /** Czy po tej wiadomości można zaplanować przypomnienia. */
    public static function canStart(array $message): bool
    {
        return in_array($message['kind'], self::KINDS, true)
            && empty($message['sequence_id'])
            && empty($message['unsubscribed_at'])
            && empty($message['replied_at'])
            && self::activeFor((int) $message['org_id'], (int) $message['contact_id']) === null;
    }

    /** Start po wysłaniu pierwszej wiadomości: kolejny krok za 4 dni. */
    public static function start(array $message, int $userId): ?int
    {
        if (!self::canStart($message)) {
            return null;
        }
        $db = Db::get();
        $now = now_utc();
        $id = $db->insert('sequences', [
            'org_id' => (int) $message['org_id'], 'contact_id' => (int) $message['contact_id'], 'kind' => (string) $message['kind'],
            'status' => 'active', 'step' => 0, 'next_at' => self::dueAt($now, 1), 'started_at' => $now, 'updated_at' => $now, 'created_by' => $userId,
        ]);
        $db->update('messages', ['sequence_id' => $id, 'step' => 0], 'id = ?', [(int) $message['id']]);
        return $id;
    }

    /** Wysłano kolejny krok: wyznaczamy następny termin albo kończymy sekwencję. */
    public static function advance(array $message): void
    {
        $seq = self::find((int) $message['org_id'], (int) $message['sequence_id']);
        $step = (int) ($message['step'] ?? 0);
        if (!$seq || $seq['status'] !== 'active' || $step <= (int) $seq['step']) {
            return;
        }
        $last = $step >= array_key_last(self::STEPS);
        Db::get()->update('sequences', [
            'step' => $step,
            'status' => $last ? 'done' : 'active',
            'stop_reason' => $last ? 'finished' : null,
            'next_at' => $last ? null : self::dueAt((string) $seq['started_at'], $step + 1, now_utc()),
            'updated_at' => now_utc(),
        ], 'id = ?', [(int) $seq['id']]);
    }

    /**
     * Termin kroku liczony od pierwszej wiadomości. Gdy poprzedni krok wyszedł z opóźnieniem, następny wypada najwcześniej
     * po takiej przerwie, jaka dzieli oba kroki w planie (żeby dwie wiadomości nie poszły dzień po dniu).
     */
    private static function dueAt(string $startedUtc, int $step, ?string $sentUtc = null): string
    {
        $planned = strtotime($startedUtc . ' UTC') + self::STEPS[$step][0] * 86400;
        if ($sentUtc !== null) {
            $gap = (self::STEPS[$step][0] - self::STEPS[$step - 1][0]) * 86400;
            $planned = max($planned, strtotime($sentUtc . ' UTC') + $gap);
        }
        return gmdate('Y-m-d H:i:s', $planned);
    }

    public static function stop(int $orgId, int $id, string $reason): bool
    {
        return Db::get()->update('sequences', ['status' => 'stopped', 'stop_reason' => $reason, 'next_at' => null, 'updated_at' => now_utc()], "id = ? AND org_id = ? AND status = 'active'", [$id, $orgId]) > 0;
    }

    /** Zatrzymuje aktywne sekwencje kontaktu (odpowiedź albo wypisanie). */
    public static function stopForContact(int $orgId, int $contactId, string $reason): int
    {
        return Db::get()->update('sequences', ['status' => 'stopped', 'stop_reason' => $reason, 'next_at' => null, 'updated_at' => now_utc()], "org_id = ? AND contact_id = ? AND status = 'active'", [$orgId, $contactId]);
    }

    /**
     * Przypomnienie po odpowiedzi na zapytanie ofertowe ma sens do terminu składania ofert. Gdy termin zapisany w rejestrze
     * zapytań minął (i kontakt nie ma innego, otwartego zapytania), sekwencja się zatrzymuje.
     */
    public static function stopExpired(int $orgId): int
    {
        $today = today_pl();
        return Db::get()->run(
            "UPDATE sequences SET status = 'stopped', stop_reason = 'deadline', next_at = NULL, updated_at = ?
              WHERE org_id = ? AND status = 'active' AND kind = 'rfq_reply'
                AND EXISTS (SELECT 1 FROM rfqs r WHERE r.org_id = sequences.org_id AND r.contact_id = sequences.contact_id AND r.deadline_at IS NOT NULL AND r.deadline_at < ?)
                AND NOT EXISTS (SELECT 1 FROM rfqs r WHERE r.org_id = sequences.org_id AND r.contact_id = sequences.contact_id AND r.status <> 'skipped' AND (r.deadline_at IS NULL OR r.deadline_at >= ?))",
            [now_utc(), $orgId, $today, $today]
        )->rowCount();
    }

    private const LIST_SQL = "SELECT s.*, c.company_name, c.person, c.email, c.unsubscribed_at, c.replied_at,
            (SELECT MAX(m.sent_at) FROM messages m WHERE m.sequence_id = s.id AND m.status = 'sent') AS last_sent_at,
            (SELECT COUNT(*) FROM messages m WHERE m.sequence_id = s.id AND m.status = 'draft' AND m.step > s.step) AS drafts
       FROM sequences s JOIN contacts c ON c.id = s.contact_id";

    /** Przypomnienia, na które przyszedł termin. Najstarsze najpierw. */
    public static function due(int $orgId, ?int $limit = null): array
    {
        self::stopExpired($orgId);
        return Db::get()->all(
            self::LIST_SQL . " WHERE s.org_id = ? AND s.status = 'active' AND s.next_at IS NOT NULL AND s.next_at <= ? AND c.unsubscribed_at IS NULL AND c.replied_at IS NULL ORDER BY s.next_at, s.id"
                . ($limit !== null ? ' LIMIT ' . max(0, $limit) : ''),
            [$orgId, now_utc()]
        );
    }

    public static function dueCount(int $orgId): int
    {
        self::stopExpired($orgId);
        return (int) Db::get()->val(
            "SELECT COUNT(*) FROM sequences s JOIN contacts c ON c.id = s.contact_id
              WHERE s.org_id = ? AND s.status = 'active' AND s.next_at IS NOT NULL AND s.next_at <= ? AND c.unsubscribed_at IS NULL AND c.replied_at IS NULL",
            [$orgId, now_utc()]
        );
    }

    /** Sekwencje czekające na termin. */
    public static function waiting(int $orgId): array
    {
        self::stopExpired($orgId);
        return Db::get()->all(self::LIST_SQL . " WHERE s.org_id = ? AND s.status = 'active' AND (s.next_at IS NULL OR s.next_at > ?) ORDER BY s.next_at, s.id LIMIT 100", [$orgId, now_utc()]);
    }

    /** Ostatnio zakończone i zatrzymane. */
    public static function closed(int $orgId, int $limit = 12): array
    {
        return Db::get()->all(self::LIST_SQL . " WHERE s.org_id = ? AND s.status <> 'active' ORDER BY s.updated_at DESC, s.id DESC LIMIT " . max(1, $limit), [$orgId]);
    }

    /** Treść kolejnego kroku. Krótka, z odwołaniem do pierwszej wiadomości i z możliwością wypisania. */
    public static function followUp(array $seq, array $contact, array $org, ?array $user, string $unsubUrl = ''): array
    {
        $db = Db::get();
        $first = $db->one("SELECT subject, sent_at FROM messages WHERE sequence_id = ? AND status = 'sent' ORDER BY step, id LIMIT 1", [(int) $seq['id']]) ?? ['subject' => '', 'sent_at' => $seq['started_at']];
        $greet = !empty($contact['person']) ? 'Dzień dobry, ' . trim((string) $contact['person']) . ',' : 'Dzień dobry,';
        $sig = trim(($user['name'] ?? '') . "\n" . ($org['name'] ?? '') . (!empty($org['phone']) ? "\ntel. " . $org['phone'] : '') . (!empty($org['website']) ? "\n" . $org['website'] : ''));
        $when = local_dt($first['sent_at'], 'd.m.Y');
        $subject = 'Re: ' . preg_replace('/^(Re:\s*)+/iu', '', (string) $first['subject']);
        $next = (int) $seq['step'] + 1;
        $body = $next >= array_key_last(self::STEPS)
            ? $greet . "\n\nto ostatnia wiadomość w sprawie, o której pisaliśmy " . $when . ". Nie chcemy zabierać Państwu czasu, więc nie będziemy pisać ponownie.\n\n"
                . "Jeśli temat wróci, wystarczy odpowiedzieć na tę wiadomość.\n\nZ poważaniem\n" . $sig
            : $greet . "\n\nwracam do wiadomości z " . $when . ($seq['kind'] === 'rfq_reply' ? '. Czy oferta dotarła i czy mogę coś doprecyzować?' : '. Czy mogę przesłać szczegóły albo odpowiedzieć na pytania?')
                . "\n\nZ poważaniem\n" . $sig;
        return ['subject' => mb_substr($subject, 0, 250), 'body' => $body . Outreach::optOut($unsubUrl)];
    }
}
