<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Type\SqlServerDateTimeType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'RecordAcademico', schema: 'Academico')]
#[ORM\Index(columns: ['cedula'], name: 'IX_RecordAcademico_cedula')]
#[ORM\Index(columns: ['origen_tabla'], name: 'IX_RecordAcademico_origen_tabla')]
#[ORM\Index(columns: ['materia'], name: 'IX_RecordAcademico_materia')]
#[ORM\Index(columns: ['anio'], name: 'IX_RecordAcademico_anio')]
class AcademicRecord
{
    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $cedula = '';

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $nombre = '';

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $materia = '';

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    private ?string $nota = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    private ?string $total = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $periodo = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $anio = 0;

    #[ORM\Column(type: Types::STRING, length: 10)]
    private string $origen_tabla = '';

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

    /*
     * Campos institucionales de auditoría.
     */

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

    // FIX: la columna real academic_records.motivoModifica es varchar(250),
    // no 255 -- con 255 el ORM permite escribir 251-255 caracteres que
    // SQL Server rechaza con "String or binary data would be truncated".
    #[ORM\Column(type: Types::STRING, length: 250)]
    private string $motivoModifica = '';

    public function __construct()
    {
        $this->fechaCrea = new \DateTime();
        $this->fechaModifica = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCedula(): string
    {
        return $this->cedula;
    }

    public function getNombre(): string
    {
        return $this->nombre;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getMateria(): string
    {
        return $this->materia;
    }

    public function getNota(): ?string
    {
        return $this->nota;
    }

    public function getTotal(): ?string
    {
        return $this->total;
    }

    public function getPeriodo(): ?string
    {
        return $this->periodo;
    }

    public function getAnio(): int
    {
        return $this->anio;
    }

    public function getOrigenTabla(): string
    {
        return $this->origen_tabla;
    }

    public function getProceso(): ?string
    {
        return $this->proceso;
    }

    public function getGrupoObjetivo(): ?string
    {
        return $this->grupo_objetivo;
    }

    public function getModalidad(): ?string
    {
        return $this->modalidad;
    }

    public function getFechaInicio(): ?string
    {
        return $this->fecha_inicio;
    }

    public function getFechaFin(): ?string
    {
        return $this->fecha_fin;
    }

    public function getAprueba(): ?string
    {
        return $this->aprueba;
    }

    public function getEstado(): string
    {
        return $this->estado;
    }

    public function getIdPersonaCrea(): int
    {
        return $this->idPersonaCrea;
    }

    public function getFechaCrea(): \DateTimeInterface
    {
        return $this->fechaCrea;
    }

    public function getIpCrea(): string
    {
        return $this->ipCrea;
    }

    public function getEquipoCrea(): string
    {
        return $this->equipoCrea;
    }

    public function getIdPersonaModifica(): int
    {
        return $this->idPersonaModifica;
    }

    public function getFechaModifica(): \DateTimeInterface
    {
        return $this->fechaModifica;
    }

    public function getIpModifica(): string
    {
        return $this->ipModifica;
    }

    public function getEquipoModifica(): string
    {
        return $this->equipoModifica;
    }

    public function getMotivoModifica(): string
    {
        return $this->motivoModifica;
    }

    public function setAuditoriaCreacion(
        int $idPersona,
        string $ip,
        string $equipo
    ): void {
        $fecha = new \DateTime();

        $this->estado = 'A';
        $this->idPersonaCrea = $idPersona;
        $this->fechaCrea = $fecha;
        $this->ipCrea = mb_substr(trim($ip), 0, 45);
        $this->equipoCrea = mb_substr(trim($equipo), 0, 50);

        $this->idPersonaModifica = $idPersona;
        $this->fechaModifica = clone $fecha;
        $this->ipModifica = $this->ipCrea;
        $this->equipoModifica = $this->equipoCrea;
        $this->motivoModifica = '';
    }

    public function setAuditoriaModificacion(
        int $idPersona,
        string $ip,
        string $equipo,
        string $motivo
    ): void {
        $this->idPersonaModifica = $idPersona;
        $this->fechaModifica = new \DateTime();
        $this->ipModifica = mb_substr(trim($ip), 0, 45);
        $this->equipoModifica = mb_substr(trim($equipo), 0, 50);
        $this->motivoModifica = mb_substr(trim($motivo), 0, 250);
    }

    public function setEstado(string $estado): void
    {
        $estado = strtoupper(trim($estado));

        if (!in_array($estado, ['A', 'I', 'X'], true)) {
            throw new \InvalidArgumentException(
                'El estado debe ser A, I o X.'
            );
        }

        $this->estado = $estado;
    }

    public function markAsDeleted(int $idPersona, string $ip, string $equipo): void
    {
        $this->estado = 'X';
        $this->idPersonaModifica = $idPersona;
        $this->fechaModifica = new \DateTime();
        $this->ipModifica = mb_substr(trim($ip), 0, 45);
        $this->equipoModifica = mb_substr(trim($equipo), 0, 50);
        $this->motivoModifica = 'Eliminado';
    }

    public function fill(array $data): void
    {
        $this->cedula = trim(
            (string) ($data['cedula'] ?? $this->cedula)
        );

        $this->nombre = trim(
            (string) ($data['nombre'] ?? $this->nombre)
        );

        $this->email = array_key_exists('email', $data)
            ? $this->nullableString($data['email'])
            : $this->email;

        $this->materia = trim(
            (string) ($data['materia'] ?? $this->materia)
        );

        $this->nota = $this->toDecimalString(
            $data['nota'] ?? $this->nota
        );

        $this->total = $this->toDecimalString(
            $data['total'] ?? $this->total
        );

        $this->periodo = array_key_exists('periodo', $data)
            ? $this->nullableString($data['periodo'])
            : $this->periodo;

        $this->anio = (int) ($data['anio'] ?? $this->anio);

        $this->origen_tabla = trim(
            (string) ($data['origen_tabla'] ?? $this->origen_tabla)
        );

        $this->proceso = array_key_exists('proceso', $data)
            ? $this->nullableString($data['proceso'])
            : $this->proceso;

        $this->grupo_objetivo = array_key_exists('grupo_objetivo', $data)
            ? $this->nullableString($data['grupo_objetivo'])
            : $this->grupo_objetivo;

        $this->modalidad = array_key_exists('modalidad', $data)
            ? $this->nullableString($data['modalidad'])
            : $this->modalidad;

        $this->fecha_inicio = array_key_exists('fecha_inicio', $data)
            ? $this->nullableString($data['fecha_inicio'])
            : $this->fecha_inicio;

        $this->fecha_fin = array_key_exists('fecha_fin', $data)
            ? $this->nullableString($data['fecha_fin'])
            : $this->fecha_fin;

        $this->aprueba = array_key_exists('aprueba', $data)
            ? $this->nullableString($data['aprueba'])
            : $this->aprueba;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function toDecimalString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace(',', '.', $value);
            $value = preg_replace('/[^0-9.\-]/', '', $value);
        }

        return $value === ''
            ? null
            : number_format((float) $value, 2, '.', '');
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'cedula' => $this->cedula,
            'nombre' => $this->nombre,
            'email' => $this->email,
            'materia' => $this->materia,
            'nota' => $this->nota,
            'total' => $this->total,
            'periodo' => $this->periodo,
            'anio' => $this->anio,
            'origen_tabla' => $this->origen_tabla,
            'proceso' => $this->proceso,
            'grupo_objetivo' => $this->grupo_objetivo,
            'modalidad' => $this->modalidad,
            'fecha_inicio' => $this->fecha_inicio,
            'fecha_fin' => $this->fecha_fin,
            'aprueba' => $this->aprueba,

            'estado' => $this->estado,
            'idPersonaCrea' => $this->idPersonaCrea,
            'fechaCrea' => $this->fechaCrea->format('Y-m-d H:i:s'),
            'ipCrea' => $this->ipCrea,
            'equipoCrea' => $this->equipoCrea,
            'idPersonaModifica' => $this->idPersonaModifica,
            'fechaModifica' => $this->fechaModifica->format('Y-m-d H:i:s'),
            'ipModifica' => $this->ipModifica,
            'equipoModifica' => $this->equipoModifica,
            'motivoModifica' => $this->motivoModifica,
        ];
    }
}