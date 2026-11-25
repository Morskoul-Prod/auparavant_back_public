<?php

namespace App\Tests\Api;

use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\Mime\Email;

class PasswordResetTest extends ApiTestCase
{
    private function post(string $path, array $body): void
    {
        $this->client->request('POST', $path, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($body));
    }

    /** Demande un lien et renvoie le jeton extrait du mail. */
    private function requestToken(User $user): string
    {
        $this->post('/api/password/forgot', ['email' => $user->getEmail()]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertEmailCount(1);

        /** @var Email $mail */
        $mail = $this->getMailerMessage();
        $this->assertSame('contact@auparavant.fr', $mail->getFrom()[0]->getAddress());
        $this->assertSame('Auparavant', $mail->getFrom()[0]->getName());
        $this->assertSame($user->getEmail(), $mail->getTo()[0]->getAddress());
        $this->assertMatchesRegularExpression('#/reset-password/([^"\s]+)#', $mail->getTextBody());
        preg_match('#/reset-password/([^"\s]+)#', $mail->getTextBody(), $m);

        return $m[1];
    }

    public function testLeLienDuMailPermetDeChangerLeMotDePasse(): void
    {
        $user = $this->createUser();
        $token = $this->requestToken($user);

        $this->post('/api/password/reset', ['token' => $token, 'password' => 'NouveauMdp#2026x']);
        $this->assertResponseStatusCodeSame(200);

        $this->em->clear();
        $hasher = static::getContainer()->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);
        $fresh = $this->em->find(User::class, $user->getId());
        $this->assertTrue($hasher->isPasswordValid($fresh, 'NouveauMdp#2026x'));
        $this->assertNotNull($fresh->getCredentialsChangedAt());
    }

    public function testLeJetonNeServQuUneFois(): void
    {
        $user = $this->createUser();
        $token = $this->requestToken($user);

        $this->post('/api/password/reset', ['token' => $token, 'password' => 'NouveauMdp#2026x']);
        $this->assertResponseStatusCodeSame(200);

        $this->post('/api/password/reset', ['token' => $token, 'password' => 'AutreMdp#2026xy']);
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('invalid_token', $this->json()['code']);
    }

    public function testLaVerificationDuJetonNeLeConsommePas(): void
    {
        $user = $this->createUser();
        $token = $this->requestToken($user);

        $this->post('/api/password/check', ['token' => $token]);
        $this->assertResponseStatusCodeSame(200);
        $this->post('/api/password/check', ['token' => $token]);
        $this->assertResponseStatusCodeSame(200);

        $this->post('/api/password/reset', ['token' => $token, 'password' => 'NouveauMdp#2026x']);
        $this->assertResponseStatusCodeSame(200);

        // Après usage : refusé dès l'ouverture de la page
        $this->post('/api/password/check', ['token' => $token]);
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('invalid_token', $this->json()['code']);
    }

    public function testVerificationDUnJetonInconnu(): void
    {
        $this->post('/api/password/check', ['token' => str_repeat('a', 40)]);
        $this->assertResponseStatusCodeSame(400);
        $this->post('/api/password/check', []);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testSuppressionDuCompteApresUneDemandeDeReinitialisation(): void
    {
        $user = $this->createUser();
        $id = $user->getId();
        $this->requestToken($user);

        $this->deleteAccount($user);

        $this->em->clear();
        $this->assertNull($this->em->getRepository(User::class)->find($id));
    }

    public function testJetonInconnu(): void
    {
        $this->post('/api/password/reset', ['token' => str_repeat('a', 40), 'password' => 'NouveauMdp#2026x']);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testMotDePasseTropFaibleRefuseSansConsommerLeJeton(): void
    {
        $user = $this->createUser();
        $token = $this->requestToken($user);

        $this->post('/api/password/reset', ['token' => $token, 'password' => 'abc']);
        $this->assertResponseStatusCodeSame(422);
        $this->assertArrayHasKey('password', $this->json()['errors']);

        $this->post('/api/password/reset', ['token' => $token, 'password' => 'NouveauMdp#2026x']);
        $this->assertResponseStatusCodeSame(200);
    }

    public function testCompteInconnuRepondPareilSansEnvoyerDeMail(): void
    {
        $user = $this->createUser();
        $this->post('/api/password/forgot', ['email' => $user->getEmail()]);
        $known = $this->json();
        $this->assertEmailCount(1);

        $this->client->getContainer()->get('cache.rate_limiter')->clear();
        $this->post('/api/password/forgot', ['email' => 'personne@inconnu.test']);

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame($known, $this->json());
        $this->assertEmailCount(0); // aucun mail pour un compte inconnu
    }

    public function testEmailInvalide(): void
    {
        $this->post('/api/password/forgot', ['email' => 'pas-un-email']);
        $this->assertResponseStatusCodeSame(422);
        $this->assertEmailCount(0);
    }

    public function testLimiteDeDemandesParIp(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/api/password/forgot', ['email' => 'personne@inconnu.test']);
            $this->assertResponseStatusCodeSame(200);
        }
        $this->post('/api/password/forgot', ['email' => 'personne@inconnu.test']);
        $this->assertResponseStatusCodeSame(429);
        $this->assertTrue($this->client->getResponse()->headers->has('Retry-After'));
    }
}
