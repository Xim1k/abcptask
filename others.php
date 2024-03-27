<?php

namespace Test;

use Exception;

abstract class Status
{
    public const COMPLETED = 0;
    public const PENDING = 1;
    public const REJECTED = 2;

    public static function getStatus(int $statusId): string
    {
        $statuses = [
            self::COMPLETED => 'Completed',
            self::PENDING => 'Pending',
            self::REJECTED => 'Rejected',
        ];

        return $statuses[$statusId] ?: throw new Exception('Status doesn\'t exists');
    }
}

class NotificationEvents
{
    public const CHANGE_RETURN_STATUS = 'changeReturnStatus';
    public const NEW_RETURN_STATUS    = 'newReturnStatus';
}