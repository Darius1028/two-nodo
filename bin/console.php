<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

use App\Core\EntityManagerProvider;
use Doctrine\ORM\Tools\SchemaTool;

// AVISO IMPORTANTE: desde que se adoptó auditoría institucional (tablas
// academic_records / AUD.REVINFO / AUD.academic_records_AUD), el esquema
// de este proyecto se gestiona a mano con scripts SQL auditados
// (ver /scripts, con el patrón EXEC [ADM].[DB_AuditaSql] ... ), NO con
// SchemaTool. Hay diferencias conocidas y aceptadas entre lo que las
// entidades Doctrine "creen" que es el tipo de columna y lo que la base
// real tiene (ej. academic_records.estado es CHAR(1) real vs. lo que
// Doctrine generaría por defecto para STRING; academic_records_AUD.fechaCrea
// es DATETIME2(6) real vs. el DATETIME_MUTABLE genérico de la entidad).
// Correr updateSchema() a ciegas contra una base ya poblada puede generar
// ALTER TABLE destructivos intentando "corregir" esas diferencias.
//
// Por eso este script exige --confirm explícito, y aun así solo debería
// usarse contra una base de desarrollo vacía/nueva, nunca contra una base
// con datos reales gestionada por los scripts SQL institucionales.
if (!in_array('--confirm', $argv, true)) {
    fwrite(STDERR, "Este script está deshabilitado por defecto -- el esquema se gestiona\n");
    fwrite(STDERR, "con scripts SQL auditados institucionales (ver /scripts), no con SchemaTool.\n");
    fwrite(STDERR, "Si estás seguro de que querés correr updateSchema() de todas formas\n");
    fwrite(STDERR, "(solo recomendado contra una base de desarrollo nueva/vacía), corré:\n\n");
    fwrite(STDERR, "  php bin/console.php --confirm\n\n");
    exit(1);
}

$em = EntityManagerProvider::get();
$schemaTool = new SchemaTool($em);
$classes = $em->getMetadataFactory()->getAllMetadata();

echo "Actualizando esquema de base de datos...\n";
$schemaTool->updateSchema($classes, true);
echo "Esquema actualizado correctamente.\n";
