<?php

namespace App\Tests\Api;

use App\Entity\DailyQuiz;
use App\Entity\Memory;
use App\Entity\QuizGame;
use App\Entity\QuizRound;
use App\Enum\QuizGameStatus;
use App\Tests\Support\QuizTestCase;

class QuizGameTest extends QuizTestCase
{
    public function testParcoursCompletD_UnePartie(): void
    {
        $this->createEligibleMemories(6);
        $user = $this->createUser();

        $state = $this->daily($user);
        $this->assertSame('not_started', $state['status']);
        $this->assertNull($state['currentRound']);
        $this->assertSame(['current' => 0, 'best' => 0], $state['streak']);

        $this->startQuiz($user);
        $this->assertResponseStatusCodeSame(201);
        $started = $this->json();
        $this->assertSame('in_progress', $started['status']);
        $this->assertSame(5, $started['totalRounds']);
        $this->assertSame(1000, $started['maxScore']);
        $this->assertSame(1, $started['currentRound']['position']);

        for ($position = 1; $position <= 5; ++$position) {
            $this->answerCurrentRound($user, yearOffset: $position === 2 ? 4 : 0);
            $this->assertResponseStatusCodeSame(200);
            $body = $this->json();

            $this->assertSame($position, $body['result']['position']);
            $this->assertSame($position === 2 ? 85 : 100, $body['result']['yearScore']);
            $this->assertSame(100, $body['result']['locationScore']);
            $this->assertEqualsWithDelta(0, $body['result']['distanceKm'], 0.0001);
            $this->assertSame('Le Panthéon', $body['result']['memory']['title']);
            $this->assertIsInt($body['result']['memory']['id']);
            $this->assertSame(1950, $body['result']['truth']['year']);

            if ($position < 5) {
                $this->assertFalse($body['finished']);
                $this->assertSame($position + 1, $body['nextRound']['position']);
                $this->assertNull($body['total']);
                $this->assertNull($body['streak']);
            }
        }

        // Dernière réponse : total et série
        $this->assertTrue($body['finished']);
        $this->assertNull($body['nextRound']);
        $this->assertSame(985, $body['gameScore']);
        $this->assertSame(985, $body['total']['score']);
        $this->assertSame(1000, $body['total']['maxScore']);
        $this->assertCount(5, $body['total']['rounds']);
        $this->assertSame(['current' => 1, 'best' => 1], $body['streak']);

        $final = $this->daily($user);
        $this->assertSame('finished', $final['status']);
        $this->assertSame(985, $final['score']);
        $this->assertNull($final['currentRound']);
        $this->assertCount(5, $final['rounds']);
    }

    public function testUneSeulePartieParJourEtDemarrageIdempotent(): void
    {
        $this->createEligibleMemories(5);
        $user = $this->createUser();

        $this->startQuiz($user);
        $this->assertResponseStatusCodeSame(201);
        $this->answerCurrentRound($user);
        $this->startQuiz($user);
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame(2, $this->json()['currentRound']['position'], 'la partie en cours est reprise, pas recréée');
        $this->assertSame(1, $this->em->getRepository(QuizGame::class)->count([]));
        $this->assertSame(5, $this->em->getRepository(QuizRound::class)->count([]));

        for ($i = 0; $i < 4; ++$i) {
            $this->answerCurrentRound($user);
        }
        $this->startQuiz($user);
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('finished', $this->json()['status'], 'une partie terminée ne se rejoue pas');
        $this->assertSame(1, $this->em->getRepository(QuizGame::class)->count([]));
    }

    public function testLesJoueursOntLesMemesSouvenirs(): void
    {
        $this->createEligibleMemories(9);
        [$a, $b] = [$this->createUser(), $this->createUser()];

        $this->startQuiz($a);
        $this->startQuiz($b);
        $ids = fn ($user) => array_map(fn (QuizRound $r) => $r->getMemory()?->getId(), $this->roundsOf($user));

        $this->assertSame($ids($a), $ids($b));
        $this->assertCount(5, array_unique($ids($a)));
        $this->assertSame(1, $this->em->getRepository(DailyQuiz::class)->count([]));
    }

