<?php

declare(strict_types=1);

namespace App\Modules\Tenders;

use App\Core\Db;
use App\Modules\Ai\Claude;

/**
 * Analiza przetargu: regułowa (z treści ogłoszenia BZP, zawsze dostępna) i przez AI (ogłoszenie + wgrane dokumenty SWZ).
 * Każdy punkt ma źródło, żeby użytkownik mógł go sprawdzić w dokumentach.
 */
final class Analysis
{
    /** Punkty z ogłoszenia BZP bez użycia AI. */
    public static function rules(array $tender, array $d): array
    {
        $items = [];
        $risks = [];
        $docs = [];
        $src = static fn (string $pkt) => 'Ogłoszenie, pkt ' . $pkt;

        if (!empty($d['descriptions'][0])) {
            $items[] = ['label' => 'Przedmiot', 'value' => truncate($d['descriptions'][0], 500), 'source' => $src('4.2.2')];
        }
        if (!empty($d['parts']) && $d['parts'] > 1) {
            $items[] = ['label' => 'Części', 'value' => $d['parts'] . ' części' . (!empty($d['partial_offers']) ? ', można składać oferty częściowe' : ''), 'source' => $src('4.1.9')];
        }
        if (!empty($d['period'])) {
            $items[] = ['label' => 'Termin wykonania', 'value' => $d['period'], 'source' => $src('4.2.10')];
        }
        if (!empty($d['criteria'])) {
            $items[] = ['label' => 'Kryteria oceny', 'value' => implode(' · ', array_map(
                static fn ($c) => $c['name'] . ($c['weight'] !== null ? ' ' . rtrim(rtrim(number_format((float) $c['weight'], 2, ',', ''), '0'), ',') : ''),
                $d['criteria']
            )), 'source' => $src('4.3')];
        }
        if (isset($d['conditions_required'])) {
            $items[] = ['label' => 'Warunki udziału', 'value' => $d['conditions_required'] ? truncate((string) ($d['conditions'] ?: 'Tak – szczegóły w SWZ'), 600) : 'Zamawiający nie stawia warunków udziału', 'source' => $src('5.3–5.4')];
        }
        if (isset($d['wadium'])) {
            $items[] = ['label' => 'Wadium', 'value' => $d['wadium'] ? ($d['wadium_value'] ? money_pl($d['wadium_value']) : ($d['wadium_info'] ?: 'Tak')) : 'Nie jest wymagane', 'source' => $src('6.4')];
            if ($d['wadium']) {
                $risks[] = ['level' => 'mid', 'text' => 'Wadium musi być wniesione przed terminem składania ofert' . ($d['wadium_value'] ? ' (' . money_pl($d['wadium_value']) . ')' : '') . '. Gwarancja bankowa lub ubezpieczeniowa wymaga zwykle kilku dni.', 'source' => $src('6.4.1')];
                $docs[] = ['name' => 'Dowód wniesienia wadium', 'source' => $src('6.4')];
            }
        }
        if (!empty($d['performance_bond'])) {
            $risks[] = ['level' => 'low', 'text' => 'Wymagane zabezpieczenie należytego wykonania umowy – uwzględnij jego koszt w cenie.', 'source' => $src('6.5')];
        }
        if (array_key_exists('advance_payments', $d) && $d['advance_payments'] === false) {
            $risks[] = ['level' => 'low', 'text' => 'Brak zaliczek – realizację finansujesz do pierwszej płatności.', 'source' => $src('7.1')];
        }
        if (!empty($d['auction'])) {
            $items[] = ['label' => 'Aukcja elektroniczna', 'value' => 'Tak – cenę będzie można poprawić w aukcji', 'source' => $src('6.3')];
        }
        if (!empty($d['deadline'])) {
            $days = days_until($d['deadline']);
            $items[] = ['label' => 'Składanie ofert', 'value' => local_dt($d['deadline']) . (!empty($d['submission_place']) ? ' · ' . $d['submission_place'] : ''), 'source' => $src('8.1–8.2')];
            if ($days !== null && $days < 5) {
                $risks[] = ['level' => 'high', 'text' => 'Na przygotowanie oferty zostało ' . max(0, $days) . ' ' . plural(max(0, $days), 'dzień', 'dni', 'dni') . '.', 'source' => $src('8.1')];
            }
        }
        if (!empty($d['binding_until'])) {
            $items[] = ['label' => 'Związanie ofertą', 'value' => 'do ' . date('d.m.Y', strtotime($d['binding_until'])), 'source' => $src('8.4')];
        }
        if (!empty($d['eu_funds'])) {
            $items[] = ['label' => 'Środki UE', 'value' => 'Zamówienie współfinansowane z UE', 'source' => $src('2.14')];
        }

        $docs = array_merge([
            ['name' => 'Formularz ofertowy', 'source' => 'SWZ'],
            ['name' => 'Oświadczenie o niepodleganiu wykluczeniu i spełnianiu warunków (art. 125 ust. 1)', 'source' => $src('5.5')],
        ], $docs);
        if (!empty($d['conditions_required'])) {
            $docs[] = ['name' => 'Wykaz robót/usług i dowody należytego wykonania (jeśli wymaga ich SWZ)', 'source' => $src('5.7')];
        }
        $docs[] = ['name' => 'Pełnomocnictwo (jeśli ofertę podpisuje pełnomocnik)', 'source' => 'SWZ'];
        if (!empty($d['other_documents'])) {
            $docs[] = ['name' => 'Inne dokumenty: ' . truncate((string) $d['other_documents'], 200), 'source' => $src('5.11')];
        }

        return ['mode' => 'rules', 'items' => $items, 'risks' => $risks, 'documents' => $docs];
    }

