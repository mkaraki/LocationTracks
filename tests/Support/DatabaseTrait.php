<?php

namespace App\Tests\Support;

use App\Entity\LocationSource;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

trait DatabaseTrait
{
    protected function prepareDatabase(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        // Refuse schema creation unless this is our disposable in-memory connection.
        self::assertTrue($em->getConnection()->getParams()['memory'] ?? false);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());

        return $em;
    }

    protected function createSource(EntityManagerInterface $em, string $username = 'phone'): LocationSource
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())->setEmail($username.'@example.com');
        $user->setPasswordHash($hasher->hashPassword($user, 'owner-password'));
        $source = (new LocationSource())
            ->setUser($user)
            ->setSourceType(1)
            ->setName('OwnTracks phone')
            ->setAuthUsername($username)
            ->setCreatedAt(new \DateTimeImmutable());
        $source->setAuthPasswordHash($hasher->hashPassword($source, 'submit-password'));
        $em->persist($user);
        $em->persist($source);
        $em->flush();

        return $source;
    }
}
