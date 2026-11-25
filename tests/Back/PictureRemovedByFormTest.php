<?php

namespace App\Tests\Back;

use App\Entity\Memory;
use App\Entity\User;
use App\Enum\PictureStatus;
use App\Tests\Support\ApiTestCase;

/**
 * Photo retirée depuis le formulaire de souvenir du back-office : celui qui l'avait proposée est prévenu.
 */
class PictureRemovedByFormTest extends ApiTestCase
{
    /** Soumet le formulaire de modification sans la photo d'index donné (comme le fait le bouton « Supprimer ») */
    private function editWithoutPicture(User $admin, Memory $memory, int $pictureId): void
    {
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/back/memory/' . $memory->getId() . '/edit');
        $form = $crawler->filter('form[name=memory]')->form();
        $values = $form->getPhpValues();
        foreach ($memory->getPictures() as $index => $picture) {
            if ($picture->getId() === $pictureId) {
                unset($values['memory']['pictures'][$index]);
            }
        }
        $this->client->request('POST', $form->getUri(), $values);
    }

    private function removedNotifications(User $user): array
    {
        $this->client->request('GET', '/api/secure/notifications/comments', server: $this->authHeaders($user));

        return array_values(array_filter($this->json(), fn ($n) => $n['type'] === 'picture_removed'));
    }

    public function testLAuteurDeLaPhotoRetireeEstPrevenu(): void
    {
        $author = $this->createUser();
        $contributor = $this->createUser();
        $memory = $this->createMemory($author, PictureStatus::APPROVED, $contributor);
        $after = $memory->getPictures()->last();

        $this->editWithoutPicture($this->createUser(['ROLE_ADMIN']), $memory, $after->getId());

        $this->assertCount(1, $this->em->getRepository(Memory::class)->find($memory->getId())->getPictures());
        $notifications = $this->removedNotifications($contributor);
        $this->assertCount(1, $notifications);
        $this->assertSame($memory->getId(), $notifications[0]['memoryId']);
        $this->assertSame('Modération', $notifications[0]['actorDisplayName']);
    }

    public function testAucuneNotificationSiLAdminRetireSaProprePhoto(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $memory = $this->createMemory($this->createUser(), PictureStatus::APPROVED, $admin);

        $this->editWithoutPicture($admin, $memory, $memory->getPictures()->last()->getId());

        $this->assertCount(0, $this->removedNotifications($admin));
    }

    /** Appel AJAX du bouton « Supprimer » : jeton CSRF lu dans la page, comme le fait le JavaScript */
    private function clickDelete(User $admin, Memory $memory, int $pictureId): void
    {
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/back/memory/' . $memory->getId() . '/edit');
        $button = $crawler->filter('button.delete-existing-picture[data-picture-id="' . $pictureId . '"]');
        $this->client->request('POST', $button->attr('data-delete-url'), ['_token' => $button->attr('data-csrf-token')], server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    }

    public function testLeBoutonSupprimerSupprimeVraimentEtPrevient(): void
    {
        $contributor = $this->createUser();
        $memory = $this->createMemory($this->createUser(), PictureStatus::APPROVED, $contributor);
        $afterId = $memory->getPictures()->last()->getId();

        $this->clickDelete($this->createUser(['ROLE_ADMIN']), $memory, $afterId);

        $this->assertResponseIsSuccessful();
        $this->em->clear();
        $this->assertNull($this->em->find(\App\Entity\Picture::class, $afterId));
        $this->assertCount(1, $this->removedNotifications($contributor));
    }

    public function testLeBoutonRefuseLaDernierePhotoVisible(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author);
        $onlyId = $memory->getPictures()->first()->getId();

        $this->clickDelete($this->createUser(['ROLE_ADMIN']), $memory, $onlyId);

        $this->assertResponseStatusCodeSame(409);
        $this->em->clear();
        $this->assertNotNull($this->em->find(\App\Entity\Picture::class, $onlyId));
    }
}