    /** Analiza AI całej dokumentacji. Zapisuje wynik i zwraca go. */
    public static function ai(int $orgId, int $userId, array $tender, array $d): array
    {
        $docs = Db::get()->all('SELECT filename, text FROM tender_documents WHERE org_id = ? AND tender_id = ? ORDER BY id', [$orgId, (int) $tender['id']]);
        $context = self::context($tender, $d, $docs);
        $system = 'Jesteś ekspertem od zamówień publicznych w Polsce. Analizujesz dokumentację przetargu dla wykonawcy (małej firmy). '
            . 'Piszesz po polsku, krótko i konkretnie. Każdy punkt MUSI mieć źródło: nazwę dokumentu i rozdział, punkt lub stronę, z których pochodzi. '
            . 'Nie zgaduj: jeśli czegoś nie ma w dokumentach, nie wpisuj tego. Odpowiadasz wyłącznie obiektem JSON.';
        $user = "Dokumentacja przetargu poniżej. Zwróć JSON o strukturze:\n"
            . '{"summary":"2-3 zdania: co jest przedmiotem i dla kogo to zlecenie","items":[{"label":"…","value":"…","source":"…"}],'
            . '"conditions":[{"text":"warunek udziału","source":"…"}],"risks":[{"level":"high|mid|low","text":"ryzyko dla wykonawcy (kary, płatności, terminy, nietypowe wymagania)","source":"…"}],'
            . '"documents":[{"name":"dokument do złożenia z ofertą","source":"…"}],"questions":["pytanie do zamawiającego warte zadania"]}' . "\n"
            . "W items uwzględnij: przedmiot, termin wykonania, kryteria oceny z wagami, wadium, zabezpieczenie, warunki płatności, kary umowne, termin składania ofert.\n\n"
            . $context;
        $r = Claude::complete($system, $user, 6000);
        if (!$r['ok']) {
            return ['ok' => false, 'error' => $r['error']];
        }
        $j = Claude::json((string) $r['text']);
        if (!$j) {
            return ['ok' => false, 'error' => 'Nie udało się odczytać odpowiedzi modelu. Spróbuj ponownie.'];
        }
        $j['mode'] = 'ai';
        $j['documents_used'] = array_column($docs, 'filename');
        Db::get()->insert('tender_analyses', [
            'org_id' => $orgId,
            'tender_id' => (int) $tender['id'],
            'kind' => 'summary',
            'result_json' => json_out($j),
            'model' => (string) config('AI_MODEL'),
            'created_by' => $userId,
            'created_at' => now_utc(),
        ]);
        return ['ok' => true, 'result' => $j];
    }

    public static function ask(int $orgId, int $userId, array $tender, array $d, string $question): array
    {
        $docs = Db::get()->all('SELECT filename, text FROM tender_documents WHERE org_id = ? AND tender_id = ? ORDER BY id', [$orgId, (int) $tender['id']]);
        $system = 'Odpowiadasz na pytania wykonawcy o dokumentację przetargu. Tylko na podstawie podanych dokumentów, po polsku, w 2-5 zdaniach. '
            . 'Podaj źródła (dokument i rozdział/punkt/stronę). Jeśli odpowiedzi nie ma w dokumentach, napisz to wprost. Odpowiadasz obiektem JSON {"answer":"…","sources":["…"]}.';
        $r = Claude::complete($system, 'Pytanie: ' . $question . "\n\n" . self::context($tender, $d, $docs), 1500);
        if (!$r['ok']) {
            return ['ok' => false, 'error' => $r['error']];
        }
        $j = Claude::json((string) $r['text']) ?? ['answer' => trim((string) $r['text']), 'sources' => []];
        Db::get()->insert('tender_analyses', [
            'org_id' => $orgId,
            'tender_id' => (int) $tender['id'],
            'kind' => 'qa',
            'question' => $question,
            'result_json' => json_out($j),
            'model' => (string) config('AI_MODEL'),
            'created_by' => $userId,
            'created_at' => now_utc(),
        ]);
        return ['ok' => true, 'result' => $j];
    }

    public static function latest(int $orgId, int $tenderId): ?array
    {
        $row = Db::get()->one("SELECT * FROM tender_analyses WHERE org_id = ? AND tender_id = ? AND kind = 'summary' ORDER BY id DESC LIMIT 1", [$orgId, $tenderId]);
        return $row ? json_in($row['result_json']) + ['created_at' => $row['created_at']] : null;
    }

    public static function questions(int $orgId, int $tenderId): array
    {
        return array_map(static fn ($r) => ['question' => $r['question'], 'created_at' => $r['created_at']] + json_in($r['result_json']), Db::get()->all(
            "SELECT * FROM tender_analyses WHERE org_id = ? AND tender_id = ? AND kind = 'qa' ORDER BY id DESC LIMIT 10",
            [$orgId, $tenderId]
        ));
    }

    /** Tekst dla modelu: ogłoszenie + dokumenty, z limitem długości. */
    public static function context(array $tender, array $d, array $docs): string
    {
        $limit = (int) config('AI_MAX_INPUT_CHARS', 400000);
        $parts = ["=== OGŁOSZENIE (" . strtoupper((string) $tender['source']) . ") ===\nTytuł: {$tender['title']}\nZamawiający: {$tender['buyer_name']}\n" . ($d['text'] ?? '')];
        foreach ($docs as $doc) {
            $parts[] = '=== DOKUMENT: ' . $doc['filename'] . " ===\n" . $doc['text'];
        }
        $all = implode("\n\n", $parts);
        return mb_strlen($all) > $limit ? mb_substr($all, 0, $limit) . "\n[…dokumentacja skrócona…]" : $all;
    }
}
