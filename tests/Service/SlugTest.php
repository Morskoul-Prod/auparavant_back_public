<?php

namespace App\Tests\Service;

use App\Service\Slug;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SlugTest extends TestCase
{
    #[DataProvider('texts')]
    public function testOf(string $text, string $expected): void
    {
        $this->assertSame($expected, Slug::of($text));
    }

    public static function texts(): iterable
    {
        yield 'accents et ponctuation' => ['Le Pont de Pierre !', 'le-pont-de-pierre'];
        yield 'tiret et accent' => ['Saint-Étienne', 'saint-etienne'];
        yield 'ligature' => ['Cœur d\'Alène', 'coeur-d-alene'];
        yield 'vide' => ['', ''];
        yield 'que de la ponctuation' => ['?!…', ''];
    }

    public function testOfEstTronqueSansTiretFinal(): void
    {
        $slug = Slug::of(str_repeat('abcd ', 40));

        $this->assertLessThanOrEqual(80, strlen($slug));
        $this->assertStringEndsNotWith('-', $slug);
    }

    public function testMemoryParam(): void
    {
        $this->assertSame('42-le-pont-de-pierre-rennes', Slug::memoryParam(42, 'Le Pont de Pierre', 'Rennes'));
        $this->assertSame('7-sans-ville', Slug::memoryParam(7, 'Sans ville', null));
        $this->assertSame('7', Slug::memoryParam(7, '', null));
    }
}
