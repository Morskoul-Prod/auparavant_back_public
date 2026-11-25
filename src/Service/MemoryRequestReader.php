<?php

namespace App\Service;

use App\Dto\Memory\CreateMemoryRequest;
use App\Dto\Memory\CreateMemoryWithLocationRequest;
use App\Dto\Memory\MemoryContent;
use App\Dto\Memory\NewLocation;
use App\Dto\Memory\NewPlace;
use App\Dto\Memory\PlaceUpdate;
use App\Dto\Memory\UpdateMemoryRequest;
use App\Entity\Memory;
use App\Enum\PlaceType;
use App\Exception\ApiResponseException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Lit et contrôle le JSON `data` (champ multipart) des routes de création et de modification de souvenir,
 * et le transforme en objets typés (`App\Dto\Memory`). Toute erreur de forme est une 400 `{error}` :
 * « Invalid JSON data », « Champ manquant : memory.title », « Champ invalide : location.id »…
 *
 * Les champs obligatoires sont décrits par des contraintes du Validator (`Collection`) ; les règles de fond
 * (entités) sont validées ensuite par `ApiValidationErrorResponder` (422).
 */
class MemoryRequestReader
{
    private const ADDRESS_ID_MAX_LENGTH = 50;

    public function __construct(private readonly ValidatorInterface $validator)
    {
    }

    public function readCreate(Request $request): CreateMemoryRequest
    {
        $data = $this->decode($request);
        $this->require($data, [
            'location' => $this->object(['id' => $this->numeric()]),
            'place' => $this->object([]),
            'memory' => $this->object(['title' => $this->string(), 'content' => $this->string()]),
        ]);

        $locationData = $data['location'];
        $addressId = $locationData['address_id'] ?? null;
        if (!$this->isValidAddressId($addressId)) {
            throw ApiResponseException::error('Invalid address_id');
        }

        $placeData = $data['place'];
        $newPlace = null;
        $placeId = null;
        if (($placeData['create_new_place'] ?? false) == true) {
            $this->require($data, ['place' => $this->object(['name' => $this->string(), 'type' => $this->string()])]);
            $newPlace = new NewPlace($placeData['name'], $this->placeType($placeData['type']));
        } else {
            $this->require($data, ['place' => $this->object(['id' => $this->numeric()])]);
            $placeId = $placeData['id'];
        }

        return new CreateMemoryRequest($locationData['id'], $addressId, $placeId, $newPlace, $this->content($data['memory']));
    }

    public function readCreateWithLocation(Request $request): CreateMemoryWithLocationRequest
    {
        $data = $this->decode($request);
        $this->require($data, [
            'location' => $this->object([
                'area' => $this->string(),
                'department' => $this->string(),
                'street' => $this->string(),
                'city' => $this->string(),
                'zipcode' => $this->numeric(),
                'latitude' => $this->numeric(),
                'longitude' => $this->numeric(),
            ]),
            'place' => $this->object(['name' => $this->string(), 'type' => $this->string()]),
            'memory' => $this->object(['title' => $this->string(), 'content' => $this->string()]),
        ]);

        $location = $data['location'];
        $addressId = $location['address_id'] ?? null;
        if (!$this->isValidAddressId($addressId)) {
            throw ApiResponseException::error('Invalid address_id');
        }
        // Le quartier est facultatif : une chaîne vide ou blanche équivaut à « non renseigné »
        $district = is_string($location['district'] ?? null) && trim($location['district']) !== '' ? trim($location['district']) : null;

        return new CreateMemoryWithLocationRequest(
            new NewLocation($location['area'], $location['department'], $district, $location['street'], $location['city'], $location['zipcode'], $location['latitude'], $location['longitude'], $addressId),
            new NewPlace($data['place']['name'], $this->placeType($data['place']['type'])),
            $this->content($data['memory']),
        );
    }

    public function readUpdate(Request $request): UpdateMemoryRequest
    {
        $data = $this->decode($request);
        $this->require($data, ['memory' => $this->object(['title' => $this->string(), 'content' => $this->string()])]);

        // Le lieu n'est modifié que si `place.update_place` est vrai
        $placeData = is_array($data['place'] ?? null) ? $data['place'] : [];
        $placeUpdate = null;
        if (isset($placeData['update_place']) && $placeData['update_place'] == true) {
            $this->require($data, ['place' => $this->object(['id' => $this->numeric(), 'name' => $this->string(), 'type' => $this->string()])]);
            $placeUpdate = new PlaceUpdate($placeData['id'], $placeData['name'], $this->placeType($placeData['type']));
        }

        return new UpdateMemoryRequest($this->content($data['memory']), $placeUpdate);
    }

