<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\EntityManagerProvider;
use App\dto\DocumentUploadDto;
use App\Entity\AcademicRecord;
use App\Exception\SystemException;
use FPDF;

class InEFPDF extends FPDF
{
    public function Footer(): void
    {
        $this->SetY(-15);
        $this->SetFont('Helvetica', 'I', 8);
        $this->SetTextColor(120, 120, 120);
        $this->Cell(0, 10, $this->PageNo() . '/{nb}', 0, 0, 'C');
    }

    public function addLetterhead(): void
    {
        $pathPng = __DIR__ . '/../../public/assets/letterhead.png';
        if (file_exists($pathPng)) {
            $this->Image($pathPng, 0, 0, 210);
        }
    }

    public function addSignature(): void
    {
        $pathPng = __DIR__ . '/../../public/assets/signature.png';
        $sigWidth = 90;
        $x = ($this->GetPageWidth() - $sigWidth) / 2;

        if ($this->GetY() > $this->GetPageHeight() - 45) {
            $this->AddPage();
            $this->addLetterhead();
            $this->SetY(48);
        }

        $this->SetAutoPageBreak(false);
        $y = $this->GetY();
        if (file_exists($pathPng)) {
            $this->Image($pathPng, $x, $y, $sigWidth, 0);
        }
        $this->SetAutoPageBreak(true, 25);
    }

    public function getNbLines(float $w, string $txt): int
    {
        $txt = str_replace("\r", '', $txt);
        $lines = 0;
        $maxWidth = $w - 2;

        foreach (explode("\n", $txt) as $paragraph) {
            $lines += $this->calculateParagraphLines($paragraph, $maxWidth);
        }

        return max(1, $lines);
    }

    private function calculateParagraphLines(string $paragraph, float $maxWidth): int
    {
        $words = explode(' ', $paragraph);
        $lines = 0;
        $currentLine = '';

        foreach ($words as $word) {
            $testLine = $currentLine === '' ? $word : $currentLine . ' ' . $word;

            if ($this->GetStringWidth($testLine) > $maxWidth) {
                if ($currentLine === '') {
                    $lines += max(1, (int)ceil($this->GetStringWidth($word) / $maxWidth));
                    $currentLine = '';
                } else {
                    $lines++;
                    $currentLine = $word;
                }
            } else {
                $currentLine = $testLine;
            }
        }

        if ($currentLine !== '') {
            $lines++;
        }

        return $lines;
    }
}

/**
 * Genera el PDF de expediente académico.
 *
 *  - generateRecord() se conserva para renderizar la copia en el visor.
 *  - generateAndArchive() es el flujo oficial disparado por “Generar PDF”:
 *    genera y archiva el documento en el Repositorio Documental.
 */
class PdfService
{
    private InEFPDF $pdf;
    private bool $qrEnabled;
    private array $columnSchema;
    private ?RepositorioDocumentalService $repositorioDocumentalService;

    public function __construct(?RepositorioDocumentalService $repositorioDocumentalService = null)
    {
        $this->qrEnabled = ConfigService::isQrEnabled();
        $this->columnSchema = ConfigService::getColumnSchema();
        $this->pdf = new InEFPDF('P', 'mm', 'A4');
        $this->pdf->AliasNbPages();
        $this->pdf->SetAutoPageBreak(true, 25);
        $this->repositorioDocumentalService = $repositorioDocumentalService;
    }

    public function generateRecord(string $cedula, array $options = []): void
    {
        $this->buildPdf($cedula, $options);
        $this->pdf->Output('I', 'record_academico_' . $cedula . '_' . date('Ymd') . '.pdf');
    }

    public function generateAndArchive(string $cedula, string $accessToken, string $ipOrigen, array $options = []): array
    {
        $this->buildPdf($cedula, $options);
        return $this->subirPdfGenerado($cedula, $accessToken, $ipOrigen, $options);
    }

