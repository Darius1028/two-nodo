<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Type\SqlServerDateTimeType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'RecordAcademico', schema: 'Academico')]
#[ORM\Index(columns: ['cedula'], name: 'IX_RecordAcademico_cedula')]
#[ORM\Index(columns: ['curso'], name: 'IX_RecordAcademico_curso')]
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
    private ?string $apellido = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $email = null;

    /** Nombre del curso. Columna canónica (reemplaza a [materia]). */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $curso = null;

    /**
     * @deprecated Usar $curso. Se mantiene poblada en espejo mientras dure la
     * transición porque la columna es NOT NULL en la tabla histórica y hay
     * ~200k filas cargadas con este campo.
     */
    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $materia = '';

    /**
     * Calificación del participante, escala 0-100 (aprueba desde 80).
     * Conserva el nombre [total] por requerimiento funcional.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    private ?string $total = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $periodo = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $anio = 0;

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $proceso = null;

    // FIX Nomenclatura: Se cambia a camelCase pero se mantiene mapeo a BD
    #[ORM\Column(name: 'grupo_objetivo', type: Types::STRING, length: 200, nullable: true)]
    private ?string $grupoObjetivo = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $modalidad = null;

    // FIX Nomenclatura: Se cambia a camelCase pero se mantiene mapeo a BD
    #[ORM\Column(name: 'nro_horas', type: Types::SMALLINT, nullable: true)]
    private ?int $nroHoras = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $genero = null;

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $tipo = null;

    #[ORM\Column(type: Types::STRING, length: 150, nullable: true)]
    private ?string $cargo = null;

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $provincia = null;

    // FIX Nomenclatura: Se cambia a camelCase pero se mantiene mapeo a BD
    #[ORM\Column(name: 'fecha_inicio', type: Types::STRING, length: 20, nullable: true)]
    private ?string $fechaInicio = null;

    // FIX Nomenclatura: Se cambia a camelCase pero se mantiene mapeo a BD
    #[ORM\Column(name: 'fecha_fin', type: Types::STRING, length: 20, nullable: true)]
    private ?string $fechaFin = null;

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

    public function getApellido(): ?string
    {
        return $this->apellido;
    }

    public function getNombreCompleto(): string
    {
        $apellido = trim((string) $this->apellido);

        if ($apellido === '' || stripos($this->nombre, $apellido) !== false) {
            return trim($this->nombre);
        }

        return trim($this->nombre . ' ' . $apellido);
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getCurso(): ?string
    {
        return $this->curso ?? ($this->materia !== '' ? $this->materia : null);
    }

    /** @deprecated Usar getCurso(). */
    public function getMateria(): string
    {
        return $this->materia;
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

    public function getProceso(): ?string
    {
        return $this->proceso;
    }

    public function getGrupoObjetivo(): ?string
    {
        return $this->grupoObjetivo;
    }

    public function getModalidad(): ?string
    {
        return $this->modalidad;
    }

    public function getNroHoras(): ?int
    {
        return $this->nroHoras;
    }

    public function getGenero(): ?string
    {
        return $this->genero;
    }

    public function getTipo(): ?string
    {
        return $this->tipo;
    }

    public function getCargo(): ?string
    {
        return $this->cargo;
    }

    public function getProvincia(): ?string
    {
        return $this->provincia;
    }

    public function getFechaInicio(): ?string
    {
        return $this->fechaInicio;
    }

    public function getFechaFin(): ?string
    {
        return $this->fechaFin;
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

    public function setAuditoriaCreacion(int $idPersona, string $ip, string $equipo): void
    {
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

    public function setAuditoriaModificacion(int $idPersona, string $ip, string $equipo, string $motivo): void
    {
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
            throw new \InvalidArgumentException('El estado debe ser A, I o X.');
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

    // ============================================================
    // Refactorización: Métodos Auxiliares para Reducir Complejidad
    // ============================================================

    private function updateString(array $data, string $key, ?string $current): ?string
    {
        return array_key_exists($key, $data) ? $this->nullableString($data[$key]) : $current;
    }

    private function updateNroHoras(array $data, ?int $current): ?int
    {
        if (!array_key_exists('nro_horas', $data)) {
            return $current;
        }

        $horas = $data['nro_horas'];
        if ($horas === null || $horas === '') {
            return null;
        }

        $horas = preg_replace('/[^0-9\-]/', '', (string) $horas);
        return ($horas === '' || $horas === '-') ? null : (int) $horas;
    }

    // ============================================================
    // FIN Métodos Auxiliares
    // ============================================================

    public function fill(array $data): void
    {
        // Campos fijos y legados
        $this->cedula = trim((string) ($data['cedula'] ?? $this->cedula));
        $this->nombre = trim((string) ($data['nombre'] ?? $this->nombre));
        $this->anio   = (int) ($data['anio'] ?? $this->anio);
        $this->total  = $this->toDecimalString($data['total'] ?? ($data['nota'] ?? $this->total));

        // Manejo de curso/materia
        if (array_key_exists('curso', $data)) {
            $this->curso = $this->nullableString($data['curso']);
        } elseif (array_key_exists('materia', $data)) {
            $this->curso = $this->nullableString($data['materia']);
        }
        $this->materia = trim((string) ($this->curso ?? $this->materia));

        // Asignaciones directas delegadas para aplanar la complejidad
        $this->apellido      = $this->updateString($data, 'apellido', $this->apellido);
        $this->email         = $this->updateString($data, 'email', $this->email);
        $this->periodo       = $this->updateString($data, 'periodo', $this->periodo);
        $this->proceso       = $this->updateString($data, 'proceso', $this->proceso);
        $this->grupoObjetivo = $this->updateString($data, 'grupo_objetivo', $this->grupoObjetivo);
        $this->modalidad     = $this->updateString($data, 'modalidad', $this->modalidad);
        $this->nroHoras      = $this->updateNroHoras($data, $this->nroHoras);
        $this->genero        = $this->updateString($data, 'genero', $this->genero);
        $this->tipo          = $this->updateString($data, 'tipo', $this->tipo);
        $this->cargo         = $this->updateString($data, 'cargo', $this->cargo);
        $this->provincia     = $this->updateString($data, 'provincia', $this->provincia);
        $this->fechaInicio   = $this->updateString($data, 'fecha_inicio', $this->fechaInicio);
        $this->fechaFin      = $this->updateString($data, 'fecha_fin', $this->fechaFin);
        $this->aprueba       = $this->updateString($data, 'aprueba', $this->aprueba);
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
        return $value === '' ? null : number_format((float) $value, 2, '.', '');
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'cedula' => $this->cedula,
            'nombre' => $this->nombre,
            'apellido' => $this->apellido,
            'email' => $this->email,
            'curso' => $this->getCurso(),
            'materia' => $this->materia,
            'total' => $this->total,
            'periodo' => $this->periodo,
            'anio' => $this->anio,
            'proceso' => $this->proceso,
            // Los keys del array se mantienen para no romper API o JSON responses,
            // pero acceden a las nuevas variables camelCase.
            'grupo_objetivo' => $this->grupoObjetivo,
            'modalidad' => $this->modalidad,
            'nro_horas' => $this->nroHoras,
            'genero' => $this->genero,
            'tipo' => $this->tipo,
            'cargo' => $this->cargo,
            'provincia' => $this->provincia,
            'fecha_inicio' => $this->fechaInicio,
            'fecha_fin' => $this->fechaFin,
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