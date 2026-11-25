<?php

namespace App\Tests\Api;

use App\Entity\Memory;
use App\Entity\Picture;
use App\Entity\User;
use App\Enum\PictureStatus;
use App\Tests\Support\ApiTestCase;

class MemoryPicturesTest extends ApiTestCase
{
    // =============== Création / modification : types ===============

    private function creationPayload(int $locationId, int $placeId): array
    {
        return [
            'location' => ['id' => $locationId],
            'place' => ['id' => $placeId],
            'memory' => ['title' => 'Nouveau souvenir', 'content' => 'Un souvenir assez long', 'year' => 1980],
        ];
    }

    public function testCreationRefuseUnTypeInvalide(): void
    {
        $author = $this->createUser();
        $existing = $this->createMemory($author);
        $data = $this->creationPayload($existing->getLocation()->getId(), $existing->getPlace()->getId());

        $this->postMemoryForm('/api/secure/create/memory-and-place', $author, $data, [$this->pngFile()], [['type' => 'inconnu']]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('Type de photo invalide', $this->json()['error']);
    }

    public function testCreationRefuseUneDateDansLeFutur(): void
    {
        $author = $this->createUser();
        $existing = $this->createMemory($author);
        $data = $this->creationPayload($existing->getLocation()->getId(), $existing->getPlace()->getId());
        $data['memory'] = ['title' => 'Souvenir du futur', 'content' => 'Un souvenir assez long', 'picture_date' => (new \DateTime('+10 days'))->format('Y-m-d')];
        $before = $this->em->getRepository(Memory::class)->count([]);

        $this->postMemoryForm('/api/secure/create/memory-and-place', $author, $data, [$this->pngFile()], [['type' => 'before']]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('memory.picture_date', $this->json()['errors']);
        $this->assertSame($before, $this->em->getRepository(Memory::class)->count([]), 'aucun souvenir créé');
    }

    public function testCreationAccepteLaDateDuJour(): void
    {
        $author = $this->createUser();
        $existing = $this->createMemory($author);
        $data = $this->creationPayload($existing->getLocation()->getId(), $existing->getPlace()->getId());
        $data['memory'] = ['title' => 'Souvenir du jour', 'content' => 'Un souvenir assez long', 'picture_date' => (new \DateTime('today'))->format('Y-m-d')];

        $this->postMemoryForm('/api/secure/create/memory-and-place', $author, $data, [$this->pngFile()], [['type' => 'before']]);

        $this->assertResponseStatusCodeSame(201);
    }

    public function testCreationRefuseUnFichierQuiNEstPasUneImage(): void
    {
        $author = $this->createUser();
        $existing = $this->createMemory($author);
        $data = $this->creationPayload($existing->getLocation()->getId(), $existing->getPlace()->getId());
        $before = $this->em->getRepository(Memory::class)->count([]);

        // Texte renommé en .png : le contenu est lu, pas le nom
        $this->postMemoryForm('/api/secure/create/memory-and-place', $author, $data, [$this->pngFile(), $this->textFile()], [['type' => 'before'], ['type' => 'other']]);

        $this->assertResponseStatusCodeSame(422);
        $errors = $this->json()['errors'];
        $this->assertSame(['pictures.1'], array_keys($errors));
        $this->assertStringContainsString('image valide', $errors['pictures.1'][0]);
        $this->assertSame($before, $this->em->getRepository(Memory::class)->count([]), 'aucun souvenir créé');
    }

    public function testModificationRefuseUnFichierQuiNEstPasUneImage(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author);
        $data = ['memory' => ['title' => 'Le Panthéon', 'content' => 'Photo de famille devant le Panthéon', 'year' => 1950]];

        $this->postMemoryForm('/api/secure/update/memory-and-place/' . $memory->getId(), $author, $data, [$this->textFile()], [['type' => 'other']]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('pictures.0', $this->json()['errors']);
        $this->em->clear();
        $this->assertCount(1, $this->em->find(Memory::class, $memory->getId())->getPictures(), 'aucune photo ajoutée');
    }

    public function testCreationRefuseDeuxPhotosAfter(): void
    {
        $author = $this->createUser();
        $existing = $this->createMemory($author);
        $data = $this->creationPayload($existing->getLocation()->getId(), $existing->getPlace()->getId());

        $this->postMemoryForm(
            '/api/secure/create/memory-and-place',
            $author,
            $data,
            [$this->pngFile(), $this->pngFile()],
            [['type' => 'after'], ['type' => 'after']]
        );

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('une seule photo actuelle', $this->json()['error']);
    }

    public function testCreationAvecPhotoAfterEstApprouveeEtAttribueeAuxAuteur(): void
    {
        $author = $this->createUser();
        $existing = $this->createMemory($author);
        $data = $this->creationPayload($existing->getLocation()->getId(), $existing->getPlace()->getId());

        $this->postMemoryForm(
            '/api/secure/create/memory-and-place',
            $author,
            $data,
            [$this->pngFile(), $this->pngFile()],
            [['type' => 'before', 'position' => 0], ['type' => 'after', 'position' => 1]]
        );

        $this->assertResponseStatusCodeSame(201);
        $pictures = $this->json()['memory']['pictures'];
        $this->assertCount(2, $pictures);
        $this->assertSame('after', $pictures[1]['type']);
        $this->assertSame('approved', $pictures[1]['status']);
        $this->assertSame($author->getId(), $pictures[1]['uploadedBy']);
        $this->assertArrayNotHasKey('email', $pictures[1]);
    }

    public function testModificationRefuseUneSecondePhotoAfterEnCumulantAvecLExistante(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author, PictureStatus::APPROVED);
        $data = ['memory' => ['title' => 'Le Panthéon', 'content' => 'Photo de famille devant le Panthéon', 'year' => 1950]];

        $this->postMemoryForm('/api/secure/update/memory-and-place/' . $memory->getId(), $author, $data, [$this->pngFile()], [['type' => 'after']]);

        $this->assertResponseStatusCodeSame(409);
    }

    public function testModificationAutoriseUneNouvelleAfterSiLAncienneEstSupprimee(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author, PictureStatus::APPROVED);
        $afterId = $memory->getApprovedAfterPicture()->getId();
        $data = ['memory' => ['title' => 'Le Panthéon', 'content' => 'Photo de famille devant le Panthéon', 'year' => 1950]];

        $this->client->request('POST', '/api/secure/update/memory-and-place/' . $memory->getId(), [
            'data' => json_encode($data),
            'pictures_meta' => json_encode([['type' => 'after']]),
            'delete_pictures' => json_encode([$afterId]),
        ], ['pictures' => [$this->pngFile()]], $this->authHeaders($author));

        $this->assertResponseStatusCodeSame(200);
    }

