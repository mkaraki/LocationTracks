<?php

namespace App\Tests\Unit\Entity;

use App\Entity\LocationSource;
use PHPUnit\Framework\TestCase;

class LocationSourceTest extends TestCase
{
    public function testDisabledSourceLosesSubmitterRole(): void
    {
        $source = new LocationSource();
        self::assertSame(['ROLE_SUBMITTER'], $source->getRoles());

        $source->setDeletedAt(new \DateTime());
        self::assertSame([], $source->getRoles());

        $source->setDeletedAt(null);
        self::assertSame(['ROLE_SUBMITTER'], $source->getRoles());
    }

    public function testSettingRolesCannotGrantAdditionalPrivilegesOrReenableSource(): void
    {
        $source = new LocationSource();
        $source->setRoles(['ROLE_ADMIN', 'ROLE_USER']);
        self::assertSame(['ROLE_SUBMITTER'], $source->getRoles());

        $source->setDeletedAt(new \DateTime());
        $source->setRoles(['ROLE_SUBMITTER']);
        self::assertSame([], $source->getRoles());
    }
}
