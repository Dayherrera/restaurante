<?php

namespace App\Services;

use ZipArchive;

/** XLSX export uses explicit text cells so customer input cannot become formulas. */
class ReportWorkbook
{
    private function xml(string $value): string
    {
        return htmlspecialchars(preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function column(int $number): string
    {
        $col = '';
        while ($number > 0) { $number--; $col = chr(65 + $number % 26).$col; $number = intdiv($number, 26); }
        return $col;
    }

    public function create(array $report): string
    {
        $path = tempnam(sys_get_temp_dir(), 'report-');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) { @unlink($path); throw new \RuntimeException('No se pudo crear el archivo Excel.'); }
        try {
            $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
            $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
            $sheets = ''; $relationships = ''; $types = '';
            $tables = $report['tables'];
            if (!in_array('Indicadores del resumen', array_column($tables, 'title'), true)) {
                $tables[] = ['title'=>'Indicadores del reporte', 'headers'=>['Indicador','Valor'], 'rows'=>collect($report['stats'])->map(fn ($value, $key) => [$key, $value])->values()->all(), 'money'=>[]];
            }
            foreach ($tables as $i=>$table) {
                $n = $i + 1;
                $sheets .= '<sheet name="'.$this->xml(mb_substr($table['title'],0,31)).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
                $relationships .= '<Relationship Id="rId'.$n.'" Type="'.$rel.'/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
                $types .= '<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
                $rows = [[$report['title'].' — '.$table['title']], [$report['period']], [$report['description']], ['Generado: '.now()->format('d/m/Y g:i A')], $table['headers'], ...$table['rows']];
                $body = '';
                foreach ($rows as $r=>$row) {
                    $height = $r >= 4 ? min(120, max(30, 15 * max(array_map(fn ($value) => (int)ceil(mb_strlen((string)$value) / 22), $row)))) : ($r === 2 ? 60 : 30);
                    $body .= '<row r="'.($r+1).'" ht="'.$height.'" customHeight="1">';
                    foreach ($row as $c=>$value) {
                        $ref = $this->column($c+1).($r+1);
                        $numeric = is_int($value) || is_float($value);
                        $style = $r === 0 || $r === 4 ? 1 : ($r > 4 && in_array($c,$table['money'],true) ? 2 : 0);
                        $body .= '<c r="'.$ref.'" s="'.$style.'"'.($numeric ? '><v>'.$value.'</v>' : ' t="inlineStr"><is><t xml:space="preserve">'.$this->xml((string)$value).'</t></is>').'</c>';
                    }
                    $body .= '</row>';
                }
                $lastCol = $this->column(count($table['headers']));
                $zip->addFromString('xl/worksheets/sheet'.$n.'.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="'.$ns.'"><sheetViews><sheetView workbookViewId="0"><pane ySplit="5" topLeftCell="A6" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="'.count($table['headers']).'" width="24" customWidth="1"/></cols><sheetData>'.$body.'</sheetData><autoFilter ref="A5:'.$lastCol.max(5,count($rows)).'"/><mergeCells count="4"><mergeCell ref="A1:'.$lastCol.'1"/><mergeCell ref="A2:'.$lastCol.'2"/><mergeCell ref="A3:'.$lastCol.'3"/><mergeCell ref="A4:'.$lastCol.'4"/></mergeCells></worksheet>');
            }
            $relationships .= '<Relationship Id="styles" Type="'.$rel.'/styles" Target="styles.xml"/>';
            $zip->addFromString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="'.$ns.'" xmlns:r="'.$rel.'"><sheets>'.$sheets.'</sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relationships.'</Relationships>');
            $zip->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="'.$rel.'/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $zip->addFromString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'.$types.'</Types>');
            $zip->addFromString('xl/styles.xml','<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="'.$ns.'"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
            if (!$zip->close()) throw new \RuntimeException('No se pudo finalizar el archivo Excel.');
            return $path;
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        }
    }
}