    // =============== Retrait de photos par l'auteur ===============

    private function updatePayload(): array
    {
        return ['memory' => ['title' => 'Le Panthéon', 'content' => 'Photo de famille devant le Panthéon', 'year' => 1950]];
    }

    /** Modification avec retrait de photos (delete_pictures) et, éventuellement, de nouveaux fichiers. */
    private function updateRemoving(Memory $memory, User $user, array $deleteIds, array $files = [], array $meta = []): void
    {
        $this->client->request('POST', '/api/secure/update/memory-and-place/' . $memory->getId(), [
            'data' => json_encode($this->updatePayload()),
            'pictures_meta' => json_encode($meta),
            'delete_pictures' => json_encode($deleteIds),
        ], $files ? ['pictures' => $files] : [], $this->authHeaders($user));
    }

    private function pictureIds(Memory $memory): array
    {
        $this->em->clear();

        return array_map(fn (Picture $p) => $p->getId(), $this->em->find(Memory::class, $memory->getId())->getPictures()->toArray());
    }

    public function testLAuteurRetireUneDeSesPhotosEtLeFichierEstSupprime(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author, PictureStatus::APPROVED);
        $after = $memory->getApprovedAfterPicture();
        $afterId = $after->getId();
        $path = $this->storeFile($after);

