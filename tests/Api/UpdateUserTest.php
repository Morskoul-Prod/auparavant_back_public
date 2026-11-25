<?php

namespace App\Tests\Api;

use App\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class UpdateUserTest extends ApiTestCase
{
    private function put(int $id, mixed $body, ?\App\Entity\User $as): void
    {
        $this->client->request('PUT', '/api/secure/update/user/' . $id, server: ($as ? $this->authHeaders($as) : []) + ['CONTENT_TYPE' => 'application/json'], content: is_string($body) ? $body : json_encode($body));
    }

    public function testSucces(): void
    {
        $user = $this->createUser();
        $this->put($user->getId(), ['pseudonym' => '  Nouveau '], $user);

        $this->assertResponseStatusCodeSame(200);
        $this->assertEquals(['id' => $user->getId(), 'displayName' => 'Nouveau', 'pseudonym' => 'Nouveau', 'email' => $user->getEmail(), 'roles' => ['ROLE_USER'], 'emailVerified' => true], $this->json()['user']);
        $this->assertStringNotContainsString('inutile', $this->client->getResponse()->getContent());
    }

    public function testChampsInterditsRefusesEtRienNEstModifie(): void
    {
        $user = $this->createUser();
        $email = $user->getEmail();
        $this->put($user->getId(), ['pseudonym' => 'Valide', 'email' => 'x@y.fr', 'password' => 'pirate', 'roles' => ['ROLE_ADMIN'], 'id' => 99, 'createdAt' => '2000-01-01'], $user);

        $this->assertResponseStatusCodeSame(422);
        $this->assertEqualsCanonicalizing(['email', 'password', 'roles', 'id', 'createdAt'], array_keys($this->json()['errors']));
        $this->em->clear();
        $fresh = $this->em->getRepository(\App\Entity\User::class)->find($user->getId());
        $this->assertSame($email, $fresh->getEmail());
        $this->assertSame('inutile', $fresh->getPassword());
        $this->assertSame('Testeur', $fresh->getPseudonym());
        $this->assertSame(['ROLE_USER'], $fresh->getRoles());
    }

    #[DataProvider('invalides')]
    public function testPseudonymeInvalide(mixed $value): void
    {
        $user = $this->createUser();
        $this->put($user->getId(), ['pseudonym' => $value], $user);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame(['pseudonym'], array_keys($this->json()['errors']));
    }

    public static function invalides(): iterable
    {
        yield 'réservé' => ['Admin'];
        yield 'réservé accentué' => ['Modération'];
        yield 'trop court' => ['a'];
        yield 'trop long' => [str_repeat('a', 31)];
        yield 'vide' => ['   '];
        yield 'pas une chaîne' => [['x']];
        yield 'null' => [null];
    }

    public function testPseudonymeAbsent(): void
    {
        $user = $this->createUser();
        $this->put($user->getId(), [], $user);
        $this->assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('pseudonym', $this->json()['errors']);
    }

    public function testCompteDUnAutre403(): void
    {
        $user = $this->createUser();
        $other = $this->createUser();
        $this->put($other->getId(), ['pseudonym' => 'Pirate'], $user);

        $this->assertResponseStatusCodeSame(403);
        $this->em->clear();
        $this->assertSame('Testeur', $this->em->getRepository(\App\Entity\User::class)->find($other->getId())->getPseudonym());
    }

    public function testUtilisateurInexistant404(): void
    {
        $this->put(999999, ['pseudonym' => 'Valide'], $this->createUser());
        $this->assertResponseStatusCodeSame(404);
    }

    public function testAnonyme401(): void
    {
        $this->put($this->createUser()->getId(), ['pseudonym' => 'Valide'], null);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testJsonInvalide400(): void
    {
        $user = $this->createUser();
        $this->put($user->getId(), 'pas du json', $user);
        $this->assertResponseStatusCodeSame(400);
    }
}
