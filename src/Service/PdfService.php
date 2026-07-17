<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\EntityManagerProvider;
use App\Entity\AcademicRecord;
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
}

class PdfService
{
    private InEFPDF $pdf;
    private bool $qrEnabled;
    private array $columnSchema;

    private RepositorioDocumentalService $repositorioDocumentalService;


    public function __construct(
      ?RepositorioDocumentalService $repositorioDocumentalService = null
    )
    {
        $this->qrEnabled = ConfigService::isQrEnabled();
        $this->columnSchema = ConfigService::getColumnSchema();
        $this->pdf = new InEFPDF('P', 'mm', 'A4');
        $this->pdf->AliasNbPages();
        $this->pdf->SetAutoPageBreak(true, 25);

        $this->repositorioDocumentalService =
            $repositorioDocumentalService
            ?? new RepositorioDocumentalService();
    }

    public function generateRecord(
      string $cedula,
      string $accessToken,
      string $ipOrigen,
      array $options = []
    ): array
    {
        $records = $this->searchByCedula($cedula);
        $startYear = $options['start_year'] ?? null;
        $endYear   = $options['end_year']   ?? null;

        if ($startYear !== null && $endYear !== null) {
            $records = array_filter($records, static function ($r) use ($startYear, $endYear) {
                $rYear = (int)($r['origen_tabla'] ?? 0);
                return $rYear >= $startYear && $rYear <= $endYear;
            });
        }

        if (empty($records)) {
          return $this->generateEmptyRecord(
              cedula: $cedula,
              accessToken: $accessToken,
              ipOrigen: $ipOrigen,
              options: $options
          );
      }

        $recordsByYear = [];
        foreach ($records as $record) {
            $year = $record['origen_tabla'] ?? date('Y');
            $recordsByYear[$year][] = $record;
        }
        krsort($recordsByYear);

        $firstRecord = reset($records);
        $studentInfo = [
            'nombre'  => $options['override_name']    ?? ($firstRecord['nombre']  ?? 'Estudiante'),
            'cedula'  => $cedula,
            'email'   => $options['override_email']   ?? ($firstRecord['email']   ?? 'No registrado'),
            'periodo' => $options['override_periodo'] ?? (($options['start_year'] ?? '') . ' - ' . ($options['end_year'] ?? '')),
            'extra1'  => $options['extra1'] ?? null,
            'extra2'  => $options['extra2'] ?? null,
        ];

        $this->pdf->AddPage();
        $this->addLetterhead();
        $this->addHeader();
        $this->addStudentInfo($studentInfo);
        if ($this->qrEnabled) {
            $this->addQRCode($cedula);
        }
        $this->addAcademicRecords($recordsByYear);
        $this->addCertificationText();
        $this->addSignature();
        return $this->subirPdfGenerado(
          cedula: $cedula,
          accessToken: $accessToken,
          ipOrigen: $ipOrigen,
          options: $options
      );
    }

    private function searchByCedula(string $cedula): array
    {
        $em = EntityManagerProvider::get();
        $qb = $em->createQueryBuilder()
            ->select('r')->from(AcademicRecord::class, 'r')
            ->where('r.cedula = :cedula')->setParameter('cedula', $cedula)
            ->orderBy('r.origen_tabla', 'DESC')
            ->addOrderBy('r.id', 'DESC');
        return array_map(static fn(AcademicRecord $r) => $r->toArray(), $qb->getQuery()->getResult());
    }

    private function generateEmptyRecord(
      string $cedula,
      string $accessToken,
      string $ipOrigen,
      array $options = []
    ): array
    {
        $this->pdf->AddPage();
        $this->addLetterhead();
        $this->pdf->SetY(48);
        $this->pdf->SetFont('Helvetica', 'B', 16);
        $this->pdf->Cell(0, 20, mb_convert_encoding('Registro Académico', 'ISO-8859-1', 'UTF-8'), 0, 1, 'C');
        $this->pdf->Ln(10);
        $this->pdf->SetFont('Helvetica', '', 12);
        $this->pdf->Cell(0, 10, mb_convert_encoding('Cédula: ', 'ISO-8859-1', 'UTF-8') . $cedula, 0, 1, 'C');
        $this->pdf->Ln(10);
        $this->pdf->SetFont('Helvetica', 'I', 12);
        $this->pdf->MultiCell(0, 8, 'No se encontraron registros en el rango especificado.', 0, 'C');
        $this->addSignature();
        return $this->subirPdfGenerado(
          cedula: $cedula,
          accessToken: $accessToken,
          ipOrigen: $ipOrigen,
          options: $options
      );
    }

