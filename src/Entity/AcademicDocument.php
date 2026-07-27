<?php
declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Type\SqlServerDateTimeType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'DocumentoAcademico', schema: 'Academico')]
#[ORM\Index(columns: ['idRecordAcademico'], name: 'IX_DocumentoAcademico_idRecordAcademico')]
#[ORM\UniqueConstraint(name: 'UX_DocumentoAcademico_uuidRepositorio', columns: ['uuidRepositorio'])]
class AcademicDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'idDocumento', type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AcademicRecord::class)]
    #[ORM\JoinColumn(name: 'idRecordAcademico', referencedColumnName: 'id', nullable: false)]
    private AcademicRecord $academicRecord;

    #[ORM\Column(name: 'uuidRepositorio', type: Types::STRING, length: 100)]
    private string $repositoryUuid;

    #[ORM\Column(name: 'nombreArchivo', type: Types::STRING, length: 255)]
    private string $fileName;

    #[ORM\Column(type: Types::STRING, length: 1)]
    private string $estado = 'A';

    #[ORM\Column(type: Types::INTEGER)]
    private int $idPersonaCrea = 0;

    #[ORM\Column(type: SqlServerDateTimeType::NAME)]
    private \DateTimeInterface $fechaCrea;

    #[ORM\Column(type: Types::STRING, length: 45)]
    private string $ipCrea = '';

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $equipoCrea = '';

    #[ORM\Column(type: Types::INTEGER)]
    private int $idPersonaModifica = 0;

    #[ORM\Column(type: SqlServerDateTimeType::NAME)]
    private \DateTimeInterface $fechaModifica;

    #[ORM\Column(type: Types::STRING, length: 45)]
    private string $ipModifica = '';

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $equipoModifica = '';

    #[ORM\Column(type: Types::STRING, length: 250)]
    private string $motivoModifica = '';

    public function __construct(AcademicRecord $academicRecord, string $repositoryUuid, string $fileName)
    {
        $this->academicRecord = $academicRecord;
        $this->repositoryUuid = trim($repositoryUuid);
        $this->fileName = basename($fileName);
        $now = new \DateTime();
        $this->fechaCrea = $now;
        $this->fechaModifica = clone $now;
    }

    public function getId(): ?int { return $this->id; }
    public function getAcademicRecord(): AcademicRecord { return $this->academicRecord; }
    public function getRepositoryUuid(): string { return $this->repositoryUuid; }
    public function getFileName(): string { return $this->fileName; }
    public function getEstado(): string { return $this->estado; }
    public function getIdPersonaCrea(): int { return $this->idPersonaCrea; }
    public function getFechaCrea(): \DateTimeInterface { return $this->fechaCrea; }
    public function getIpCrea(): string { return $this->ipCrea; }
    public function getEquipoCrea(): string { return $this->equipoCrea; }
    public function getIdPersonaModifica(): int { return $this->idPersonaModifica; }
    public function getFechaModifica(): \DateTimeInterface { return $this->fechaModifica; }
    public function getIpModifica(): string { return $this->ipModifica; }
    public function getEquipoModifica(): string { return $this->equipoModifica; }
    public function getMotivoModifica(): string { return $this->motivoModifica; }

    public function setCreationAudit(int $userId, string $ip, string $host): void
    {
        $this->idPersonaCrea = $userId;
        $this->ipCrea = mb_substr($ip, 0, 45);
        $this->equipoCrea = mb_substr($host, 0, 50);
        $this->idPersonaModifica = $userId;
        $this->ipModifica = $this->ipCrea;
        $this->equipoModifica = $this->equipoCrea;
    }
}
