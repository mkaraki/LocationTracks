<?php

namespace App\Tests\Integration;

use App\Tests\Support\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

class SourceAuthenticationTest extends KernelTestCase
{
    use DatabaseTrait;

    public function testClearedCredentialsCannotBeLoadedBySecurityProvider(): void
    {
        self::bootKernel();
        $em = $this->prepareDatabase();
        $source = $this->createSource($em);
        $provider = static::getContainer()->get('security.user.provider.concrete.app_submitter_user_provider');
        self::assertSame($source->getId(), $provider->loadUserByIdentifier('phone')->getId());

        $source->setDeletedAt(new \DateTime())->setAuthUsername(null)->setAuthPasswordHash(null);
        $em->flush();
        $em->clear();

        $this->expectException(UserNotFoundException::class);
        $provider->loadUserByIdentifier('phone');
    }
}
