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

    public static function extract(string $path, string $mime, string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($mime === 'application/pdf' || $ext === 'pdf') {
            return self::pdf($path);
        }
        if ($ext === 'docx') {
            return self::docx($path);
        }
        if ($ext === 'txt' || $mime === 'text/plain') {
            $t = (string) file_get_contents($path);
            return mb_check_encoding($t, 'UTF-8') ? $t : mb_convert_encoding($t, 'UTF-8', 'Windows-1250');
        }
        return '';
    }

    public static function pdf(string $path): string
    {
        $bin = trim((string) @shell_exec('command -v pdftotext 2>/dev/null'));
        if ($bin === '') {
            return '';
        }
        $out = tempnam(sys_get_temp_dir(), 'pdf');
        $cmd = escapeshellcmd($bin) . ' -layout -enc UTF-8 ' . escapeshellarg($path) . ' ' . escapeshellarg($out) . ' 2>/dev/null';
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
