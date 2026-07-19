<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\RequestContext;
use Doctrine\ORM\EntityManagerInterface;

class EntityManagerProvider
{
    private static ?EntityManagerInterface $instance = null;

    public static function get(): EntityManagerInterface
    {
        if (self::$instance === null) {
            /** @var EntityManagerInterface $em */
            $em = require __DIR__ . '/../../config/doctrine.php';
            self::$instance = $em;
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}