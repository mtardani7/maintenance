<?php

namespace App\Services;

use Carbon\Carbon;
use RuntimeException;
use ZipArchive;

class ReportsExcelWorkbook
{
    public function write(string $path, array $sheets, array $chartSources = []): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Ekspor Excel membutuhkan ekstensi PHP zip.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Tidak dapat membuat berkas Excel.');
        }

        $sheetCount = count($sheets);
        $chartCount = count($chartSources);
        $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $types .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        if ($chartCount > 0) {
            $types .= '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
            for ($i = 1; $i <= $chartCount; $i++) {
                $types .= '<Override PartName="/xl/charts/chart'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/>';
            }
        }
        $zip->addFromString('[Content_Types].xml', $types.'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');

        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($sheets as $index => $sheet) {
            $number = $index + 1;
            $workbook .= '<sheet name="'.htmlspecialchars($sheet['name'], ENT_XML1 | ENT_QUOTES, 'UTF-8').'" sheetId="'.$number.'" r:id="rId'.$number.'"/>';
            $workbookRels .= '<Relationship Id="rId'.$number.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$number.'.xml"/>';
        }
        $stylesId = $sheetCount + 1;
        $workbookRels .= '<Relationship Id="rId'.$stylesId.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $zip->addFromString('xl/workbook.xml', $workbook.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels.'</Relationships>');
        $zip->addFromString('xl/styles.xml', $this->stylesXml());

        $sourceStartRow = count($sheets[0]['rows']) + 3;
        foreach ($chartSources as $index => $source) {
            $chartSources[$index]['start_row'] = $sourceStartRow;
            $chartSources[$index]['start_column'] = 51 + $index * 5;
        }

        $tempFiles = [];
        foreach ($sheets as $index => $sheet) {
            $number = $index + 1;
            $xmlPath = tempnam(sys_get_temp_dir(), 'report-sheet-');
            if ($xmlPath === false) {
                throw new RuntimeException('Tidak dapat menyiapkan worksheet Excel.');
            }
            $tempFiles[] = $xmlPath;
            $this->writeSheet($xmlPath, $sheet, $number === 1 ? $chartSources : []);
            $zip->addFile($xmlPath, 'xl/worksheets/sheet'.$number.'.xml');
        }

        if ($chartCount > 0) {
            $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/></Relationships>');
            $zip->addFromString('xl/drawings/drawing1.xml', $this->drawingXml($chartSources));
            $drawingRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
            foreach ($chartSources as $index => $source) {
                $number = $index + 1;
                $drawingRels .= '<Relationship Id="rId'.$number.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart" Target="../charts/chart'.$number.'.xml"/>';
                $zip->addFromString('xl/charts/chart'.$number.'.xml', $this->chartXml($source, $index));
            }
            $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', $drawingRels.'</Relationships>');
        }

        $zip->close();
        foreach ($tempFiles as $tempFile) {
            @unlink($tempFile);
        }
    }

    private function writeSheet(string $path, array $sheet, array $chartSources): void
    {
        $handle = fopen($path, 'wb');
        fwrite($handle, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>');
        fwrite($handle, '<cols>');
        foreach (($sheet['widths'] ?? []) as $index => $width) {
            fwrite($handle, '<col min="'.($index + 1).'" max="'.($index + 1).'" width="'.$width.'" customWidth="1"/>');
        }
        if ($chartSources !== []) {
            fwrite($handle, '<col min="51" max="'.(50 + count($chartSources) * 5).'" hidden="1" width="12" customWidth="1"/>');
        }
        fwrite($handle, '</cols><sheetData>');
        $rowNumber = 0;
        foreach ($sheet['rows'] as $row) {
            $rowNumber++;
            $this->writeRow($handle, $rowNumber, array_values((array) $row), in_array($rowNumber, $sheet['header_rows'] ?? [1], true) ? 1 : 0, $sheet['date_columns'] ?? []);
        }
        if ($rowNumber === 1) {
            $this->writeRow($handle, 2, ['Tidak ada data pada periode dan filter yang dipilih.']);
            $rowNumber++;
        }

        if ($chartSources !== []) {
            $dataRows = max(array_map(fn ($source) => count($source['categories']), $chartSources));
            for ($dataIndex = -1; $dataIndex < $dataRows; $dataIndex++) {
                $excelRow = $chartSources[0]['start_row'] + $dataIndex + 1;
                fwrite($handle, '<row r="'.$excelRow.'">');
                foreach ($chartSources as $source) {
                    $baseColumn = $source['start_column'] - 1;
                    $this->writeCellAt($handle, $baseColumn, $excelRow, $dataIndex === -1 ? $source['label'] : ($source['categories'][$dataIndex] ?? ''));
                    foreach ($source['series'] as $seriesIndex => $series) {
                        $this->writeCellAt($handle, $baseColumn + $seriesIndex + 1, $excelRow, $dataIndex === -1 ? $series['label'] : ($series['values'][$dataIndex] ?? null), $dataIndex === -1 ? 1 : 0);
                    }
                }
                fwrite($handle, '</row>');
            }
        }
        fwrite($handle, '</sheetData>');
        if ($sheet['filter'] ?? false) {
            $lastColumn = $this->columnName(count($sheet['headers']));
            fwrite($handle, '<autoFilter ref="A1:'.$lastColumn.$rowNumber.'"/>');
        }
        if ($chartSources !== []) {
            fwrite($handle, '<drawing r:id="rId1"/>');
        }
        fwrite($handle, '</worksheet>');
        fclose($handle);
    }

    private function writeRow($handle, int $row, array $values, int $style = 0, array $dateColumns = []): void
    {
        fwrite($handle, '<row r="'.$row.'">');
        foreach ($values as $index => $value) {
            $this->writeCellAt($handle, $index, $row, $value, $style, $dateColumns[$index] ?? false);
        }
        fwrite($handle, '</row>');
    }

    private function writeCellAt($handle, int $column, int $row, mixed $value, int $style = 0, bool $date = false): void
    {
        $reference = $this->columnName($column + 1).$row;
        if ($date && $value !== null && $value !== '') {
            try {
                $localDateTime = Carbon::parse((string) $value, 'UTC')->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s');
                $timestamp = Carbon::parse($localDateTime, 'UTC')->timestamp;
                $value = ($timestamp / 86400) + 25569;
                fwrite($handle, '<c r="'.$reference.'" s="2"><v>'.sprintf('%.10F', $value).'</v></c>');

                return;
            } catch (\Throwable) {
                // Keep malformed legacy timestamps as text rather than aborting the export.
            }
        }
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value) && ! preg_match('/^0\d+$/', $value))) {
            fwrite($handle, '<c r="'.$reference.'"'.($style ? ' s="'.$style.'"' : '').'><v>'.$value.'</v></c>');

            return;
        }
        $text = htmlspecialchars((string) ($value ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        fwrite($handle, '<c r="'.$reference.'" t="inlineStr"'.($style ? ' s="'.$style.'"' : '').'><is><t xml:space="preserve">'.$text.'</t></is></c>');
    }

    private function columnName(int $index): string
    {
        $name = '';
        for ($n = $index; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + (($n - 1) % 26)).$name;
        }

        return $name;
    }

    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF176B4A"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="22" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    private function drawingXml(array $sources): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">';
        foreach ($sources as $index => $_source) {
            $left = 4 + (($index % 2) * 8);
            $top = intdiv($index, 2) * 15;
            $right = $left + 7;
            $bottom = $top + 13;
            $number = $index + 1;
            $xml .= '<xdr:twoCellAnchor><xdr:from><xdr:col>'.$left.'</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>'.$top.'</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from><xdr:to><xdr:col>'.$right.'</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>'.$bottom.'</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:to><xdr:graphicFrame macro=""><xdr:nvGraphicFramePr><xdr:cNvPr id="'.$number.'" name="Chart '.$number.'"/><xdr:cNvGraphicFramePr/></xdr:nvGraphicFramePr><xdr:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></xdr:xfrm><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart"><c:chart xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:id="rId'.$number.'"/></a:graphicData></a:graphic></xdr:graphicFrame><xdr:clientData/></xdr:twoCellAnchor>';
        }

        return $xml.'</xdr:wsDr>';
    }

    private function chartXml(array $source, int $index): string
    {
        $sourceColumn = $this->columnName($source['start_column']);
        $firstDataRow = $source['start_row'] + 1;
        $lastRow = $source['start_row'] + count($source['categories']);
        $axisOne = 100000 + ($index * 2);
        $axisTwo = $axisOne + 1;
        $type = $source['type'] === 'line' ? 'lineChart' : 'barChart';
        $seriesXml = '';
        foreach ($source['series'] as $seriesIndex => $series) {
            $valueColumn = $this->columnName($source['start_column'] + $seriesIndex + 1);
            $seriesXml .= '<c:ser><c:idx val="'.$seriesIndex.'"/><c:order val="'.$seriesIndex.'"/><c:tx><c:v>'.htmlspecialchars($series['label'], ENT_XML1 | ENT_QUOTES, 'UTF-8').'</c:v></c:tx>';
            if ($source['type'] === 'line') {
                $seriesXml .= '<c:marker><c:symbol val="circle"/></c:marker>';
            }
            $seriesXml .= '<c:cat><c:strRef><c:f>\'Ringkasan\'!$'.$sourceColumn.'$'.$firstDataRow.':$'.$sourceColumn.'$'.$lastRow.'</c:f></c:strRef></c:cat><c:val><c:numRef><c:f>\'Ringkasan\'!$'.$valueColumn.'$'.$firstDataRow.':$'.$valueColumn.'$'.$lastRow.'</c:f></c:numRef></c:val></c:ser>';
        }
        $shape = $source['type'] === 'line'
            ? '<c:grouping val="standard"/>'.$seriesXml.'<c:axId val="'.$axisOne.'"/><c:axId val="'.$axisTwo.'"/>'
            : '<c:barDir val="col"/><c:grouping val="clustered"/>'.$seriesXml.'<c:axId val="'.$axisOne.'"/><c:axId val="'.$axisTwo.'"/>';
        $axes = '<c:catAx><c:axId val="'.$axisOne.'"/><c:scaling><c:orientation val="minMax"/></c:scaling><c:axPos val="b"/><c:tickLblPos val="nextTo"/><c:crossAx val="'.$axisTwo.'"/><c:crosses val="autoZero"/><c:auto val="1"/><c:lblAlgn val="ctr"/><c:lblOffset val="100"/></c:catAx><c:valAx><c:axId val="'.$axisTwo.'"/><c:scaling><c:orientation val="minMax"/></c:scaling><c:axPos val="l"/><c:majorGridlines/><c:numFmt formatCode="0.0" sourceLinked="0"/><c:tickLblPos val="nextTo"/><c:crossAx val="'.$axisOne.'"/><c:crosses val="autoZero"/><c:crossBetween val="between"/></c:valAx>';
        $title = htmlspecialchars($source['title'], ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><c:chartSpace xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><c:lang val="id-ID"/><c:chart><c:title><c:tx><c:rich><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="1400" b="1"/></a:pPr><a:r><a:rPr lang="id-ID"/><a:t>'.$title.'</a:t></a:r><a:endParaRPr lang="id-ID"/></a:p></c:rich></c:tx><c:overlay val="0"/></c:title><c:plotArea><c:layout/><c:'.$type.'>'.$shape.'</c:'.$type.'>'.$axes.'</c:plotArea><c:legend><c:legendPos val="b"/><c:layout/></c:legend><c:plotVisOnly val="0"/><c:dispBlanksAs val="gap"/></c:chart></c:chartSpace>';
    }
}
