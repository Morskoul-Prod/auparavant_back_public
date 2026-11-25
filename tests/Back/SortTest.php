<?php

namespace App\Tests\Back;

use App\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tri des listes du back-office : le paramètre `order` est une liste blanche, une valeur vide,
 * inconnue ou mal formée ne doit plus provoquer d'erreur 500.
 */
class SortTest extends ApiTestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function cas(): iterable
    {
        foreach (['user' => 'pseudonym', 'memory' => 'title', 'location' => 'city', 'place' => 'name', 'picture' => 'imageName'] as $entite => $champ) {
            yield "$entite valide" => [$entite, "order=$champ&selectedDirection=desc"];
            yield "$entite absent" => [$entite, ''];
            yield "$entite vide" => [$entite, 'order='];
            yield "$entite inconnu" => [$entite, 'order=inexistant'];
            yield "$entite champ sensible" => [$entite, 'order=password'];
            yield "$entite tableau" => [$entite, 'order[]=id'];
            yield "$entite direction invalide" => [$entite, 'order=id&selectedDirection=;DROP'];
        }
    }

    #[DataProvider('cas')]
    public function testLeTriNeProvoquePasDErreur(string $entite, string $query): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $this->createMemory($admin);
        $this->client->loginUser($admin);

        $this->client->request('GET', "/back/sort/$entite/?$query");

        $this->assertResponseIsSuccessful();
    }
}
