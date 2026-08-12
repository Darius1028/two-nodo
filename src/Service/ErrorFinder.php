<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\EntityManagerProvider;
use App\Entity\AcademicRecord;

class ErrorFinder
{
    /**
     * Definición de cada chequeo disponible.
     * NOTA: se restauraron 'slash_emails' e 'invalid_grades', presentes en el
     * ErrorFinder.php original y que faltaban en la primera versión migrada.
     */
    private const CHECKS = [
        'comma_emails' => [
            'sql'   => "SELECT r.id, r.cedula, r.nombre, r.email FROM App\Entity\AcademicRecord r WHERE r.email LIKE '%,%' AND r.email NOT LIKE '%.%'",
            'label' => 'comma_in_email',
            'desc'  => 'Email contains comma instead of dot',
        ],
        'semicolon_emails' => [
            'sql'   => "SELECT r.id, r.cedula, r.nombre, r.email FROM App\Entity\AcademicRecord r WHERE r.email LIKE '%;%'",
            'label' => 'semicolon_in_email',
            'desc'  => 'Email contains semicolon (multiple emails)',
        ],
        'slash_emails' => [
            'sql'   => "SELECT r.id, r.cedula, r.nombre, r.email FROM App\Entity\AcademicRecord r WHERE r.email LIKE '%/%'",
            'label' => 'slash_in_email',
            'desc'  => 'Email contains slash (multiple emails)',
        ],
        'missing_totals' => [
            'sql'   => "SELECT r.id, r.cedula, r.nombre, r.curso, r.nro_horas, r.total FROM App\Entity\AcademicRecord r WHERE r.total IS NULL OR r.curso IS NULL",
            'label' => 'missing_grade_or_course',
            'desc'  => 'Record missing grade or course',
        ],
        'invalid_cedulas' => [
            'sql'   => "SELECT r.id, r.cedula, r.nombre FROM App\Entity\AcademicRecord r WHERE r.cedula IS NULL OR LEN(r.cedula) < 5",
            'label' => 'invalid_cedula',
            'desc'  => 'Cedula is missing or too short',
        ],
        'missing_names' => [
            'sql'   => "SELECT r.id, r.cedula, r.nombre FROM App\Entity\AcademicRecord r WHERE r.nombre IS NULL OR TRIM(r.nombre) = ''",
            'label' => 'missing_name',
            'desc'  => 'Student name is missing or empty',
        ],
        'invalid_grades' => [
            'sql'   => "SELECT r.id, r.cedula, r.nombre, r.curso, r.nro_horas, r.total FROM App\Entity\AcademicRecord r WHERE (r.total IS NOT NULL AND (r.total < 0 OR r.total > 100)) OR (r.nro_horas IS NOT NULL AND r.nro_horas < 0)",
            'label' => 'invalid_grade',
            'desc'  => 'Grade outside valid range (0-100) or negative hours',
        ],
    ];

    private static function runCheck(string $key, int $year): array
    {
        if (!isset(self::CHECKS[$key])) {
            return [];
        }
        $def = self::CHECKS[$key];
        $em = EntityManagerProvider::get();

        try {
            $dql = $def['sql'] . " AND r.anio = :year ORDER BY r.id";
            $query = $em->createQuery($dql)->setParameter('year', (int)$year);
            $results = $query->getArrayResult();

            foreach ($results as &$row) {
                $row['error_type']  = $def['label'];
                $row['description'] = $def['desc'];
                $row['source_year'] = $year;
            }
            return $results;
        } catch (\Throwable $e) {
            error_log("Find $key error: " . $e->getMessage());
            return [];
        }
    }

    public static function findCommaEmails(int $year): array     { return self::runCheck('comma_emails', $year); }
    public static function findSemicolonEmails(int $year): array { return self::runCheck('semicolon_emails', $year); }
    public static function findSlashEmails(int $year): array     { return self::runCheck('slash_emails', $year); }
    public static function findMissingTotals(int $year): array   { return self::runCheck('missing_totals', $year); }
    public static function findInvalidCedulas(int $year): array  { return self::runCheck('invalid_cedulas', $year); }
    public static function findMissingNames(int $year): array    { return self::runCheck('missing_names', $year); }
    public static function findInvalidGrades(int $year): array   { return self::runCheck('invalid_grades', $year); }

    public static function runAllChecks(int $year): array
    {
        $out = [];
        foreach (array_keys(self::CHECKS) as $key) {
            $out[$key] = self::runCheck($key, $year);
        }
        return $out;
    }

    public static function getErrorSummary(int $year): array
    {
        $checks = self::runAllChecks($year);
        $summary = [];
        foreach ($checks as $type => $records) {
            $summary[$type] = count($records);
        }
        $summary['total_errors'] = array_sum($summary);
        $summary['year'] = $year;
        return $summary;
    }

    /**
     * Resumen de errores para todos los años disponibles (existía en el
     * original vía api.php?action=error_summary sin year).
     */
    public static function getErrorSummaryAllYears(): array
    {
        $summaries = [];
        foreach (self::getAvailableYears() as $year) {
            $summaries[$year] = self::getErrorSummary((int)$year);
        }
        return $summaries;
    }

    public static function getAvailableYears(): array
    {
        $em = EntityManagerProvider::get();
        $qb = $em->createQueryBuilder()
            ->select('DISTINCT r.anio')
            ->from(AcademicRecord::class, 'r')
            ->where("r.estado != 'X'")
            ->orderBy('r.anio', 'DESC');
        $result = $qb->getQuery()->getScalarResult();
        return array_column($result, 'anio');
    }
}