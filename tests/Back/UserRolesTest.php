<?php

namespace App\Tests\Back;

use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * Modification des rôles depuis le back-office : un administrateur ne peut pas se retirer son propre rôle.
 */
class UserRolesTest extends ApiTestCase
{
    /** @param list<string> $roles */
    private function editRoles(User $target, User $as, array $roles): void
    {
        $this->client->loginUser($as);
        $crawler = $this->client->request('GET', '/back/user/' . $target->getId() . '/edit');
        $form = $crawler->filter('form[name=user]')->form();
        // Cases à cocher « Rôles » (user[roles][0], [1]…) : cocher exactement celles demandées
        foreach ($crawler->filter('input[name="user[roles][]"]')->extract(['value']) as $i => $value) {
            $field = $form["user[roles][$i]"];
            $this->assertInstanceOf(ChoiceFormField::class, $field);
            in_array($value, $roles, true) ? $field->tick() : $field->untick();
        }
        $this->client->submit($form);
    }

    private function rolesOf(User $user): array
    {
        $this->em->clear();

        return $this->em->find(User::class, $user->getId())->getRoles();
    }

    public function testUnAdminNePeutPasSeRetirerLeRoleAdmin(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);

        $this->editRoles($admin, $admin, ['ROLE_MODERATOR']);

        $this->assertResponseStatusCodeSame(200); // formulaire réaffiché avec l'erreur, pas de redirection
        $this->assertSelectorTextContains('body', 'Vous ne pouvez pas vous retirer le rôle d\'administrateur');
        $this->assertContains('ROLE_ADMIN', $this->rolesOf($admin));
    }

    public function testUnAdminPeutModifierSesAutresRolesEnGardantAdmin(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);

        $this->editRoles($admin, $admin, ['ROLE_ADMIN', 'ROLE_MODERATOR']);

        $this->assertResponseRedirects('/back/user/');
        $this->assertContains('ROLE_MODERATOR', $this->rolesOf($admin));
    }

    public function testUnAdminPeutRetirerLeRoleAdminACeluiDUnAutre(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $other = $this->createUser(['ROLE_ADMIN']);

        $this->editRoles($other, $admin, ['ROLE_MODERATOR']);

        $this->assertResponseRedirects('/back/user/');
        $this->assertNotContains('ROLE_ADMIN', $this->rolesOf($other));
    }
}
