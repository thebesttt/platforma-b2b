<?php

declare(strict_types=1);

namespace App\Modules\Ai;

/** Tekst z dokumentów SWZ: PDF (pdftotext), DOCX (XML w archiwum ZIP), TXT. */
final class DocumentText
{
    public const ALLOWED = [
        'application/pdf' => 'pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'text/plain' => 'txt',
        'application/zip' => 'docx', // niektóre przeglądarki tak oznaczają .docx
    ];

    /** Najdłuższy tekst jednego dokumentu, jaki zapisujemy (ok. 700 stron). */
    public const MAX_CHARS = 1500000;

    /** Limit rozmiaru word/document.xml po rozpakowaniu – ochrona przed „bombą ZIP”. */
    public const MAX_DOCX_XML_BYTES = 60 * 1024 * 1024;

    public static function extract(string $path, string $mime, string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($mime === 'application/pdf' || $ext === 'pdf') {
            $t = self::pdf($path);
        } elseif ($ext === 'docx') {
            $t = self::docx($path);
        } elseif ($ext === 'txt' || $mime === 'text/plain') {
            $t = (string) file_get_contents($path);
            $t = mb_check_encoding($t, 'UTF-8') ? $t : mb_convert_encoding($t, 'UTF-8', 'Windows-1250');
        } else {
            return '';
        }
        // PostgreSQL nie przyjmuje znaku NUL w tekście; usuwamy też błędne sekwencje UTF-8.
        $t = str_replace("\0", '', mb_scrub($t, 'UTF-8'));
        return mb_strlen($t) > self::MAX_CHARS ? mb_substr($t, 0, self::MAX_CHARS) . "\n[…dokument skrócony…]" : $t;
    }

    public static function pdf(string $path): string
    {
        $bin = trim((string) @shell_exec('command -v pdftotext 2>/dev/null'));
        if ($bin === '') {
            return '';
        }
        $out = tempnam(sys_get_temp_dir(), 'pdf');
        // „timeout” przerywa konwersję uszkodzonego lub złośliwego pliku po 60 sekundach.
        $timeout = trim((string) @shell_exec('command -v timeout 2>/dev/null')) !== '' ? 'timeout 60 ' : '';
        $cmd = $timeout . escapeshellcmd($bin) . ' -layout -enc UTF-8 ' . escapeshellarg($path) . ' ' . escapeshellarg($out) . ' 2>/dev/null';
        @shell_exec($cmd);
        $t = is_file($out) ? (string) file_get_contents($out) : '';
        @unlink($out);
        // Numery stron jako znaczniki (pdftotext rozdziela strony znakiem \f).
        $pages = explode("\f", $t);
        $res = '';
        foreach ($pages as $i => $p) {
            if (trim($p) !== '') {
                $res .= "\n[strona " . ($i + 1) . "]\n" . rtrim($p) . "\n";
            }
        }
        return trim($res);
    }

    public static function docx(string $path): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return '';
        }
        $stat = $zip->statName('word/document.xml');
        if ($stat === false || (int) $stat['size'] > self::MAX_DOCX_XML_BYTES) {
            $zip->close();
            return '';
        }
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === '') {
            return '';
        }
        $xml = preg_replace('#</w:p>#', "\n", $xml) ?? '';
        $xml = preg_replace('#<w:tab/>#', "\t", $xml) ?? '';
        $xml = preg_replace('#<w:br/>#', "\n", $xml) ?? '';
        $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? '');
    }
}
