<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'academic_records')]
#[ORM\Index(columns: ['cedula'], name: 'idx_cedula')]
#[ORM\Index(columns: ['origen_tabla'], name: 'idx_origen_tabla')]
#[ORM\Index(columns: ['materia'], name: 'idx_materia')]
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

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $grupo_objetivo = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $modalidad = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $fecha_inicio = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $fecha_fin = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $aprueba = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $created_at = null;

    public function __construct()
    {
        $this->created_at = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCedula(): string { return $this->cedula; }
    public function getNombre(): string { return $this->nombre; }
    public function getEmail(): ?string { return $this->email; }
    public function getMateria(): string { return $this->materia; }
    public function getNota(): ?string { return $this->nota; }
    public function getTotal(): ?string { return $this->total; }
    public function getPeriodo(): ?string { return $this->periodo; }
    public function getAnio(): int { return $this->anio; }
    public function getOrigenTabla(): string { return $this->origen_tabla; }
    public function getProceso(): ?string { return $this->proceso; }
    public function getGrupoObjetivo(): ?string { return $this->grupo_objetivo; }
    public function getModalidad(): ?string { return $this->modalidad; }
    public function getFechaInicio(): ?string { return $this->fecha_inicio; }
    public function getFechaFin(): ?string { return $this->fecha_fin; }
    public function getAprueba(): ?string { return $this->aprueba; }
    public function getCreatedAt(): ?\DateTimeInterface { return $this->created_at; }

    public function fill(array $data): void
    {
        $this->cedula         = trim((string)($data['cedula']         ?? $this->cedula));
        $this->nombre         = trim((string)($data['nombre']         ?? $this->nombre));
        $this->email          = isset($data['email'])          ? trim((string)$data['email'])          : $this->email;
        $this->materia        = trim((string)($data['materia']        ?? $this->materia));
        $this->nota           = $this->toDecimalString($data['nota']   ?? $this->nota);
        $this->total          = $this->toDecimalString($data['total']  ?? $this->total);
        $this->periodo        = isset($data['periodo'])        ? trim((string)$data['periodo'])        : $this->periodo;
        $this->anio           = (int)($data['anio']           ?? $this->anio);
        $this->origen_tabla   = trim((string)($data['origen_tabla']   ?? $this->origen_tabla));
        $this->proceso        = isset($data['proceso'])        ? trim((string)$data['proceso'])        : $this->proceso;
        $this->grupo_objetivo = isset($data['grupo_objetivo']) ? trim((string)$data['grupo_objetivo']) : $this->grupo_objetivo;
        $this->modalidad      = isset($data['modalidad'])      ? trim((string)$data['modalidad'])      : $this->modalidad;
        $this->fecha_inicio   = isset($data['fecha_inicio'])   ? trim((string)$data['fecha_inicio'])   : $this->fecha_inicio;
        $this->fecha_fin      = isset($data['fecha_fin'])      ? trim((string)$data['fecha_fin'])      : $this->fecha_fin;
        $this->aprueba        = isset($data['aprueba'])        ? trim((string)$data['aprueba'])        : $this->aprueba;
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
        return $value === '' ? null : (string)(float)$value;
    }

    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'cedula'         => $this->cedula,
            'nombre'         => $this->nombre,
            'email'          => $this->email,
            'materia'        => $this->materia,
            'nota'           => $this->nota,
            'total'          => $this->total,
            'periodo'        => $this->periodo,
            'anio'           => $this->anio,
            'origen_tabla'   => $this->origen_tabla,
            'proceso'        => $this->proceso,
            'grupo_objetivo' => $this->grupo_objetivo,
            'modalidad'      => $this->modalidad,
            'fecha_inicio'   => $this->fecha_inicio,
            'fecha_fin'      => $this->fecha_fin,
            'aprueba'        => $this->aprueba,
            'created_at'     => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}