<?php

namespace App\Tests\Back;

use App\Entity\Picture;
use App\Enum\PictureStatus;
use App\Tests\Support\ApiTestCase;

class ModerationTest extends ApiTestCase
{
    /** Les services sont réinitialisés après chaque requête : on relit la photo en base. */
    private function reload(Picture $picture): Picture
    {
        $id = $picture->getId();
        $this->em->clear();

        return $this->em->find(Picture::class, $id);
    }

    public function testLaPageEstReserveAuxAdmins(): void
    {
        $this->client->request('GET', '/back/moderation/pictures/');
        $this->assertResponseRedirects(); // anonyme -> connexion

        $this->client->loginUser($this->createUser(['ROLE_USER']));
        $this->client->request('GET', '/back/moderation/pictures/');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testAfficheLeComparateurQuandUnePhotoAncienneApprouveeExiste(): void
    {
        $memory = $this->createMemory($this->createUser(), PictureStatus::PENDING, $this->createUser());

        $this->client->loginUser($this->createUser(['ROLE_ADMIN']));
        $this->client->request('GET', '/back/moderation/pictures/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorCount(1, '[data-compare]');
        $this->assertSelectorExists('[data-compare-before][src="/uploads/memories/before.png"]');
        $this->assertSelectorExists('.compare__img--top[src="/uploads/memories/after.png"]');
        $this->assertSelectorTextContains('.compare', 'Avant');
        $this->assertSelectorTextContains('.compare', 'Proposée');
    }

    public function testSansPhotoAncienneApprouveeOnGardeLaMiniature(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author, PictureStatus::PENDING, $this->createUser());
        // La seule photo ancienne n'est plus approuvée
        $memory->getPictures()->first()->setStatus(PictureStatus::REJECTED);
        $this->em->flush();

        $this->client->loginUser($this->createUser(['ROLE_ADMIN']));
        $this->client->request('GET', '/back/moderation/pictures/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-compare]');
        $this->assertSelectorExists('img.img-thumbnail[src="/uploads/memories/after.png"]');
    }

    public function testApprouverEtRejeter(): void
    {
        $proposer = $this->createUser();
        $memory = $this->createMemory($this->createUser(), PictureStatus::PENDING, $proposer);
        $pending = $memory->getPictures()->last();

        $this->client->loginUser($this->createUser(['ROLE_ADMIN']));
        $crawler = $this->client->request('GET', '/back/moderation/pictures/');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('table', $memory->getTitle());

        $this->client->submit($crawler->filter('form[action$="/' . $pending->getId() . '/approve"]')->form());
        $this->assertResponseRedirects();
        $pending = $this->reload($pending);
        $this->assertSame(PictureStatus::APPROVED, $pending->getStatus());
    }

    /** Souvenir sans photo « avant » dont un contributeur propose une photo actuelle en suggérant sa première photo comme « avant ». */
    private function pendingWithSuggestion(): array
    {
        $memory = $this->createMemory($this->createUser(), PictureStatus::PENDING, $this->createUser());
        $gallery = $memory->getPictures()->first();
        $gallery->setType(Picture::TYPE_OTHER);
        $pending = $memory->getPictures()->last();
        $pending->setSuggestedBefore($gallery);
        $this->em->flush();

        return [$memory, $gallery, $pending];
    }

    public function testLaPageMontreLaPhotoAvantSuggereeEtL_approbationL_applique(): void
    {
        [, $gallery, $pending] = $this->pendingWithSuggestion();

        $this->client->loginUser($this->createUser(['ROLE_ADMIN']));
        $crawler = $this->client->request('GET', '/back/moderation/pictures/');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('table', 'suggérée');

        $this->client->submit($crawler->filter('form[action$="/' . $pending->getId() . '/approve"]')->form());

        $this->assertResponseRedirects();
        $this->assertSame(Picture::TYPE_BEFORE, $this->reload($gallery)->getType());
        $this->assertNull($this->reload($pending)->getSuggestedBefore(), 'suggestion consommée');
    }

    public function testLaSuggestionEstIgnoreeSiLeSouvenirAEntreTempsUnePhotoAvant(): void
    {
        [$memory, $gallery, $pending] = $this->pendingWithSuggestion();
        $existing = $this->createPicture('avant.png', Picture::TYPE_BEFORE, 3, PictureStatus::APPROVED, null);
        $memory->addPicture($existing);
        $this->em->flush();

        $this->client->loginUser($this->createUser(['ROLE_ADMIN']));
        $crawler = $this->client->request('GET', '/back/moderation/pictures/');
        $this->client->submit($crawler->filter('form[action$="/' . $pending->getId() . '/approve"]')->form());

        $this->assertSame(Picture::TYPE_OTHER, $this->reload($gallery)->getType(), 'la photo avant existante est conservée');
        $this->assertNull($this->reload($pending)->getSuggestedBefore());
    }

    public function testApprobationRefuseeSiUneActuelleApprouveeExisteDeja(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author, PictureStatus::APPROVED);
        $second = $this->createPicture('autre.png', Picture::TYPE_AFTER, 2, PictureStatus::PENDING, $this->createUser());
        $memory->addPicture($second);
        $this->em->flush();

        $this->client->loginUser($this->createUser(['ROLE_ADMIN']));
        $crawler = $this->client->request('GET', '/back/moderation/pictures/');
        $this->client->submit($crawler->filter('form[action$="/' . $second->getId() . '/approve"]')->form());
        $this->assertResponseRedirects('/back/moderation/pictures/');

        // Refusée : la photo reste en attente (le message flash n'est pas conservé par le navigateur de test)
        $second = $this->reload($second);
        $this->assertSame(PictureStatus::PENDING, $second->getStatus());
    }