        $this->updateRemoving($memory, $author, [$afterId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertNotContains($afterId, $this->pictureIds($memory));
        $this->assertFileDoesNotExist($path);
    }

    public function testLAuteurNeRetirePasLaPhotoEnvoyeeParUnAutre(): void
    {
        $author = $this->createUser();
        $contributor = $this->createUser();
        $memory = $this->createMemory($author, PictureStatus::APPROVED, $contributor);
        $afterId = $memory->getApprovedAfterPicture()->getId();
        $path = $this->storeFile($memory->getApprovedAfterPicture());

        $this->updateRemoving($memory, $author, [$afterId]);

        $this->assertResponseStatusCodeSame(403);
        $this->assertContains($afterId, $this->pictureIds($memory));
        $this->assertFileExists($path);
    }

    public function testUneDemandeMelangeeNeRetireRienSiUneSeulePhotoEstInterdite(): void
    {
        $author = $this->createUser();
        $contributor = $this->createUser();
        $memory = $this->createMemory($author, PictureStatus::APPROVED, $contributor);
        $before = $memory->getPictures()[0]->getId();
        $other = $this->createPicture('other.png', Picture::TYPE_OTHER, 2, PictureStatus::APPROVED, $author);
        $memory->addPicture($other);
        $this->em->flush();

        $this->updateRemoving($memory, $author, [$other->getId(), $memory->getApprovedAfterPicture()->getId()]);

        $this->assertResponseStatusCodeSame(403);
        $this->assertContains($other->getId(), $this->pictureIds($memory), 'rien n\'est retiré');
        $this->assertContains($before, $this->pictureIds($memory));
    }

    public function testLaDerniereDesPhotosAvantNePeutPasEtreRetiree(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author, PictureStatus::APPROVED);
        $beforeId = $memory->getPictures()[0]->getId();

        $this->updateRemoving($memory, $author, [$beforeId]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('delete_pictures', $this->json()['errors']);
        $this->assertContains($beforeId, $this->pictureIds($memory));
    }

    public function testLaPhotoAvantPeutEtreRemplaceeDansLaMemeModification(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author);
        $beforeId = $memory->getPictures()[0]->getId();

        $this->updateRemoving($memory, $author, [$beforeId], [$this->pngFile()], [['type' => 'before']]);

        $this->assertResponseStatusCodeSame(200);
        $ids = $this->pictureIds($memory);
        $this->assertNotContains($beforeId, $ids);
        $this->assertCount(1, $ids);
    }

    public function testUneAvantPeutEtreRetireeSiUneAutreAvantResteDansLeSouvenir(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author);
        $second = $this->createPicture('before2.png', Picture::TYPE_BEFORE, 1, PictureStatus::APPROVED, $author);
        $memory->addPicture($second);
        $this->em->flush();
        $firstId = $memory->getPictures()[0]->getId();

        $this->updateRemoving($memory, $author, [$firstId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame([$second->getId()], $this->pictureIds($memory));
    }

    public function testLaDerniereDesPhotosDeLaGalerieNePeutPasEtreRetireeMemeSansAvant(): void
    {
        $author = $this->createUser();
        // Souvenir sans photo « avant » : une photo « autre » et la photo actuelle
        $memory = $this->createMemory($author, PictureStatus::APPROVED);
        $other = $memory->getPictures()[0];
        $other->setType(Picture::TYPE_OTHER);
        $this->em->flush();
        $otherId = $other->getId();

        $this->updateRemoving($memory, $author, [$otherId]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('« avant » ou « autre »', $this->json()['errors']['delete_pictures'][0]);
        $this->assertContains($otherId, $this->pictureIds($memory));
    }

    public function testUnePhotoAutrePeutEtreRetireeSiUneAvantResteDansLaGalerie(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author);
        $other = $this->createPicture('other.png', Picture::TYPE_OTHER, 1, PictureStatus::APPROVED, $author);
        $memory->addPicture($other);
        $this->em->flush();
        $otherId = $other->getId();

        $this->updateRemoving($memory, $author, [$otherId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertNotContains($otherId, $this->pictureIds($memory));
    }

    public function testLaPhotoActuelleSeuleRestePossibleSiLeSouvenirNAvaitQueElle(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author, PictureStatus::APPROVED);
        $before = $memory->getPictures()[0];
        $before->setType(Picture::TYPE_OTHER);
        $this->em->flush();

        // Retirer la photo actuelle ne touche pas à la galerie
        $this->updateRemoving($memory, $author, [$memory->getApprovedAfterPicture()->getId()]);

        $this->assertResponseStatusCodeSame(200);
    }

    public function testUnAutreUtilisateurNePeutPasModifierLeSouvenir(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author, PictureStatus::APPROVED);

        $this->updateRemoving($memory, $this->createUser(), [$memory->getApprovedAfterPicture()->getId()]);

        $this->assertResponseStatusCodeSame(403);
    }

    // =============== Route current-picture ===============

    private function postCurrentPicture(int $memoryId, ?\App\Entity\User $user, ?array $files = null, array $params = []): void
    {
        $this->client->request(
            'POST',
            "/api/secure/memory/$memoryId/current-picture",
            $params,
            $files ?? ['picture' => $this->pngFile()],
            $user ? $this->authHeaders($user) : []
        );
    }

    public function testCurrentPictureDeLAuteurEstApprouvee(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author);

        $this->postCurrentPicture($memory->getId(), $author, null, ['credit' => '© Moi']);

        $this->assertResponseStatusCodeSame(201);
        $picture = $this->json()['picture'];
        $this->assertSame('approved', $picture['status']);
        $this->assertSame('after', $picture['type']);
        $this->assertSame(1, $picture['position']); // après la photo « avant » en position 0
        $this->assertSame('© Moi', $picture['copyrightHolder']);
        $this->assertSame($author->getId(), $picture['uploadedBy']);
        $this->assertNotEmpty($picture['imageUrl']);
    }