    private function buildPdf(string $cedula, array $options): void
    {
        $records = $this->searchByCedula($cedula);
        $startYear = $options['start_year'] ?? null;
        $endYear   = $options['end_year']   ?? null;

        if ($startYear !== null && $endYear !== null) {
            $records = array_filter($records, static function ($r) use ($startYear, $endYear) {
                $rYear = (int)($r['anio'] ?? 0);
                return $rYear >= $startYear && $rYear <= $endYear;
            });
        }

        if (empty($records)) {
            $this->buildEmptyRecord($cedula);
            return;
        }

        $recordsByYear = [];
        foreach ($records as $record) {
            $year = $record['anio'] ?? date('Y');
            $recordsByYear[$year][] = $record;
        }
        krsort($recordsByYear);

        $firstRecord = reset($records);

        // Los registros nuevos guardan nombre y apellido por separado; los
        // históricos traen el nombre completo en [nombre] y [apellido] nulo.
        $nombreBase = trim((string)($firstRecord['nombre'] ?? ''));
        $apellido   = trim((string)($firstRecord['apellido'] ?? ''));
        if ($apellido !== '' && stripos($nombreBase, $apellido) === false) {
            $nombreBase = trim($nombreBase . ' ' . $apellido);
        }
        if ($nombreBase === '') {
            $nombreBase = 'Estudiante';
        }

        $studentInfo = [
            'nombre'  => $options['override_name']    ?? $nombreBase,
            'cedula'  => $cedula,
            'email'   => $options['override_email']   ?? ($firstRecord['email']   ?? 'No registrado'),
            'periodo' => $options['override_periodo'] ?? (($options['start_year'] ?? '') . ' - ' . ($options['end_year'] ?? '')),
            'extra1'  => $options['extra1'] ?? null,
            'extra2'  => $options['extra2'] ?? null,
        ];

        $this->pdf->AddPage();
        $this->pdf->addLetterhead(); // Llamada corregida
        $this->addHeader();
        $this->addStudentInfo($studentInfo);
        if ($this->qrEnabled) {
            $this->addQRCode($cedula);
        }
        $this->addAcademicRecords($recordsByYear);
        $this->addCertificationText();
        $this->pdf->addSignature(); // Llamada corregida
    }

    private function searchByCedula(string $cedula): array
    {
        $em = EntityManagerProvider::get();
        $qb = $em->createQueryBuilder()
            ->select('r')->from(AcademicRecord::class, 'r')
            ->where('r.cedula = :cedula')->setParameter('cedula', $cedula)
            ->andWhere("r.estado != 'X'")
            ->orderBy('r.anio', 'DESC')
            ->addOrderBy('r.id', 'DESC');
        return array_map(static fn(AcademicRecord $r) => $r->toArray(), $qb->getQuery()->getResult());
    }

    private function buildEmptyRecord(string $cedula): void
    {
        $this->pdf->AddPage();
        $this->pdf->addLetterhead(); // Llamada corregida
        $this->pdf->SetY(48);
        $this->pdf->SetFont('Helvetica', 'B', 16);
        $this->pdf->Cell(0, 20, mb_convert_encoding('Registro Académico', 'ISO-8859-1', 'UTF-8'), 0, 1, 'C');
        $this->pdf->Ln(10);
        $this->pdf->SetFont('Helvetica', '', 12);
        $this->pdf->Cell(0, 10, mb_convert_encoding('Cédula: ', 'ISO-8859-1', 'UTF-8') . $cedula, 0, 1, 'C');
        $this->pdf->Ln(10);
        $this->pdf->SetFont('Helvetica', 'I', 12);
        $this->pdf->MultiCell(0, 8, 'No se encontraron registros en el rango especificado.', 0, 'C');
        $this->pdf->addSignature(); // Llamada corregida
    }

