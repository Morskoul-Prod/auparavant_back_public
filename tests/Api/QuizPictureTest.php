<?php

namespace App\Tests\Api;

use App\Entity\QuizRound;
use App\Tests\Support\QuizTestCase;

/**
 * La photo d'une manche passe par une URL signée du quiz, jamais par l'URL publique du fichier
 * (qui permettrait de retrouver le souvenir dans GET /api/memories).
 */
class QuizPictureTest extends QuizTestCase
{
    private function startAndGetPictureUrl(): array
    {
        $memories = $this->createEligibleMemories(5);
        $user = $this->createUser();
        $this->startQuiz($user);
        $this->assertResponseStatusCodeSame(201);

        return [$user, $memories, $this->json()['currentRound']['pictureUrl']];
    }

    public function testL_UrlDeLaPhotoNeRevelePasLeFichierNiLeSouvenir(): void
    {
        [$user, , $url] = $this->startAndGetPictureUrl();

        $this->assertMatchesRegularExpression('#^/api/quiz/picture/\d+\?exp=\d+&sig=[0-9a-f]{64}$#', $url);
        $this->assertStringNotContainsString('/uploads', $url);
        $this->assertStringNotContainsString('before.png', $url);

        $roundId = $this->currentRoundOf($user)->getId();
        $this->assertStringContainsString('/api/quiz/picture/' . $roundId . '?', $url);

        // Ni l'état, ni la réponse, ni la correction ne contiennent l'URL publique ou le nom du fichier
        $this->answerCurrentRound($user);
        $answer = json_encode($this->json());
        $daily = json_encode($this->daily($user));
        foreach ([$answer, $daily] as $raw) {
            $this->assertStringNotContainsString('/uploads', $raw);
            $this->assertStringNotContainsString('before.png', $raw);
        }
        $this->assertStringContainsString('/api/quiz/picture/', json_decode($answer, true)['result']['pictureUrl']);
    }

    public function testLaPhotoSeChargeSansJwt(): void
    {
        [$user, , $url] = $this->startAndGetPictureUrl();
        $round = $this->currentRoundOf($user);
        $picture = $this->createPicture($round->getPictureName(), 'before', 0, \App\Enum\PictureStatus::APPROVED, $user);
        $this->storeFile($picture);

        $this->client->request('GET', $url); // pas d'en-tête Authorization : comme une balise <img>

        $this->assertResponseStatusCodeSame(200);
        $headers = $this->client->getResponse()->headers;
        $this->assertStringContainsString('private', $headers->get('Cache-Control'));
        $this->assertStringNotContainsString('before.png', (string) $headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $headers->get('X-Content-Type-Options'));
    }

    public function testSignatureAltereeOuAutreMancheRefusee(): void
    {
        [$user, , $url] = $this->startAndGetPictureUrl();
        $round = $this->currentRoundOf($user);
        $other = null;
        foreach ($this->roundsOf($user) as $candidate) {
            if ($candidate->getId() !== $round->getId()) {
                $other = $candidate;
                break;
            }
        }

        // Premier caractère de la signature remplacé par un autre (sinon, 1 fois sur 16, la signature resterait valide)
        $tampered = preg_replace_callback('/sig=(.)/', fn (array $m) => 'sig=' . ($m[1] === '0' ? '1' : '0'), $url);
        $this->client->request('GET', $tampered);
        $this->assertResponseStatusCodeSame(403);

        // La signature d'une manche ne vaut pas pour une autre (impossible de parcourir les ids)
        $this->client->request('GET', str_replace('/picture/' . $round->getId(), '/picture/' . $other->getId(), $url));
        $this->assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/api/quiz/picture/' . $round->getId());
        $this->assertResponseStatusCodeSame(403, 'sans signature');
        $this->assertStringContainsString('invalide ou expiré', $this->json()['error']);
    }

    public function testLienExpire(): void
    {
        [$user] = $this->startAndGetPictureUrl();
        $id = $this->currentRoundOf($user)->getId();
        $secret = static::getContainer()->getParameter('kernel.secret');
        $expired = time() - 10;
        $sig = hash_hmac('sha256', 'quiz-picture|' . $id . '|' . $expired, $secret);

        $this->client->request('GET', sprintf('/api/quiz/picture/%d?exp=%d&sig=%s', $id, $expired, $sig));
        $this->assertResponseStatusCodeSame(403);

        $valid = time() + 600;
        $this->client->request('GET', sprintf('/api/quiz/picture/%d?exp=%d&sig=%s', $id, $valid, hash_hmac('sha256', 'quiz-picture|' . $id . '|' . $valid, $secret)));
        $this->assertResponseStatusCodeSame(404, 'signature valide mais fichier absent du disque de test');
    }

    public function testMancheInconnue(): void
    {
        $secret = static::getContainer()->getParameter('kernel.secret');
        $exp = time() + 600;
        $sig = hash_hmac('sha256', 'quiz-picture|999999|' . $exp, $secret);

        $this->client->request('GET', sprintf('/api/quiz/picture/999999?exp=%d&sig=%s', $exp, $sig));
        $this->assertResponseStatusCodeSame(404);
        $this->assertSame(0, $this->em->getRepository(QuizRound::class)->count([]));
    }
}
