<?php

namespace App\Tests\Command;

use App\Entity\Notification;
use App\Enum\NotificationType;
use App\Tests\Support\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class PurgeSeenNotificationsCommandTest extends ApiTestCase
{
    private function notification(?string $seenAt): int
    {
        $owner = $this->createUser();
        $notification = (new Notification(NotificationType::MEMORY_DELETED))
            ->setRecipient($owner)->setMemoryTitle('Souvenir')
            ->setSeenAt($seenAt ? new \DateTimeImmutable($seenAt) : null);
        $this->em->persist($notification);
        $this->em->flush();

        return $notification->getId();
    }

    private function purge(array $options = []): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:notifications:purge'));
        $tester->execute($options);

        return $tester;
    }

    public function testSupprimeLesNotificationsVuesDepuisPlusDe30Jours(): void
    {
        $old = $this->notification('-31 days');
        $recent = $this->notification('-29 days');
        $unseen = $this->notification(null);

        $dry = $this->purge(['--dry-run' => true]);
        $this->assertStringContainsString('1 notification(s) à supprimer', $dry->getDisplay());
        $this->assertSame(3, $this->em->getRepository(Notification::class)->count([]));

        $this->assertSame(0, $this->purge()->getStatusCode());
        $this->em->clear();
        $this->assertNull($this->em->find(Notification::class, $old));
        $this->assertNotNull($this->em->find(Notification::class, $recent));
        $this->assertNotNull($this->em->find(Notification::class, $unseen));
    }

    public function testOptionDays(): void
    {
        $id = $this->notification('-10 days');

        $this->purge(['--days' => '7']);
        $this->em->clear();
        $this->assertNull($this->em->find(Notification::class, $id));
        $this->assertSame(2, $this->purge(['--days' => 'abc'])->getStatusCode());
    }
}
