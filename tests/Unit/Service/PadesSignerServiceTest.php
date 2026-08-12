<?php
declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\PadesSignerService;
use PHPUnit\Framework\TestCase;

/**
 * Firma un PDF mínimo generado con FPDF y verifica que la revisión
 * incremental sea coherente: /ByteRange bien formado, /Contents con DER
 * válido de un CMS SignedData y offsets consistentes con el tamaño real.
 *
 * No hace verificación criptográfica completa (cadena, revocación): la
 * firmeza depende de que openssl_cms_sign produzca un CMS válido, cosa
 * que ya está garantizada por la propia librería OpenSSL.
 */
final class PadesSignerServiceTest extends TestCase
{
    private string $p12Path = '';
    private string $p12Pass = 'test-pass-123';

    protected function setUp(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('openssl_cms_sign no disponible en este build de PHP.');
        }

        $opts = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $cnf = $this->findOpensslConf();
        if ($cnf !== null) {
            $opts['config'] = $cnf;
        }

        // Generar cert auto-firmado en memoria y exportar a .p12 temporal.
        $key = @openssl_pkey_new($opts);
        if ($key === false) {
            self::markTestSkipped('openssl_pkey_new falló (falta openssl.cnf en el sistema). Detalle: ' . (openssl_error_string() ?: 'sin detalle'));
        }

        $csrOpts = $cnf !== null ? ['config' => $cnf] : [];
        $csr = openssl_csr_new(['commonName' => 'PadesSignerTest', 'countryName' => 'EC'], $key, $csrOpts);
        self::assertNotFalse($csr, 'No se pudo generar CSR.');

        $cert = openssl_csr_sign($csr, null, $key, 1, $csrOpts);
        self::assertNotFalse($cert, 'No se pudo firmar CSR.');

        $tmp = tempnam(sys_get_temp_dir(), 'pst_p12_');
        self::assertNotFalse($tmp);
        $this->p12Path = $tmp;