    /**
     * Titre, texte et datation. Il faut au moins `picture_date` (date exacte) ou `year` (année seule) ; si les deux
     * sont fournis, la date prime et l'année en est déduite.
     *
     * @param array<string, mixed> $memoryData
     */
    private function content(array $memoryData): MemoryContent
    {
        $rawDate = $memoryData['picture_date'] ?? null;
        $rawYear = $memoryData['year'] ?? null;
        // Une chaîne vide (champ de formulaire non rempli) équivaut à « non renseigné »
        $rawDate = $rawDate === '' ? null : $rawDate;
        $rawYear = $rawYear === '' ? null : $rawYear;

        if ($rawDate === null && $rawYear === null) {
            throw ApiResponseException::error('Indiquez une date exacte (picture_date) ou, à défaut, une année (year).');
        }

        $pictureDate = null;
        if ($rawDate !== null) {
            $pictureDate = $this->parseDate($rawDate);
            if (!$pictureDate) {
                throw ApiResponseException::error('Date invalide : picture_date doit être une date valide au format AAAA-MM-JJ.');
            }
            $year = (int) $pictureDate->format('Y');
        } else {
            if (filter_var($rawYear, FILTER_VALIDATE_INT) === false) {
                throw ApiResponseException::error('Année invalide : year doit être un entier.');
            }
            $year = (int) $rawYear;
        }

        $maxYear = (int) date('Y');
        if ($year < Memory::MIN_YEAR || $year > $maxYear) {
            throw ApiResponseException::error(sprintf('Année invalide : elle doit être comprise entre %d et %d.', Memory::MIN_YEAR, $maxYear));
        }

        return new MemoryContent($memoryData['title'], $memoryData['content'], $pictureDate, $year);
    }

    private function parseDate(mixed $value): ?\DateTime
    {
        if (!is_string($value)) {
            return null;
        }

        try {
            $date = new \DateTime($value);
        } catch (\Exception) {
            return null;
        }

        // Refuse aussi les dates qui « débordent » (ex. 1990-02-30)
        $errors = \DateTime::getLastErrors();
        if ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        return $date;
    }

    private function placeType(string $value): PlaceType
    {
        return PlaceType::tryFrom($value) ?? throw ApiResponseException::error('Invalid place type');
    }

    /** Champ optionnel address_id : absent ou null, ou chaîne de 50 caractères au plus */
    private function isValidAddressId(mixed $addressId): bool
    {
        return $addressId === null || (is_string($addressId) && mb_strlen($addressId) <= self::ADDRESS_ID_MAX_LENGTH);
    }

    /** @return array<string, mixed> */
    private function decode(Request $request): array
    {
        $raw = $request->request->all()['data'] ?? null;
        $data = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($data) || !$data) {
            throw ApiResponseException::error('Invalid JSON data');
        }

        return $data;
    }

    // =============== Contraintes ===============

    /**
     * Vérifie la présence et le type des champs ; la première violation devient la 400.
     *
     * @param array<string, Constraint> $fields contraintes par champ de premier niveau
     * @param array<string, mixed>      $data
     */
    private function require(array $data, array $fields): void
    {
        $constraint = new Assert\Collection(
            fields: array_map(fn (Constraint $c) => new Assert\Required($c), $fields),
            allowExtraFields: true,
        );

        foreach ($this->validator->validate($data, $constraint) as $violation) {
            throw $this->toResponse($violation);
        }
    }

    /**
     * Objet JSON dont les champs listés sont obligatoires. `Sequentially` s'arrête à la première erreur :
     * absent, null ou pas un tableau, avant de regarder les champs.
     *
     * @param array<string, Constraint> $fields
     */
    private function object(array $fields): Constraint
    {
        $steps = [new Assert\NotNull(), new Assert\Type('array')];
        if ($fields) {
            $steps[] = new Assert\Collection(
                fields: array_map(fn (Constraint $c) => new Assert\Required($c), $fields),
                allowExtraFields: true,
            );
        }

        return new Assert\Sequentially($steps);
    }

    private function string(): Constraint
    {
        return new Assert\Sequentially([new Assert\NotNull(), new Assert\Type('string')]);
    }

    /** Nombre, ou chaîne numérique */
    private function numeric(): Constraint
    {
        return new Assert\Sequentially([new Assert\NotNull(), new Assert\Type('numeric')]);
    }

    private function toResponse(ConstraintViolationInterface $violation): ApiResponseException
    {
        // `[memory][title]` -> `memory.title`
        $path = implode('.', array_filter(preg_split('/[\[\]]+/', $violation->getPropertyPath()) ?: []));
        $missing = $violation->getCode() === Assert\Collection::MISSING_FIELD_ERROR;

        return ApiResponseException::error(sprintf($missing ? 'Champ manquant : %s' : 'Champ invalide : %s', $path));
    }
}
