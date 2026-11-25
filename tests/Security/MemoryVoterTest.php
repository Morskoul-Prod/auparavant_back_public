<?php

namespace App\Tests\Security;

use App\Security\Voter\MemoryVoter;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Modification et suppression d'un souvenir par l'API : l'auteur seul (administrateurs et modérateurs
 * passent par le back-office).
 */
class MemoryVoterTest extends ApiTestCase
{
    private function vote(string $attribute, ?object $user, object $memory): int
    {
        $token = $user ? new UsernamePasswordToken($user, 'api', $user->getRoles()) : new NullToken();

        return static::getContainer()->get(MemoryVoter::class)->vote($token, $memory, [$attribute]);
    }

    public function testLAuteurPeutModifierEtSupprimer(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(MemoryVoter::EDIT, $author, $memory));
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(MemoryVoter::DELETE, $author, $memory));
    }

    public function testPersonneDAutreNeLePeut(): void
    {
        $memory = $this->createMemory($this->createUser());

        foreach ([$this->createUser(), $this->createUser(['ROLE_MODERATOR']), $this->createUser(['ROLE_ADMIN']), null] as $other) {
            $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote(MemoryVoter::EDIT, $other, $memory));
            $this->assertSame(VoterInterface::ACCESS_DENIED, $this->vote(MemoryVoter::DELETE, $other, $memory));
        }
    }

    public function testLeVoterIgnoreLesAutresAttributsEtSujets(): void
    {
        $author = $this->createUser();
        $memory = $this->createMemory($author);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote('AUTRE', $author, $memory));
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote(MemoryVoter::EDIT, $author, $author));
    }
}