    public function testApprouverUnRemplacementSupprimeLAncienneEtNotifieSonDeposant(): void
    {
        $uploader = $this->createUser();
        $proposer = $this->createUser();
        $memory = $this->createMemory($this->createUser(), PictureStatus::APPROVED, $uploader);
        $old = $memory->getApprovedAfterPicture();
        $oldId = $old->getId();
        $replacement = $this->createPicture('nouvelle.png', Picture::TYPE_AFTER, 2, PictureStatus::PENDING, $proposer)->setReplaces($old);
        $memory->addPicture($replacement);
        $this->em->flush();

        $this->client->loginUser($this->createUser(['ROLE_ADMIN']));
        $crawler = $this->client->request('GET', '/back/moderation/pictures/');
        $this->client->submit($crawler->filter('form[action$="/' . $replacement->getId() . '/approve"]')->form());
        $this->assertResponseRedirects('/back/moderation/pictures/');

        $replacement = $this->reload($replacement);
        $this->assertSame(PictureStatus::APPROVED, $replacement->getStatus());
        $this->assertNull($replacement->getReplaces());
        $this->assertNull($this->em->find(Picture::class, $oldId));
        $this->assertSame($replacement->getId(), $replacement->getMemory()->getApprovedAfterPicture()->getId());

        $notifications = $this->em->getRepository(\App\Entity\Notification::class)->findBy(['recipient' => $uploader]);
        $this->assertCount(1, $notifications);
        $this->assertSame(\App\Enum\NotificationType::CURRENT_PICTURE_REPLACED, $notifications[0]->getType());
    }

    public function testRefuserUnRemplacementConserveLAncienne(): void
    {
        $memory = $this->createMemory($this->createUser(), PictureStatus::APPROVED);
        $old = $memory->getApprovedAfterPicture();
        $oldId = $old->getId();
        $replacement = $this->createPicture('nouvelle.png', Picture::TYPE_AFTER, 2, PictureStatus::PENDING, $this->createUser())->setReplaces($old);
        $memory->addPicture($replacement);
        $this->em->flush();

        $this->client->loginUser($this->createUser(['ROLE_ADMIN']));
        $crawler = $this->client->request('GET', '/back/moderation/pictures/');
        $this->client->submit($crawler->filter('form[action$="/' . $replacement->getId() . '/reject"]')->form(['reason' => 'floue']));
        $this->assertResponseRedirects('/back/moderation/pictures/');

        $this->em->clear();
        $this->assertSame(PictureStatus::APPROVED, $this->em->find(Picture::class, $oldId)->getStatus());
    }