    public function testCurrentPictureDUnAutreUtilisateurEstEnAttente(): void
    {
        $memory = $this->createMemory($this->createUser());
        $other = $this->createUser();

        $this->postCurrentPicture($memory->getId(), $other);

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('pending', $this->json()['picture']['status']);
        $this->assertSame($other->getId(), $this->json()['picture']['uploadedBy']);
    }

    public function testCurrentPictureRemplacementToujoursEnAttenteMemePourLAuteur(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author, PictureStatus::APPROVED);
        $oldId = $memory->getApprovedAfterPicture()->getId();

        $this->postCurrentPicture($memory->getId(), $author);

        $this->assertResponseStatusCodeSame(201);
        $picture = $this->json()['picture'];
        $this->assertSame('pending', $picture['status']);
        $this->assertSame($oldId, $picture['replacesId']);
        $this->assertSame($author->getId(), $picture['uploadedBy']);

        // L'ancienne reste la photo actuelle approuvée
        $this->em->clear();
        $old = $this->em->find(Picture::class, $oldId);
        $this->assertSame(PictureStatus::APPROVED, $old->getStatus());
    }

    public function testCurrentPictureRemplacementNotifieLAncienDeposantSeulement(): void
    {
        $author = $this->createUser();
        $uploader = $this->createUser();
        $memory = $this->createMemory($author, PictureStatus::APPROVED, $uploader);

        // L'auteur propose : l'ancien déposant (un autre) est prévenu, pas l'auteur
        $this->postCurrentPicture($memory->getId(), $author);
        $this->assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/api/secure/notifications/comments', [], [], $this->authHeaders($uploader));
        $notifications = $this->json();
        $this->assertCount(1, $notifications);
        $this->assertSame('current_picture_replacement_proposed', $notifications[0]['type']);
        $this->assertSame($memory->getId(), $notifications[0]['memoryId']);
        $this->assertSame($memory->getTitle(), $notifications[0]['memoryTitle']);

        $this->client->request('GET', '/api/secure/notifications/comments', [], [], $this->authHeaders($author));
        $this->assertSame([], $this->json());
    }

    public function testCurrentPictureRemplacementSansNotificationSiMemeDeposant(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author, PictureStatus::APPROVED);

        $this->postCurrentPicture($memory->getId(), $author);
        $this->assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/api/secure/notifications/comments', [], [], $this->authHeaders($author));
        $this->assertSame([], $this->json());
    }

    public function testCurrentPictureRefuseUneSecondePropositionEnAttenteDuMemeUtilisateur(): void
    {
        $memory = $this->createMemory($this->createUser());
        $other = $this->createUser();

        $this->postCurrentPicture($memory->getId(), $other);
        $this->assertResponseStatusCodeSame(201);

        $this->postCurrentPicture($memory->getId(), $other);
        $this->assertResponseStatusCodeSame(409);

        // Un autre utilisateur peut proposer la sienne
        $this->postCurrentPicture($memory->getId(), $this->createUser());
        $this->assertResponseStatusCodeSame(201);
    }

    public function testCurrentPictureExigeUnJwt(): void
    {
        $memory = $this->createMemory($this->createUser());

        $this->postCurrentPicture($memory->getId(), null);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testCurrentPictureSurSouvenirInexistant(): void
    {
        $this->postCurrentPicture(999999, $this->createUser());

        $this->assertResponseStatusCodeSame(404);
    }

    public function testCurrentPictureValideLeFichier(): void
    {
        $user = $this->createUser();
        $memory = $this->createMemory($user);

        $this->postCurrentPicture($memory->getId(), $user, []);
        $this->assertResponseStatusCodeSame(400);

        $this->postCurrentPicture($memory->getId(), $user, ['picture' => $this->textFile()]);
        $this->assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('picture.', $this->json()['errors']);

        // Rien n'a été créé
        $this->assertNull($this->em->getRepository(Picture::class)->findOneBy(['type' => 'after']));
    }

    // =============== Filtrage des photos non approuvées ===============

    public function testLesRoutesPubliquesNExposentQueLesPhotosApprouvees(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author, PictureStatus::PENDING, $this->createUser());
        $memory->addPicture($this->createPicture('rejetee.png', Picture::TYPE_OTHER, 2, PictureStatus::REJECTED, $author));
        $this->em->flush();
        $id = $memory->getId();

        $names = fn (array $pictures) => array_column($pictures, 'imageName');

        $this->client->request('GET', "/api/memory/$id");
        $this->assertSame(['before.png'], $names($this->json()['pictures']));

        $this->client->request('GET', '/api/memories');
        $found = array_values(array_filter($this->json(), fn ($m) => $m['id'] === $id));
        $this->assertSame(['before.png'], $names($found[0]['pictures']));

        $this->client->request('GET', '/api/memorieswithtwopictures');
        $found = array_values(array_filter($this->json(), fn ($m) => $m['id'] === $id));
        $this->assertSame(['before.png'], $names($found[0]['pictures']));
        $this->assertSame('before.png', $found[0]['main_picture']);

        $this->client->request('GET', '/api/location/' . $memory->getLocation()->getId() . '/timeline');
        $this->assertSame(['before.png'], $names($this->json()['timeline'][0]['pictures']));

        $this->client->request('GET', '/api/place/' . $memory->getPlace()->getId() . '/timeline');
        $this->assertSame(['before.png'], $names($this->json()['timeline'][0]['pictures']));

        $this->client->request('GET', '/api/pictures');
        $this->assertNotContains('after.png', $names($this->json()));
        $this->assertNotContains('rejetee.png', $names($this->json()));
    }

    public function testLeDetailExposeLaPropositionEnAttenteSeulementAUsonAuteur(): void
    {
        $proposer = $this->createUser();
        $memory = $this->createMemory($this->createUser(), PictureStatus::PENDING, $proposer);
        $id = $memory->getId();

        $this->client->request('GET', "/api/memory/$id", [], [], $this->authHeaders($proposer));
        $pictures = $this->json()['pictures'];
        $this->assertSame(['before.png', 'after.png'], array_column($pictures, 'imageName'));
        $this->assertSame('pending', $pictures[1]['status']);
        $this->assertSame($proposer->getId(), $pictures[1]['uploadedBy']);

        // Un autre utilisateur connecté, l'auteur du souvenir et un anonyme ne la voient pas
        foreach ([$this->createUser(), $memory->getUser()] as $viewer) {
            $this->client->request('GET', "/api/memory/$id", [], [], $this->authHeaders($viewer));
            $this->assertSame(['before.png'], array_column($this->json()['pictures'], 'imageName'));
        }
        $this->client->request('GET', "/api/memory/$id");
        $this->assertSame(['before.png'], array_column($this->json()['pictures'], 'imageName'));
    }

    public function testLeContratExistantDesPhotosEstConserve(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author, PictureStatus::APPROVED);

        $this->client->request('GET', '/api/memory/' . $memory->getId());

        $picture = $this->json()['pictures'][0];
        foreach (['id', 'imageName', 'imageUrl', 'position', 'type', 'copyrightHolder', 'status', 'uploadedBy'] as $key) {
            $this->assertArrayHasKey($key, $picture);
        }
        $this->assertSame('/uploads/memories/before.png', $picture['imageUrl']);
    }
}
