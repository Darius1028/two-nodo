<?php
declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Core\EntityManagerProvider;
use App\Entity\AcademicRecord;
use App\Service\PdfService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class PdfServiceTest extends TestCase
{
    private \ReflectionProperty $entityManagerInstance;
    private ?EntityManagerInterface $previousEntityManager;

    protected function setUp(): void
    {
        $this->entityManagerInstance = new \ReflectionProperty(EntityManagerProvider::class, 'instance');
        $this->previousEntityManager = $this->entityManagerInstance->getValue();
    }

    protected function tearDown(): void
    {
        $this->entityManagerInstance->setValue($this->previousEntityManager);
    }

    public function testCertificateQueryOnlyIncludesApprovedActiveRecords(): void
    {
        $cedula = '0102030405';
        $query = $this->createMock(Query::class);
        $query->expects(self::once())
            ->method('getResult')
            ->willReturn([]);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->expects(self::once())
            ->method('select')
            ->with('r')
            ->willReturnSelf();
        $queryBuilder->expects(self::once())
            ->method('from')
            ->with(AcademicRecord::class, 'r')
            ->willReturnSelf();
        $queryBuilder->expects(self::once())
            ->method('where')
            ->with('r.cedula = :cedula')
            ->willReturnSelf();
        $queryBuilder->expects(self::once())
            ->method('setParameter')
            ->with('cedula', $cedula)
            ->willReturnSelf();
        $queryBuilder->expects(self::once())
            ->method('andWhere')
            ->with("r.estado != 'X' AND r.aprueba = 'SI'")
            ->willReturnSelf();
        $queryBuilder->expects(self::once())
            ->method('orderBy')
            ->with('r.anio', 'DESC')
            ->willReturnSelf();
        $queryBuilder->expects(self::once())
            ->method('addOrderBy')
            ->with('r.id', 'DESC')
            ->willReturnSelf();
        $queryBuilder->expects(self::once())
            ->method('getQuery')
            ->willReturn($query);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('createQueryBuilder')
            ->willReturn($queryBuilder);
        $this->entityManagerInstance->setValue($entityManager);

        $method = new \ReflectionMethod(PdfService::class, 'searchByCedula');
        $records = $method->invoke(new PdfService(), $cedula);

        self::assertSame([], $records);
    }
}
