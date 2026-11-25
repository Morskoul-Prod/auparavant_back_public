<?php

namespace App\Tests\Service;

use App\Service\EmailMasker;
use PHPUnit\Framework\TestCase;

class EmailMaskerTest extends TestCase
{
    public function testGardeLePremierCaractereEtLeDomaine(): void
    {
        $this->assertSame('j***@gmail.com', EmailMasker::mask('jean.dupont@gmail.com'));
    }

    public function testPartieLocaleDUnSeulCaractere(): void
    {
        $this->assertSame('a***@b.fr', EmailMasker::mask('a@b.fr'));
    }

    public function testChaineSansArobaseInchangee(): void
    {
        $this->assertSame('pas-un-email', EmailMasker::mask('pas-un-email'));
    }
}