    public function testAucuneFuiteAvantLaReponse(): void
    {
        $memories = $this->createEligibleMemories(5);
        $user = $this->createUser();
        $forbidden = ['id', 'memory', 'memoryId', 'title', 'memoryTitle', 'location', 'place', 'truth', 'year', 'trueYear', 'lat', 'lng', 'trueLat', 'trueLng', 'answer', 'distanceKm', 'score'];

        $this->startQuiz($user);
        $started = $this->json();
        $this->client->request('GET', '/api/secure/quiz/daily', server: $this->authHeaders($user));
        $daily = $this->json();

        foreach ([$started, $daily] as $body) {
            $this->assertSame(['position', 'totalRounds', 'pictureUrl'], array_keys($body['currentRound']));
            $this->assertSame([], $body['rounds']);
            $this->assertSame([], array_values(array_intersect($this->allKeys($body['currentRound'] + ['rounds' => $body['rounds']]), $forbidden)));
            $raw = json_encode($body, JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('Panthéon', $raw);
            $this->assertStringNotContainsString('1950', $raw);
            $this->assertStringNotContainsString('48.846', $raw);
        }

        // Après la première réponse, la deuxième manche reste muette : seule la première est révélée
        $this->answerCurrentRound($user);
        $answer = $this->json();
        $this->assertSame(['position', 'totalRounds', 'pictureUrl'], array_keys($answer['nextRound']));
        $this->assertCount(1, $this->daily($user)['rounds']);
        $this->assertSame(['position', 'totalRounds', 'pictureUrl'], array_keys($this->json()['currentRound']));
        $this->assertCount(5, $memories);
    }

    public function testValidationDeLaReponse(): void
    {
        $this->createEligibleMemories(5);
        $user = $this->createUser();
        $this->startQuiz($user);
        $maxYear = (int) date('Y');

        $invalid = [
            'latitude trop grande' => [['lat' => 91, 'lng' => 2, 'year' => 1950], 'answer.lat'],
            'longitude trop petite' => [['lat' => 48, 'lng' => -181, 'year' => 1950], 'answer.lng'],
            'latitude absente' => [['lng' => 2, 'year' => 1950], 'answer.lat'],
            'latitude texte' => [['lat' => 'abc', 'lng' => 2, 'year' => 1950], 'answer.lat'],
            'latitude booléen' => [['lat' => true, 'lng' => 2, 'year' => 1950], 'answer.lat'],
            'année trop ancienne' => [['lat' => 48, 'lng' => 2, 'year' => 1499], 'answer.year'],
            'année future' => [['lat' => 48, 'lng' => 2, 'year' => $maxYear + 1], 'answer.year'],
            'année décimale' => [['lat' => 48, 'lng' => 2, 'year' => 1950.5], 'answer.year'],
            'année absente' => [['lat' => 48, 'lng' => 2], 'answer.year'],
        ];
        foreach ($invalid as $label => [$payload, $field]) {
            $this->answer($user, $payload);
            $this->assertResponseStatusCodeSame(422, $label);
            $this->assertArrayHasKey($field, $this->json()['errors'], $label);
        }

        // Aucune de ces tentatives n'a consommé la manche
        $this->assertSame(1, $this->daily($user)['currentRound']['position']);

        $this->answer($user, ['lat' => 90, 'lng' => -180, 'year' => 1500]);
        $this->assertResponseStatusCodeSame(200, 'les bornes sont acceptées');
        $this->answer($user, ['lat' => '48.85', 'lng' => '2.35', 'year' => '1950']);
        $this->assertResponseStatusCodeSame(200, 'les nombres sous forme de chaîne sont acceptés');
    }

    public function testErreursDeParcours(): void
    {
        $this->createEligibleMemories(5);
        $user = $this->createUser();

        $this->client->request('POST', '/api/secure/quiz/daily/answer', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        $this->assertResponseStatusCodeSame(401);
        $this->client->request('GET', '/api/secure/quiz/daily');
        $this->assertResponseStatusCodeSame(401);
        $this->client->request('POST', '/api/secure/quiz/daily/start');
        $this->assertResponseStatusCodeSame(401);
        $this->client->request('GET', '/api/secure/quiz/me');
        $this->assertResponseStatusCodeSame(401);

        $this->answer($user, ['lat' => 48, 'lng' => 2, 'year' => 1950]);
        $this->assertResponseStatusCodeSame(404, 'pas de partie');
        $this->assertStringContainsString('Aucune partie', $this->json()['error']);

        $this->playPerfectGame($user);
        $this->answer($user, ['lat' => 48, 'lng' => 2, 'year' => 1950]);
        $this->assertResponseStatusCodeSame(409, 'partie terminée');
        $this->assertSame('La partie du jour est déjà terminée.', $this->json()['error']);
        $this->assertSame(1000, $this->daily($user)['score'], 'la réponse refusée ne change rien');
    }

    public function testUneMancheNeSeRepondQu_UneFois(): void
    {
        $this->createEligibleMemories(5);
        $user = $this->createUser();
        $this->startQuiz($user);

        $round = $this->currentRoundOf($user);
        $repository = $this->em->getRepository(QuizRound::class);
        $scored = ['distanceKm' => 0.0, 'yearDiff' => 0, 'locationScore' => 100, 'yearScore' => 100, 'score' => 200];
        $now = new \DateTimeImmutable();

        // Deux requêtes simultanées ont lu la même manche non répondue : une seule gagne
        $this->assertTrue($repository->recordAnswer($round, 1.0, 2.0, 1950, $scored, $now));
        $this->assertFalse($repository->recordAnswer($round, 3.0, 4.0, 1900, $scored, $now));

        $this->em->clear();
        $saved = $this->em->find(QuizRound::class, $round->getId());
        $this->assertSame(1.0, $saved->getAnswerLat(), 'la première réponse est conservée');
        $this->assertSame(1950, $saved->getAnswerYear());
    }

    public function testPartieAbandonneeLaVeille(): void
    {
        $this->createEligibleMemories(5);
        $user = $this->createUser();
        $this->createGame($user, -1, QuizGameStatus::IN_PROGRESS, 0);

        $state = $this->daily($user);
        $this->assertSame('not_started', $state['status'], 'la partie d\'hier n\'est pas reprise');
        $this->assertSame(['current' => 0, 'best' => 0], $state['streak']);

        $this->answer($user, ['lat' => 48, 'lng' => 2, 'year' => 1950]);
        $this->assertResponseStatusCodeSame(404);

        $this->startQuiz($user);
        $this->assertResponseStatusCodeSame(201, 'une nouvelle partie du jour démarre');
        $this->assertSame(0, $this->daily($user)['streak']['current']);
    }

    public function testMoinsDeCinqSouvenirsEligibles(): void
    {
        $this->createEligibleMemories(4);
        $user = $this->createUser();

        $this->startQuiz($user);
        $this->assertResponseStatusCodeSame(503);
        $this->assertSame('Pas assez de souvenirs pour composer le quiz du jour. Réessayez plus tard.', $this->json()['error']);
        $this->assertSame(0, $this->em->getRepository(DailyQuiz::class)->count([]));
        $this->assertSame(0, $this->em->getRepository(QuizGame::class)->count([]));

        $this->assertSame('not_started', $this->daily($user)['status'], 'la lecture de l\'état ne plante pas');

        // Le cinquième souvenir arrive : le quiz peut démarrer
        $this->createMemory($this->createUser());
        $this->startQuiz($user);
        $this->assertResponseStatusCodeSame(201);
    }

    public function testSouvenirSupprimeAvantLeDemarrageEstRemplace(): void
    {
        $memories = $this->createEligibleMemories(6);
        [$first, $second] = [$this->createUser(), $this->createUser()];

        $this->startQuiz($first); // crée le quiz du jour
        $firstIds = array_map(fn (QuizRound $r) => $r->getMemory()->getId(), $this->roundsOf($first));
        $spare = array_values(array_filter($memories, fn (Memory $m) => !in_array($m->getId(), $firstIds, true)));
        $this->assertCount(1, $spare);

        // Un souvenir du quiz est supprimé avant que le deuxième joueur démarre
        $deletedId = $firstIds[2];
        $this->em->remove($this->em->find(Memory::class, $deletedId));
        $this->em->flush();

        $this->startQuiz($second);
        $this->assertResponseStatusCodeSame(201);
        $secondIds = array_map(fn (QuizRound $r) => $r->getMemory()?->getId(), $this->roundsOf($second));

        $this->assertNotContains($deletedId, $secondIds);
        $this->assertContains($spare[0]->getId(), $secondIds, 'le souvenir de remplacement est un souvenir éligible');
        $this->assertSame(array_values(array_diff($firstIds, [$deletedId])), array_values(array_diff($secondIds, [$spare[0]->getId()])));
        $this->assertSame($spare[0]->getId(), $secondIds[2], 'même place que le souvenir supprimé');
        $this->assertCount(5, array_unique($secondIds));

        // Le premier joueur va au bout grâce à son instantané : la correction garde le titre, sans id
        for ($i = 0; $i < 5; ++$i) {
            $this->answerCurrentRound($first);
            $this->assertResponseStatusCodeSame(200);
        }
        $rounds = $this->json()['total']['rounds'];
        $this->assertNull($rounds[2]['memory']['id']);
        $this->assertSame('Le Panthéon', $rounds[2]['memory']['title']);
        $this->assertSame(1000, $this->json()['total']['score']);
    }

    public function testSouvenirSupprimePendantLaPartie(): void
    {
        $this->createEligibleMemories(5);
        $user = $this->createUser();
        $this->startQuiz($user);

        $ids = array_map(fn (QuizRound $r) => $r->getMemory()->getId(), $this->roundsOf($user));
        foreach ([$ids[0], $ids[4]] as $id) {
            $this->em->remove($this->em->find(Memory::class, $id));
        }
        $this->em->flush();

        for ($i = 0; $i < 5; ++$i) {
            $this->answerCurrentRound($user);
            $this->assertResponseStatusCodeSame(200);
        }
        $body = $this->json();
        $this->assertTrue($body['finished']);
        $this->assertSame(1000, $body['total']['score']);
        $this->assertNull($body['total']['rounds'][0]['memory']['id']);
        $this->assertNull($body['total']['rounds'][4]['memory']['id']);
        $this->assertSame($ids[1], $body['total']['rounds'][1]['memory']['id']);
    }

    public function testLaVeriteEstFigeALaCreationDeLaPartie(): void
    {
        $memories = $this->createEligibleMemories(5);
        $user = $this->createUser();
        $this->startQuiz($user);

        // Le souvenir change ensuite (année et titre) : la partie en cours ne bouge pas
        foreach ($memories as $memory) {
            $managed = $this->em->find(Memory::class, $memory->getId());
            $managed->setYear(1800)->setTitle('Autre titre');
        }
        $this->em->flush();

        $this->answerCurrentRound($user);
        $result = $this->json()['result'];
        $this->assertSame(1950, $result['truth']['year']);
        $this->assertSame('Le Panthéon', $result['memory']['title']);
        $this->assertSame(200, $result['score']);
    }

    public function testScoresDeManche(): void
    {
        $this->createEligibleMemories(5);
        $user = $this->createUser();
        $this->startQuiz($user);

        $this->answerCurrentRound($user, yearOffset: -20); // 20 ans d'écart
        $this->assertSame(45, $this->json()['result']['yearScore']);
        $this->assertSame(20, $this->json()['result']['yearDiff']);

        $this->answerCurrentRound($user, yearOffset: 19);
        $this->assertSame(47, $this->json()['result']['yearScore']);

        $this->answerCurrentRound($user, latOffset: 1.0); // ≈ 111 km au nord
        $result = $this->json()['result'];
        $this->assertEqualsWithDelta(111.2, $result['distanceKm'], 0.1);
        $this->assertSame(11, $result['locationScore']);
        $this->assertSame($result['locationScore'] + $result['yearScore'], $result['score']);
        $this->assertSame(145 + 147 + $result['score'], $this->json()['gameScore'], 'le score de partie cumule les manches');
    }

    public function testPhotoPrincipaleEtPhotoActuelleNeFuitePas(): void
    {
        $author = $this->createUser();
        // Photo actuelle (after) approuvée : ne doit jamais servir de question
        $withAfter = $this->createMemory($author, \App\Enum\PictureStatus::APPROVED);
        // « other » en position 0, « before » plus loin : before est préférée
        $withOther = $this->createMemory($author);
        $withOther->getPictures()->first()->setPosition(2);
        $withOther->addPicture($this->createPicture('autre.png', \App\Entity\Picture::TYPE_OTHER, 0, \App\Enum\PictureStatus::APPROVED, $author));
        // Sans type « before » : la première par position
        $noBefore = $this->createMemory($author);
        $noBefore->getPictures()->first()->setType(\App\Entity\Picture::TYPE_OTHER)->setPosition(3);
        $noBefore->addPicture($this->createPicture('premiere.png', \App\Entity\Picture::TYPE_OTHER, 1, \App\Enum\PictureStatus::APPROVED, $author));
        $this->createEligibleMemories(2, $author);
        $this->em->flush();

        $this->startQuiz($this->createUser());
        $this->assertResponseStatusCodeSame(201);

        $byMemory = [];
        foreach ($this->em->getRepository(QuizRound::class)->findAll() as $round) {
            $byMemory[$round->getMemory()->getId()] = $round->getPictureName();
        }
        $this->assertSame('before.png', $byMemory[$withAfter->getId()]);
        $this->assertSame('before.png', $byMemory[$withOther->getId()]);
        $this->assertSame('premiere.png', $byMemory[$noBefore->getId()]);
        $this->assertNotContains('after.png', $byMemory);
    }

    public function testAnneeEffectiveDepuisUneDateComplete(): void
    {
        $author = $this->createUser();
        $this->createEligibleMemories(4, $author);
        $dated = $this->createMemory($author);
        $this->em->find(Memory::class, $dated->getId())->setPictureDate(new \DateTime('1987-06-15'));
        $this->em->flush();

        $this->startQuiz($this->createUser());
        $years = array_map(fn (QuizRound $r) => $r->getTrueYear(), $this->em->getRepository(QuizRound::class)->findAll());
        sort($years);

        $this->assertSame([1950, 1950, 1950, 1950, 1987], $years);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ineligibles')]
    public function testSouvenirIneligibleEcarte(callable $makeIneligible): void
    {
        $author = $this->createUser();
        $memories = $this->createEligibleMemories(5, $author);
        $makeIneligible($this, $memories[0]);
        $this->em->flush();

        $this->startQuiz($this->createUser());
        $this->assertResponseStatusCodeSame(503, 'seulement 4 souvenirs éligibles');

        $this->createMemory($author);
        $this->startQuiz($this->createUser());
        $this->assertResponseStatusCodeSame(201);
        $used = array_map(fn (QuizRound $r) => $r->getMemory()->getId(), $this->em->getRepository(QuizRound::class)->findAll());
        $this->assertNotContains($memories[0]->getId(), $used);
    }

    public static function ineligibles(): iterable
    {
        yield 'sans année' => [fn (self $t, Memory $m) => $t->em->find(Memory::class, $m->getId())->setYear(null)];
        yield 'photo seulement en attente' => [fn (self $t, Memory $m) => $t->em->find(Memory::class, $m->getId())->getPictures()->first()->setStatus(\App\Enum\PictureStatus::PENDING)];
        yield 'photo seulement rejetée' => [fn (self $t, Memory $m) => $t->em->find(Memory::class, $m->getId())->getPictures()->first()->setStatus(\App\Enum\PictureStatus::REJECTED)];
        yield 'photo seulement « après »' => [fn (self $t, Memory $m) => $t->em->find(Memory::class, $m->getId())->getPictures()->first()->setType(\App\Entity\Picture::TYPE_AFTER)];
        yield 'latitude hors limites' => [function (self $t, Memory $m) {
            $t->em->getConnection()->executeStatement('UPDATE location SET latitude = 95 WHERE id = ?', [$t->em->find(Memory::class, $m->getId())->getLocation()->getId()]);
        }];
    }

    public function testDesLocalisationsDifferentesSontPrivilegiees(): void
    {
        $author = $this->createUser();
        // Une Location très fournie (6 souvenirs) et 4 autres Location d'un souvenir chacune
        $crowded = $this->createMemory($author)->getLocation();
        for ($i = 0; $i < 5; ++$i) {
            $extra = $this->createMemory($author);
            $extra->setLocation($crowded);
        }
        $this->createEligibleMemories(4, $author);
        $this->em->flush();

        for ($try = 0; $try < 4; ++$try) {
            $user = $this->createUser();
            $this->em->getConnection()->executeStatement('DELETE FROM daily_quiz');
            $this->em->getConnection()->executeStatement('DELETE FROM quiz_game');
            $this->startQuiz($user);
            $this->assertResponseStatusCodeSame(201);
            $locations = array_map(fn (QuizRound $r) => $r->getMemory()->getLocation()->getId(), $this->roundsOf($user));
            $this->assertCount(5, array_unique($locations), 'cinq Location différentes tant qu\'il y en a assez');
        }
    }

    public function testLeQuizEstIndependantDesAutresJours(): void
    {
        $this->createEligibleMemories(5);
        $user = $this->createUser();
        $this->createGame($user, -1, QuizGameStatus::FINISHED, 20000);

        $state = $this->daily($user);
        $this->assertSame('not_started', $state['status'], 'la partie d\'hier ne bloque pas celle d\'aujourd\'hui');
        $this->assertSame(1, $state['streak']['current']);
    }
}
