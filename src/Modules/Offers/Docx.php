<?php

declare(strict_types=1);

namespace App\Modules\Offers;

/** Minimalny dokument Word (.docx) z nagłówkami i akapitami - bez zewnętrznych bibliotek. */
final class Docx
{
    /**
     * @param array<int, array{title: string, body: string}> $sections
     * @param ?array{png: string, after: string, cm?: float, alt?: string} $image obraz PNG wstawiany pod pierwszym akapitem, który zawiera tekst „after”
     */
    public static function build(string $title, array $sections, bool $stripMarkers = true, ?array $image = null): string
    {
        $p = [];
        $placed = false;
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
                if ($image !== null && !$placed && $image['after'] !== '' && str_contains($line, $image['after'])) {
                    $p[] = self::picture((float) ($image['cm'] ?? 3.0), (string) ($image['alt'] ?? ''));
                    $placed = true;
                }
            }
        }
        $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
            . ($placed ? ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"' : '')
            . '><w:body>'
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
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>' . ($placed ? '<Default Extension="png" ContentType="image/png"/>' : '') . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . ($placed ? '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.png"/>' : '') . '</Relationships>');
        if ($placed) {
            $zip->addFromString('word/media/image1.png', $image['png']);
        }
        $zip->addFromString('word/document.xml', $doc);
        $zip->addFromString('word/styles.xml', $styles);
        $zip->close();
        $bin = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bin;
    }

    /** Akapit z jednym obrazem (word/media/image1.png) o boku podanym w centymetrach. */
    private static function picture(float $cm, string $alt): string
    {
        $emu = (int) round(max(1.0, min(15.0, $cm)) * 360000);
        $alt = htmlspecialchars($alt, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        return '<w:p><w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' . $emu . '" cy="' . $emu . '"/>'
            . '<wp:docPr id="1" name="Obraz 1" descr="' . $alt . '"/>'
            . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="1" name="image1.png" descr="' . $alt . '"/><pic:cNvPicPr/></pic:nvPicPr>'
            . '<pic:blipFill><a:blip r:embed="rId2"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $emu . '" cy="' . $emu . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic>'
            . '</a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
    }

    private static function para(string $text, ?string $style = null): string
    {
        $pPr = $style ? '<w:pPr><w:pStyle w:val="' . $style . '"/></w:pPr>' : '';
        // XML 1.0 nie dopuszcza znaków sterujących (np. \f z tekstu PDF) - Word odmawia otwarcia takiego pliku.
        $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', mb_scrub($text, 'UTF-8')) ?? '';
        $t = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        return '<w:p>' . $pPr . '<w:r><w:t xml:space="preserve">' . $t . '</w:t></w:r></w:p>';
    }
}
