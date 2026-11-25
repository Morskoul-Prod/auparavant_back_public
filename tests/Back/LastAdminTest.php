<?php

namespace App\Tests\Back;

use App\Entity\User;
use App\Tests\Support\ApiTestCase;

/**
 * Le seul administrateur ne peut pas être supprimé (back-office et API) : sinon le back-office
 * n'aurait plus personne pour attribuer des rôles.
 */
class LastAdminTest extends ApiTestCase
{
    private function deleteInBackOffice(User $target, User $as): void
    {
        $this->client->loginUser($as);
        $crawler = $this->client->request('GET', '/back/user/' . $target->getId());
        $this->client->submit($crawler->filter('form[action="/back/user/' . $target->getId() . '"]')->form());
    }

    private function deleteByApi(User $user): void
    {
        $this->givePassword($user);
        $this->client->request('DELETE', '/api/secure/delete/user/' . $user->getId(), server: $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], content: json_encode(['currentPassword' => 'secret123']));
    }

    private function exists(int $id): bool
    {
        $this->em->clear();

        return $this->em->find(User::class, $id) !== null;
    }

    public function testLeSeulAdminNePeutPasSeSupprimerDepuisLeBackOffice(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $adminId = $admin->getId();

        $this->deleteInBackOffice($admin, $admin);

        $this->assertResponseRedirects('/back/user/');
        // Le navigateur de test ne conserve pas les messages flash : on vérifie seulement que le compte est resté
        $this->assertTrue($this->exists($adminId));
    }

    public function testUnAdminPeutSupprimerUnAutreAdmin(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $adminId = $admin->getId();
        $other = $this->createUser(['ROLE_ADMIN']);
        $otherId = $other->getId();

        $this->deleteInBackOffice($other, $admin);

        $this->assertFalse($this->exists($otherId));
        $this->assertTrue($this->exists($adminId));
    }

    public function testUnUtilisateurSimpleSeSupprimeToujours(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $adminId = $admin->getId();
        $user = $this->createUser();
        $userId = $user->getId();

        $this->deleteInBackOffice($user, $admin);

        $this->assertFalse($this->exists($userId));
    }

    public function testLeSeulAdminNePeutPasSupprimerSonCompteParLApi(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $adminId = $admin->getId();

        $this->deleteByApi($admin);

        $this->assertResponseStatusCodeSame(409);
        $this->assertSame('last_admin', json_decode($this->client->getResponse()->getContent(), true)['code']);
        $this->assertTrue($this->exists($adminId));
    }

    public function testUnAdminPeutSupprimerSonCompteParLApiSiUnAutreAdminExiste(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $adminId = $admin->getId();
        $this->createUser(['ROLE_ADMIN']);

        $this->deleteByApi($admin);

        $this->assertResponseIsSuccessful();
        $this->assertFalse($this->exists($adminId));
    }
}
