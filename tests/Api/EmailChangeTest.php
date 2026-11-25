<?php

namespace App\Tests\Api;

use App\Entity\EmailChange;
use App\Entity\User;
use App\Service\RefreshTokenCookie;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\BrowserKit\Cookie as BrowserCookie;
use Symfony\Component\Mime\Email;

class EmailChangeTest extends ApiTestCase
{
    private function request(User $user, string $email): void
    {
        $this->client->request('PUT', '/api/secure/update/user/' . $user->getId() . '/email', server: $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $email, 'currentPassword' => 'secret123']));
    }

    private function userWithPassword(): User
    {
        $user = $this->createUser();
        $this->givePassword($user);

        return $user;
    }

    private function post(string $path, array $body = []): void
    {
        $this->client->request('POST', $path, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($body));
    }

    /** Fait la demande et renvoie le jeton de confirmation lu dans le mail envoyé à la nouvelle adresse. */
    private function requestToken(User $user, string $email): string
    {
        $this->request($user, $email);
        $this->assertResponseStatusCodeSame(200);
        /** @var Email $mail */
        $mail = $this->getMailerMessage();
        $this->assertMatchesRegularExpression('#/confirm-new-email/([0-9a-f]{64})#', $mail->getTextBody());
        preg_match('#/confirm-new-email/([0-9a-f]{64})#', $mail->getTextBody(), $m);

        return $m[1];
    }

    /** Connexion par l'API : renvoie le refresh token posé en cookie. */
    private function loginRefreshToken(User $user): string
    {
        $this->post('/api/login_check', ['username' => $user->getEmail(), 'password' => 'secret123']);
        $this->assertResponseIsSuccessful();
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === RefreshTokenCookie::NAME) {
                return $cookie->getValue();
            }
        }
        $this->fail('Cookie refresh_token absent');
    }

    /** Jeton de confirmation contenu dans le dernier mail envoyé. */
    private function tokenFromLastMail(): string
    {
        /** @var Email $mail */
        $mail = $this->getMailerMessage();
        preg_match('#/confirm-new-email/([0-9a-f]{64})#', $mail->getTextBody(), $m);
        $this->assertNotEmpty($m, 'Lien de confirmation absent du mail');

        return $m[1];
    }

    /** Demande puis confirme un changement ; renvoie le jeton de rétablissement lu dans l'alerte envoyée à l'ancienne adresse. */
    private function changeAndConfirm(User $user, string $newEmail): string
    {
        $token = $this->requestToken($user, $newEmail);
        $this->post('/api/email/change/confirm', ['token' => $token]);
        $this->assertResponseStatusCodeSame(200);
        /** @var Email $mail */
        $mail = $this->getMailerMessage();
        $this->assertMatchesRegularExpression('#/revert-email/([0-9a-f]{64})#', $mail->getTextBody());
        preg_match('#/revert-email/([0-9a-f]{64})#', $mail->getTextBody(), $m);

        return $m[1];
    }

    private function revert(string $token): void
    {
        $this->post('/api/email/change/revert', ['token' => $token]);
    }

    private function register(string $email): void
    {
        $this->post('/api/register', ['pseudonym' => 'Squatteur', 'email' => $email, 'password' => 'secret123', 'terms_accepted' => true]);
    }

    private function freshUser(User $user): User
    {
        $this->em->clear();

        return $this->em->find(User::class, $user->getId());
    }

    private function resend(User $user): void
    {
        $this->client->request('POST', '/api/secure/email/resend', server: $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json']);
    }

    private function expireConfirmation(): void
    {
        $this->em->createQuery('UPDATE ' . EmailChange::class . ' c SET c.confirmExpiresAt = :past')
            ->setParameter('past', new \DateTimeImmutable('-1 minute'))
            ->execute();
    }

    /** @return list<EmailChange> */
    private function changes(): array
    {
        $this->em->clear();

        return $this->em->getRepository(EmailChange::class)->findAll();
    }

    public function testLaDemandeLaisseLAdresseEtLaConfirmationInchangees(): void
    {
        $user = $this->userWithPassword();
        $old = $user->getEmail();

        $this->request($user, 'nouveau@example.com');

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('nouveau@example.com', $this->json()['pendingEmail']);
        $this->em->clear();
        $fresh = $this->em->find(User::class, $user->getId());
        $this->assertSame($old, $fresh->getEmail());
        $this->assertTrue($fresh->isEmailVerified());
        $this->assertCount(1, $this->changes());

        $this->assertEmailCount(1);
        /** @var Email $mail */
        $mail = $this->getMailerMessage();
        $this->assertSame('nouveau@example.com', $mail->getTo()[0]->getAddress());
        $this->assertSame('Confirmez votre nouvelle adresse email', $mail->getSubject());
        $this->assertMatchesRegularExpression('#/confirm-new-email/[0-9a-f]{64}#', $mail->getTextBody());
        $this->assertStringContainsString('changement d\'adresse email', $mail->getTextBody());
        $this->assertStringNotContainsString('Merci de votre inscription', $mail->getTextBody());
        $this->assertStringContainsString('Confirmer ma nouvelle adresse', $mail->getHtmlBody());
    }

    public function testUneNouvelleDemandeRemplaceLaPrecedenteEnAttente(): void
    {
        $user = $this->userWithPassword();

        $this->request($user, 'premiere@example.com');
        $this->request($user, 'seconde@example.com');

        $this->assertResponseStatusCodeSame(200);
        $changes = $this->changes();
        $this->assertCount(1, $changes);
        $this->assertSame('seconde@example.com', $changes[0]->getNewEmail());
    }

    public function testDemandeVersLAdresseCouranteNeCreeRien(): void
    {
        $user = $this->userWithPassword();

        $this->request($user, strtoupper($user->getEmail()));

        $this->assertResponseStatusCodeSame(200);
        $this->assertCount(0, $this->changes());
        $this->assertEmailCount(0);
    }

    public function testDemandeVersUneAdressePriseRepond409(): void
    {
        $user = $this->userWithPassword();
        $other = $this->createUser();

        $this->request($user, $other->getEmail());

        $this->assertResponseStatusCodeSame(409);
        $this->assertCount(0, $this->changes());
        $this->assertEmailCount(0);
    }

    public function testLeJwtResteValideApresLaDemande(): void
    {
        $user = $this->userWithPassword();
        $jwt = $this->jwtFor($user);

        $this->request($user, 'nouveau@example.com');
        $this->assertResponseStatusCodeSame(200);

        $this->client->request('GET', '/api/secure/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]);
        $this->assertResponseStatusCodeSame(200);
    }

    public function testSuppressionDeCompteAvecUnChangementEnAttente(): void
    {
        $user = $this->userWithPassword();
        $id = $user->getId();
        $this->request($user, 'nouveau@example.com');
        $this->assertResponseStatusCodeSame(200);

        $this->deleteAccount($user);

        $this->em->clear();
        $this->assertNull($this->em->find(User::class, $id));
        $this->assertCount(0, $this->changes());
    }

    public function testPendingEmailDansMeEtDansLeLogin(): void
    {
        $user = $this->userWithPassword();

        $this->client->request('GET', '/api/secure/me', server: $this->authHeaders($user));
        $this->assertNull($this->json()['pendingEmail']);

        $this->request($user, 'nouveau@example.com');

        $this->client->request('GET', '/api/secure/me', server: $this->authHeaders($user));
        $this->assertSame('nouveau@example.com', $this->json()['pendingEmail']);

        $this->client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['username' => $user->getEmail(), 'password' => 'secret123']));
        $this->assertSame('nouveau@example.com', $this->json()['data']['pendingEmail']);
    }

    public function testLaConfirmationEchangeLAdresse(): void
    {
        $user = $this->userWithPassword();
        $old = $user->getEmail();
        $token = $this->requestToken($user, 'nouveau@example.com');

        $this->post('/api/email/change/confirm', ['token' => $token]);

        $this->assertResponseStatusCodeSame(200);
        $this->em->clear();
        $fresh = $this->em->find(User::class, $user->getId());
        $this->assertSame('nouveau@example.com', $fresh->getEmail());
        $this->assertTrue($fresh->isEmailVerified());

        $this->post('/api/login_check', ['username' => 'nouveau@example.com', 'password' => 'secret123']);
        $this->assertResponseStatusCodeSame(200);
        $this->post('/api/login_check', ['username' => $old, 'password' => 'secret123']);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testLaConfirmationFermeLesSessions(): void
    {
        $user = $this->userWithPassword();
        $jwt = $this->jwtFor($user);
        $refresh = $this->loginRefreshToken($user);
        $token = $this->requestToken($user, 'nouveau@example.com');

        $this->post('/api/email/change/confirm', ['token' => $token]);
        $this->assertResponseStatusCodeSame(200);

        $this->client->request('GET', '/api/secure/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]);
        $this->assertResponseStatusCodeSame(401);
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set(new BrowserCookie(RefreshTokenCookie::NAME, $refresh, null, RefreshTokenCookie::PATH));
        $this->client->request('POST', '/api/token/refresh', server: ['HTTP_ORIGIN' => 'http://localhost:5173']);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testLaConfirmationAlerteLAncienneAdresse(): void
    {
        $user = $this->userWithPassword();
        $old = $user->getEmail();
        $token = $this->requestToken($user, 'nouveau@example.com');

        $this->post('/api/email/change/confirm', ['token' => $token]);

        $this->assertEmailCount(1);
        /** @var Email $mail */
        $mail = $this->getMailerMessage();
        $this->assertSame($old, $mail->getTo()[0]->getAddress());
        $this->assertSame('Votre adresse email a été modifiée', $mail->getSubject());
        $this->assertMatchesRegularExpression('#/revert-email/[0-9a-f]{64}#', $mail->getTextBody());
        $this->assertStringContainsString('n***@example.com', $mail->getTextBody());
        $this->assertStringNotContainsString('nouveau@example.com', $mail->getTextBody());
        $this->assertStringNotContainsString('nouveau@example.com', $mail->getHtmlBody());
    }

    public function testJetonDeConfirmationAUsageUnique(): void
    {
        $user = $this->userWithPassword();
        $token = $this->requestToken($user, 'nouveau@example.com');

        $this->post('/api/email/change/confirm', ['token' => $token]);
        $this->assertResponseStatusCodeSame(200);
        $this->post('/api/email/change/confirm', ['token' => $token]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('invalid_token', $this->json()['code']);
    }

    public function testJetonDeConfirmationExpire(): void
    {
        $user = $this->userWithPassword();
        $old = $user->getEmail();
        $token = $this->requestToken($user, 'nouveau@example.com');
        $this->expireConfirmation();

        $this->post('/api/email/change/confirm', ['token' => $token]);

        $this->assertResponseStatusCodeSame(400);
        $this->em->clear();
        $this->assertSame($old, $this->em->find(User::class, $user->getId())->getEmail());
    }

    public function testJetonVideOuInconnu(): void
    {
        $this->post('/api/email/change/confirm', ['token' => str_repeat('a', 64)]);
        $this->assertResponseStatusCodeSame(400);
        $this->post('/api/email/change/confirm', ['token' => '']);
        $this->assertResponseStatusCodeSame(400);
        $this->post('/api/email/change/confirm', []);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testAdressePriseEntreLaDemandeEtLaConfirmation(): void
    {
        $user = $this->userWithPassword();
        $old = $user->getEmail();
        $token = $this->requestToken($user, 'nouveau@example.com');
        $other = $this->createUser();
        $other->setEmail('nouveau@example.com');
        $this->em->flush();

        $this->post('/api/email/change/confirm', ['token' => $token]);

        $this->assertResponseStatusCodeSame(409);
        $this->assertSame('email_taken', $this->json()['code']);
        $this->em->clear();
        $this->assertSame($old, $this->em->find(User::class, $user->getId())->getEmail());
    }

    public function testConfirmationLimiteeParIp(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->post('/api/email/change/confirm', ['token' => 'x']);
            $this->assertResponseStatusCodeSame(400);
        }
        $this->post('/api/email/change/confirm', ['token' => 'x']);

        $this->assertResponseStatusCodeSame(429);
        $this->assertTrue($this->client->getResponse()->headers->has('Retry-After'));
    }

    public function testLeRenvoiCibleLaNouvelleAdresseEnAttente(): void
    {
        $user = $this->userWithPassword();
        $first = $this->requestToken($user, 'nouveau@example.com');

        $this->resend($user);

        $this->assertResponseStatusCodeSame(200);
        $this->assertEmailCount(1);
        $this->assertSame('nouveau@example.com', $this->email()->getTo()[0]->getAddress());
        $second = $this->tokenFromLastMail();
        $this->assertNotSame($first, $second);

        $this->post('/api/email/change/confirm', ['token' => $first]);
        $this->assertResponseStatusCodeSame(400);
        $this->post('/api/email/change/confirm', ['token' => $second]);
        $this->assertResponseStatusCodeSame(200);
    }

    public function testRenvoiApresExpirationDuLien(): void
    {
        $user = $this->userWithPassword();
        $expired = $this->requestToken($user, 'nouveau@example.com');
        $this->expireConfirmation();

        $this->resend($user);
        $this->assertResponseStatusCodeSame(200);
        $fresh = $this->tokenFromLastMail();

        $this->post('/api/email/change/confirm', ['token' => $expired]);
        $this->assertResponseStatusCodeSame(400);
        $this->post('/api/email/change/confirm', ['token' => $fresh]);
        $this->assertResponseStatusCodeSame(200);
    }

    public function testSansDemandeEnAttenteUnCompteConfirmeRecoitToujours409(): void
    {
        $user = $this->userWithPassword();

        $this->resend($user);

        $this->assertResponseStatusCodeSame(409);
        $this->assertSame('already_verified', $this->json()['code']);
        $this->assertEmailCount(0);
    }

    public function testLeRetablissementRestaureLAdresseEtVerrouilleLeCompte(): void
    {
        $user = $this->userWithPassword();
        $old = $user->getEmail();
        $revertToken = $this->changeAndConfirm($user, 'pirate@example.com');

        $this->revert($revertToken);

        $this->assertResponseStatusCodeSame(200);
        // Un lien de réinitialisation part vers l'ancienne adresse (à lire avant la requête suivante)
        $this->assertEmailCount(1);
        /** @var Email $mail */
        $mail = $this->getMailerMessage();
        $this->assertSame($old, $mail->getTo()[0]->getAddress());
        $this->assertMatchesRegularExpression('#/reset-password/([^"\s]+)#', $mail->getTextBody());
        preg_match('#/reset-password/([^"\s]+)#', $mail->getTextBody(), $m);
        $resetToken = $m[1];

        $fresh = $this->freshUser($user);
        $this->assertSame($old, $fresh->getEmail());
        $this->assertTrue($fresh->isEmailVerified());
        // L'ancien mot de passe (que le pirate peut connaître) ne marche plus, ni l'adresse du pirate
        $this->post('/api/login_check', ['username' => $old, 'password' => 'secret123']);
        $this->assertResponseStatusCodeSame(401);
        $this->post('/api/login_check', ['username' => 'pirate@example.com', 'password' => 'secret123']);
        $this->assertResponseStatusCodeSame(401);

        // Le lien reçu permet de reprendre la main avec un nouveau mot de passe
        $this->post('/api/password/reset', ['token' => $resetToken, 'password' => 'NouveauMdp#2026x']);
        $this->assertResponseStatusCodeSame(200);
        $this->post('/api/login_check', ['username' => $old, 'password' => 'NouveauMdp#2026x']);
        $this->assertResponseStatusCodeSame(200);
    }

    public function testLeRetablissementFermeLesSessions(): void
    {
        $user = $this->userWithPassword();
        $revertToken = $this->changeAndConfirm($user, 'pirate@example.com');
        // Session ouverte par le pirate avec la nouvelle adresse
        $this->post('/api/login_check', ['username' => 'pirate@example.com', 'password' => 'secret123']);
        $this->assertResponseStatusCodeSame(200);
        $pirateJwt = $this->json()['token'];

        $this->revert($revertToken);

        $this->assertResponseStatusCodeSame(200);
        $this->client->request('GET', '/api/secure/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $pirateJwt]);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testLeRetablissementPurgeLesDemandesDeReinitialisationEnCours(): void
    {
        $user = $this->userWithPassword();
        $revertToken = $this->changeAndConfirm($user, 'pirate@example.com');
        // Le pirate demande un lien de réinitialisation vers SA adresse avant le rétablissement
        $this->post('/api/password/forgot', ['email' => 'pirate@example.com']);
        $this->assertEmailCount(1);
        preg_match('#/reset-password/([^"\s]+)#', $this->email()->getTextBody(), $m);
        $pirateResetToken = $m[1];

        $this->revert($revertToken);
        $this->assertResponseStatusCodeSame(200);

        $this->post('/api/password/reset', ['token' => $pirateResetToken, 'password' => 'PirateMdp#2026x']);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testLeRetablissementEstAUsageUnique(): void
    {
        $user = $this->userWithPassword();
        $revertToken = $this->changeAndConfirm($user, 'pirate@example.com');

        $this->revert($revertToken);
        $this->assertResponseStatusCodeSame(200);
        $this->revert($revertToken);

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('invalid_token', $this->json()['code']);
    }

    public function testLeLienDeRetablissementExpireAuBoutDeSeptJours(): void
    {
        $user = $this->userWithPassword();
        $revertToken = $this->changeAndConfirm($user, 'pirate@example.com');
        $this->em->createQuery('UPDATE ' . EmailChange::class . ' c SET c.revertExpiresAt = :past')
            ->setParameter('past', new \DateTimeImmutable('-1 minute'))
            ->execute();

        $this->revert($revertToken);

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('pirate@example.com', $this->freshUser($user)->getEmail());
    }

    public function testRetablissementAvecUnJetonVideOuInconnu(): void
    {
        $this->revert('');
        $this->assertResponseStatusCodeSame(400);
        $this->revert(str_repeat('b', 64));
        $this->assertResponseStatusCodeSame(400);
        $this->post('/api/email/change/revert', []);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testLeLienDuPremierChangementDefaitUnePirateQuiChangeDeuxFois(): void
    {
        $user = $this->userWithPassword();
        $original = $user->getEmail();
        $firstLink = $this->changeAndConfirm($user, 'pirate1@example.com');
        $user = $this->freshUser($user);
        $secondLink = $this->changeAndConfirm($user, 'pirate2@example.com');
        $this->assertSame('pirate2@example.com', $this->freshUser($user)->getEmail());

        $this->revert($firstLink);

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame($original, $this->freshUser($user)->getEmail());
        // Tous les autres liens de rétablissement sont invalidés
        $this->revert($secondLink);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testRetablissementImpossibleSiLAncienneAdresseEstPrise(): void
    {
        $user = $this->userWithPassword();
        $original = $user->getEmail();
        $revertToken = $this->changeAndConfirm($user, 'pirate@example.com');
        $squatter = $this->createUser();
        $squatter->setEmail($original);
        $this->em->flush();

        $this->revert($revertToken);

        $this->assertResponseStatusCodeSame(409);
        $this->assertSame('email_taken', $this->json()['code']);
        $this->assertSame('pirate@example.com', $this->freshUser($user)->getEmail());
    }

    public function testLeRetablissementSupprimeLesDemandesEnAttente(): void
    {
        $user = $this->userWithPassword();
        $revertToken = $this->changeAndConfirm($user, 'pirate@example.com');
        $user = $this->freshUser($user);
        $this->request($user, 'encore-un-autre@example.com');
        $this->assertResponseStatusCodeSame(200);

        $this->revert($revertToken);

        $this->assertResponseStatusCodeSame(200);
        $pending = array_filter($this->changes(), static fn (EmailChange $c) => $c->getConfirmedAt() === null);
        $this->assertCount(0, $pending);
    }

    // --- passe de correction après la relecture indépendante ---

    public function testLePirateNePeutPasTuerLeLienDeLaVictimeAvecLeSien(): void
    {
        $user = $this->userWithPassword();
        $original = $user->getEmail();
        $victimLink = $this->changeAndConfirm($user, 'pirate1@example.com');
        $user = $this->freshUser($user);
        $pirateLink = $this->changeAndConfirm($user, 'pirate2@example.com'); // l'alerte part vers pirate1@, boîte du pirate

        // Le pirate clique son propre lien : le compte revient sur pirate1@
        $this->revert($pirateLink);
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('pirate1@example.com', $this->freshUser($user)->getEmail());

        // Le lien envoyé à la victime reste utilisable et lui rend le compte
        $this->revert($victimLink);
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame($original, $this->freshUser($user)->getEmail());
    }

    public function testUnCompteNonConfirmeNeProduitNiAlerteNiLienDeRetablissement(): void
    {
        // Inscription avec une faute de frappe : l'ancienne adresse n'a jamais été prouvée
        $user = $this->createUser(['ROLE_USER'], false);
        $this->givePassword($user);
        $token = $this->requestToken($user, 'corrigee@example.com');

        $this->post('/api/email/change/confirm', ['token' => $token]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertEmailCount(0);
        $changes = $this->changes();
        $this->assertCount(1, $changes);
        $this->assertNull($changes[0]->getRevertExpiresAt());
        $this->assertSame('corrigee@example.com', $this->freshUser($user)->getEmail());
    }

    public function testUneDemandeEnAttenteNeSurvitPasALaReinitialisationDuMotDePasse(): void
    {
        $user = $this->userWithPassword();
        $token = $this->requestToken($user, 'pirate@example.com');
        $this->post('/api/password/forgot', ['email' => $user->getEmail()]);
        preg_match('#/reset-password/([^"\s]+)#', $this->email()->getTextBody(), $m);

        $this->post('/api/password/reset', ['token' => $m[1], 'password' => 'NouveauMdp#2026x']);
        $this->assertResponseStatusCodeSame(200);

        $this->post('/api/email/change/confirm', ['token' => $token]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testUneDemandeEnAttenteNeSurvitPasAUnChangementDeMotDePasse(): void
    {
        $user = $this->userWithPassword();
        $token = $this->requestToken($user, 'pirate@example.com');

        $this->client->request('PUT', '/api/secure/update/user/' . $user->getId() . '/password', server: $this->authHeaders($user) + ['CONTENT_TYPE' => 'application/json'], content: json_encode(['currentPassword' => 'secret123', 'newPassword' => 'nouveau-secret']));
        $this->assertResponseStatusCodeSame(200);

        $this->post('/api/email/change/confirm', ['token' => $token]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testUnEchecEnCoursDeRetablissementNeLaisseRienDeMoitie(): void
    {
        $user = $this->userWithPassword();
        $userId = $user->getId();
        $revertToken = $this->changeAndConfirm($user, 'pirate@example.com');
        $this->em->clear();
        // Le flush échoue après les requêtes de masse : sans transaction, les liens de rétablissement seraient perdus
        $this->em->getEventManager()->addEventListener('onFlush', new class {
            public function onFlush(): void
            {
                throw new \RuntimeException('échec simulé');
            }
        });

        $this->revert($revertToken);

        $this->assertResponseStatusCodeSame(500);
        $connection = $this->em->getConnection();
        $this->assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM email_change WHERE revert_token_hash IS NOT NULL'));
        $this->assertSame('pirate@example.com', $connection->fetchOne('SELECT email FROM user WHERE id = ?', [$userId]));
    }

    public function testDemandeVersLAdresseCouranteStockeeAvecDesMajuscules(): void
    {
        $user = $this->createUser();
        $user->setEmail('Jean.Probe@Example.com');
        $this->em->flush();
        $this->givePassword($user);

        $this->request($user, 'jean.probe@example.com');

        $this->assertResponseStatusCodeSame(200);
        $this->assertCount(0, $this->changes());
        $this->assertEmailCount(0);
    }

    public function testSuppressionDeCompteAvecUnChangementConfirme(): void
    {
        $user = $this->userWithPassword();
        $id = $user->getId();
        $this->changeAndConfirm($user, 'nouveau@example.com');
        $user = $this->freshUser($user);

        $this->deleteAccount($user);

        $this->em->clear();
        $this->assertNull($this->em->find(User::class, $id));
        $this->assertCount(0, $this->changes());
    }

    public function testLAncienneAdresseEstReserveePendantLeDelaiDeRetablissement(): void
    {
        $user = $this->userWithPassword();
        $original = $user->getEmail();
        $revertToken = $this->changeAndConfirm($user, 'pirate@example.com');

        // Personne d'autre ne peut la prendre : ni par inscription, ni par changement d'adresse
        $this->register($original);
        $this->assertResponseStatusCodeSame(409);
        $other = $this->userWithPassword();
        $this->request($other, $original);
        $this->assertResponseStatusCodeSame(409);

        // Le titulaire du changement, lui, peut la reprendre (c'est son adresse)
        $user = $this->freshUser($user);
        $this->request($user, $original);
        $this->assertResponseStatusCodeSame(200);

        // Une fois le rétablissement utilisé, l'adresse n'est plus réservée... par ce lien
        $this->revert($revertToken);
        $this->assertResponseStatusCodeSame(200);
    }

    public function testLaReservationPrendFinAvecLeLien(): void
    {
        $user = $this->userWithPassword();
        $original = $user->getEmail();
        $this->changeAndConfirm($user, 'pirate@example.com');
        $this->em->createQuery('UPDATE ' . EmailChange::class . ' c SET c.revertExpiresAt = :past')
            ->setParameter('past', new \DateTimeImmutable('-1 minute'))
            ->execute();

        $this->register($original);

        $this->assertResponseStatusCodeSame(201);
    }
}
