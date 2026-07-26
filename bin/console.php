<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

use App\Core\EntityManagerProvider;
use Doctrine\ORM\Tools\SchemaTool;


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
