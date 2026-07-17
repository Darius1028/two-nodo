<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\DateTimeType;

final class SqlServerDateTimeType extends DateTimeType
{
    public const NAME = 'sqlserver_datetime';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getSQLDeclaration(
        array $column,
        AbstractPlatform $platform
    ): string {
        return 'DATETIME';
    }

    public function convertToDatabaseValue(
        mixed $value,
        AbstractPlatform $platform
    ): ?string {
        if ($value === null) {
            return null;
        }

        if (!$value instanceof DateTimeInterface) {
            throw ConversionException::conversionFailedInvalidType(
                $value,
                self::NAME,
                ['null', DateTimeInterface::class]
            );
        }

        // DATETIME admite milisegundos, no los 6 dígitos de DATETIME2.
        // La T hace que el valor sea ISO 8601 e independiente de DATEFORMAT.
        return $value->format('Y-m-d\TH:i:s.v');
    }
}