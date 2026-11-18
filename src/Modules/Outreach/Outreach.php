<?php

declare(strict_types=1);

namespace App\Modules\Outreach;

use App\Core\Db;
use App\Modules\Ai\Claude;
use App\Modules\Offers\Offers;

/** Szkice wiadomości do firm i plik .eml do wysłania z własnej skrzynki użytkownika. */
final class Outreach
{
    public static function contacts(int $orgId, string $q = ''): array
    {
        $sql = 'SELECT c.*, (SELECT MAX(created_at) FROM messages m WHERE m.contact_id = c.id) AS last_message_at FROM contacts c WHERE c.org_id = ?';
        $params = [$orgId];
        if ($q !== '') {
            $sql .= ' AND (LOWER(c.company_name) LIKE ? OR LOWER(c.email) LIKE ? OR c.nip = ?)';
            $like = '%' . mb_strtolower($q) . '%';
            array_push($params, $like, $like, preg_replace('/\D/', '', $q));
        }
        return Db::get()->all($sql . ' ORDER BY c.id DESC LIMIT 300', $params);
    }

    public static function contact(int $orgId, int $id): ?array
    {
        return Db::get()->one('SELECT * FROM contacts WHERE org_id = ? AND id = ?', [$orgId, $id]);
    }

    public static function message(int $orgId, int $id): ?array
    {
        return Db::get()->one(
            'SELECT m.*, c.company_name, c.email, c.person FROM messages m JOIN contacts c ON c.id = m.contact_id WHERE m.org_id = ? AND m.id = ?',
            [$orgId, $id]
        );
    }

    /** Szkic regułowy (bez AI) dla wybranego rodzaju wiadomości. @return array{subject: string, body: string} */
    public static function draft(string $kind, array $contact, array $org, ?array $user): array
    {
        $greet = !empty($contact['person']) ? 'Dzień dobry, ' . trim((string) $contact['person']) . ',' : 'Dzień dobry,';
        $sig = trim(($user['name'] ?? '') . "\n" . ($org['name'] ?? '') . (!empty($org['phone']) ? "\ntel. " . $org['phone'] : '') . (!empty($org['website']) ? "\n" . $org['website'] : ''));
        $about = '';
        foreach (Offers::library((int) $org['id']) as $i) {
            if ($i['kind'] === 'text') {
                $about = truncate((string) $i['body'], 500);
                break;
            }
        }
        $topic = trim((string) ($contact['basis_detail'] ?? ''));
        $optOut = "\n\nJeśli nie chcą Państwo otrzymywać od nas wiadomości, wystarczy odpisać „rezygnuję”.";

        return match ($kind) {
            'rfq_reply' => [
                'subject' => 'Odpowiedź na zapytanie ofertowe' . ($topic ? ': ' . truncate($topic, 80) : ''),
                'body' => $greet . "\n\ndziękujemy za zapytanie ofertowe" . ($topic ? ' dotyczące: ' . $topic : '') . ". W załączeniu przesyłamy ofertę.\n\n"
                    . ($about ? $about . "\n\n" : '')
                    . "Cena: [CENA] zł netto\nTermin realizacji: [TERMIN]\nWarunki płatności: [WARUNKI]\n\nChętnie odpowiemy na pytania i doprecyzujemy zakres.\n\nZ poważaniem\n" . $sig,
            ],
            'consented' => [
                'subject' => 'Oferta ' . ($org['name'] ?? ''),
                'body' => $greet . "\n\nzgodnie z Państwa zgodą przesyłamy informację o naszej ofercie.\n\n"
                    . ($about ?: '[Krótki opis oferty dopasowany do firmy odbiorcy]') . "\n\nCzy możemy umówić krótką rozmowę w przyszłym tygodniu?\n\nZ poważaniem\n" . $sig . $optOut,
            ],
            'consent_request' => [
                'subject' => 'Czy możemy przesłać ofertę?',
                'body' => $greet . "\n\nczy wyrażają Państwo zgodę na przesłanie na ten adres e-mail jednej wiadomości z ofertą firmy " . ($org['name'] ?? '') . "?\n\n"
                    . "Wystarczy odpowiedzieć „tak”. Jeśli nie odpowiedzą Państwo, nie będziemy pisać ponownie.\n\nZ poważaniem\n" . $sig,
            ],
            default => [
                'subject' => 'Propozycja współpracy – ' . ($org['name'] ?? ''),
                'body' => $greet . "\n\n" . ($about ?: '[Krótki opis oferty dopasowany do firmy odbiorcy]') . "\n\nCzy możemy przesłać szczegóły?\n\nZ poważaniem\n" . $sig . $optOut,
            ],
        };
    }

