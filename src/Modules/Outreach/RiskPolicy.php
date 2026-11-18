<?php

declare(strict_types=1);

namespace App\Modules\Outreach;

/**
 * Ocena ryzyka prawnego wiadomości do firmy. Platforma nie blokuje wyboru – pokazuje ryzyko,
 * a przy ryzyku wysokim i bardzo wysokim wymaga świadomego potwierdzenia, które zapisujemy w historii.
 *
 * Wyjątek: kontakt, który się wypisał (sprzeciw, art. 21 RODO), nie może dostać wiadomości marketingowych.
 */
final class RiskPolicy
{
    public const KINDS = [
        'rfq_reply' => [
            'title' => 'Odpowiedź na zapytanie ofertowe',
            'note' => 'Firma sama poprosiła o oferty i podała adres. Odpowiadasz na jej prośbę.',
        ],
        'consented' => [
            'title' => 'Oferta do firmy, która wyraziła zgodę',
            'note' => 'Odbiorca zgodził się na informacje handlowe e-mailem albo jest Twoim klientem i o to prosił.',
        ],
        'consent_request' => [
            'title' => 'Prośba o zgodę na przesłanie oferty',
            'note' => 'Krótka wiadomość bez treści reklamowej z pytaniem, czy możesz przesłać ofertę.',
        ],
        'cold_offer' => [
            'title' => 'Oferta bez zgody odbiorcy',
            'note' => 'Informacja handlowa wysłana bez wcześniejszej zgody.',
        ],
    ];

    public const BASES = [
        'none' => 'Brak podstawy – adres z publicznego źródła',
        'rfq' => 'Zapytanie ofertowe od tej firmy',
        'consent' => 'Zgoda na informacje handlowe e-mailem',
        'client' => 'Obecny klient (wcześniejsza współpraca)',
    ];

    public const LEVELS = [
        'low' => ['label' => 'niskie', 'chip' => 'low'],
        'high' => ['label' => 'wysokie', 'chip' => 'mid'],
        'very_high' => ['label' => 'bardzo wysokie', 'chip' => 'high'],
    ];

    public const ACK_TEXT = 'Zapoznałem się z ryzykiem. Sam decyduję o wysłaniu tej wiadomości i odpowiedzialność za wysyłkę ponosi moja firma.';

    /**
     * @return array{level: string, blocked: bool, lead: string, reasons: string[], law: string[], needs_ack: bool}
     */
    public static function assess(string $kind, array $contact): array
    {
        $basis = (string) ($contact['basis'] ?? 'none');
        $unsub = !empty($contact['unsubscribed_at']);
        $reasons = [];
        $law = [];

        if ($unsub && $kind !== 'rfq_reply') {
            return [
                'level' => 'very_high',
                'blocked' => true,
                'lead' => 'Odbiorca wypisał się z wiadomości. Nie możesz wysyłać mu ofert ani próśb o zgodę.',
                'reasons' => ['Sprzeciw zapisano ' . local_dt((string) $contact['unsubscribed_at']) . '.'],
                'law' => ['Art. 21 RODO: po sprzeciwie dane nie mogą być przetwarzane na potrzeby marketingu bezpośredniego.'],
                'needs_ack' => true,
            ];
        }

        switch ($kind) {
            case 'rfq_reply':
                if ($basis === 'rfq') {
                    $level = 'low';
                    $lead = 'Firma sama poprosiła o oferty. Odpowiedź na zapytanie nie wymaga osobnej zgody marketingowej.';
                    $reasons[] = 'Podstawa kontaktu: ' . self::basisLine($contact);
                } else {
                    $level = 'high';
                    $lead = 'W karcie kontaktu nie ma zapisanego zapytania ofertowego od tej firmy. Bez niego wiadomość może zostać uznana za niezamówioną informację handlową.';
                    $reasons[] = 'Dopisz w karcie kontaktu, gdzie i kiedy firma prosiła o oferty (np. link do zapytania).';
                    $law = self::pkeLaw();
                }
                break;
            case 'consented':
                if (in_array($basis, ['consent', 'client'], true)) {
                    $level = 'low';
                    $lead = 'Odbiorca wyraził zgodę albo jest Twoim klientem. Pamiętaj o łatwym sposobie wypisania się w stopce.';
                    $reasons[] = 'Podstawa kontaktu: ' . self::basisLine($contact);
                } else {
                    $level = 'very_high';
                    $lead = 'W karcie kontaktu nie ma zapisanej zgody. Wysłanie oferty bez zgody narusza przepisy o informacji handlowej.';
                    $reasons[] = 'Zapisz w karcie kontaktu, kiedy i w jaki sposób odbiorca wyraził zgodę.';
                    $law = self::pkeLaw();
                }
                break;
            case 'consent_request':
                $level = in_array($basis, ['consent', 'client', 'rfq'], true) ? 'low' : 'high';
                $lead = $level === 'low'
                    ? 'Masz już podstawę kontaktu – zamiast prosić o zgodę możesz od razu odpowiedzieć lub wysłać ofertę.'
                    : 'Prośba o zgodę wysłana e-mailem bez wcześniejszej zgody to sprawa sporna. Sądy uznawały takie wiadomości za pośrednią promocję nadawcy.';
                if ($level === 'high') {
                    $law = array_merge(self::pkeLaw(), [
                        'Sąd Apelacyjny w Warszawie (VII AGa 1567/18) uznał prośbę o zgodę wysłaną SMS-em za pośrednią promocję nadawcy.',
                        'Brak orzeczeń pod nowymi przepisami dla e-maila z samą prośbą o zgodę – sprawa sporna.',
                    ]);
                    $reasons[] = 'Wiadomość nie może zawierać oferty, cen ani opisu usług – tylko pytanie o zgodę.';
                }
                break;
            case 'cold_offer':
            default:
                if (in_array($basis, ['consent', 'client'], true)) {
                    $level = 'low';
                    $lead = 'Masz zapisaną zgodę lub relację z klientem, więc to w praktyce oferta za zgodą.';
                    $reasons[] = 'Podstawa kontaktu: ' . self::basisLine($contact);
                } else {
                    $level = 'very_high';
                    $lead = 'Informacja handlowa bez wcześniejszej zgody odbiorcy jest zakazana, także wobec firm.';
                    $law = self::pkeLaw();
                    $reasons[] = 'Odbiorca może złożyć skargę do Prezesa UKE albo pozew z tytułu nieuczciwej konkurencji.';
                }
                break;
        }

        return [
            'level' => $level,
            'blocked' => false,
            'lead' => $lead,
            'reasons' => $reasons,
            'law' => $law,
            'needs_ack' => $level !== 'low',
        ];
    }

    private static function pkeLaw(): array
    {
        return [
            'Art. 398 Prawa komunikacji elektronicznej: informacja handlowa wymaga uprzedniej zgody odbiorcy, także firmy.',
            'Kara Prezesa UKE: do 3% przychodu z poprzedniego roku albo do 1 mln zł (art. 444); także wykroczenie (art. 448) i czyn nieuczciwej konkurencji.',
        ];
    }

    public static function basisLine(array $contact): string
    {
        $line = self::BASES[$contact['basis'] ?? 'none'] ?? 'brak';
        if (!empty($contact['basis_detail'])) {
            $line .= ' – ' . $contact['basis_detail'];
        }
        if (!empty($contact['basis_at'])) {
            $line .= ' (' . date('d.m.Y', strtotime((string) $contact['basis_at'])) . ')';
        }
        return $line;
    }
}
