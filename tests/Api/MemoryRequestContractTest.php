<?php

namespace App\Tests\Api;

use App\Entity\Location;
use App\Entity\Memory;
use App\Entity\Place;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Contrat des endpoints de création et de modification de souvenir (validation du JSON `data`, dates,
 * lieu, localisation, droits) : figé avant le découpage de MemoryController, il doit rester identique après.
 */
class MemoryRequestContractTest extends ApiTestCase
{
    private const CREATE_EXISTING = '/api/secure/create/memory-and-place';
    private const CREATE_NEW = '/api/secure/create/memory-and-location-and-place';

    private User $author;
    private Memory $existing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->author = $this->createUser();
        $this->existing = $this->createMemory($this->author);
    }

    /** @return array<string, mixed> */
    private function existingPayload(): array
    {
        return [
            'location' => ['id' => $this->existing->getLocation()->getId()],
            'place' => ['id' => $this->existing->getPlace()->getId()],
            'memory' => ['title' => 'Nouveau souvenir', 'content' => 'Un souvenir assez long', 'year' => 1980],
        ];
    }

    /** @return array<string, mixed> */
    private function newLocationPayload(): array
    {
        return [
            'location' => ['area' => 'Bretagne', 'department' => 'Finistère', 'street' => '1 rue de Siam', 'city' => 'Brest', 'zipcode' => 29200, 'latitude' => '48.390394', 'longitude' => '-4.486076'],
            'place' => ['name' => 'Le port', 'type' => 'maison'],
            'memory' => ['title' => 'Brest en 1950', 'content' => 'Un souvenir assez long', 'year' => 1950],
        ];
    }

    /** @param array<string, mixed>|string $data */
    private function post(string $uri, array|string $data, ?User $as = null, bool $withPicture = true): void
    {
        $this->client->request('POST', $uri, [
            'data' => is_array($data) ? json_encode($data) : $data,
            'pictures_meta' => json_encode([['type' => 'before']]),
        ], $withPicture ? ['pictures' => [$this->pngFile()]] : [], $this->authHeaders($as ?? $this->author));
    }

    private function assertError(int $status, string $message): void
    {
        $this->assertResponseStatusCodeSame($status);
        $this->assertSame($message, $this->json()['error']);
    }

    // =============== Accès ===============

    public function testLesCreationsExigentUnJwt(): void
    {
        foreach ([self::CREATE_EXISTING, self::CREATE_NEW] as $uri) {
            $this->client->request('POST', $uri, ['data' => '{}']);
            $this->assertResponseStatusCodeSame(401, $uri);
        }
    }

    // =============== JSON `data` ===============

    /** @return iterable<string, array{string}> */
    public static function jsonInvalides(): iterable
    {
        yield 'texte' => ['pas du json'];
        yield 'tableau vide' => ['[]'];
        yield 'objet vide' => ['{}'];
        yield 'scalaire' => ['"x"'];
    }

    #[DataProvider('jsonInvalides')]
    public function testJsonInvalideRenvoie400(string $data): void
    {
        foreach ([self::CREATE_EXISTING, self::CREATE_NEW] as $uri) {
            $this->post($uri, $data);
            $this->assertError(400, 'Invalid JSON data');
        }
    }

    public function testChampsMultipartEnTableauRenvoient400OuEstIgnore(): void
    {
        // data[]=… (tableau au lieu d'une chaîne JSON) : 400 et non une erreur 500
        $this->client->request('POST', self::CREATE_EXISTING, ['data' => ['x']], [], $this->authHeaders($this->author));
        $this->assertResponseStatusCodeSame(400);

        // pictures_meta et delete_pictures mal formés : ignorés
        $this->client->request('POST', self::CREATE_EXISTING, ['data' => json_encode($this->existingPayload()), 'pictures_meta' => ['x']], ['pictures' => [$this->pngFile()]], $this->authHeaders($this->author));
        $this->assertResponseStatusCodeSame(201);

        $this->client->request('POST', '/api/secure/update/memory-and-place/' . $this->existing->getId(), [
            'data' => json_encode(['memory' => ['title' => 'Titre', 'content' => 'Un contenu assez long', 'year' => 1950]]),
            'delete_pictures' => ['x'],
        ], [], $this->authHeaders($this->author));
        $this->assertResponseStatusCodeSame(200);
    }

    public function testChampDataAbsentRenvoie400(): void
    {
        $this->client->request('POST', self::CREATE_EXISTING, [], [], $this->authHeaders($this->author));

        $this->assertResponseStatusCodeSame(400);
    }

    // =============== Champs obligatoires (localisation existante) ===============

    /** @return iterable<int, array{string, string}> chemin retiré, message attendu */
    public static function champsManquants(): iterable
    {
        yield ['location', 'Champ manquant : location'];
        yield ['location.id', 'Champ manquant : location.id'];
        yield ['place', 'Champ manquant : place'];
        yield ['memory', 'Champ manquant : memory'];
        yield ['memory.title', 'Champ manquant : memory.title'];
        yield ['memory.content', 'Champ manquant : memory.content'];
    }

    #[DataProvider('champsManquants')]
    public function testChampManquantLocalisationExistante(string $path, string $message): void
    {
        $data = $this->existingPayload();
        $keys = explode('.', $path);
        if (count($keys) === 1) {
            unset($data[$keys[0]]);
        } else {
            unset($data[$keys[0]][$keys[1]]);
        }

        $this->post(self::CREATE_EXISTING, $data);

        $this->assertError(400, $message);
    }

    public function testChampsMalTypes(): void
    {
        $data = $this->existingPayload();
        $data['memory']['title'] = 12;
        $this->post(self::CREATE_EXISTING, $data);
        $this->assertError(400, 'Champ invalide : memory.title');

        $data = $this->existingPayload();
        $data['location']['id'] = 'abc';
        $this->post(self::CREATE_EXISTING, $data);
        $this->assertError(400, 'Champ invalide : location.id');

        $data = $this->existingPayload();
        $data['place'] = 'x';
        $this->post(self::CREATE_EXISTING, $data);
        $this->assertError(400, 'Champ invalide : place');
    }

    public function testUnIdNumeriqueEnChaineEstAccepte(): void
    {
        $data = $this->existingPayload();
        $data['location']['id'] = (string) $data['location']['id'];

        $this->post(self::CREATE_EXISTING, $data);

        $this->assertResponseStatusCodeSame(201);
    }

    // =============== Localisation et lieu existants ===============

    public function testLocalisationInconnue404(): void
    {
        $data = $this->existingPayload();
        $data['location']['id'] = 999999;

        $this->post(self::CREATE_EXISTING, $data);

        $this->assertError(404, 'Location not found');
    }

    public function testLieuInconnu404(): void
    {
        $data = $this->existingPayload();
        $data['place'] = ['id' => 999999];

        $this->post(self::CREATE_EXISTING, $data);

        $this->assertError(404, 'Place not found');
    }

    public function testLieuExistantSansIdRenvoie400(): void
    {
        $data = $this->existingPayload();
        $data['place'] = [];

        $this->post(self::CREATE_EXISTING, $data);

        $this->assertError(400, 'Champ manquant : place.id');
    }

    /** @return iterable<string, array{mixed}> */
    public static function addressIdInvalides(): iterable
    {
        yield 'trop long' => [str_repeat('a', 51)];
        yield 'entier' => [12];
        yield 'tableau' => [['x']];
    }

    #[DataProvider('addressIdInvalides')]
    public function testAddressIdInvalide(mixed $addressId): void
    {
        $data = $this->existingPayload();
        $data['location']['address_id'] = $addressId;
        $this->post(self::CREATE_EXISTING, $data);
        $this->assertError(400, 'Invalid address_id');

        $data = $this->newLocationPayload();
        $data['location']['address_id'] = $addressId;
        $this->post(self::CREATE_NEW, $data);
        $this->assertError(400, 'Invalid address_id');
    }

    public function testAddressIdRenseigneSeulementSiLaLocalisationNEnAPas(): void
    {
        $locationId = $this->existing->getLocation()->getId();
        $data = $this->existingPayload();
        $data['location']['address_id'] = '75105_7255_00028';
        $this->post(self::CREATE_EXISTING, $data);
        $this->assertResponseStatusCodeSame(201);
        $this->em->clear();
        $this->assertSame('75105_7255_00028', $this->em->find(Location::class, $locationId)->getAddressId());

        $data['location']['address_id'] = 'autre';
        $this->post(self::CREATE_EXISTING, $data);
        $this->em->clear();
        $this->assertSame('75105_7255_00028', $this->em->find(Location::class, $locationId)->getAddressId(), 'inchangé');
    }

    // =============== Nouveau lieu sur une localisation existante ===============

    public function testNouveauLieuCreeEtRattacheALaLocalisation(): void
    {
        $data = $this->existingPayload();
        $data['place'] = ['create_new_place' => true, 'name' => 'La chapelle', 'type' => 'maison'];
        $places = $this->em->getRepository(Place::class)->count([]);

        $this->post(self::CREATE_EXISTING, $data);

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame($places + 1, $this->em->getRepository(Place::class)->count([]));
        $this->assertSame('La chapelle', $this->json()['memory']['place']['name']);
    }

    public function testNouveauLieuExigeNomEtType(): void
    {
        $data = $this->existingPayload();
        $data['place'] = ['create_new_place' => true, 'type' => 'maison'];
        $this->post(self::CREATE_EXISTING, $data);
        $this->assertError(400, 'Champ manquant : place.name');

        $data['place'] = ['create_new_place' => true, 'name' => 'La chapelle'];
        $this->post(self::CREATE_EXISTING, $data);
        $this->assertError(400, 'Champ manquant : place.type');
    }

    public function testNouveauLieuTypeInconnu(): void
    {
        $data = $this->existingPayload();
        $data['place'] = ['create_new_place' => true, 'name' => 'La chapelle', 'type' => 'château-fort'];

        $this->post(self::CREATE_EXISTING, $data);

        $this->assertError(400, 'Invalid place type');
    }

    // =============== Date ou année ===============

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function datesRefusees(): iterable
    {
        $max = (int) date('Y');
        yield 'ni date ni année' => [[], 'Indiquez une date exacte (picture_date) ou, à défaut, une année (year).'];
        yield 'chaînes vides' => [['picture_date' => '', 'year' => ''], 'Indiquez une date exacte (picture_date) ou, à défaut, une année (year).'];
        yield 'date illisible' => [['picture_date' => 'hier'], 'Date invalide : picture_date doit être une date valide au format AAAA-MM-JJ.'];
        yield 'date qui déborde' => [['picture_date' => '1990-02-30'], 'Date invalide : picture_date doit être une date valide au format AAAA-MM-JJ.'];
        yield 'date non texte' => [['picture_date' => 1990], 'Date invalide : picture_date doit être une date valide au format AAAA-MM-JJ.'];
        yield 'année non entière' => [['year' => 'abc'], 'Année invalide : year doit être un entier.'];
        yield 'année décimale' => [['year' => 1990.5], 'Année invalide : year doit être un entier.'];
        yield 'année trop ancienne' => [['year' => 1200], sprintf('Année invalide : elle doit être comprise entre %d et %d.', Memory::MIN_YEAR, $max)];
        yield 'année future' => [['year' => $max + 1], sprintf('Année invalide : elle doit être comprise entre %d et %d.', Memory::MIN_YEAR, $max)];
        yield 'date trop ancienne' => [['picture_date' => '1200-01-01'], sprintf('Année invalide : elle doit être comprise entre %d et %d.', Memory::MIN_YEAR, $max)];
    }

    /** @param array<string, mixed> $dates */
    #[DataProvider('datesRefusees')]
    public function testDateOuAnneeRefusee(array $dates, string $message): void
    {
        $data = $this->existingPayload();
        $data['memory'] = ['title' => 'Titre', 'content' => 'Contenu assez long'] + $dates;
        $this->post(self::CREATE_EXISTING, $data);
        $this->assertError(400, $message);

        $data = $this->newLocationPayload();
        $data['memory'] = ['title' => 'Titre', 'content' => 'Contenu assez long'] + $dates;
        $this->post(self::CREATE_NEW, $data);
        $this->assertError(400, $message);
    }

    public function testAnneeSeuleNeFixePasDeDate(): void
    {
        $this->post(self::CREATE_EXISTING, $this->existingPayload());

        $this->assertResponseStatusCodeSame(201);
        $this->assertNull($this->json()['memory']['picture_date']);
        $this->assertSame(1980, $this->json()['memory']['year']);
    }

    public function testAnneeEnChaineEstAcceptee(): void
    {
        $data = $this->existingPayload();
        $data['memory']['year'] = '1975';

        $this->post(self::CREATE_EXISTING, $data);

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame(1975, $this->json()['memory']['year']);
    }

    public function testLaDatePrimeSurLAnnee(): void
    {
        $data = $this->existingPayload();
        $data['memory']['picture_date'] = '1962-07-14';
        $data['memory']['year'] = 1999;

        $this->post(self::CREATE_EXISTING, $data);

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame(1962, $this->json()['memory']['year']);
        $this->assertStringStartsWith('1962-07-14', $this->json()['memory']['picture_date']);
    }

    // =============== Réponse de création ===============

    public function testReponseDeCreation(): void
    {
        $this->post(self::CREATE_EXISTING, $this->existingPayload());

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('Souvenir créé', $this->json()['message']);
        $memory = $this->json()['memory'];
        $this->assertSame('Nouveau souvenir', $memory['title']);
        $this->assertSame($this->author->getId(), $memory['user']['id']);
        $this->assertCount(1, $memory['pictures']);
    }

    public function testCreationSansPhotoRenvoie422(): void
    {
        $this->post(self::CREATE_EXISTING, $this->existingPayload(), null, false);

        $this->assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('memory.pictures', $this->json()['errors']);
    }

    // =============== Nouvelle localisation ===============

    /** @return iterable<string, array{string}> */
    public static function champsNouvelleLocalisation(): iterable
    {
        foreach (['location', 'location.area', 'location.department', 'location.street', 'location.city', 'location.zipcode', 'location.latitude', 'location.longitude', 'place', 'place.name', 'place.type', 'memory', 'memory.title', 'memory.content'] as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('champsNouvelleLocalisation')]
    public function testChampManquantNouvelleLocalisation(string $path): void
    {
        $data = $this->newLocationPayload();
        $keys = explode('.', $path);
        count($keys) === 1 ? $data[$keys[0]] = null : $data[$keys[0]][$keys[1]] = null;
        count($keys) === 1 ? ($data = array_diff_key($data, [$keys[0] => 1])) : ($data = array_replace($data, [$keys[0] => array_diff_key($data[$keys[0]], [$keys[1] => 1])]));

        $this->post(self::CREATE_NEW, $data);

        $this->assertError(400, 'Champ manquant : ' . $path);
    }

    public function testNouvelleLocalisationCreeLocalisationEtLieu(): void
    {
        $locations = $this->em->getRepository(Location::class)->count([]);
        $data = $this->newLocationPayload();
        $data['location']['district'] = '  Recouvrance  ';
        $data['location']['address_id'] = '29019_1234';

        $this->post(self::CREATE_NEW, $data);

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame($locations + 1, $this->em->getRepository(Location::class)->count([]));
        $location = $this->json()['memory']['location'];
        $this->assertSame('Brest', $location['city']);
        $this->assertSame('Recouvrance', $location['district']);
        $this->assertSame('Le port', $this->json()['memory']['place']['name']);
    }

    public function testQuartierVideDevientNull(): void
    {
        $data = $this->newLocationPayload();
        $data['location']['district'] = '   ';

        $this->post(self::CREATE_NEW, $data);

        $this->assertResponseStatusCodeSame(201);
        $this->assertNull($this->json()['memory']['location']['district']);
    }

    public function testNouvelleLocalisationTypeDeLieuInconnu(): void
    {
        $data = $this->newLocationPayload();
        $data['place']['type'] = 'inconnu';

        $this->post(self::CREATE_NEW, $data);

        $this->assertError(400, 'Invalid place type');
    }

    public function testNouvelleLocalisationValideLaLocalisation(): void
    {
        $locations = $this->em->getRepository(Location::class)->count([]);
        $data = $this->newLocationPayload();
        $data['location']['city'] = str_repeat('B', 51);

        $this->post(self::CREATE_NEW, $data);

        $this->assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('location.city', $this->json()['errors']);
        $this->assertSame($locations, $this->em->getRepository(Location::class)->count([]), 'rien n\'est créé');
    }

    // =============== Modification ===============

    /** @param array<string, mixed>|string $data */
    private function update(array|string $data, ?User $as = null, ?int $id = null): void
    {
        $this->client->request('POST', '/api/secure/update/memory-and-place/' . ($id ?? $this->existing->getId()), [
            'data' => is_array($data) ? json_encode($data) : $data,
        ], [], $this->authHeaders($as ?? $this->author));
    }

    public function testModificationExigeUnJwt(): void
    {
        $this->client->request('POST', '/api/secure/update/memory-and-place/' . $this->existing->getId(), ['data' => '{}']);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testModificationSouvenirInconnu(): void
    {
        $this->update(['memory' => ['title' => 'Titre', 'content' => 'Un contenu assez long', 'year' => 1950]], null, 999999);

        $this->assertError(404, 'Memory not found');
    }

    public function testModificationReserveeALAuteur(): void
    {
        $this->update(['memory' => ['title' => 'Titre', 'content' => 'Un contenu assez long', 'year' => 1950]], $this->createUser());

        $this->assertError(403, 'Unauthorized');
    }

    public function testModificationJsonInvalide(): void
    {
        $this->update('pas du json');
        $this->assertError(400, 'Invalid JSON data');

        $this->update('[]');
        $this->assertError(400, 'Invalid JSON data');
    }

    public function testModificationChampsObligatoires(): void
    {
        $this->update(['place' => []]);
        $this->assertError(400, 'Champ manquant : memory');

        $this->update(['memory' => ['content' => 'Un contenu assez long']]);
        $this->assertError(400, 'Champ manquant : memory.title');

        $this->update(['memory' => ['title' => 'T']]);
        $this->assertError(400, 'Champ manquant : memory.content');
    }

    public function testModificationDuTitreEtDeLAnnee(): void
    {
        $this->update(['memory' => ['title' => 'Nouveau titre', 'content' => 'Nouveau contenu', 'year' => 1944]]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('Souvenir mis à jour', $this->json()['message']);
        $this->assertSame('Nouveau titre', $this->json()['memory']['title']);
        $this->assertSame(1944, $this->json()['memory']['year']);
    }

    public function testModificationExigeUneDateOuUneAnnee(): void
    {
        $this->update(['memory' => ['title' => 'Titre', 'content' => 'Un contenu assez long']]);

        $this->assertError(400, 'Indiquez une date exacte (picture_date) ou, à défaut, une année (year).');
    }

    public function testModificationAnneeSeuleRetireLaDateExacte(): void
    {
        $this->update(['memory' => ['title' => 'Titre', 'content' => 'Un contenu assez long', 'picture_date' => '1950-05-05']]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertStringStartsWith('1950-05-05', $this->json()['memory']['picture_date']);

        $this->update(['memory' => ['title' => 'Titre', 'content' => 'Un contenu assez long', 'year' => 1951]]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertNull($this->json()['memory']['picture_date']);
        $this->assertSame(1951, $this->json()['memory']['year']);
    }

    public function testModificationDuLieu(): void
    {
        $placeId = $this->existing->getPlace()->getId();

        $this->update([
            'memory' => ['title' => 'Titre', 'content' => 'Un contenu assez long', 'year' => 1950],
            'place' => ['update_place' => true, 'id' => $placeId, 'name' => 'Nouveau nom', 'type' => 'ecole'],
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->em->clear();
        $place = $this->em->find(Place::class, $placeId);
        $this->assertSame('Nouveau nom', $place->getName());
        $this->assertSame('ecole', $place->getType()->value);
    }

    public function testModificationDuLieuChampsEtTypeInvalides(): void
    {
        $base = ['memory' => ['title' => 'Titre', 'content' => 'Un contenu assez long', 'year' => 1950]];
        $placeId = $this->existing->getPlace()->getId();

        $this->update($base + ['place' => ['update_place' => true, 'name' => 'N', 'type' => 'maison']]);
        $this->assertError(400, 'Champ manquant : place.id');

        $this->update($base + ['place' => ['update_place' => true, 'id' => $placeId, 'type' => 'maison']]);
        $this->assertError(400, 'Champ manquant : place.name');

        $this->update($base + ['place' => ['update_place' => true, 'id' => $placeId, 'name' => 'N', 'type' => 'inconnu']]);
        $this->assertError(400, 'Invalid place type');
    }

    public function testSansUpdatePlaceLeLieuNEstPasModifie(): void
    {
        $placeId = $this->existing->getPlace()->getId();

        $this->update([
            'memory' => ['title' => 'Titre', 'content' => 'Un contenu assez long', 'year' => 1950],
            'place' => ['id' => $placeId, 'name' => 'Ignoré', 'type' => 'ecole'],
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->em->clear();
        $this->assertSame('Panthéon', $this->em->find(Place::class, $placeId)->getName());
    }

    // =============== Suppression ===============

    public function testSuppressionReserveeALAuteur(): void
    {
        $id = $this->existing->getId();

        $this->client->request('DELETE', '/api/secure/delete/memory/' . $id, server: $this->authHeaders($this->createUser()));
        $this->assertError(403, 'Unauthorized');

        $this->client->request('DELETE', '/api/secure/delete/memory/' . $id, server: $this->authHeaders($this->createUser(['ROLE_ADMIN'])));
        $this->assertError(403, 'Unauthorized');

        $this->client->request('DELETE', '/api/secure/delete/memory/' . $id, server: $this->authHeaders($this->author));
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('Souvenir supprimé', $this->json()['message']);
    }
}
