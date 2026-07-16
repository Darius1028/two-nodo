<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

use App\Core\EntityManagerProvider;
use Doctrine\ORM\Tools\SchemaTool;

$em = EntityManagerProvider::get();
$schemaTool = new SchemaTool($em);
$classes = $em->getMetadataFactory()->getAllMetadata();

echo "Actualizando esquema de base de datos...\n";
$schemaTool->updateSchema($classes, true);
echo "Esquema actualizado correctamente.\n";