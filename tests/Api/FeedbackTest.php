<?php

namespace App\Tests\Api;

use App\Tests\Support\ApiTestCase;
use Symfony\Component\Mime\Email;

class FeedbackTest extends ApiTestCase
{
    private function send(array $body, string $ip = '10.0.0.1'): void
    {
        $this->client->request('POST', '/api/feedback', server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip], content: json_encode($body));
    }

    public function testLeMessageArriveSurLAdresseDeContactAvecLaReponseVersLAuteur(): void
    {
        $this->send(['message' => 'Le slider est super mais lent sur mon téléphone.', 'email' => 'visiteur@example.com', 'page' => '/memories']);

        $this->assertResponseStatusCodeSame(200);
        $this->assertEmailCount(1);
        /** @var Email $mail */
        $mail = $this->getMailerMessage();
        $this->assertSame('contact@auparavant.fr', $mail->getTo()[0]->getAddress());
        $this->assertSame('visiteur@example.com', $mail->getReplyTo()[0]->getAddress());
        $this->assertStringContainsString('Le slider est super', $mail->getTextBody());
        $this->assertStringContainsString('/memories', $mail->getTextBody());
    }

    public function testSansEmailPasDeReplyTo(): void
    {
        $this->send(['message' => 'Un avis anonyme assez long.']);

        $this->assertResponseStatusCodeSame(200);
        $this->assertEmailCount(1);
        $this->assertCount(0, $this->email()->getReplyTo());
    }

    public function testMessageTropCourtOuTropLongRefuse(): void
    {
        $this->send(['message' => 'ok']);
        $this->assertResponseStatusCodeSame(422);
        $this->send(['message' => str_repeat('a', 3001)]);
        $this->assertResponseStatusCodeSame(422);
        $this->assertEmailCount(0);
    }

    public function testEmailInvalideRefuse(): void
    {
        $this->send(['message' => 'Un message tout à fait correct.', 'email' => 'pas-un-email']);
        $this->assertResponseStatusCodeSame(422);
        $this->assertEmailCount(0);
    }

    public function testLePiegeAntiRobotEstSilencieux(): void
    {
        $this->send(['message' => 'Achetez mes lunettes à bas prix.', 'website' => 'http://spam.example']);

        $this->assertResponseStatusCodeSame(200);
        $this->assertEmailCount(0);
    }

    public function testUnEnTeteInjecteDansLEmailNeFabriquePasDeCopie(): void
    {
        $this->send(['message' => 'Un message tout à fait correct.', 'email' => "a@b.fr\r\nBcc: victime@example.com"]);
        $this->assertResponseStatusCodeSame(422);
        $this->assertEmailCount(0);
    }

    public function testLimiteParAdresseIp(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->send(['message' => 'Un message tout à fait correct.']);
            $this->assertResponseStatusCodeSame(200);
        }
        $this->send(['message' => 'Un message tout à fait correct.']);
        $this->assertResponseStatusCodeSame(429);

        $this->send(['message' => 'Un message tout à fait correct.'], '10.0.0.2');
        $this->assertResponseStatusCodeSame(200);
    }

    public function testUnTexteHtmlEstEchappeDansLaVersionHtml(): void
    {
        $this->send(['message' => '<script>alert(1)</script> et du texte']);
        $this->assertEmailCount(1);
        $this->assertStringNotContainsString('<script>', $this->email()->getHtmlBody());
    }
}
