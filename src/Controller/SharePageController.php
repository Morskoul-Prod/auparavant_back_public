<?php

namespace App\Controller;

use App\Entity\Memory;
use App\Repository\LocationRepository;
use App\Repository\MemoryRepository;
use App\Service\Slug;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages de partage : HTML minimal avec balises Open Graph / Twitter, titre, description et photo.
 *
 * Le front est une SPA : son HTML initial est vide, et les robots des réseaux sociaux (WhatsApp, Facebook,
 * iMessage, Slack…) ne lisent que ce HTML initial. Le serveur web du front doit donc envoyer ces robots ici
 * (même chemin, voir docs/PARTAGE_ET_ADRESSES.md) ; les visiteurs, eux, sont renvoyés vers le front.
 * L'adresse canonique (og:url, canonical) est toujours celle du front.
 */
class SharePageController extends AbstractController
{
    private readonly string $frontUrl;

    public function __construct(
        private readonly MemoryRepository $memoryRepository,
        private readonly LocationRepository $locationRepository,
        string $frontUrl,
    ) {
        // Sans slash final : les adresses sont construites en ajoutant « /souvenirs/… »
        $this->frontUrl = rtrim($frontUrl, '/');
    }

    /** /souvenirs/42-le-pont-de-pierre-rennes (l'id seul suffit, le slug est redirigé s'il est périmé) */
    #[Route('/souvenirs/{param}', name: 'share_memory', requirements: ['param' => '\d+(?:-[a-z0-9-]*)?'], methods: ['GET'])]
    public function memory(string $param, Request $request): Response
    {
        $memory = $this->memoryRepository->find((int) $param);
        if (!$memory instanceof Memory) {
            return $this->notFound();
        }

        $canonicalParam = Slug::memoryParam($memory->getId(), $memory->getTitle(), $memory->getLocation()?->getCity());
        if ($param !== $canonicalParam) {
            return $this->redirectToRoute('share_memory', ['param' => $canonicalParam], Response::HTTP_MOVED_PERMANENTLY);
        }

        $location = $memory->getLocation();
        $year = $memory->getEffectiveYear();
        $title = $memory->getTitle()
            . ($location?->getCity() ? ' – ' . $location->getCity() : '')
            . ($year ? ' (' . $year . ')' : '');

        return $this->page([
            'ogType' => 'article',
            'title' => $title,
            'heading' => $memory->getTitle(),
            'description' => $this->excerpt($memory->getContent()),
            'image' => $this->imageOf($memory, $request),
            'canonicalUrl' => $this->frontUrl . '/souvenirs/' . $canonicalParam,
            'details' => array_filter([
                $memory->getPlace()?->getName(),
                $location?->getCity(),
                $year ? (string) $year : null,
            ]),
            'memories' => [],
        ]);
    }

    /** /lieux/rennes */
    #[Route('/lieux/{slug}', name: 'share_city', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function city(string $slug, Request $request): Response
    {
        // Les noms de ville ne sont pas stockés sous forme de slug : on rapproche ceux de la base (peu nombreux)
        $cities = array_values(array_filter(
            $this->locationRepository->findDistinctCities(),
            static fn (string $city): bool => Slug::of($city) === $slug,
        ));
        $memories = $this->memoryRepository->findByCities($cities);
        if (!$memories) {
            return $this->notFound();
        }

        $city = $cities[0];
        $count = count($memories);
        $titles = implode(', ', array_map(static fn (Memory $memory): string => $memory->getTitle(), array_slice($memories, 0, 5)));

        return $this->page([
            'ogType' => 'website',
            'title' => 'Souvenirs à ' . $city . ' – photos avant/après',
            'heading' => 'Souvenirs à ' . $city,
            'description' => sprintf('%d souvenir%s à %s : %s…', $count, $count > 1 ? 's' : '', $city, $titles),
            'image' => $this->imageOf($memories[0], $request),
            'canonicalUrl' => $this->frontUrl . '/lieux/' . $slug,
            'details' => [],
            'memories' => array_map(fn (Memory $memory): array => [
                'title' => $memory->getTitle(),
                'url' => $this->frontUrl . '/souvenirs/' . Slug::memoryParam($memory->getId(), $memory->getTitle(), $memory->getLocation()?->getCity()),
            ], $memories),
        ]);
    }

    /** @param array<string, mixed> $context */
    private function page(array $context): Response
    {
        $response = $this->render('share/page.html.twig', $context);
        // Les aperçus n'ont pas besoin d'être à la seconde : de quoi soulager le serveur lors d'un partage massif
        $response->setPublic();
        $response->setMaxAge(300);

        return $response;
    }

    private function notFound(): Response
    {
        return $this->render('share/not_found.html.twig', ['frontUrl' => $this->frontUrl], new Response('', Response::HTTP_NOT_FOUND));
    }

    /** Première photo approuvée (les photos en attente ou refusées ne sont pas publiques), en URL absolue */
    private function imageOf(Memory $memory, Request $request): ?string
    {
        $picture = $memory->getApprovedPictures()[0] ?? null;

        return $picture?->getImageFullUrl($request->getSchemeAndHttpHost());
    }

    private function excerpt(?string $text, int $length = 200): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $text)));

        return mb_strimwidth($text, 0, $length, '…', 'UTF-8');
    }
}
