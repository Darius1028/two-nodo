<?php
declare(strict_types=1);

namespace App\Core;

use App\Entity\AcademicRecord;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Escucha los eventos postPersist/postUpdate/postRemove de Doctrine para
 * AcademicRecord y guarda una fila en academic_records_audit con una foto
 * del registro. Se usa INSERT crudo por DBAL (no $em->persist()) porque
 * disparar el UnitOfWork dentro de un listener postX del mismo flush()
 * puede generar problemas de re-entrada; un INSERT directo es seguro
 * porque ya no toca el UnitOfWork.
 *
 * Reemplaza a simplethings/entity-audit (ver AcademicRecordAudit.php para
 * el porqué).
 */
class AuditListener
{
    /** @var callable():string */
    private $usernameResolver;

    public function __construct(callable $usernameResolver)
    {
        $this->usernameResolver = $usernameResolver;
    }

    public function getSubscribedEvents(): array
    {
        return [Events::postPersist, Events::postUpdate, Events::postRemove];
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->log($args->getObject(), $args, 'INS');
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->log($args->getObject(), $args, 'UPD');
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $this->log($args->getObject(), $args, 'DEL');
    }

    private function log(object $entity, PostPersistEventArgs|PostUpdateEventArgs|PostRemoveEventArgs $args, string $action): void
    {
        if (!$entity instanceof AcademicRecord) {
            return;
        }
        if ($entity->getId() === null) {
            return;
        }

        $connection = $args->getObjectManager()->getConnection();
        try {
            $connection->insert('academic_records_audit', [
                'record_id'  => $entity->getId(),
                'action'     => $action,
                'payload'    => json_encode($entity->toArray(), JSON_UNESCAPED_UNICODE) ?: '{}',
                'username'   => ($this->usernameResolver)(),
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // No queremos que un fallo de auditoría tumbe la operación
            // principal (ej. la tabla de auditoría aún no fue creada por
            // bin/console.php en un entorno recién levantado).
            error_log('AuditListener: no se pudo registrar auditoría: ' . $e->getMessage());
        }
    }
}
