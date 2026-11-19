<?php

declare(strict_types=1);

namespace App\Modules\Offers;

/** Minimalny dokument Word (.docx) z nagłówkami i akapitami – bez zewnętrznych bibliotek. */
final class Docx
{
    /** @param array<int, array{title: string, body: string}> $sections */
    public static function build(string $title, array $sections, bool $stripMarkers = true): string
    {
        $p = [];
        $p[] = self::para($title, 'Title');
        foreach ($sections as $s) {
            if (trim((string) $s['title']) !== '') {
                $p[] = self::para($s['title'], 'Heading1');
            }
            foreach (preg_split('/\r?\n/', (string) $s['body']) ?: [] as $line) {
                if ($stripMarkers) {
                    $line = trim(preg_replace('/\s*⟦źródło:[^⟧]*⟧/u', '', $line) ?? '');
                    $line = trim(str_replace('⟦sprawdź⟧', '[SPRAWDŹ]', $line));
                }
                $p[] = self::para($line);
            }
        }
        $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            . implode('', $p)
            . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr>'
            . '</w:body></w:document>';
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/><w:sz w:val="22"/><w:lang w:val="pl-PL"/></w:rPr></w:rPrDefault>'
            . '<w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="276" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:after="240"/></w:pPr><w:rPr><w:b/><w:sz w:val="32"/></w:rPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:pPr><w:keepNext/><w:spacing w:before="240" w:after="120"/><w:outlineLvl w:val="0"/></w:pPr><w:rPr><w:b/><w:sz w:val="26"/></w:rPr></w:style>'
            . '</w:styles>';
        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', $doc);
        $zip->addFromString('word/styles.xml', $styles);
        $zip->close();
        $bin = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bin;
    }

    private static function para(string $text, ?string $style = null): string
    {
        $pPr = $style ? '<w:pPr><w:pStyle w:val="' . $style . '"/></w:pPr>' : '';
        // XML 1.0 nie dopuszcza znaków sterujących (np. \f z tekstu PDF) – Word odmawia otwarcia takiego pliku.
        $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', mb_scrub($text, 'UTF-8')) ?? '';
        $t = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        return '<w:p>' . $pPr . '<w:r><w:t xml:space="preserve">' . $t . '</w:t></w:r></w:p>';
    }
}