    private function addHeader(): void
    {
        $this->pdf->SetY(48);
        $this->pdf->SetFont('Helvetica', 'B', 18);
        $this->pdf->SetTextColor(0, 51, 102);
        $this->pdf->Cell(0, 12, mb_convert_encoding('EXPEDIENTE ACADÉMICO', 'ISO-8859-1', 'UTF-8'), 0, 1, 'C');
        $this->pdf->Ln(3);
        $this->pdf->SetFont('Helvetica', '', 11);
        $this->pdf->SetTextColor(80, 80, 80);
        $meses = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
        $fecha = 'Quito, ' . date('d') . ' de ' . $meses[(int)date('n') - 1] . ' de ' . date('Y');
        $this->pdf->Cell(0, 7, mb_convert_encoding($fecha, 'ISO-8859-1', 'UTF-8'), 0, 1, 'R');
        $this->pdf->Ln(8);
    }

    private function addStudentInfo(array $info): void
    {
        $this->pdf->SetFont('Helvetica', '', 10);
        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->SetFillColor(240, 245, 250);
        $this->pdf->SetDrawColor(0, 51, 102);
        $x = 10;
        $y = $this->pdf->GetY();
        $w = $this->pdf->GetPageWidth() - 20;
        $boxHeight = 30;
        if (!empty($info['extra1'])) { $boxHeight += 6;}
        if (!empty($info['extra2'])) { $boxHeight += 6;}

        $this->pdf->Rect($x, $y, $w, $boxHeight, 'DF');
        $this->pdf->SetXY($x + 5, $y + 3);
        $this->pdf->SetFont('Helvetica', 'B', 10);
        $this->pdf->Cell(30, 6, 'Participante:', 0, 0);
        $this->pdf->SetFont('Helvetica', '', 10);
        $this->pdf->Cell(0, 6, mb_convert_encoding((string)$info['nombre'], 'ISO-8859-1', 'UTF-8'), 0, 1);

        $this->pdf->SetX($x + 5);
        $this->pdf->SetFont('Helvetica', 'B', 10);
        $this->pdf->Cell(30, 6, 'Cedula:', 0, 0);
        $this->pdf->SetFont('Helvetica', '', 10);
        $this->pdf->Cell(0, 6, (string)$info['cedula'], 0, 1);

        $this->pdf->SetX($x + 5);
        $this->pdf->SetFont('Helvetica', 'B', 10);
        $this->pdf->Cell(30, 6, 'Correo:', 0, 0);
        $this->pdf->SetFont('Helvetica', '', 10);
        $this->pdf->Cell(0, 6, mb_convert_encoding((string)$info['email'], 'ISO-8859-1', 'UTF-8'), 0, 1);

        $this->pdf->SetX($x + 5);
        $this->pdf->SetFont('Helvetica', 'B', 10);
        $this->pdf->Cell(30, 6, 'Periodo:', 0, 0);
        $this->pdf->SetFont('Helvetica', '', 10);
        $this->pdf->Cell(0, 6, mb_convert_encoding((string)$info['periodo'], 'ISO-8859-1', 'UTF-8'), 0, 1);

        if (!empty($info['extra1'])) {
            $this->pdf->SetX($x + 5);
            $this->pdf->SetFont('Helvetica', 'I', 10);
            $this->pdf->Cell(0, 6, mb_convert_encoding((string)$info['extra1'], 'ISO-8859-1', 'UTF-8'), 0, 1);
        }
        if (!empty($info['extra2'])) {
            $this->pdf->SetX($x + 5);
            $this->pdf->SetFont('Helvetica', 'I', 10);
            $this->pdf->Cell(0, 6, mb_convert_encoding((string)$info['extra2'], 'ISO-8859-1', 'UTF-8'), 0, 1);
        }
        $this->pdf->Ln(10);
    }

