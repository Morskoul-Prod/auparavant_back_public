<?php

namespace App\Tests\Entity;

use App\Entity\Memory;
use PHPUnit\Framework\TestCase;

/**
 * Année de référence d'un souvenir pour le quiz : `picture_date` est une vraie date (DATETIME), pas une chaîne.
 */
class MemoryEffectiveYearTest extends TestCase
{
    public function testAnneeSeule(): void
    {
        $memory = (new Memory())->setYear(1950);

        $this->assertSame(1950, $memory->getEffectiveYear());
    }

    public function testDateComplete(): void
    {
        $memory = (new Memory())->setPictureDate(new \DateTimeImmutable('1987-06-15'));

        $this->assertSame(1987, $memory->getEffectiveYear());
    }

    public function testLaDateL_EmporteSurUneAnneeContradictoire(): void
    {
        $memory = (new Memory())->setPictureDate(new \DateTimeImmutable('1987-06-15'))->setYear(1950);

        $this->assertSame(1987, $memory->getEffectiveYear());
    }

    public function testAnneeConserveeQuandLaDateEstRetiree(): void
    {
        $memory = (new Memory())->setYear(1950);
        $memory->setPictureDate(null);

        $this->assertSame(1950, $memory->getEffectiveYear());
    }

    public function testSansDateNiAnnee(): void
    {
        $this->assertNull((new Memory())->getEffectiveYear());
    }
}
