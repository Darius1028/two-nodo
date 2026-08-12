<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'REVINFO', schema: 'AUD')]
class RevisionInfo
{
    #[ORM\Id]
    #[ORM\Column(name: 'REV', type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private ?int $rev = null;

    /*
     * Doctrine maneja BIGINT como string para evitar pérdida de precisión.
     */
    #[ORM\Column(name: 'REVTSTMP', type: Types::BIGINT, nullable: true)]
    private ?string $revisionTimestamp;

    public function __construct()
    {
        $this->revisionTimestamp = (string) round(
            microtime(true) * 1000
        );
    }

    public function getRev(): ?int
    {
        return $this->rev;
    }

    public function getRevisionTimestamp(): ?string
    {
        return $this->revisionTimestamp;
    }
}