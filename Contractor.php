<?php

namespace Test;

/**
 * @property Seller $seller
 */
class Contractor
{
    public const TYPE_CUSTOMER = 0;
    public readonly int $id;
    public readonly int $type;
    public readonly int $name;

    public static function getById(int $resellerId): static
    {
        // mock data
        return new self($resellerId, self::TYPE_CUSTOMER, 'test');
    }

    public function getFullName(): string
    {
        return sprintf('%s %d', $this->name, $this->id);
    }
}