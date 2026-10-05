<?php

namespace App\Domain\Reports\Export;

use App\Models\Report;
use RuntimeException;
use ZipArchive;

/**
 * An editable Word document: title, every section as a heading with its
 * narrative and its data as a formatted table, and the evidence behind each
 * section. Written as Office Open XML directly, so no extra dependency.
 */
class DocxExporter implements Exporter
{
    public function export(Report $report, string $path): void
    {
        $report->loadMissing('sections', 'organisation');
        $theme = Theme::get($report->theme);
        $tables = new XlsxExporter;
        $body = $this->para($report->title, 'Title')
            .$this->para(($report->organisation->name ?? '').' · generated '.now()->toDayDateTimeString().' · version '.$report->current_version, 'Subtitle');

        foreach ($report->sections as $section) {
            $c = $section->content ?? [];
            $body .= $this->para($section->title, 'Heading1');
            if (! empty($c['error'])) {
                $body .= $this->para('This section could not be computed: '.$c['error']);

                continue;
            }
            foreach (array_filter([...(array) ($c['paragraphs'] ?? []), $c['text'] ?? null, $c['narrative'] ?? null], 'is_string') as $p) {
                $body .= $this->para($p);
            }
            if ($rows = $tables->rows($section->type, $c)) {
                $body .= $this->table($rows['header'][0], $rows['data'], $rows['formats'], $theme['ink']);
            }
            $hashes = array_filter(array_map(fn ($e) => $e['query_hash'] ?? null, $c['evidence'] ?? []));
            if ($hashes) {
                $body .= $this->para('Evidence: '.implode(', ', array_slice($hashes, 0, 5)), 'Caption');
            }
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the Word file.');
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>');
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>'.$this->e($report->title).'</dc:title><dc:creator>AIXBI</dc:creator></cp:coreProperties>');
        $zip->addFromString('word/styles.xml', $this->styles($theme['ink']));
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            .$body.'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="567" w:footer="567" w:gutter="0"/></w:sectPr></w:body></w:document>');
        $zip->close();
    }

    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function para(string $text, ?string $style = null): string
    {
        $pPr = $style ? '<w:pPr><w:pStyle w:val="'.$style.'"/></w:pPr>' : '';

        return '<w:p>'.$pPr.'<w:r><w:t xml:space="preserve">'.$this->e($text).'</w:t></w:r></w:p>';
    }

    /**
     * @param  array<int, string>  $header
     * @param  array<int, array<mixed>>  $data
     * @param  array<string, string>  $formats  column letter => spreadsheet number format
     */
    private function table(array $header, array $data, array $formats, string $ink): string
    {
        $cell = fn (string $text, bool $head = false, bool $right = false) => '<w:tc><w:tcPr>'.($head ? '<w:shd w:val="clear" w:color="auto" w:fill="'.$ink.'"/>' : '').'</w:tcPr><w:p><w:pPr>'
            .($right ? '<w:jc w:val="right"/>' : '').'<w:spacing w:before="40" w:after="40"/></w:pPr><w:r>'.($head ? '<w:rPr><w:b/><w:color w:val="FFFFFF"/></w:rPr>' : '')
            .'<w:t xml:space="preserve">'.$this->e($text).'</w:t></w:r></w:p></w:tc>';
        $xml = '<w:tbl><w:tblPr><w:tblStyle w:val="TableGrid"/><w:tblW w:w="5000" w:type="pct"/></w:tblPr><w:tr>'
            .implode('', array_map(fn ($h) => $cell((string) $h, true), $header)).'</w:tr>';
        foreach (array_slice($data, 0, 200) as $row) {
            $xml .= '<w:tr>';
            foreach (array_values($row) as $i => $v) {
                $xml .= $cell($this->value($v, $formats[chr(ord('A') + $i)] ?? null), false, is_int($v) || is_float($v));
            }
            $xml .= '</w:tr>';
        }

        return $xml.'</w:tbl>'.$this->para('');
    }

    private function value(mixed $v, ?string $format): string
    {
        if ($v === null) {
            return '—';
        }
        if (! is_int($v) && ! is_float($v)) {
            return is_scalar($v) ? (string) $v : (string) json_encode($v);
        }

        return match (true) {
            $format !== null && str_contains($format, '%') => number_format($v * 100, 1).'%',
            $format !== null && str_contains($format, 'RM') => 'RM '.number_format($v),
            default => number_format($v, fmod((float) $v, 1.0) === 0.0 ? 0 : 2),
        };
    }

    private function styles(string $ink): string
    {
        $style = fn (string $id, string $name, string $rPr, string $pPr = '') => '<w:style w:type="paragraph" w:styleId="'.$id.'"><w:name w:val="'.$name.'"/><w:basedOn w:val="Normal"/><w:pPr>'.$pPr.'</w:pPr><w:rPr>'.$rPr.'</w:rPr></w:style>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/><w:sz w:val="21"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="276" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
            .'<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
            .$style('Title', 'Title', '<w:b/><w:sz w:val="44"/><w:color w:val="'.$ink.'"/>')
            .$style('Subtitle', 'Subtitle', '<w:color w:val="666666"/><w:sz w:val="20"/>', '<w:spacing w:after="360"/>')
            .$style('Heading1', 'heading 1', '<w:b/><w:sz w:val="30"/><w:color w:val="'.$ink.'"/>', '<w:keepNext/><w:spacing w:before="360" w:after="120"/><w:outlineLvl w:val="0"/>')
            .$style('Caption', 'caption', '<w:i/><w:sz w:val="16"/><w:color w:val="888888"/>')
            .'<w:style w:type="table" w:styleId="TableGrid"><w:name w:val="Table Grid"/><w:tblPr><w:tblBorders><w:top w:val="single" w:sz="4" w:color="D0D0D0"/><w:bottom w:val="single" w:sz="4" w:color="D0D0D0"/><w:insideH w:val="single" w:sz="4" w:color="D0D0D0"/></w:tblBorders><w:tblCellMar><w:left w:w="80" w:type="dxa"/><w:right w:w="80" w:type="dxa"/></w:tblCellMar></w:tblPr></w:style>'
            .'</w:styles>';
    }

    public function extension(): string
    {
        return 'docx';
    }

    public function mime(): string
    {
        return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    }
}
