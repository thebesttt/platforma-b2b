<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\Brand;
use App\Core\Db;
use App\Core\Kv;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Inbound\Inbound;

/**
 * Publiczna część widżetu zapytań: kod wklejany na stronę firmy i cztery adresy, z którymi rozmawia.
 *
 * Bez logowania i bez plików cookie. Zabezpieczenia:
 * - odpowiadamy tylko stronom wpisanym w ustawieniach widżetu (nagłówek Origin) i samemu panelowi (podgląd),
 * - limity zapytań na adres IP (zapisujemy skrót adresu, nie sam adres) i dzienny limit rozmów,
 * - treść zapytania najwyżej 16 KB, każde pole ma własny limit długości,
 * - do przeglądarki nie trafiają punkty ani progi oceny.
 */
final class WidgetController
{
    private const MAX_BODY = 16384;

    /** Zapytań na godzinę z jednego adresu IP. */
    private const RATE = ['start' => 60, 'answer' => 300, 'ask' => 60, 'lead' => 10];

    /** GET /w/{id}.js - kod widżetu z ustawieniami tej firmy. */
    public function script(Request $r): Response
    {
        $js = ['Content-Type' => 'application/javascript; charset=utf-8', 'Cache-Control' => 'public, max-age=300', 'X-Frame-Options' => 'DENY'];
        if (!preg_match('/^([a-f0-9]{16})\.js$/', $r->param('file'), $m) || !($w = Inbound::byPublicId($m[1]))) {
            return new Response('/* Nie ma takiego widżetu. */', 404, $js);
        }
        if ((int) $w['active'] !== 1) {
            return new Response('/* Widżet jest wyłączony w panelu. */', 200, $js);
        }
        $org = Db::get()->one('SELECT settings_json FROM organizations WHERE id = ?', [(int) $w['org_id']]);
        $texts = Inbound::texts($w);
        $cfg = [
            'id' => $w['public_id'],
            'base' => $r->baseUrl(),
            'button' => $texts['button'],
            'company' => $texts['company'],
            'theme' => Brand::theme($org) === 'perfectdata' ? 'dark' : 'light',
        ];
        $code = (string) file_get_contents(APP_ROOT . '/public/assets/widget.js');
        $json = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE);
        return new Response(str_replace('/*CFG*/null', (string) $json, $code), 200, $js);
    }

    public function start(Request $r): Response
    {
        return $this->handle($r, 'start', static function (array $w, array $in, bool $preview) use ($r): array {
            if (!$preview && Inbound::todayViews($w) >= Inbound::limits($w)['daily']) {
                return ['ok' => false, 'error' => 'Formularz jest chwilowo niedostępny. Spróbuj ponownie jutro.', 'code' => 429];
            }
            return Inbound::start($w, self::text($in, 'page', Inbound::MAX['page']), $r->ip(), $preview);
        });
    }

    public function answer(Request $r): Response
    {
        return $this->handle($r, 'answer', static function (array $w, array $in): array {
            $conv = Inbound::conversation($w, self::text($in, 'token', 40));
            if (!$conv) {
                return ['ok' => false, 'error' => 'Rozmowa wygasła. Odśwież stronę i zacznij od nowa.', 'code' => 404];
            }
            $q = $in['q'] ?? null;
            $a = $in['a'] ?? null;
            if (!is_int($q) || !is_int($a)) {
                return ['ok' => false, 'error' => 'Nie ma takiej odpowiedzi.', 'code' => 422];
            }
            return Inbound::answer($w, $conv, $q, $a) + ['code' => 422];
        });
    }

    public function ask(Request $r): Response
    {
        return $this->handle($r, 'ask', static function (array $w, array $in): array {
            $conv = Inbound::conversation($w, self::text($in, 'token', 40));
            if (!$conv) {
                return ['ok' => false, 'error' => 'Rozmowa wygasła. Odśwież stronę i zacznij od nowa.', 'code' => 404];
            }
            $question = is_string($in['question'] ?? null) ? $in['question'] : '';
            if (mb_strlen($question) > Inbound::MAX['question']) {
                return ['ok' => false, 'error' => 'Pytanie jest za długie. Skróć je do ' . Inbound::MAX['question'] . ' znaków.', 'code' => 422];
            }
            return Inbound::ask($w, $conv, $question);
        });
    }

    public function lead(Request $r): Response
    {
        return $this->handle($r, 'lead', static function (array $w, array $in) use ($r): array {
            $conv = Inbound::conversation($w, self::text($in, 'token', 40));
            if (!$conv) {
                return ['ok' => false, 'error' => 'Rozmowa wygasła. Odśwież stronę i zacznij od nowa.', 'code' => 404];
            }
            $res = Inbound::lead($w, $conv, $in, $r->baseUrl());
            // Numer zapytania i informacja o odrzuceniu automatu zostają na serwerze.
            return $res['ok'] ? ['ok' => true, 'thanks' => Inbound::texts($w)['thanks']] : $res;
        });
    }

    /**
     * Wspólna część czterech adresów: widżet, dozwolona strona, limity, treść zapytania.
     * @param callable(array, array, bool): array $fn zwraca tablicę z kluczem ok; przy błędzie także error i code
     */
    private function handle(Request $r, string $action, callable $fn): Response
    {
        $w = Inbound::byPublicId($r->param('id'));
        if (!$w || (int) $w['active'] !== 1) {
            return self::out(['ok' => false, 'error' => 'Ten formularz jest wyłączony.'], 404, null);
        }
        $origin = $r->header('Origin');
        if (!Inbound::originAllowed($w, $origin, $r->baseUrl())) {
            // Bez nagłówka CORS przeglądarka i tak nie pokaże tej odpowiedzi obcej stronie.
            return self::out(['ok' => false, 'error' => 'Ta strona nie jest wpisana w ustawieniach widżetu.'], 403, null);
        }
        if (strlen($r->body()) > self::MAX_BODY) {
            return self::out(['ok' => false, 'error' => 'Zapytanie jest za duże.'], 413, $origin);
        }
        if (Kv::hit('inbound:rl:' . $action . ':' . Inbound::ipHash($r->ip()), 3600) > self::RATE[$action]) {
            return self::out(['ok' => false, 'error' => 'Za dużo prób w krótkim czasie. Spróbuj ponownie za godzinę.'], 429, $origin);
        }
        $preview = Inbound::host($origin) === Inbound::host($r->baseUrl());
        try {
            $res = $fn($w, $r->json(), $preview);
        } catch (\Throwable $e) {
            error_log('[widget] ' . $action . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
            return self::out(['ok' => false, 'error' => 'Coś poszło nie tak. Spróbuj ponownie za chwilę.'], 500, $origin);
        }
        $status = !empty($res['ok']) ? 200 : (int) ($res['code'] ?? 422);
        unset($res['code']);
        return self::out($res, $status, $origin);
    }

    private static function text(array $in, string $key, int $max): string
    {
        return is_scalar($in[$key] ?? null) ? mb_substr(trim((string) $in[$key]), 0, $max) : '';
    }

    private static function out(array $data, int $status, ?string $origin): Response
    {
        $h = ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store', 'Vary' => 'Origin'];
        if ($origin !== null && $origin !== '') {
            $h['Access-Control-Allow-Origin'] = $origin;
        }
        return new Response(json_out($data), $status, $h);
    }
}
