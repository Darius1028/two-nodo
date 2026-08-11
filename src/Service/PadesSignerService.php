<?php
declare(strict_types=1);

namespace App\Service;

use App\Exception\InvalidConfigurationException;
use App\Exception\SystemException;
use App\Exception\ValidationException;

/**
 * Firma un PDF ya generado incrustando una firma PKCS#7/CMS detached
 * mediante una revisión incremental (subFilter /adbe.pkcs7.detached).
 *
 * Notas de alcance:
 *  - Produce una firma que Adobe Reader valida como "Firma PDF" con el
 *    icono de check si el certificado es de confianza. Es la variante
 *    histórica de Adobe, universalmente soportada por lectores PDF.
 *  - No cumple estrictamente ETSI EN 319 142 (PAdES-B-B): faltaría el
 *    atributo firmado SigningCertificateV2, que OpenSSL no genera de
 *    forma nativa. Para PAdES-B-T/LT se requiere además TSA + validation
 *    info; considerar SetaPDF-Signer o servicio de firma externo.
 */
final class PadesSignerService
{
    /** Longitud (en caracteres hex) del placeholder de /Contents.
     *  8192 bytes binarios cubren firma RSA-2048 + cadena de 2-3 certs. */
    private const CONTENTS_HEX_LEN = 16384;

    /** Placeholder de /ByteRange: 10 dígitos por número (hasta ~10GB). */
    private const BYTERANGE_PLACEHOLDER = '/ByteRange [0 ********** ********** **********]';

    public function __construct(
        private readonly string $p12Path,
        private readonly string $p12Password,
        private readonly string $signerName = 'Institución',
        private readonly string $signerReason = 'Certificación de documento oficial',
        private readonly string $signerLocation = 'Ecuador'
    ) {
    }

    public function sign(string $pdf): string
    {
        if ($pdf === '' || strncmp($pdf, '%PDF-', 5) !== 0) {
            throw new ValidationException('Entrada no es un PDF válido.');
        }

        [$cert, $privateKey, $chain] = $this->loadPkcs12();
        $meta = $this->parsePdfMeta($pdf);

        // Asegurar separador limpio entre el PDF original y el incremento.
        if (substr($pdf, -1) !== "\n") {
            $pdf .= "\n";
        }

        $increment = $this->buildIncrement(strlen($pdf), $meta);
        $full = $pdf . $increment;

        // Localizar el placeholder de /Contents dentro del incremento.
        $contentsMarker = '/Contents <';
        $contentsPos = strpos($full, $contentsMarker, strlen($pdf));
        if ($contentsPos === false) {
            throw new SystemException('Placeholder /Contents no encontrado.');
        }
        $startOfHex = $contentsPos + strlen($contentsMarker); // primer char hex
        $endOfHexAngle = $startOfHex + self::CONTENTS_HEX_LEN; // posición del '>'

        // Cálculo del ByteRange: excluye [< ... >] de /Contents.
        $len1 = $startOfHex - 1;              // 0 .. (startOfHex-2), es decir hasta antes del '<'
        $start2 = $endOfHexAngle + 1;          // primer byte después del '>'
        $len2 = strlen($full) - $start2;

        $this->patchByteRange($full, $len1, $start2, $len2, strlen($pdf));

        // Extraer bytes a firmar y firmar.
        $toSign = substr($full, 0, $len1) . substr($full, $start2, $len2);
        $signature = $this->signBytes($toSign, $cert, $privateKey, $chain);

        $hex = bin2hex($signature);
        if (strlen($hex) > self::CONTENTS_HEX_LEN) {
            throw new SystemException(sprintf(
                'Firma (%d bytes hex) excede el placeholder (%d). Aumentar CONTENTS_HEX_LEN.',
                strlen($hex),
                self::CONTENTS_HEX_LEN
            ));
        }
        $hex = str_pad($hex, self::CONTENTS_HEX_LEN, '0');

        $full = substr_replace($full, $hex, $startOfHex, self::CONTENTS_HEX_LEN);
        return $full;
    }

    /**
     * @return array{0:string,1:mixed,2:array<int,string>}
     */
    private function loadPkcs12(): array
    {
        if (!is_readable($this->p12Path)) {
            throw new InvalidConfigurationException("Certificado PKCS#12 no legible: {$this->p12Path}");
        }
        $blob = file_get_contents($this->p12Path);
        if ($blob === false) {
            throw new SystemException("No se pudo leer {$this->p12Path}");
        }
        $out = [];
        if (!openssl_pkcs12_read($blob, $out, $this->p12Password)) {
            throw new InvalidConfigurationException('openssl_pkcs12_read falló: ' . (openssl_error_string() ?: 'password?'));
        }
        $chain = [];
        if (isset($out['extracerts']) && is_array($out['extracerts'])) {
            foreach ($out['extracerts'] as $c) {
                $chain[] = (string)$c;
            }
        }
        return [(string)$out['cert'], $out['pkey'], $chain];
    }

