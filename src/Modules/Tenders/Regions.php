<?php

declare(strict_types=1);

namespace App\Modules\Tenders;

/**
 * Województwa. BZP podaje kody TERYT (PL14 = mazowieckie), TED – kody NUTS (PL91 = Warszawski stołeczny).
 * Uwaga: „PL22” znaczy co innego w obu systemach (pomorskie w BZP, śląskie w NUTS), więc zawsze mapujemy na własny identyfikator.
 */
final class Regions
{
    public const ALL = [
        'dolnoslaskie' => ['Dolnośląskie', '02', ['PL51']],
        'kujawsko-pomorskie' => ['Kujawsko-pomorskie', '04', ['PL61']],
        'lubelskie' => ['Lubelskie', '06', ['PL81']],
        'lubuskie' => ['Lubuskie', '08', ['PL43']],
        'lodzkie' => ['Łódzkie', '10', ['PL71']],
        'malopolskie' => ['Małopolskie', '12', ['PL21']],
        'mazowieckie' => ['Mazowieckie', '14', ['PL91', 'PL92']],
        'opolskie' => ['Opolskie', '16', ['PL52']],
        'podkarpackie' => ['Podkarpackie', '18', ['PL82']],
        'podlaskie' => ['Podlaskie', '20', ['PL84']],
        'pomorskie' => ['Pomorskie', '22', ['PL63']],
        'slaskie' => ['Śląskie', '24', ['PL22']],
        'swietokrzyskie' => ['Świętokrzyskie', '26', ['PL72']],
        'warminsko-mazurskie' => ['Warmińsko-mazurskie', '28', ['PL62']],
        'wielkopolskie' => ['Wielkopolskie', '30', ['PL41']],
        'zachodniopomorskie' => ['Zachodniopomorskie', '32', ['PL42']],
    ];

    public static function name(?string $slug): string
    {
        return self::ALL[$slug][0] ?? '';
    }

    /** Kod BZP (TERYT), np. „PL14”. */
    public static function fromBzp(?string $code): ?string
    {
        if (!$code || !preg_match('/^PL(\d{2})$/', $code, $m)) {
            return null;
        }
        foreach (self::ALL as $slug => [$name, $teryt]) {
            if ($teryt === $m[1]) {
                return $slug;
            }
        }
        return null;
    }

    /** Kod NUTS dowolnego poziomu, np. „PL911” albo „PL22B”. */
    public static function fromNuts(?string $code): ?string
    {
        if (!$code || !preg_match('/^(PL\d{2})/', strtoupper($code), $m)) {
            return null;
        }
        foreach (self::ALL as $slug => [$name, $teryt, $nuts]) {
            if (in_array($m[1], $nuts, true)) {
                return $slug;
            }
        }
        return null;
    }

    /** Nazwa województwa z treści ogłoszenia, np. „śląskie”. */
    public static function fromName(?string $name): ?string
    {
        $n = search_text((string) $name);
        foreach (self::ALL as $slug => [$label]) {
            if ($n !== '' && search_text($label) === $n) {
                return $slug;
            }
        }
        return null;
    }
}