    /**
     * Krótki opis firmy odbiorcy z jej strony głównej (tytuł, opis, nagłówki) – do personalizacji wiadomości.
     * Pobieranie przez silnik audytu, który blokuje adresy wewnętrzne (ochrona przed SSRF).
     */
    public static function siteSnippet(string $url): ?string
    {
        if ($url === '') {
            return null;
        }
        try {
            $scanner = \App\Modules\Audit\Audit::scanner();
            $target = $scanner->normalize_url($url);
            $page = $scanner->fetch_follow('https://' . $target['authority'] . '/');
            if (!$page || !$page['ok']) {
                $page = $scanner->fetch_follow('http://' . $target['authority'] . '/');
            }
        } catch (\Throwable) {
            return null;
        }
        if (!$page || !$page['ok'] || (int) $page['status'] >= 400) {
            return null;
        }
        $html = (string) $page['body'];
        $parts = [];
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            $parts[] = 'Tytuł strony: ' . trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if (preg_match('#<meta[^>]+name=["\']description["\'][^>]*content=["\']([^"\']*)#i', $html, $m)) {
            $parts[] = 'Opis: ' . trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if (preg_match_all('#<h[12][^>]*>(.*?)</h[12]>#is', $html, $mm)) {
            $heads = array_slice(array_filter(array_map(static fn ($h) => truncate(html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 120), $mm[1])), 0, 8);
            if ($heads) {
                $parts[] = 'Nagłówki: ' . implode(' | ', $heads);
            }
        }
        $text = preg_replace('#<(script|style|nav|footer|header)\b[^>]*>.*?</\1>#is', ' ', $html) ?? '';
        $parts[] = 'Fragment treści: ' . truncate(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 1200);
        return mb_substr(implode("\n", $parts), 0, 2500);
    }

    /** Szkic przez AI z zachowaniem ograniczeń danego rodzaju wiadomości. */
    public static function aiDraft(string $kind, array $contact, array $org, ?array $user, string $hint): array
    {
        $rules = match ($kind) {
            'consent_request' => 'Wiadomość to WYŁĄCZNIE prośba o zgodę na przesłanie oferty: bez opisu usług, cen, korzyści, linków ani zachęt. Maksymalnie 3 zdania.',
            'rfq_reply' => 'To odpowiedź na zapytanie ofertowe odbiorcy. Odnieś się do jego zapytania, zostaw miejsca [CENA], [TERMIN] do uzupełnienia.',
            default => 'Konkretna, krótka oferta B2B (do 120 słów), bez przesadnych obietnic, z prośbą o rozmowę i informacją o możliwości rezygnacji z wiadomości.',
        };
        $lib = [];
        foreach (Offers::library((int) $org['id']) as $i) {
            $lib[] = '[' . $i['title'] . '] ' . truncate((string) $i['body'], 600);
        }
        $system = 'Piszesz e-mail biznesowy po polsku w imieniu firmy. Używasz tylko faktów z podanych danych firmy i biblioteki – nie wymyślaj referencji ani liczb. '
            . $rules . ' Zwróć JSON {"subject":"…","body":"…"}.';
        $userMsg = 'Nadawca: ' . ($org['name'] ?? '') . ', ' . ($user['name'] ?? '') . "\nOdbiorca: " . $contact['company_name'] . ($contact['person'] ? ', ' . $contact['person'] : '')
            . "\nKontekst kontaktu: " . RiskPolicy::basisLine($contact) . ($contact['notes'] ? "\nNotatki: " . $contact['notes'] : '')
            . ($hint !== '' ? "\nWskazówki użytkownika: " . $hint : '') . "\n\nBiblioteka firmy:\n" . implode("\n", $lib);
        $site = $kind === 'consent_request' ? null : self::siteSnippet((string) ($contact['website'] ?? ''));
        if ($site) {
            $userMsg .= "\n\nStrona internetowa odbiorcy (do personalizacji – nawiąż do jego działalności jednym zdaniem, bez schlebiania):\n" . $site;
        }
        $r = Claude::complete($system, $userMsg, 1200);
        if (!$r['ok']) {
            return ['ok' => false, 'error' => $r['error']];
        }
        $j = Claude::json((string) $r['text']);
        if (!$j || empty($j['body'])) {
            return ['ok' => false, 'error' => 'Nie udało się odczytać odpowiedzi modelu.'];
        }
        return ['ok' => true, 'subject' => (string) ($j['subject'] ?? ''), 'body' => (string) $j['body']];
    }

    /** Wiadomość w formacie .eml (otwiera się w Outlooku, Thunderbirdzie, Apple Mail jako nowa wiadomość). */
    public static function eml(array $message, string $fromEmail, string $fromName): string
    {
        $enc = static fn (string $s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
        $headers = [
            'From: ' . ($fromName !== '' ? $enc($fromName) . ' ' : '') . '<' . $fromEmail . '>',
            'To: ' . (!empty($message['person']) ? $enc((string) $message['person']) . ' ' : '') . '<' . $message['email'] . '>',
            'Subject: ' . $enc((string) $message['subject']),
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'X-Unsent: 1',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        return implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode(str_replace("\n", "\r\n", str_replace("\r\n", "\n", (string) $message['body']))));
    }

    public static function mailto(array $message): string
    {
        return 'mailto:' . rawurlencode((string) $message['email']) . '?subject=' . rawurlencode((string) $message['subject']) . '&body=' . rawurlencode((string) $message['body']);
    }
}
