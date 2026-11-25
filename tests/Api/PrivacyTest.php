<?php

namespace App\Tests\Api;

use App\Tests\Support\ApiTestCase;

/**
 * L'email, le nom de famille et les rôles d'un utilisateur ne doivent apparaître sur aucune route publique.
 */
class PrivacyTest extends ApiTestCase
{
    public function testLesRoutesPubliquesNExposentNiEmailNiRolesNiNom(): void
    {
        $author = $this->createUser(['ROLE_USER', 'ROLE_ADMIN']);
        $memory = $this->createMemory($author);
        $location = $memory->getLocation();
        $this->em->flush();
        $uris = [
            '/api/memories', '/api/memories/latest', '/api/memory/' . $memory->getId(),
            '/api/memories/nearby?lat=48.846198&lng=2.346105&radius=5',
            '/api/memorieswithtwopictures',
            '/api/place/' . $memory->getPlace()->getId() . '/timeline', '/api/location/' . $location->getId() . '/timeline',
            '/api/locations/' . $location->getId() . '/memories', '/api/places/' . $memory->getPlace()->getId() . '/memories',
            '/api/user/' . $author->getId(),
        ];

        foreach ($uris as $uri) {
            $this->client->request('GET', $uri);
            $this->assertResponseIsSuccessful($uri);
            $body = $this->client->getResponse()->getContent();
            $this->assertStringNotContainsString($author->getEmail(), $body, $uri);
            $this->assertStringNotContainsString('ROLE_', $body, $uri);
            $this->assertStringNotContainsString('"roles"', $body, $uri);
            $this->assertStringNotContainsString('"email"', $body, $uri);
            $this->assertStringNotContainsString('pseudonym', $body, $uri);
            $this->assertStringNotContainsString('"password"', $body, $uri);
        }
    }

    public function testLAuteurDUnSouvenirEstIdentifieParIdEtNomAffiche(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author);

        $this->client->request('GET', '/api/memories/latest');
        $user = $this->json()[0]['user'];
        $this->assertSame(['id', 'displayName'], array_keys($user));
        $this->assertSame('Testeur', $user['displayName']);

        $this->client->request('GET', '/api/memory/' . $memory->getId());
        $this->assertSame(['id', 'displayName'], array_keys($this->json()['user']));
    }

    public function testLaListeDesUtilisateursEstReserveeAuxAdmins(): void
    {
        $user = $this->createUser();
        $this->client->request('GET', '/api/users');
        $this->assertResponseStatusCodeSame(401);
        $this->client->request('GET', '/api/users', server: $this->authHeaders($user));
        $this->assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/api/users', server: $this->authHeaders($this->createUser(['ROLE_ADMIN'])));
        $this->assertResponseIsSuccessful();
        $this->assertArrayHasKey('email', $this->json()[0]);
    }

    public function testUnUtilisateurVoitSesPropresDonneesPrivees(): void
    {
        $a = $this->createUser();
        $b = $this->createUser();
        $this->client->request('GET', '/api/user/' . $a->getId(), server: $this->authHeaders($a));
        $this->assertSame($a->getEmail(), $this->json()['email']);
        $this->client->request('GET', '/api/user/' . $a->getId(), server: $this->authHeaders($b));
        $this->assertArrayNotHasKey('email', $this->json());
    }
}
