<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

$pdf = new FPDF();
$pdf->AddPage();
$pdf->SetFont('Helvetica', 'B', 12);
$pdf->Cell(0, 10, '--- PRUEBA DE IMAGENES ---', 0, 1, 'C');
$pdf->Ln(10);

$sigPath = __DIR__ . '/assets/signature.png';
if (file_exists($sigPath)) {
    $size = filesize($sigPath);
    $pdf->Cell(0, 10, "Firma encontrada. Peso: $size bytes", 0, 1);
    if ($size > 0) {
        try {
            $pdf->Image($sigPath, 10, 70, 50, 0, 'PNG');
            $pdf->SetXY(10, 120);
            $pdf->SetTextColor(0, 128, 0);
            $pdf->Cell(0, 10, "EXITO", 0, 1);
        } catch (Exception $e) {
            $pdf->SetTextColor(255, 0, 0);
            $pdf->Cell(0, 10, "ERROR: " . $e->getMessage(), 0, 1);
        }
    }
} else {
    $pdf->SetTextColor(255, 0, 0);
    $pdf->Cell(0, 10, "ERROR: Imagen no encontrada", 0, 1);
}
$pdf->Output('I', 'prueba.pdf');