<?php

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="legacy_users")
 */
class LegacyEntity
{
    /**
     * @ORM\Column(type="integer")
     * @ORM\Id
     */
    private $id;
}