    public function testRejeterConserveLaPhotoMaisNeLExposePlus(): void
    {
        $memory = $this->createMemory($this->createUser(), PictureStatus::PENDING, $this->createUser());
        $pending = $memory->getPictures()->last();

        $this->client->loginUser($this->createUser(['ROLE_ADMIN']));
        $crawler = $this->client->request('GET', '/back/moderation/pictures/');
        $form = $crawler->filter('form[action$="/' . $pending->getId() . '/reject"]')->form();
        $form['reason'] = 'floue';
        $form['details'] = 'Trop sombre';
        $this->client->submit($form);

        $pending = $this->reload($pending);
        $this->assertSame(PictureStatus::REJECTED, $pending->getStatus());
        $this->assertSame('Photo floue ou illisible : Trop sombre', $pending->getRejectionReason());
        $this->assertNotNull($pending->getModeratedAt());
        $this->assertNull($pending->getRejectionSeenAt());

        $this->client->request('GET', '/back/moderation/pictures/');
        $this->assertSelectorTextContains('.empty-state', 'Aucune photo en attente');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('motifsProvider')]
    public function testSeulLeContenuInappropriePerdSonFichierAuRejet(string $reason, bool $fichierConserve): void
    {
        $memory = $this->createMemory($this->createUser(), PictureStatus::PENDING, $this->createUser());
        $pending = $memory->getPictures()->last();
        $path = $this->storeFile($pending);

        $this->client->loginUser($this->createUser(['ROLE_ADMIN']));
        $crawler = $this->client->request('GET', '/back/moderation/pictures/');
        $form = $crawler->filter('form[action$="/' . $pending->getId() . '/reject"]')->form();
        $form['reason'] = $reason;
        $this->client->submit($form);

        $this->assertSame($fichierConserve, file_exists($path));
        $reloaded = $this->reload($pending);
        $this->assertSame($fichierConserve, $reloaded->getImageName() !== null);
        // Dans tous les cas la ligne reste (pierre tombale si le fichier est supprimé) et l'auteur est notifié
        $this->assertSame(PictureStatus::REJECTED, $reloaded->getStatus());
        $this->assertNull($reloaded->getRejectionSeenAt());
        $this->assertNotNull($reloaded->getModeratedAt());
        $this->client->request('GET', '/api/secure/notifications', server: $this->authHeaders($pending->getUploadedBy()));
        $this->assertSame($pending->getId(), $this->json()[0]['pictureId']);
    }

    public static function motifsProvider(): iterable
    {
        yield 'inapproprié : supprimé' => ['inapproprie', false];
        yield 'floue : conservé jusqu\'au vu' => ['floue', true];
    }

    public function testRejetSansMotifOuAutreSansPrecisionEstRefuse(): void
    {
        $memory = $this->createMemory($this->createUser(), PictureStatus::PENDING, $this->createUser());
        $pending = $memory->getPictures()->last();

        $this->client->loginUser($this->createUser(['ROLE_ADMIN']));
        $crawler = $this->client->request('GET', '/back/moderation/pictures/');
        $token = $crawler->filter('form[action$="/' . $pending->getId() . '/reject"] input[name=_token]')->attr('value');

        foreach ([[], ['reason' => 'autre', 'details' => '  '], ['reason' => 'inconnu']] as $fields) {
            $this->client->request('POST', '/back/moderation/pictures/' . $pending->getId() . '/reject', $fields + ['_token' => $token]);
            $this->assertResponseRedirects();

            $reloaded = $this->reload($pending);
            $this->assertSame(PictureStatus::PENDING, $reloaded->getStatus());
            $this->assertNull($reloaded->getRejectionReason());
            $this->assertNull($reloaded->getModeratedAt());
        }
    }

    public function testApprouverRenseigneLaDateDeModeration(): void
    {
        $memory = $this->createMemory($this->createUser(), PictureStatus::PENDING, $this->createUser());
        $pending = $memory->getPictures()->last();

        $this->client->loginUser($this->createUser(['ROLE_ADMIN']));
        $crawler = $this->client->request('GET', '/back/moderation/pictures/');
        $this->client->submit($crawler->filter('form[action$="/' . $pending->getId() . '/approve"]')->form());

        $this->assertNotNull($this->reload($pending)->getModeratedAt());
    }

