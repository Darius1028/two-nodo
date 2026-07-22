<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

/**
 * Espejo de auditoría de AcademicRecord — equivalente al patrón @Audited de
 * Hibernate Envers. Cada fila representa el estado de un registro en el momento
 * de un INSERT (REVTYPE=0), UPDATE (REVTYPE=1) o DELETE (REVTYPE=2).
 *
 * La escritura siempre se hace vía DBAL (no ORM) para no re-entrar al
 * UnitOfWork durante flush(), igual que Envers usa SQL directo internamente.
 * Por eso readOnly=true: Doctrine nunca debe hacer flush de esta entidad.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'RecordAcademico_AUD', schema: 'AcademicoAUD')]
class AcademicRecordAudit
{
    public const INSERT = 0;
    public const UPDATE = 1;
    public const DELETE = 2;

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

    #[ORM\Column(type: Types::STRING, length: 45, nullable: true)]
    private ?string $ipCrea = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $equipoCrea = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $idPersonaModifica = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $fechaModifica = null;

    #[ORM\Column(type: Types::STRING, length: 45, nullable: true)]
    private ?string $ipModifica = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $equipoModifica = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $motivoModifica = null;

    private function __construct() {
        // Entidad de solo lectura, instanciada únicamente por métodos de fábrica.
    }

    /**
     * Crea la entrada de auditoría a partir del registro original y la revisión
     * ya creada en AUD.REVINFO. Equivalente a lo que Hibernate Envers hace
     * internamente al interceptar un evento de persistencia.
     */
    public static function fromRecord(
        AcademicRecord $record,
        int $revisionId,
        int $revisionType
    ): self {
        if (!in_array($revisionType, [self::INSERT, self::UPDATE, self::DELETE], true)) {
            throw new InvalidArgumentException('Tipo de revisión inválido.');
        }

        $id = $record->getId();
        if ($id === null) {
            throw new InvalidArgumentException('No se puede auditar un registro sin identificador.');
        }

        $audit = new self();
        $audit->id             = $id;
        $audit->revision       = $revisionId;
        $audit->revisionType   = $revisionType;
        $audit->cedula         = $record->getCedula();
        $audit->nombre         = $record->getNombre();
        $audit->email          = $record->getEmail();
        $audit->materia        = $record->getMateria();
        $audit->nota           = $record->getNota();
        $audit->total          = $record->getTotal();
        $audit->periodo        = $record->getPeriodo();
        $audit->anio           = $record->getAnio();
        $audit->origen_tabla   = $record->getOrigenTabla();
        $audit->proceso        = $record->getProceso();
        $audit->grupo_objetivo = $record->getGrupoObjetivo();
        $audit->modalidad      = $record->getModalidad();
        $audit->fecha_inicio   = $record->getFechaInicio();
        $audit->fecha_fin      = $record->getFechaFin();
        $audit->aprueba        = $record->getAprueba();
        $audit->estado         = $record->getEstado();
        $audit->idPersonaCrea      = $record->getIdPersonaCrea();
        $audit->fechaCrea          = clone $record->getFechaCrea();
        $audit->ipCrea             = $record->getIpCrea();
        $audit->equipoCrea         = $record->getEquipoCrea();
        $audit->idPersonaModifica  = $record->getIdPersonaModifica();
        $audit->fechaModifica      = clone $record->getFechaModifica();
        $audit->ipModifica         = $record->getIpModifica();
        $audit->equipoModifica     = $record->getEquipoModifica();
        $audit->motivoModifica     = $record->getMotivoModifica();

        return $audit;
    }

    /**
     * Fábrica para el caso bulk: acepta la fila cruda devuelta por
     * OUTPUT INSERTED.* (DBAL fetchAllAssociative) en vez de una entidad ORM.
     * Las fechas pueden llegar como string o DateTime según el driver.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRawRow(array $row, int $revisionId, int $revisionType): self
    {
        if (!in_array($revisionType, [self::INSERT, self::UPDATE, self::DELETE], true)) {
            throw new InvalidArgumentException('Tipo de revisión inválido.');
        }

        $s = static fn(mixed $v): ?string => ($v === null || $v === '') ? null : (string) $v;
        $i = static fn(mixed $v): ?int   => $v === null ? null : (int) $v;

        $audit = new self();
        $audit->id             = (int) $row['id'];
        $audit->revision       = $revisionId;
        $audit->revisionType   = $revisionType;
        $audit->cedula         = $s($row['cedula'] ?? null);
        $audit->nombre         = $s($row['nombre'] ?? null);
        $audit->email          = $s($row['email'] ?? null);
        $audit->materia        = $s($row['materia'] ?? null);
        $audit->nota           = $s($row['nota'] ?? null);
        $audit->total          = $s($row['total'] ?? null);
        $audit->periodo        = $s($row['periodo'] ?? null);
        $audit->anio           = $i($row['anio'] ?? null);
        $audit->origen_tabla   = $s($row['origen_tabla'] ?? null);
        $audit->proceso        = $s($row['proceso'] ?? null);
        $audit->grupo_objetivo = $s($row['grupo_objetivo'] ?? null);
        $audit->modalidad      = $s($row['modalidad'] ?? null);
        $audit->fecha_inicio   = $s($row['fecha_inicio'] ?? null);
        $audit->fecha_fin      = $s($row['fecha_fin'] ?? null);
        $audit->aprueba        = $s($row['aprueba'] ?? null);
        $audit->estado         = $s($row['estado'] ?? null);
        $audit->idPersonaCrea      = $i($row['idPersonaCrea'] ?? null);
        $audit->fechaCrea          = self::parseDate($row['fechaCrea'] ?? null);
        $audit->ipCrea             = $s($row['ipCrea'] ?? null);
        $audit->equipoCrea         = $s($row['equipoCrea'] ?? null);
        $audit->idPersonaModifica  = $i($row['idPersonaModifica'] ?? null);
        $audit->fechaModifica      = self::parseDate($row['fechaModifica'] ?? null);
        $audit->ipModifica         = $s($row['ipModifica'] ?? null);
        $audit->equipoModifica     = $s($row['equipoModifica'] ?? null);
        $audit->motivoModifica     = $s($row['motivoModifica'] ?? null);

        return $audit;
    }

    private static function parseDate(mixed $value): ?\DateTimeInterface
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value;
        }
        return new \DateTime((string) $value);
    }

    /**
     * Devuelve el array listo para DBAL::insert(). La entidad es la única
     * fuente de verdad del mapeo de columnas — AcademicRecordAuditService
     * nunca debería listar columnas por su cuenta.
     *
     * @return array<string, mixed>
     */
    public function toInsertArray(): array
    {
        return [
            'id'              => $this->id,
            'REV'             => $this->revision,
            'REVTYPE'         => $this->revisionType,
            'cedula'          => $this->cedula,
            'nombre'          => $this->nombre,
            'email'           => $this->email,
            'materia'         => $this->materia,
            'nota'            => $this->nota,
            'total'           => $this->total,
            'periodo'         => $this->periodo,
            'anio'            => $this->anio,
            'origen_tabla'    => $this->origen_tabla,
            'proceso'         => $this->proceso,
            'grupo_objetivo'  => $this->grupo_objetivo,
            'modalidad'       => $this->modalidad,
            'fecha_inicio'    => $this->fecha_inicio,
            'fecha_fin'       => $this->fecha_fin,
            'aprueba'         => $this->aprueba,
            'estado'          => $this->estado,
            'idPersonaCrea'   => $this->idPersonaCrea,
            'fechaCrea'       => $this->fechaCrea?->format('Y-m-d\TH:i:s.v'),
            'ipCrea'          => $this->ipCrea,
            'equipoCrea'      => $this->equipoCrea,
            'idPersonaModifica'  => $this->idPersonaModifica,
            'fechaModifica'      => $this->fechaModifica?->format('Y-m-d\TH:i:s.v'),
            'ipModifica'         => $this->ipModifica,
            'equipoModifica'     => $this->equipoModifica,
            'motivoModifica'     => $this->motivoModifica,
        ];
    }
}