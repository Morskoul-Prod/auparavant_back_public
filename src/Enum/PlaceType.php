<?php

namespace App\Enum;

enum PlaceType: string
{
    // Lieux de vie
    case MAISON = 'maison';
    case APPARTEMENT = 'appartement';
    case QUARTIER = 'quartier';

    // Lieux éducatifs
    case ECOLE = 'ecole';
    case COLLEGE = 'college';
    case LYCEE = 'lycee';
    case UNIVERSITE = 'universite';

    // Lieux de loisirs et culture
    case RESTAURANT = 'restaurant';
    case CAFE = 'cafe';
    case BAR = 'bar';
    case CINEMA = 'cinema';
    case THEATRE = 'theatre';
    case MUSEE = 'musee';
    case BIBLIOTHEQUE = 'bibliotheque';

    // Espaces publics
    case PARC = 'parc';
    case JARDIN_PUBLIC = 'jardin_public';
    case PLACE_PUBLIQUE = 'place_publique';
    case RUE = 'rue';

    // Nature
    case PLAGE = 'plage';
    case MONTAGNE = 'montagne';
    case FORET = 'foret';
    case LAC = 'lac';
    case RIVIERE = 'riviere';

    // Monuments et patrimoine
    case MONUMENT = 'monument';
    case EGLISE = 'eglise';
    case CHATEAU = 'chateau';

    // Lieux de voyage
    case HOTEL = 'hotel';
    case CAMPING = 'camping';
    case GITE = 'gite';

    // Lieux sportifs
    case STADE = 'stade';
    case PISCINE = 'piscine';
    case SALLE_SPORT = 'salle_sport';

    // Lieux de travail
    case BUREAU = 'bureau';
    case USINE = 'usine';
    case MAGASIN = 'magasin';

    // Divers
    case GARE = 'gare';
    case AUTRE = 'autre';

    public function getLabel(): string
    {
        return match ($this) {
            self::MAISON => 'Maison',
            self::APPARTEMENT => 'Appartement',
            self::QUARTIER => 'Quartier',

            self::ECOLE => 'École',
            self::COLLEGE => 'Collège',
            self::LYCEE => 'Lycée',
            self::UNIVERSITE => 'Université',

            self::RESTAURANT => 'Restaurant',
            self::CAFE => 'Café',
            self::BAR => 'Bar',
            self::CINEMA => 'Cinéma',
            self::THEATRE => 'Théâtre',
            self::MUSEE => 'Musée',
            self::BIBLIOTHEQUE => 'Bibliothèque',

            self::PARC => 'Parc',
            self::JARDIN_PUBLIC => 'Jardin public',
            self::PLACE_PUBLIQUE => 'Place publique',
            self::RUE => 'Rue',

            self::PLAGE => 'Plage',
            self::MONTAGNE => 'Montagne',
            self::FORET => 'Forêt',
            self::LAC => 'Lac',
            self::RIVIERE => 'Rivière',

            self::MONUMENT => 'Monument',
            self::EGLISE => 'Église',
            self::CHATEAU => 'Château',

            self::HOTEL => 'Hôtel',
            self::CAMPING => 'Camping',
            self::GITE => 'Gîte',

            self::STADE => 'Stade',
            self::PISCINE => 'Piscine',
            self::SALLE_SPORT => 'Salle de sport',

            self::BUREAU => 'Bureau',
            self::USINE => 'Usine',
            self::MAGASIN => 'Magasin',

            self::GARE => 'Gare',
            self::AUTRE => 'Autre',
        };
    }

    /**
     * Retourne tous les types de lieux disponibles
     * @return array<string, string>
     */
    public static function getChoices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) {
            $choices[$case->getLabel()] = $case->value;
        }

        return $choices;
    }

    /**
     * Retourne les types groupés par catégorie
     * @return array<string, array<PlaceType>>
     */
    public static function getGroupedTypes(): array
    {
        return [
            'Lieux de vie' => [
                self::MAISON,
                self::APPARTEMENT,
                self::QUARTIER,
            ],
            'Éducation' => [
                self::ECOLE,
                self::COLLEGE,
                self::LYCEE,
                self::UNIVERSITE,
            ],
            'Loisirs & Culture' => [
                self::RESTAURANT,
                self::CAFE,
                self::BAR,
                self::CINEMA,
                self::THEATRE,
                self::MUSEE,
                self::BIBLIOTHEQUE,
            ],
            'Espaces publics' => [
                self::PARC,
                self::JARDIN_PUBLIC,
                self::PLACE_PUBLIQUE,
                self::RUE,
            ],
            'Nature' => [
                self::PLAGE,
                self::MONTAGNE,
                self::FORET,
                self::LAC,
                self::RIVIERE,
            ],
            'Patrimoine' => [
                self::MONUMENT,
                self::EGLISE,
                self::CHATEAU,
            ],
            'Voyage' => [
                self::HOTEL,
                self::CAMPING,
                self::GITE,
            ],
            'Sport' => [
                self::STADE,
                self::PISCINE,
                self::SALLE_SPORT,
            ],
            'Travail' => [
                self::BUREAU,
                self::USINE,
                self::MAGASIN,
            ],
            'Transport' => [
                self::GARE,
            ],
            'Autre' => [
                self::AUTRE,
            ],
        ];
    }
}
