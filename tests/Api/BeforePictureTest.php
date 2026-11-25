<?php

namespace App\Tests\Api;

use App\Entity\Memory;
use App\Entity\Picture;
use App\Entity\User;
use App\Enum\PictureStatus;
use App\Tests\Support\ApiTestCase;

/**
 * Choix de la photo « avant » du comparateur : à la proposition d'une photo actuelle, et par l'auteur en modification.
 */
class BeforePictureTest extends ApiTestCase
{
    /** Souvenir dont la seule photo est une photo « autre » (donc sans photo « avant »). Renvoie [souvenir, id de cette photo]. */
    private function memoryWithoutBefore(User $author): array
    {
        $memory = $this->createMemory($author);
        $other = $memory->getPictures()[0];
        $other->setType(Picture::TYPE_OTHER);
        $this->em->flush();

        return [$memory, $other->getId()];
    }

    /** Ajoute une photo approuvée au souvenir et renvoie son id. */
    private function addPicture(Memory $memory, string $type, ?User $uploader = null): int
    {
        $picture = $this->createPicture('p' . uniqid() . '.png', $type, 5, PictureStatus::APPROVED, $uploader);
        $memory->addPicture($picture);
        $this->em->flush();

        return $picture->getId();
    }

    private function proposeCurrent(Memory $memory, User $user, array $params): void
    {
        $this->client->request('POST', '/api/secure/memory/' . $memory->getId() . '/current-picture', $params, ['picture' => $this->pngFile()], $this->authHeaders($user));
    }

    private function typeOf(int $pictureId): string
    {
        $this->em->clear();

        return $this->em->find(Picture::class, $pictureId)->getType();
    }

    private function updateMemory(Memory $memory, User $user, array $params): void
    {
        $data = ['memory' => ['title' => 'Le Panthéon', 'content' => 'Photo de famille devant le Panthéon', 'year' => 1950]];
        $this->client->request('POST', '/api/secure/update/memory-and-place/' . $memory->getId(), ['data' => json_encode($data)] + $params, [], $this->authHeaders($user));
    }

    // =============== Proposition d'une photo actuelle ===============