    private function addQRCode(string $cedula): void
    {
        $verifyUrl = $this->getVerificationUrl($cedula);

        $cacheDir  = dirname(__DIR__, 2) . '/var/cache/qr';
        $cacheFile = $cacheDir . '/qr_' . preg_replace('/[^0-9A-Za-z]/', '', $cedula) . '.png';
        $ttl       = 86400 * 30; // 30 días

        if (!file_exists($cacheFile) || (time() - filemtime($cacheFile)) > $ttl) {
            if (!is_dir($cacheDir)) {
                mkdir($cacheDir, 0775, true);
            }
            $url     = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($verifyUrl);
            $context = stream_context_create(['http' => ['timeout' => 8]]);

            $imageData = false;
            for ($attempt = 1; $attempt <= 3 && $imageData === false; $attempt++) {
                $imageData = @file_get_contents($url, false, $context);
            }

            if ($imageData !== false) {
                file_put_contents($cacheFile, $imageData);
            }
        }

        if (file_exists($cacheFile)) {
            $currentY = $this->pdf->GetY();
            $qrSize   = 28;
            $x        = $this->pdf->GetPageWidth() - $qrSize - 15;
            $this->pdf->Image($cacheFile, $x, 80, $qrSize, $qrSize);
            $this->pdf->SetY($currentY);
        }
    }

    private function getVerificationUrl(string $cedula): string
    {
        $baseUrl = rtrim(ConfigService::getQrBaseUrl(), '/');
        $separator = strpos($baseUrl, '?') !== false ? '&' : '?';
        return $baseUrl . $separator . 'cedula=' . urlencode($cedula);
    }

    private function addAcademicRecords(array $recordsByYear): void
    {
        $visibleSchema = array_filter($this->columnSchema, static fn($c) => !empty($c['visible']));
        $totalSchemaWidth = array_sum(array_column($visibleSchema, 'width'));
        $maxTableWidth = $this->pdf->GetPageWidth() - 20;
        $scaleFactor = ($totalSchemaWidth > 0) ? ($maxTableWidth / $totalSchemaWidth) : 1;

        foreach ($recordsByYear as $year => $yearRecords) {
            $this->checkPageBreakForYear();
            $this->printYearHeader((string) $year, $maxTableWidth);
            $this->printTableHeader($visibleSchema, $scaleFactor);

            $fill = false; // Alternar color de filas

            foreach ($yearRecords as $record) {
                $fill = $this->printRecordRow($record, $visibleSchema, $scaleFactor, $fill);
            }
            $this->pdf->Ln(6);
        }
    }

    private function checkPageBreakForYear(): void
    {
        if ($this->pdf->GetY() > 220) {
            $this->pdf->AddPage();
            $this->pdf->addLetterhead(); // Llamada corregida
        }
    }

    private function printYearHeader(string $year, float $maxWidth): void
    {
        $this->pdf->SetX(10);
        $this->pdf->SetFont('Helvetica', 'B', 11);
        $this->pdf->SetFillColor(0, 51, 102);
        $this->pdf->SetTextColor(255, 255, 255);
        $this->pdf->Cell($maxWidth, 8, '  Periodo Academico: ' . $year, 0, 1, 'L', true);
        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->Ln(1);
    }

    private function printTableHeader(array $visibleSchema, float $scaleFactor): void
    {
        $this->pdf->SetX(10);
        $this->pdf->SetFont('Helvetica', 'B', 8);
        $this->pdf->SetFillColor(230, 238, 248);

        foreach ($visibleSchema as $col) {
            $label = mb_convert_encoding((string)$col['label'], 'ISO-8859-1', 'UTF-8');
            $this->pdf->Cell($col['width'] * $scaleFactor, 7, $label, 1, 0, 'C', true);
        }
        $this->pdf->Ln();
    }