    /**
     * @return array{prevXref:int,catalogObjNum:int,catalogInnerDict:string,size:int,id:string}
     */
    private function parsePdfMeta(string $pdf): array
    {
        $startXrefPos = strrpos($pdf, 'startxref');
        if ($startXrefPos === false) {
            throw new SystemException('startxref no encontrado.');
        }
        if (!preg_match('/startxref\s+(\d+)\s+%%EOF/', substr($pdf, $startXrefPos), $m)) {
            throw new SystemException('Valor de startxref inválido.');
        }
        $prevXref = (int)$m[1];

        $trailerPos = strrpos($pdf, 'trailer');
        if ($trailerPos === false) {
            throw new SystemException('trailer no encontrado.');
        }
        $trailerBlob = substr($pdf, $trailerPos, $startXrefPos - $trailerPos);

        if (!preg_match('/\/Root\s+(\d+)\s+0\s+R/', $trailerBlob, $m)) {
            throw new SystemException('/Root no encontrado en trailer.');
        }
        $catalogObjNum = (int)$m[1];

        $size = 0;
        if (preg_match('/\/Size\s+(\d+)/', $trailerBlob, $m)) {
            $size = (int)$m[1];
        }

        $id = '';
        if (preg_match('/\/ID\s*\[\s*<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>\s*\]/', $trailerBlob, $m)) {
            $id = "/ID [<{$m[1]}><{$m[2]}>]";
        }

        // Extraer diccionario del catalogo (asumimos flat, sin dicts anidados — FPDF lo cumple).
        $pattern = '/' . preg_quote((string)$catalogObjNum, '/') . '\s+0\s+obj\s*<<(.*?)>>\s*endobj/s';
        if (!preg_match($pattern, $pdf, $m)) {
            throw new SystemException("Catalog obj {$catalogObjNum} no encontrado o formato inesperado.");
        }
        $catalogInnerDict = trim($m[1]);

        return [
            'prevXref' => $prevXref,
            'catalogObjNum' => $catalogObjNum,
            'catalogInnerDict' => $catalogInnerDict,
            'size' => $size,
            'id' => $id,
        ];
    }

    /**
     * @param array{prevXref:int,catalogObjNum:int,catalogInnerDict:string,size:int,id:string} $meta
     */
    private function buildIncrement(int $baseLen, array $meta): string
    {
        $nextObj = max($meta['size'], $meta['catalogObjNum'] + 1);
        $sigObjNum      = $nextObj;
        $fieldObjNum    = $nextObj + 1;
        $acroFormObjNum = $nextObj + 2;
        $newCatalogNum  = $nextObj + 3;

        $signingTime = gmdate('YmdHis');
        $placeholder = str_repeat('0', self::CONTENTS_HEX_LEN);
        $brPlaceholder = self::BYTERANGE_PLACEHOLDER;

        $nameE     = $this->pdfString($this->signerName);
        $reasonE   = $this->pdfString($this->signerReason);
        $locationE = $this->pdfString($this->signerLocation);

        $offsets = [];
        $buf = '';

        $offsets[$sigObjNum] = $baseLen + strlen($buf);
        $buf .= "{$sigObjNum} 0 obj\n";
        $buf .= "<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /adbe.pkcs7.detached\n";
        $buf .= "   {$brPlaceholder}\n";
        $buf .= "   /Contents <{$placeholder}>\n";
        $buf .= "   /M (D:{$signingTime}Z)\n";
        $buf .= "   /Name {$nameE} /Reason {$reasonE} /Location {$locationE}\n";
        $buf .= ">>\nendobj\n";

        // Campo de firma "invisible" (sin /Subtype /Widget: es un field puro).
        $offsets[$fieldObjNum] = $baseLen + strlen($buf);
        $buf .= "{$fieldObjNum} 0 obj\n";
        $buf .= "<< /FT /Sig /T (Signature1) /V {$sigObjNum} 0 R >>\nendobj\n";

        $offsets[$acroFormObjNum] = $baseLen + strlen($buf);
        $buf .= "{$acroFormObjNum} 0 obj\n";
        $buf .= "<< /Fields [ {$fieldObjNum} 0 R ] /SigFlags 3 >>\nendobj\n";

        // Nuevo Catalog: preserva el diccionario original + /AcroForm.
        $catalogDict = preg_replace('/\/AcroForm\s+\d+\s+0\s+R/', '', $meta['catalogInnerDict']) ?? $meta['catalogInnerDict'];
        $catalogDict = trim($catalogDict) . " /AcroForm {$acroFormObjNum} 0 R";

        $offsets[$newCatalogNum] = $baseLen + strlen($buf);
        $buf .= "{$newCatalogNum} 0 obj\n";
        $buf .= "<< {$catalogDict} >>\nendobj\n";

        // xref
        $xrefOffset = $baseLen + strlen($buf);
        $buf .= "xref\n";
        ksort($offsets);
        foreach ($this->groupContiguous(array_keys($offsets)) as [$startNum, $nums]) {
            $buf .= "{$startNum} " . count($nums) . "\n";
            foreach ($nums as $n) {
                $buf .= sprintf("%010d 00000 n \n", $offsets[$n]);
            }
        }

        $newSize = $newCatalogNum + 1;
        $buf .= "trailer\n<< /Size {$newSize} /Root {$newCatalogNum} 0 R /Prev {$meta['prevXref']}";
        if ($meta['id'] !== '') {
            $buf .= ' ' . $meta['id'];
        }
        $buf .= " >>\nstartxref\n{$xrefOffset}\n%%EOF\n";

        return $buf;
    }