    private function addLetterhead(): void
    {
        $pathPng = __DIR__ . '/../../public/assets/letterhead.png';
        if (file_exists($pathPng)) {
            $this->pdf->Image($pathPng, 0, 0, 210);
        }
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
        if (!empty($info['extra1'])) $boxHeight += 6;
        if (!empty($info['extra2'])) $boxHeight += 6;

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
        $qrPath = sys_get_temp_dir() . '/qr_' . $cedula . '.png';
        $url = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($verifyUrl);
        $context = stream_context_create(['http' => ['timeout' => 5]]);
        $imageData = @file_get_contents($url, false, $context);
        if ($imageData !== false) {
            file_put_contents($qrPath, $imageData);
        }
        if (file_exists($qrPath)) {
            $currentY = $this->pdf->GetY();
            $qrSize = 28;
            $x = $this->pdf->GetPageWidth() - $qrSize - 15;
            $this->pdf->Image($qrPath, $x, 80, $qrSize, $qrSize);
            unlink($qrPath);
            $this->pdf->SetY($currentY);
        }
    }

    private function getVerificationUrl(string $cedula): string
    {
        $baseUrl = rtrim(ConfigService::getQrBaseUrl(), '/');
        $separator = strpos($baseUrl, '?') !== false ? '&' : '?';
        return $baseUrl . $separator . 'cedula=' . urlencode($cedula);
    }

