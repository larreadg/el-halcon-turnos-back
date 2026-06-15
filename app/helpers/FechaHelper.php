<?php

declare(strict_types=1);

class FechaHelper
{
    public static function ahora(): string
    {
        return date('Y-m-d H:i:s');
    }

    public static function hoy(): string
    {
        return date('Y-m-d');
    }
}
