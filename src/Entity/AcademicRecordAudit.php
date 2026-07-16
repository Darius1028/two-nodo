<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tabla de auditoría propia para AcademicRecord.
 *
 * Reemplaza a simplethings/entity-audit, que no tiene releases estables
 * publicados (solo dev-master, sin actualizaciones desde 2017) y exige
 * doctrine/orm ~2.5 / doctrine/dbal ~2.5, versiones incompatibles con las
 * que usa este proyecto (doctrine/orm ^2.17, doctrine/dbal ^3.7). En vez de
 * downgradear Doctrine (lo que rompería el mapeo por atributos PHP 8 de
 * AcademicRecord), se implementa un log de auditoría simple y propio.
 */
#[ORM\Entity]
#[ORM\Table(name: 'academic_records_audit')]
#[ORM\Index(columns: ['record_id'], name: 'idx_audit_record_id')]
#[ORM\Index(columns: ['created_at'], name: 'idx_audit_created_at')]
class AcademicRecordAudit
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private ?int $id = null;

    #[ORM\Column(name: 'record_id', type: Types::INTEGER)]
    private int $recordId;

    /** 'INS' | 'UPD' | 'DEL' */
    #[ORM\Column(name: 'action', type: Types::STRING, length: 3)]
    private string $action;

    /** Foto completa del registro (AcademicRecord::toArray()) en JSON. */
    #[ORM\Column(name: 'payload', type: Types::TEXT)]
    private string $payload;

    #[ORM\Column(name: 'username', type: Types::STRING, length: 255)]
    private string $username;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    public function __construct(int $recordId, string $action, array $payload, string $username)
    {
        $this->recordId = $recordId;
        $this->action = $action;
        $this->payload = json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}';
        $this->username = $username;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getRecordId(): int { return $this->recordId; }
    public function getAction(): string { return $this->action; }
    public function getPayload(): array { return json_decode($this->payload, true) ?? []; }
    public function getUsername(): string { return $this->username; }
    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
}
