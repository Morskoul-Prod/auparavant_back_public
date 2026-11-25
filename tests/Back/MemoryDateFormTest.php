<?php

namespace App\Tests\Back;

use App\Entity\Memory;
use App\Tests\Support\ApiTestCase;

/**
 * Formulaire de souvenir du back-office : date exacte ou année seule, au choix.
 */
class MemoryDateFormTest extends ApiTestCase
{
    /** Soumet le formulaire de modification ; `null` laisse le champ tel qu'il est affiché */
    private function edit(Memory $memory, ?string $date, ?string $year): void
    {
        $this->client->loginUser($this->createUser(['ROLE_ADMIN']));
        $crawler = $this->client->request('GET', '/back/memory/' . $memory->getId() . '/edit');
        $form = $crawler->filter('form[name=memory]')->form();
        if ($date !== null) {
            $form['memory[picture_date]'] = $date;
        }
        if ($year !== null) {
            $form['memory[year]'] = $year;
        }
        $this->client->submit($form);
    }

    private function reload(int $id): Memory
    {
        $this->em->clear();

        return $this->em->find(Memory::class, $id);
    }

    private function memoryWithDate(string $date): Memory
    {
        $memory = $this->createMemory($this->createUser());
        $memory->setPictureDate(new \DateTime($date));
        $this->em->flush();

        return $memory;
    }

    public function testLeFormulaireProposeLesDeuxOptions(): void
    {
        $memory = $this->createMemory($this->createUser());
        $this->client->loginUser($this->createUser(['ROLE_ADMIN']));
        $crawler = $this->client->request('GET', '/back/memory/' . $memory->getId() . '/edit');

        $this->assertSame(1, $crawler->filter('input[name="memory[picture_date]"][type=date]')->count());
        $this->assertSame(1, $crawler->filter('input[name="memory[year]"][type=number]')->count());
        $this->assertSame('1950', $crawler->filter('input[name="memory[year]"]')->attr('value'));
    }

    public function testDateExacteEnregistreEtDeduitLAnnee(): void
    {
        $memory = $this->createMemory($this->createUser());
        $id = $memory->getId();

        $this->edit($memory, '1987-06-15', '');

        $this->assertResponseRedirects('/back/memory/');
        $fresh = $this->reload($id);
        $this->assertSame('1987-06-15', $fresh->getPictureDate()->format('Y-m-d'));
        $this->assertSame(1987, $fresh->getEffectiveYear());
    }

    public function testAnneeSeuleEnregistree(): void
    {
        $memory = $this->createMemory($this->createUser());
        $id = $memory->getId();

        $this->edit($memory, '', '2000');

        $this->assertResponseRedirects('/back/memory/');
        $fresh = $this->reload($id);
        $this->assertNull($fresh->getPictureDate());
        $this->assertSame(2000, $fresh->getEffectiveYear());
    }

    public function testViderLaDatePasseEnAnneeSeule(): void
    {
        $memory = $this->memoryWithDate('1987-06-15');
        $id = $memory->getId();

        $this->edit($memory, '', null); // l'année affichée (1987) devient l'année du souvenir

        $this->assertResponseRedirects('/back/memory/');
        $fresh = $this->reload($id);
        $this->assertNull($fresh->getPictureDate());
        $this->assertSame(1987, $fresh->getEffectiveYear());
    }

    public function testVideLaDatePuisChangeLAnnee(): void
    {
        $memory = $this->memoryWithDate('1987-06-15');
        $id = $memory->getId();

        $this->edit($memory, '', '1990');

        $fresh = $this->reload($id);
        $this->assertNull($fresh->getPictureDate());
        $this->assertSame(1990, $fresh->getEffectiveYear());
    }

    public function testLaDateExacteEstConserveeSiRienNeChange(): void
    {
        $memory = $this->memoryWithDate('1987-06-15');
        $id = $memory->getId();

        $this->edit($memory, null, null);

        $this->assertResponseRedirects('/back/memory/');
        $this->assertSame('1987-06-15', $this->reload($id)->getPictureDate()->format('Y-m-d'));
    }

    public function testLaDatePrimeSurLAnnee(): void
    {
        $memory = $this->memoryWithDate('1987-06-15');
        $id = $memory->getId();

        $this->edit($memory, '1992-01-02', null); // l'année affichée (1987) est périmée : la date prime

        $this->assertResponseRedirects('/back/memory/');
        $fresh = $this->reload($id);
        $this->assertSame('1992-01-02', $fresh->getPictureDate()->format('Y-m-d'));
        $this->assertSame(1992, $fresh->getEffectiveYear());
    }

    public function testAucuneDateNiAnneeEstRefuse(): void
    {
        $memory = $this->createMemory($this->createUser());
        $id = $memory->getId();

        $this->edit($memory, '', '');

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'Indiquez une date exacte ou, à défaut, une année.');
        $this->assertSame(1950, $this->reload($id)->getEffectiveYear());
    }

    public function testUneAnneeHorsLimitesEstRefusee(): void
    {
        $memory = $this->createMemory($this->createUser());
        $id = $memory->getId();

        $this->edit($memory, '', '1200');

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'L\'année doit être comprise entre');
        $this->assertSame(1950, $this->reload($id)->getEffectiveYear());
    }

    public function testUneDateDansLeFuturEstRefusee(): void
    {
        $memory = $this->createMemory($this->createUser());
        $id = $memory->getId();

        $this->edit($memory, (new \DateTimeImmutable('+10 days'))->format('Y-m-d'), '');

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'ne peut pas être dans le futur');
        $this->assertSame(1950, $this->reload($id)->getEffectiveYear());
    }

    /** @param array<string, string> $dates champs `picture_date` et/ou `year` envoyés */
    private function create(string $title, array $dates): ?Memory
    {
        $author = $this->createUser();
        $model = $this->createMemory($author); // fournit une Location et un Lieu existants
        $this->client->loginUser($this->createUser(['ROLE_ADMIN']));
        $crawler = $this->client->request('GET', '/back/memory/new');
        $token = $crawler->filter('input[name="memory[_token]"]')->attr('value');

        $this->client->request('POST', '/back/memory/new', [
            'memory' => $dates + [
                'title' => $title,
                'content' => 'Une description',
                'user' => $author->getId(),
                'location' => $model->getLocation()->getId(),
                'place' => $model->getPlace()->getId(),
                'pictures' => [['type' => 'before']],
                '_token' => $token,
            ],
        ], ['memory' => ['pictures' => [['imageFile' => ['file' => $this->pngFile()]]]]]);

        return $this->em->getRepository(Memory::class)->findOneBy(['title' => $title]);
    }

    public function testCreerUnSouvenirAvecUneAnneeSeule(): void
    {
        $created = $this->create('Souvenir sans date', ['year' => '2000']);

        $this->assertResponseRedirects();
        $this->assertSame(2000, $created->getEffectiveYear());
        $this->assertNull($created->getPictureDate());
    }

    public function testCreerUnSouvenirAvecUneDateComplete(): void
    {
        $created = $this->create('Souvenir daté', ['picture_date' => '1987-06-15']);

        $this->assertResponseRedirects();
        $this->assertSame('1987-06-15', $created->getPictureDate()->format('Y-m-d'));
        $this->assertSame(1987, $created->getEffectiveYear());
    }

    public function testCreerSansDateNiAnneeEstRefuse(): void
    {
        $created = $this->create('Souvenir sans rien', []);

        $this->assertResponseStatusCodeSame(422);
        $this->assertNull($created);
    }
}
