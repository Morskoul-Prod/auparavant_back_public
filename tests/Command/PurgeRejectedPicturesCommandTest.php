<?php

namespace App\Tests\Command;

use App\Entity\Picture;
use App\Enum\PictureStatus;
use App\Tests\Support\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class PurgeRejectedPicturesCommandTest extends ApiTestCase
{
    private function refus(string $moderatedAt, PictureStatus $status = PictureStatus::REJECTED): Picture
    {
        $memory = $this->createMemory($this->createUser(), $status, $this->createUser());
        $picture = $memory->getPictures()->last();
        $picture->setImageName(uniqid('refus-') . '.png'); // un fichier par photo
        $picture->setModeratedAt(new \DateTimeImmutable($moderatedAt));
        $this->em->flush();
        $this->storeFile($picture);

        return $picture;
    }

    private function purge(array $options = []): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:pictures:purge-rejected'));
        $tester->execute($options);

        return $tester;
    }

    private function chemin(Picture $picture): string
    {
        return static::getContainer()->getParameter('kernel.project_dir') . '/var/test-uploads/' . $picture->getImageName();
    }

    public function testPurgeA30JoursLignesEtFichiersVusOuNon(): void
    {
        $a29 = $this->refus('-29 days');
        $a31 = $this->refus('-31 days');
        $vu = $this->refus('-45 days');
        $vu->setRejectionSeenAt(new \DateTimeImmutable('-44 days'));
        $approuvee = $this->refus('-40 days', PictureStatus::APPROVED);
        $enAttente = $this->refus('-40 days', PictureStatus::PENDING);
        $this->em->flush();
        $chemins = array_map($this->chemin(...), [$a29, $a31, $vu, $approuvee, $enAttente]);
        $ids = array_map(fn (Picture $p) => $p->getId(), [$a29, $a31, $vu, $approuvee, $enAttente]);

        $dry = $this->purge(['--dry-run' => true]);
        $this->assertStringContainsString('2 photo(s) à supprimer', $dry->getDisplay());
        $this->assertFileExists($chemins[1]);

        $this->assertStringContainsString('2 photo(s) supprimée(s)', $this->purge()->getDisplay());
        $this->em->clear();
        // 29 jours, approuvée et en attente : conservées ; 31 jours et « vu » à 45 jours : fichier et ligne supprimés
        $this->assertFileExists($chemins[0]);
        $this->assertFileDoesNotExist($chemins[1]);
        $this->assertFileDoesNotExist($chemins[2]);
        $this->assertFileExists($chemins[3]);
        $this->assertFileExists($chemins[4]);
        $this->assertNotNull($this->em->find(Picture::class, $ids[0]));
        $this->assertNull($this->em->find(Picture::class, $ids[1]));
        $this->assertNull($this->em->find(Picture::class, $ids[2]));
        $this->assertNotNull($this->em->find(Picture::class, $ids[3]));
        $this->assertNotNull($this->em->find(Picture::class, $ids[4]));

        // Idempotente
        $this->assertStringContainsString('0 photo(s) supprimée(s)', $this->purge()->getDisplay());
    }

    public function testPurgeToleraFichierDejaAbsentEtPierreTombale(): void
    {
        $sansFichier = $this->refus('-40 days');
        unlink($this->chemin($sansFichier));
        $tombale = $this->refus('-40 days');
        $tombale->setImageName(null);
        $this->em->flush();
        $id1 = $sansFichier->getId();
        $id2 = $tombale->getId();

        $tester = $this->purge();
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('2 photo(s) supprimée(s)', $tester->getDisplay());
        $this->em->clear();
        $this->assertNull($this->em->find(Picture::class, $id1));
        $this->assertNull($this->em->find(Picture::class, $id2));
    }

    public function testOptionDaysInvalide(): void
    {
        $this->assertSame(2, $this->purge(['--days' => 'abc'])->getStatusCode());
    }
}