        $ok = openssl_pkcs12_export_to_file($cert, $this->p12Path, $key, $this->p12Pass);
        self::assertTrue($ok, 'No se pudo exportar .p12: ' . (openssl_error_string() ?: ''));
    }

    protected function tearDown(): void
    {
        if ($this->p12Path !== '' && file_exists($this->p12Path)) {
            @unlink($this->p12Path);
        }
    }

    public function testSignProducesStructurallyValidSignedPdf(): void
    {
        $pdf = new \FPDF('P', 'mm', 'A4');
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->Cell(0, 10, 'Test document for PAdES signing', 0, 1, 'C');
        $bytes = $pdf->Output('S');
        self::assertIsString($bytes);
        self::assertNotSame('', $bytes);

        $signer = new PadesSignerService($this->p12Path, $this->p12Pass, 'Test Signer', 'Unit test', 'Localhost');
        $signed = $signer->sign($bytes);

        // Estructura PDF preservada.
        self::assertStringStartsWith('%PDF-', $signed);
        self::assertStringEndsWith("%%EOF\n", $signed);

        // Diccionario de firma presente.
        self::assertStringContainsString('/Type /Sig', $signed);
        self::assertStringContainsString('/SubFilter /adbe.pkcs7.detached', $signed);
        self::assertStringContainsString('/AcroForm', $signed);
        self::assertStringContainsString('/SigFlags 3', $signed);

        // /ByteRange bien formado (4 enteros).
        self::assertSame(
            1,
            preg_match('/\/ByteRange \[(\d+) (\d+) (\d+) (\d+)\]/', $signed, $br),
            '/ByteRange no encontrado o mal formado.'
        );
        [$s1, $l1, $s2, $l2] = [(int)$br[1], (int)$br[2], (int)$br[3], (int)$br[4]];

        // ByteRange debe cubrir todo el archivo excepto <...> de /Contents.
        self::assertSame(0, $s1, 'Primer offset debe ser 0.');
        self::assertGreaterThan(0, $l1);
        self::assertSame($s1 + $l1 + 1 /* '<' */ + PadesSignerServiceTest::hexLen() + 1 /* '>' */, $s2);
        self::assertSame($s2 + $l2, strlen($signed));

        // /Contents es hex y ya no es todo ceros (firma real embebida).
        self::assertSame(
            1,
            preg_match('/\/Contents <([0-9a-fA-F]+)>/', $signed, $cm),
            '/Contents no encontrado.'
        );
        $hex = $cm[1];
        self::assertSame(self::hexLen(), strlen($hex));
        self::assertNotSame(str_repeat('0', self::hexLen()), $hex, '/Contents sigue siendo el placeholder vacío.');

        // Los bytes del inicio del /Contents deben ser DER: SEQUENCE (0x30) con longitud válida.
        $rawStart = hex2bin(substr($hex, 0, 20));
        self::assertIsString($rawStart);
        self::assertSame(0x30, ord($rawStart[0]), 'DER no comienza con SEQUENCE (0x30).');

        // El DER debe contener el OID de CMS SignedData: 1.2.840.113549.1.7.2 (encoded as 06 09 2a 86 48 86 f7 0d 01 07 02).
        $signedDataOid = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x07\x02";
        $rawFull = hex2bin(rtrim($hex, '0'));
        self::assertIsString($rawFull);
        self::assertNotFalse(
            strpos($rawFull, $signedDataOid),
            'OID de CMS SignedData no encontrado en /Contents.'
        );

        // Verificación criptográfica real: extraer DER exacto y verificar con OpenSSL.
        $der = self::extractDerFromHex($hex);
        $tmpData = tempnam(sys_get_temp_dir(), 'v_data_');
        $tmpSig  = tempnam(sys_get_temp_dir(), 'v_sig_');
        self::assertNotFalse($tmpData);
        self::assertNotFalse($tmpSig);
        file_put_contents($tmpData, substr($signed, $s1, $l1) . substr($signed, $s2, $l2));
        file_put_contents($tmpSig, $der);

        try {
            // API PHP: para verificar CMS detached, input_filename = contenido, sigfile = CMS.
            $ok = @openssl_cms_verify(
                input_filename: $tmpData,
                flags: OPENSSL_CMS_BINARY | OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_DETACHED,
                sigfile: $tmpSig,
                encoding: OPENSSL_ENCODING_DER
            );
            self::assertTrue($ok, 'openssl_cms_verify rechazó la firma: ' . (openssl_error_string() ?: ''));
        } finally {
            @unlink($tmpData);
            @unlink($tmpSig);
        }
    }

    /**
     * Extrae el blob DER real desde el hex de /Contents descartando el
     * padding de ceros a la derecha, usando la longitud declarada en la
     * cabecera ASN.1 (SEQUENCE).
     */
    private static function extractDerFromHex(string $hex): string
    {
        $raw = hex2bin($hex);
        self::assertIsString($raw);
        self::assertGreaterThanOrEqual(2, strlen($raw));
        self::assertSame(0x30, ord($raw[0]));

        $lenByte = ord($raw[1]);
        if ($lenByte < 0x80) {
            $total = 2 + $lenByte;
        } else {
            $numLenBytes = $lenByte & 0x7F;
            $len = 0;
            for ($i = 0; $i < $numLenBytes; $i++) {
                $len = ($len << 8) | ord($raw[2 + $i]);
            }
            $total = 2 + $numLenBytes + $len;
        }
        return substr($raw, 0, $total);
    }

    public function testSignFailsWithWrongPassword(): void
    {
        $pdf = new \FPDF('P', 'mm', 'A4');
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->Cell(0, 10, 'x', 0, 1);
        $bytes = $pdf->Output('S');

        $signer = new PadesSignerService($this->p12Path, 'password-incorrecta');
        $this->expectException(\RuntimeException::class);
        $signer->sign($bytes);
    }

    public function testSignRejectsNonPdfInput(): void
    {
        $signer = new PadesSignerService($this->p12Path, $this->p12Pass);
        $this->expectException(\RuntimeException::class);
        $signer->sign('esto no es un pdf');
    }

    private static function hexLen(): int
    {
        return 16384; // debe coincidir con PadesSignerService::CONTENTS_HEX_LEN
    }

    /**
     * Localiza openssl.cnf: variable de entorno OPENSSL_CONF o rutas
     * comunes en Windows (dev). En Linux/Docker la extensión OpenSSL de
     * PHP suele encontrarlo sin ayuda.
     */
    private function findOpensslConf(): ?string
    {
        $env = getenv('OPENSSL_CONF');
        if (is_string($env) && $env !== '' && file_exists($env)) {
            return $env;
        }
        foreach ([
            'C:/xampp/apache/conf/openssl.cnf',
            'C:/Program Files/Git/usr/ssl/openssl.cnf',
            'C:/Program Files/Git/mingw64/ssl/openssl.cnf',
        ] as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }
        return null;
    }
}