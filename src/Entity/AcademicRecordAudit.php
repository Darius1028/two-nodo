<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'academic_records_AUD', schema: 'AUD')]
class AcademicRecordAudit
{
    public const INSERT = 0;
    public const UPDATE = 1;
    public const DELETE = 2;

    /*
     * La tabla tiene clave primaria compuesta: REV + id.
     */

    #[ORM\Id]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    private int $id;

    #[ORM\Id]
    #[ORM\Column(name: 'REV', type: Types::INTEGER)]
    private int $revision;

    #[ORM\Column(name: 'REVTYPE', type: Types::SMALLINT, nullable: true)]
    private ?int $revisionType = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $cedula = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $nombre = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $materia = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    private ?string $nota = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    private ?string $total = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $periodo = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $anio = null;

    #[ORM\Column(type: Types::STRING, length: 10, nullable: true)]
    private ?string $origen_tabla = null;

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $proceso = null;

    #[ORM\Column(type: Types::STRING, length: 200, nullable: true)]
    private ?string $grupo_objetivo = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $modalidad = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $fecha_inicio = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $fecha_fin = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $aprueba = null;

    #[ORM\Column(type: Types::STRING, length: 1, nullable: true)]
    private ?string $estado = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $idPersonaCrea = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $fechaCrea = null;

    #[ORM\Column(type: Types::STRING, length: 25, nullable: true)]
    private ?string $ipCrea = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $equipoCrea = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $idPersonaModifica = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $fechaModifica = null;

    #[ORM\Column(type: Types::STRING, length: 25, nullable: true)]
    private ?string $ipModifica = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $equipoModifica = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $motivoModifica = null;

    private function __construct()
    {
    }

    public static function fromRecord(
        AcademicRecord $record,
        int $revision,
        int $revisionType
    ): self {
        $id = $record->getId();

        if ($id === null) {
            throw new \RuntimeException(
                'No se puede auditar un registro sin identificador.'
            );
        }

        if (!in_array(
            $revisionType,
            [self::INSERT, self::UPDATE, self::DELETE],
            true
        )) {
            throw new \InvalidArgumentException(
                'El tipo de revisión no es válido.'
            );
        }

        $audit = new self();

        $audit->id = $id;
        $audit->revision = $revision;
        $audit->revisionType = $revisionType;

        $audit->cedula = $record->getCedula();
        $audit->nombre = $record->getNombre();
        $audit->email = $record->getEmail();
        $audit->materia = $record->getMateria();
        $audit->nota = $record->getNota();
        $audit->total = $record->getTotal();
        $audit->periodo = $record->getPeriodo();
        $audit->anio = $record->getAnio();
        $audit->origen_tabla = $record->getOrigenTabla();
        $audit->proceso = $record->getProceso();
        $audit->grupo_objetivo = $record->getGrupoObjetivo();
        $audit->modalidad = $record->getModalidad();
        $audit->fecha_inicio = $record->getFechaInicio();
        $audit->fecha_fin = $record->getFechaFin();
        $audit->aprueba = $record->getAprueba();

        $audit->estado = $record->getEstado();
        $audit->idPersonaCrea = $record->getIdPersonaCrea();
        $audit->fechaCrea = clone $record->getFechaCrea();
        $audit->ipCrea = $record->getIpCrea();
        $audit->equipoCrea = $record->getEquipoCrea();

        $audit->idPersonaModifica = $record->getIdPersonaModifica();
        $audit->fechaModifica = clone $record->getFechaModifica();
        $audit->ipModifica = $record->getIpModifica();
        $audit->equipoModifica = $record->getEquipoModifica();
        $audit->motivoModifica = $record->getMotivoModifica();

        return $audit;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function getRevisionType(): ?int
    {
        return $this->revisionType;
    }
}