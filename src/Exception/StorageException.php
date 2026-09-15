<?php
declare(strict_types=1);

namespace App\Exception;

/**
 * Error producido por el almacenamiento de objetos.
 *
 * El indicador retryable permite que procesos asíncronos distingan una
 * caída temporal de red/servidor de errores permanentes como un objeto que
 * ya no existe o una configuración inválida.
 */
final class StorageException extends SystemException
{
    public function __construct(
        string $message,
        private readonly bool $retryable = false,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