    private function nbLines(float $w, string $txt): int
    {
        $txt = str_replace("\r", '', $txt);
        $lines = 0;
        foreach (explode("\n", $txt) as $paragraph) {
            $words = explode(' ', $paragraph);
            $currentLine = '';
            foreach ($words as $word) {
                $testLine = $currentLine === '' ? $word : $currentLine . ' ' . $word;
                if ($this->pdf->GetStringWidth($testLine) > ($w - 2)) {
                    if ($currentLine === '') {
                        $lines += max(1, (int)ceil($this->pdf->GetStringWidth($word) / ($w - 2)));
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
        }
        return max(1, $lines);
    }

    private function addAcademicRecords(array $recordsByYear): void
    {
        $visibleSchema = array_filter($this->columnSchema, static fn($c) => !empty($c['visible']));
        $totalSchemaWidth = array_sum(array_column($visibleSchema, 'width'));
        $maxTableWidth = $this->pdf->GetPageWidth() - 20;
        $scaleFactor = ($totalSchemaWidth > 0) ? ($maxTableWidth / $totalSchemaWidth) : 1;

        foreach ($recordsByYear as $year => $yearRecords) {
            if ($this->pdf->GetY() > 220) {
                $this->pdf->AddPage();
                $this->addLetterhead();
            }
            $this->pdf->SetX(10);
            $this->pdf->SetFont('Helvetica', 'B', 11);
            $this->pdf->SetFillColor(0, 51, 102);
            $this->pdf->SetTextColor(255, 255, 255);
            $this->pdf->Cell($maxTableWidth, 8, '  Periodo Academico: ' . $year, 0, 1, 'L', true);
            $this->pdf->SetTextColor(0, 0, 0);
            $this->pdf->Ln(1);

            $this->pdf->SetX(10);
            $this->pdf->SetFont('Helvetica', 'B', 8);
            $this->pdf->SetFillColor(230, 238, 248);
            foreach ($visibleSchema as $col) {
                $this->pdf->Cell($col['width'] * $scaleFactor, 7, mb_convert_encoding((string)$col['label'], 'ISO-8859-1', 'UTF-8'), 1, 0, 'C', true);
            }
            $this->pdf->Ln();

            $this->pdf->SetFont('Helvetica', '', 8);
            $fill = false;

            foreach ($yearRecords as $record) {
                $rowTexts = [];
                $maxLines = 1;
                foreach ($visibleSchema as $col) {
                    $scaledWidth = $col['width'] * $scaleFactor;
                    $key = $col['key'] ?? $col['field'] ?? '';
                    $val = $record[$key] ?? '';
                    if (in_array($key, ['nota', 'total'], true) && $val !== '' && $val !== null) {
                        $val = number_format((float)$val, 2);
                    } elseif ($key === 'created_at' && $val) {
                        $val = date('d/m/Y', strtotime((string)$val));
                    } elseif ($val === '' || $val === null) {
                        $val = 'N/A';
                    }
                    $val = mb_convert_encoding((string)$val, 'ISO-8859-1', 'UTF-8');
                    $linesCount = $this->nbLines($scaledWidth, $val);
                    if ($linesCount > $maxLines) {
                        $maxLines = $linesCount;
                    }
                    $rowTexts[] = ['w' => $scaledWidth, 'txt' => $val, 'lines' => $linesCount];
                }
                $lineHeight = 5;
                $rowHeight = $maxLines * $lineHeight;

                if ($this->pdf->GetY() + $rowHeight > 270) {
                    $this->pdf->AddPage();
                    $this->addLetterhead();
                    $this->pdf->SetX(10);
                    $this->pdf->SetFont('Helvetica', 'B', 8);
                    $this->pdf->SetFillColor(230, 238, 248);
                    foreach ($visibleSchema as $col) {
                        $this->pdf->Cell($col['width'] * $scaleFactor, 7, mb_convert_encoding((string)$col['label'], 'ISO-8859-1', 'UTF-8'), 1, 0, 'C', true);
                    }
                    $this->pdf->Ln();
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
                $fill = !$fill;
            }
            $this->pdf->Ln(6);
        }
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
        $this->pdf->MultiCell($w, 5, mb_convert_encoding($texto, 'ISO-8859-1', 'UTF-8'), 0, 'J');
        $this->pdf->Ln(15);
    }

    private function addSignature(): void
    {
        $pathPng = __DIR__ . '/../../public/assets/signature.png';
        $sigWidth = 90;
        $x = ($this->pdf->GetPageWidth() - $sigWidth) / 2;
        if ($this->pdf->GetY() > $this->pdf->GetPageHeight() - 45) {
            $this->pdf->AddPage();
            $this->addLetterhead();
            $this->pdf->SetY(48);
        }
        $this->pdf->SetAutoPageBreak(false);
        $y = $this->pdf->GetY();
        if (file_exists($pathPng)) {
            $this->pdf->Image($pathPng, $x, $y, $sigWidth, 0);
        }
        $this->pdf->SetAutoPageBreak(true, 25);
    }


    /**
     * Convierte el documento FPDF en un string binario y lo envía
     * al RepositorioDocumentalService.
     *
     * @return array<string, mixed>
     */
    private function subirPdfGenerado(
        string $cedula,
        string $accessToken,
        string $ipOrigen,
        array $options = []
    ): array {
        $nombreArchivo = sprintf(
            'record_academico_%s_%s.pdf',
            preg_replace('/[^0-9A-Za-z_-]/', '', $cedula),
            date('Ymd_His')
        );

        /*
        * S significa "String".
        * FPDF devuelve el contenido binario del PDF sin enviarlo
        * al navegador y sin guardarlo físicamente.
        */
        $contenidoPdf = $this->pdf->Output('S');

        if (!is_string($contenidoPdf) || $contenidoPdf === '') {
            throw new \RuntimeException(
                'FPDF no pudo generar el contenido del documento.'
            );
        }

        return $this->repositorioDocumentalService->subirPdf(
            contenidoPdf: $contenidoPdf,
            nombreArchivo: $nombreArchivo,
            accessToken: $accessToken,
            ipOrigen: $ipOrigen,
            sistema: $options['sistema'] ?? 'SistemaRecordAcademico',
            modulo: $options['modulo'] ?? 'ExpedienteAcademico',
            requiereFirmado: $options['requiere_firmado'] ?? true,
            requiereIndex: $options['requiere_index'] ?? true
        );
    }
}