    /**
     * @param int[] $nums
     * @return list<array{0:int,1:int[]}>
     */
    private function groupContiguous(array $nums): array
    {
        if ($nums === []) {
            return [];
        }
        $out = [];
        $startNum = $nums[0];
        $group = [$nums[0]];
        $count = count($nums);
        for ($i = 1; $i < $count; $i++) {
            if ($nums[$i] === $startNum + count($group)) {
                $group[] = $nums[$i];
            } else {
                $out[] = [$startNum, $group];
                $startNum = $nums[$i];
                $group = [$nums[$i]];
            }
        }
        $out[] = [$startNum, $group];
        return $out;
    }

    private function patchByteRange(string &$full, int $len1, int $start2, int $len2, int $searchFrom): void
    {
        $brPos = strpos($full, self::BYTERANGE_PLACEHOLDER, $searchFrom);
        if ($brPos === false) {
            throw new SystemException('Placeholder /ByteRange no encontrado.');
        }
        $real = sprintf('/ByteRange [0 %d %d %d]', $len1, $start2, $len2);
        $real = str_pad($real, strlen(self::BYTERANGE_PLACEHOLDER), ' ', STR_PAD_RIGHT);
        if (strlen($real) !== strlen(self::BYTERANGE_PLACEHOLDER)) {
            throw new SystemException('ByteRange excede placeholder (números demasiado grandes).');
        }
        $full = substr_replace($full, $real, $brPos, strlen(self::BYTERANGE_PLACEHOLDER));
    }

    private function pdfString(string $s): string
    {
        // Literal PDF string; escapa \ ( ).
        return '(' . strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ')';
    }

    /**
     * @param array<int,string> $chain
     */
    private function signBytes(string $data, string $cert, mixed $key, array $chain): string
    {
        $tmpIn  = tempnam(sys_get_temp_dir(), 'pds_in_');
        $tmpOut = tempnam(sys_get_temp_dir(), 'pds_out_');
        if ($tmpIn === false || $tmpOut === false) {
            throw new SystemException('No se pudo crear archivo temporal.');
        }
        file_put_contents($tmpIn, $data);

        $extraCertsFile = null;
        if ($chain !== []) {
            $extraCertsFile = tempnam(sys_get_temp_dir(), 'pds_chain_');
            if ($extraCertsFile !== false) {
                file_put_contents($extraCertsFile, implode("\n", $chain));
            } else {
                $extraCertsFile = null;
            }
        }

        try {
            $ok = openssl_cms_sign(
                $tmpIn,
                $tmpOut,
                $cert,
                $key,
                [],
                OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY,
                OPENSSL_ENCODING_DER,
                $extraCertsFile
            );
            if (!$ok) {
                throw new SystemException('openssl_cms_sign falló: ' . (openssl_error_string() ?: 'desconocido'));
            }
            $sig = file_get_contents($tmpOut);
            if (!is_string($sig) || $sig === '') {
                throw new SystemException('CMS vacío.');
            }
            return $sig;
        } finally {
            @unlink($tmpIn);
            @unlink($tmpOut);
            if ($extraCertsFile !== null) {
                @unlink($extraCertsFile);
            }
        }
    }
}