    public function testLAuteurChoisitLaPhotoAvantEnAjoutantLaPhotoActuelle(): void
    {
        $author = $this->createUser();
        [$memory, $otherId] = $this->memoryWithoutBefore($author);

        $this->proposeCurrent($memory, $author, ['before_picture_id' => (string) $otherId]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('approved', $this->json()['picture']['status']);
        $this->assertSame(Picture::TYPE_BEFORE, $this->typeOf($otherId), 'appliqué tout de suite');
    }

    public function testUnContributeurSuggereLaPhotoAvantSansLaModifier(): void
    {
        $author = $this->createUser();
        [$memory, $otherId] = $this->memoryWithoutBefore($author);

        $this->proposeCurrent($memory, $this->createUser(), ['before_picture_id' => (string) $otherId]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('pending', $this->json()['picture']['status']);
        $this->assertSame($otherId, $this->json()['picture']['suggestedBeforeId']);
        $this->assertSame(Picture::TYPE_OTHER, $this->typeOf($otherId), 'en attente de la validation du modérateur');
    }

    public function testSansChoixLaPropositionResteInchangee(): void
    {
        $author = $this->createUser();
        [$memory, $otherId] = $this->memoryWithoutBefore($author);

        $this->proposeCurrent($memory, $this->createUser(), []);

        $this->assertResponseStatusCodeSame(201);
        $this->assertNull($this->json()['picture']['suggestedBeforeId']);
        $this->assertSame(Picture::TYPE_OTHER, $this->typeOf($otherId));
    }

    public function testUneSuggestionInvalideEstRefuseeSansCreerLaPhoto(): void
    {
        $author = $this->createUser();
        [$memory] = $this->memoryWithoutBefore($author);
        [, $foreignId] = $this->memoryWithoutBefore($this->createUser());
        $pictures = $memory->getPictures()->count();

        foreach (['999999', 'abc', (string) $foreignId] as $invalid) {
            $this->proposeCurrent($memory, $author, ['before_picture_id' => $invalid]);

            $this->assertResponseStatusCodeSame(422, 'valeur ' . $invalid);
            $this->assertArrayHasKey('before_picture_id', $this->json()['errors']);
        }
        $this->em->clear();
        $this->assertCount($pictures, $this->em->find(Memory::class, $memory->getId())->getPictures());
    }

    public function testLaPhotoActuelleNePeutPasEtreSuggereeCommeAvant(): void
    {
        $author = $this->createUser();
        [$memory] = $this->memoryWithoutBefore($author);
        $afterId = $this->addPicture($memory, Picture::TYPE_AFTER, $author);

        $this->proposeCurrent($memory, $this->createUser(), ['before_picture_id' => (string) $afterId]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testPasDeSuggestionSiLeSouvenirALDejaUnePhotoAvant(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author);
        $otherId = $this->addPicture($memory, Picture::TYPE_OTHER, $author);

        $this->proposeCurrent($memory, $this->createUser(), ['before_picture_id' => (string) $otherId]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('possède déjà une photo « avant »', $this->json()['errors']['before_picture_id'][0]);
    }

    // =============== Choix de l'auteur en modification ===============

    public function testLAuteurChangeLaPhotoAvantEnModification(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author);
        $beforeId = $memory->getPictures()[0]->getId();
        $otherId = $this->addPicture($memory, Picture::TYPE_OTHER, $author);

        $this->updateMemory($memory, $author, ['before_picture_id' => (string) $otherId]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame(Picture::TYPE_BEFORE, $this->typeOf($otherId));
        $this->assertSame(Picture::TYPE_OTHER, $this->typeOf($beforeId), 'une seule photo avant : l\'ancienne repasse en autre');
    }

    public function testLAuteurChoisitLaNouvelleAvantEtRetireLAncienneEnUneFois(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author);
        $beforeId = $memory->getPictures()[0]->getId();
        $otherId = $this->addPicture($memory, Picture::TYPE_OTHER, $author);

        $this->updateMemory($memory, $author, ['before_picture_id' => (string) $otherId, 'delete_pictures' => json_encode([$beforeId])]);

        $this->assertResponseStatusCodeSame(200);
        $this->em->clear();
        $this->assertNull($this->em->find(Picture::class, $beforeId));
        $this->assertSame(Picture::TYPE_BEFORE, $this->em->find(Picture::class, $otherId)->getType());
    }

    public function testLaPhotoAvantChoisieNePeutPasEtreRetireeNiEtreLaPhotoActuelle(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author);
        $otherId = $this->addPicture($memory, Picture::TYPE_OTHER, $author);
        $afterId = $this->addPicture($memory, Picture::TYPE_AFTER, $author);

        $this->updateMemory($memory, $author, ['before_picture_id' => (string) $otherId, 'delete_pictures' => json_encode([$otherId])]);
        $this->assertResponseStatusCodeSame(422);

        $this->updateMemory($memory, $author, ['before_picture_id' => (string) $afterId]);
        $this->assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('before_picture_id', $this->json()['errors']);
        $this->assertSame(Picture::TYPE_AFTER, $this->typeOf($afterId));
    }

    // =============== Suppression directe d'une photo : mêmes règles ===============

    public function testLaSuppressionDirecteRefuseLaPhotoDUnAutreEtLaDerniereAvant(): void
    {
        $author = $this->createUser();
        $contributor = $this->createUser();
        $memory = $this->createMemory($author);
        $beforeId = $memory->getPictures()[0]->getId();
        $foreignAfterId = $this->addPicture($memory, Picture::TYPE_AFTER, $contributor);

        $this->client->request('DELETE', '/api/secure/delete/picture/' . $foreignAfterId, server: $this->authHeaders($author));
        $this->assertResponseStatusCodeSame(403);

        $this->client->request('DELETE', '/api/secure/delete/picture/' . $beforeId, server: $this->authHeaders($author));
        $this->assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('delete_pictures', $this->json()['errors']);

        $this->assertSame(Picture::TYPE_BEFORE, $this->typeOf($beforeId));
    }

    public function testLaSuppressionDirecteAutoriseUnePhotoAutreSiUneAvantReste(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author);
        $otherId = $this->addPicture($memory, Picture::TYPE_OTHER, $author);

        $this->client->request('DELETE', '/api/secure/delete/picture/' . $otherId, server: $this->authHeaders($author));

        $this->assertResponseStatusCodeSame(200);
    }
}