    public function testLaPastilleEtLeCompteurIndiquentLesPhotosEnAttente(): void
    {
        $this->client->request('GET', '/back/moderation/pictures/count');
        $this->assertResponseRedirects(); // anonyme -> connexion

        $this->client->loginUser($this->createUser(['ROLE_USER']));
        $this->client->request('GET', '/back/moderation/pictures/count');
        $this->assertResponseStatusCodeSame(403);

        $this->createMemory($this->createUser(), PictureStatus::PENDING, $this->createUser());
        $this->createMemory($this->createUser(), PictureStatus::PENDING, $this->createUser());
        $this->createMemory($this->createUser(), PictureStatus::APPROVED, $this->createUser());

        $this->client->loginUser($this->createUser(['ROLE_ADMIN']));
        $this->client->request('GET', '/back/moderation/pictures/count');
        $this->assertSame(['count' => 2], $this->json());

        $this->client->request('GET', '/back');
        $this->assertSelectorTextSame('#moderation-badge', '2');
        // Le tableau de bord ajoute sa propre pastille et un accès à la modération
        $this->assertSelectorTextSame('.stat-card .js-moderation-badge', '2');
        $this->assertSelectorExists('.stat-card[href="/back/moderation/pictures/"]');
    }

    public function testJetonCsrfInvalide(): void
    {
        $memory = $this->createMemory($this->createUser(), PictureStatus::PENDING, $this->createUser());
        $pending = $memory->getPictures()->last();

        $this->client->loginUser($this->createUser(['ROLE_ADMIN']));
        $this->client->request('POST', '/back/moderation/pictures/' . $pending->getId() . '/approve', ['_token' => 'faux']);

        $this->assertResponseRedirects();
        $pending = $this->reload($pending);
        $this->assertSame(PictureStatus::PENDING, $pending->getStatus());
    }

    /** Notifications de commentaires et de modération non vues d'un utilisateur, lues par l'API. */
    private function notificationsOf(\App\Entity\User $user): array
    {
        $this->client->request('GET', '/api/secure/notifications/comments', server: $this->authHeaders($user));
        $this->assertResponseIsSuccessful();

        return $this->json();
    }

    public function testApprouverUnePhotoPrevientCeluiQuiLAvaitProposee(): void
    {
        $proposer = $this->createUser();
        $memory = $this->createMemory($this->createUser(), PictureStatus::PENDING, $proposer);
        $pending = $memory->getPictures()->last();
        [$memoryId, $pendingId] = [$memory->getId(), $pending->getId()];
        $this->assertSame([], $this->notificationsOf($proposer), 'rien tant que la photo est en attente');

        $this->client->loginUser($this->createUser(['ROLE_ADMIN']));
        $crawler = $this->client->request('GET', '/back/moderation/pictures/');
        $this->client->submit($crawler->filter('form[action$="/' . $pendingId . '/approve"]')->form());
        $this->assertResponseRedirects();

        $notifications = $this->notificationsOf($proposer);
        $this->assertCount(1, $notifications);
        $n = $notifications[0];
        $this->assertSame('picture_approved', $n['type']);
        $this->assertSame($memoryId, $n['memoryId']);
        $this->assertSame('Le Panthéon', $n['memoryTitle']);
        $this->assertSame('Modération', $n['actorDisplayName']);
        $this->assertNull($n['commentId']);
        $this->assertNull($n['excerpt']);
        $this->assertNull($n['reason']);
        $this->assertNull($n['message']);
    }

    public function testApprouverSaPropreProPositionNeNotifiePersonne(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $memory = $this->createMemory($this->createUser(), PictureStatus::PENDING, $admin);
        $pendingId = $memory->getPictures()->last()->getId();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/back/moderation/pictures/');
        $this->client->submit($crawler->filter('form[action$="/' . $pendingId . '/approve"]')->form());
        $this->assertResponseRedirects();

        $this->assertSame([], $this->notificationsOf($admin));
    }
}
