<?php

namespace App\Tests\Api;

use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\Mime\Email;

class EmailVerificationTest extends ApiTestCase
{
    private function post(string $path, array $body = [], ?User $as = null): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'] + ($as ? $this->authHeaders($as) : []);
        $this->client->request('POST', $path, server: $server, content: json_encode($body));
    }

    private function register(string $email = 'nouveau@example.com'): array
    {
        $this->post('/api/register', ['pseudonym' => 'Nouveau', 'email' => $email, 'password' => 'secret123', 'terms_accepted' => true]);
        $this->assertResponseStatusCodeSame(201);

        return $this->json();
    }

    /** Jeton contenu dans le dernier mail envoyé. */
    private function tokenFromMail(): string
    {
        /** @var Email $mail */
        $mail = $this->getMailerMessage();
        $this->assertSame('contact@auparavant.fr', $mail->getFrom()[0]->getAddress());
        $this->assertMatchesRegularExpression('#/verify-email/([0-9a-f]{64})#', $mail->getTextBody());
        preg_match('#/verify-email/([0-9a-f]{64})#', $mail->getTextBody(), $m);

        return $m[1];
    }

    public function testInscriptionEnvoieUnLienEtLeCompteEstNonConfirme(): void
    {
        $data = $this->register();
        $this->assertFalse($data['user']['emailVerified']);
        $this->assertEmailCount(1);
        $this->assertSame('nouveau@example.com', $this->email()->getTo()[0]->getAddress());
        $this->assertSame('Confirmez votre adresse email', $this->email()->getSubject());
        $this->assertStringContainsString('Merci de votre inscription', $this->email()->getTextBody());
        $this->tokenFromMail();

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'nouveau@example.com']);
        $this->assertFalse($user->isEmailVerified());
        // Le jeton n'est jamais stocké en clair
        $this->assertSame(64, strlen($user->getEmailVerificationTokenHash()));
    }

    public function testLeLienConfirmeLAdresseUneSeuleFois(): void
    {
        $this->register();
        $token = $this->tokenFromMail();

        $this->post('/api/email/verify', ['token' => $token]);
        $this->assertResponseStatusCodeSame(200);
        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'nouveau@example.com']);
        $this->assertTrue($user->isEmailVerified());
        $this->assertNull($user->getEmailVerificationTokenHash());

        $this->post('/api/email/verify', ['token' => $token]);
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('invalid_token', $this->json()['code']);
    }

    public function testJetonExpireOuInconnu(): void
    {
        $this->register();
        $token = $this->tokenFromMail();

        $this->post('/api/email/verify', ['token' => str_repeat('a', 64)]);
        $this->assertResponseStatusCodeSame(400);

        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'nouveau@example.com']);
        $user->setEmailVerificationToken($user->getEmailVerificationTokenHash(), new \DateTimeImmutable('-1 minute'));
        $this->em->flush();

        $this->post('/api/email/verify', ['token' => $token]);
        $this->assertResponseStatusCodeSame(400);
        $this->em->clear();
        $this->assertFalse($this->em->getRepository(User::class)->findOneBy(['email' => 'nouveau@example.com'])->isEmailVerified());
    }

    public function testUnNouveauLienInvalideLePrecedent(): void
    {
        $user = $this->createUser(['ROLE_USER'], false);
        $this->post('/api/secure/email/resend', [], $user);
        $this->assertResponseStatusCodeSame(200);
        $first = $this->tokenFromMail();

        $this->client->getContainer()->get('cache.rate_limiter')->clear();
        $this->post('/api/secure/email/resend', [], $user);
        $second = $this->tokenFromMail();
        $this->assertNotSame($first, $second);

        $this->post('/api/email/verify', ['token' => $first]);
        $this->assertResponseStatusCodeSame(400);
        $this->post('/api/email/verify', ['token' => $second]);
        $this->assertResponseStatusCodeSame(200);
    }

    public function testRenvoiRefuseSiDejaConfirme(): void
    {
        $user = $this->createUser();
        $this->post('/api/secure/email/resend', [], $user);
        $this->assertResponseStatusCodeSame(409);
        $this->assertEmailCount(0);
    }

    public function testRenvoiNecessiteUneConnexion(): void
    {
        $this->post('/api/secure/email/resend');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testRenvoiLimite(): void
    {
        $user = $this->createUser(['ROLE_USER'], false);
        for ($i = 0; $i < 3; $i++) {
            $this->post('/api/secure/email/resend', [], $user);
            $this->assertResponseStatusCodeSame(200);
        }
        $this->post('/api/secure/email/resend', [], $user);
        $this->assertResponseStatusCodeSame(429);
        $this->assertTrue($this->client->getResponse()->headers->has('Retry-After'));
    }

    public function testActionsReserveesAuxComptesConfirmes(): void
    {
        $user = $this->createUser(['ROLE_USER'], false);
        $memory = $this->createMemory($this->createUser());

        // Commentaire, quiz et création : 403 email_not_verified
        $this->post('/api/secure/memory/' . $memory->getId() . '/comments', ['content' => 'Bonjour'], $user);
        $this->assertResponseStatusCodeSame(403);
        $this->assertSame('email_not_verified', $this->json()['code']);

        $this->post('/api/secure/quiz/daily/start', [], $user);
        $this->assertResponseStatusCodeSame(403);
        $this->assertSame('email_not_verified', $this->json()['code']);

        $this->post('/api/secure/create/memory-and-place', [], $user);
        $this->assertResponseStatusCodeSame(403);

        // La lecture et le profil restent possibles
        $this->client->request('GET', '/api/secure/me', server: $this->authHeaders($user));
        $this->assertResponseStatusCodeSame(200);
        $this->assertFalse($this->json()['emailVerified']);
    }

    public function testUnCompteConfirmeCommente(): void
    {
        $user = $this->createUser();
        $memory = $this->createMemory($this->createUser());
        $this->post('/api/secure/memory/' . $memory->getId() . '/comments', ['content' => 'Bonjour'], $user);
        $this->assertResponseStatusCodeSame(201);
    }

    public function testLaModerationEstDispensee(): void
    {
        $moderator = $this->createUser(['ROLE_USER', 'ROLE_MODERATOR'], false);
        $memory = $this->createMemory($this->createUser());
        $this->post('/api/secure/memory/' . $memory->getId() . '/comments', ['content' => 'Bonjour'], $moderator);
        $this->assertResponseStatusCodeSame(201);
    }
}