    private function printRecordRow(array $record, array $visibleSchema, float $scaleFactor, bool $fill): bool
    {
        $this->pdf->SetFont('Helvetica', '', 8);

        $rowTexts = [];
        $maxLines = 1;

        foreach ($visibleSchema as $col) {
            $scaledWidth = $col['width'] * $scaleFactor;
            $key = $col['key'] ?? $col['field'] ?? '';

            $val = $this->formatCellValue($key, $record[$key] ?? '');

            // Llamada corregida
            $linesCount = $this->pdf->getNbLines($scaledWidth, $val);
            if ($linesCount > $maxLines) {
                $maxLines = $linesCount;
            }

            $rowTexts[] = ['w' => $scaledWidth, 'txt' => $val, 'lines' => $linesCount];
        }

        $lineHeight = 5;
        $rowHeight = $maxLines * $lineHeight;

        if ($this->pdf->GetY() + $rowHeight > 270) {
            $this->pdf->AddPage();
            $this->pdf->addLetterhead(); // Llamada corregida
            $this->printTableHeader($visibleSchema, $scaleFactor);
            $this->pdf->SetFont('Helvetica', '', 8);
            $fill = false;
        }

        $this->pdf->SetX(10);
        $this->pdf->SetFillColor($fill ? 248 : 255, $fill ? 250 : 255, $fill ? 252 : 255);
        $x = $this->pdf->GetX();
        $y = $this->pdf->GetY();

        foreach ($rowTexts as $colData) {
            $w = $colData['w'];
            $txt = $colData['txt'];

            $this->pdf->Rect($x, $y, $w, $rowHeight, 'DF');
            $offsetY = ($rowHeight - ($colData['lines'] * $lineHeight)) / 2;
            $this->pdf->SetXY($x, $y + $offsetY);
            $this->pdf->MultiCell($w, $lineHeight, $txt, 0, 'C');

            $x += $w;
        }

        $this->pdf->SetXY(10, $y + $rowHeight);
        return !$fill;
    }

    private function formatCellValue(string $key, mixed $val): string
    {
        if (in_array($key, ['total'], true) && $val !== '' && $val !== null) {
            $val = number_format((float)$val, 2);
        } elseif ($key === 'created_at' && $val) {
            $val = date('d/m/Y', strtotime((string)$val));
        } elseif ($val === '' || $val === null) {
            $val = 'N/A';
        }

        return mb_convert_encoding((string)$val, 'ISO-8859-1', 'UTF-8');
    }

    private function addCertificationText(): void
    {
        $this->pdf->Ln(5);
        $this->pdf->SetX(10);
        $this->pdf->SetFont('Helvetica', '', 10);
        $this->pdf->SetTextColor(0, 0, 0);
        $texto = 'De conformidad a la información proporcionada por la Subdirección Administrativa y Estratégica, '
            . 'a través de la Jefatura de Informática de la Escuela de la Función Judicial, '
            . 'es todo cuanto puedo certificar.';
        $w = $this->pdf->GetPageWidth() - 20;
        $this->pdf->MultiCell($w, 5, iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $texto), 0, 'J');
        $this->pdf->Ln(15);
    }

    private function subirPdfGenerado(string $cedula, string $accessToken, string $ipOrigen, array $options = []): array
    {
        $nombreArchivo = sprintf(
            'record_academico_%s_%s.pdf',
            preg_replace('/[^0-9A-Za-z_-]/', '', $cedula),
            date('Ymd')
        );

        $contenidoPdf = $this->pdf->Output('S');

        if (!is_string($contenidoPdf) || $contenidoPdf === '') {
            throw new SystemException('FPDF no pudo generar el contenido del documento.');
        }

        $repositorio = $this->repositorioDocumentalService ?? new RepositorioDocumentalService();

        $dto = new DocumentUploadDto(
            contenidoPdf: $contenidoPdf,
            nombreArchivo: $nombreArchivo,
            accessToken: $accessToken,
            ipOrigen: $ipOrigen,
            tipo: $options['tipo'] ?? 'Nuevo',
            sistema: $options['sistema'] ?? 'SISTEMA RECORD ACADEMICO',
            modulo: $options['modulo'] ?? 'Record Academico',
            requiereFirmado: $options['requiere_firmado'] ?? 'N',
            requiereIndex: $options['requiere_index'] ?? 'N'
        );

        $response = $repositorio->subirPdf($dto);
        $response['_nombreArchivo'] = $nombreArchivo;

        return $response;
    }
}