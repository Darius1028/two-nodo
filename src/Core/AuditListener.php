<?php

declare(strict_types=1);

namespace App\Core;

use App\Entity\AcademicRecord;
use App\Entity\AcademicRecordAudit;
use App\Service\AcademicRecordAuditService;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Registra automáticamente cada cambio de AcademicRecord en el esquema AcademicoAUD.
 * La escritura se hace por DBAL para evitar reentrar al UnitOfWork durante
 * flush() y para compartir la misma transacción que la operación principal.
 */
class AuditListener
{
    public function getSubscribedEvents(): array
    {
        return [Events::postPersist, Events::postUpdate, Events::preRemove];
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->log($args->getObject(), $args, AcademicRecordAudit::INSERT);
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->log($args->getObject(), $args, AcademicRecordAudit::UPDATE);
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $this->log($args->getObject(), $args, AcademicRecordAudit::DELETE);
    }

    private function log(
        object $entity,
        PostPersistEventArgs|PostUpdateEventArgs|PreRemoveEventArgs $args,
        int $revisionType
    ): void {
        if (!$entity instanceof AcademicRecord || $entity->getId() === null) {
            return;
        }

        AcademicRecordAuditService::register(
            $entity,
            $revisionType,
            $args->getObjectManager()->getConnection()
        );
    }
}