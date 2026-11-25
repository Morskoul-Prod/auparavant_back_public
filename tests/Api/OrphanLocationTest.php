<?php

namespace App\Tests\Api;

use App\Entity\Location;
use App\Entity\Memory;
use App\Entity\Place;
use App\Service\OrphanLocationCleaner;
use App\Tests\Support\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class OrphanLocationTest extends ApiTestCase
{
    private function exists(string $class, ?int $id): bool
    {
        $this->em->clear();

        return $this->em->find($class, $id) !== null;
    }

    public function testSupprimerLeDernierSouvenirSupprimeLocationEtLieu(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author);
        $locationId = $memory->getLocation()->getId();
        $placeId = $memory->getPlace()->getId();

        $this->client->request('DELETE', '/api/secure/delete/memory/' . $memory->getId(), [], [], $this->authHeaders($author));

        $this->assertResponseIsSuccessful();
        $this->assertFalse($this->exists(Location::class, $locationId));
        $this->assertFalse($this->exists(Place::class, $placeId));
    }

    public function testSupprimerUnSouvenirGardeLaLocationSiUnAutreSouvenirReste(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author);
        $other = (new Memory())
            ->setUser($author)->setLocation($memory->getLocation())->setPlace($memory->getPlace())
            ->setTitle('Autre')->setContent('Un autre souvenir au même endroit')->setYear(1980);
        $other->addPicture($this->createPicture('o.png', 'before', 0, \App\Enum\PictureStatus::APPROVED, $author));
        $this->em->persist($other);
        $this->em->flush();
        $locationId = $memory->getLocation()->getId();
        $placeId = $memory->getPlace()->getId();

        $this->client->request('DELETE', '/api/secure/delete/memory/' . $memory->getId(), [], [], $this->authHeaders($author));

        $this->assertResponseIsSuccessful();
        $this->assertTrue($this->exists(Location::class, $locationId));
        $this->assertTrue($this->exists(Place::class, $placeId));
    }

    public function testDeplacerLeDernierSouvenirSupprimeLAncienneLocationSansSupprimerLeSouvenir(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author);
        $target = $this->createMemory($author);
        $memoryId = $memory->getId();
        $oldLocation = $memory->getLocation();
        $oldPlace = $memory->getPlace();
        $oldLocationId = $oldLocation->getId();

        // Chargement des collections comme le ferait une page déjà rendue
        $oldLocation->getMemories()->toArray();
        $memory->setLocation($target->getLocation())->setPlace($target->getPlace());

        $cleaner = static::getContainer()->get(OrphanLocationCleaner::class);
        $this->em->wrapInTransaction(function () use ($cleaner, $memory, $oldLocation, $oldPlace) {
            $this->em->flush();
            $cleaner->cleanAfterMove($memory, $oldLocation, $oldPlace);
            $this->em->flush();
        });

        $this->assertFalse($this->exists(Location::class, $oldLocationId));
        $this->assertTrue($this->exists(Memory::class, $memoryId));
        $this->assertTrue($this->exists(Location::class, $target->getLocation()->getId()));
    }

    public function testLaCommandePurgeLesLocationsSansSouvenir(): void
    {
        $memory = $this->createMemory($this->createUser());
        $orphan = (new Location())
            ->setArea('Bretagne')->setDepartment('Finistère')->setStreet('1 Rue Vide')
            ->setCity('Brest')->setZipcode(29200)->setLatitude('48.39')->setLongitude('-4.48');
        $this->em->persist($orphan);
        $this->em->flush();
        $orphanId = $orphan->getId();
        $keptId = $memory->getLocation()->getId();

        $application = new Application(static::getContainer()->get('kernel'));
        $tester = new CommandTester($application->find('app:locations:purge-orphans'));

        $tester->execute(['--dry-run' => true]);
        $this->assertTrue($this->exists(Location::class, $orphanId));

        $tester->execute([]);
        $this->assertFalse($this->exists(Location::class, $orphanId));
        $this->assertTrue($this->exists(Location::class, $keptId));
    }
}
