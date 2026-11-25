# Auparavant — Backend

**Auparavant** est une application de souvenirs : des photos d'hier et d'aujourd'hui d'un même lieu, comparées avec un curseur avant/après.

Ce dépôt contient le **backend**, développé avec Symfony 7.4. Il fournit :

- une **API JSON** (`/api/...`) consommée par un front React / TypeScript (dépôt séparé) ;
- un **back-office Twig** (`/back/...`) pour l'administration et la modération.

## Fonctionnalités

- **Souvenirs** : photos, lieux et localisations, frises chronologiques, recherche à proximité (formule de Haversine).
- **Comparateur avant/après** : proposition de photos actuelles par les utilisateurs, validées par la modération.
- **Quiz du jour** : cinq photos à situer dans l'espace et dans le temps, scores, séries et classements.
- **Social** : commentaires avec réponses, mentions « j'aime », notifications.
- **Modération** : signalements, masquage et suppression de contenus, motif transmis à l'auteur.
- **Comptes** : confirmation de l'adresse email, mot de passe oublié, changement d'adresse sécurisé.
- **Partage** : adresses lisibles et pages Open Graph pour les robots des réseaux sociaux.

## Points techniques

| Sujet | Mise en œuvre |
|---|---|
| Authentification | JWT de courte durée et refresh token opaque en cookie `HttpOnly`, avec rotation, détection de rejeu et stockage haché |
| Autorisations | Rôles hiérarchisés (utilisateur, modérateur, administrateur) et voters Symfony |
| Sécurité des comptes | Changement d'email confirmé par lien, annulable depuis l'ancienne adresse |
| Anti-abus | Limitation de débit (`symfony/rate-limiter`) |
| Anti-triche du quiz | Réponses révélées après validation, photos servies par URL signées (HMAC) |
| Concurrence | Contraintes d'unicité et mises à jour conditionnelles contre les doubles requêtes |
| Fichiers | Contrôle du contenu des images, redimensionnement automatique à l'envoi |
| Architecture | Contrôleurs minces, services métier, DTO et contraintes du Validator |
| Qualité | Tests PHPUnit fonctionnels et unitaires, PHPStan niveau 5, php-cs-fixer |

## Stack

PHP 8.2+ · Symfony 7.4 · Doctrine ORM · MySQL 8 · LexikJWTAuthenticationBundle · VichUploader · NelmioApiDocBundle · Twig, AssetMapper, Stimulus et Turbo · Symfony Mailer (Brevo) · PHPUnit

## Organisation du code

```
src/
├── Controller/Api/     API JSON
├── Controller/Back/    back-office
├── Entity/             modèle de données
├── Service/            logique métier
├── Security/           voters et règles d'accès
├── Dto/                objets des requêtes
├── EventListener/      traitements transverses
└── Command/            tâches de maintenance (purges)
tests/                  tests fonctionnels et unitaires
```

## Installation

Prérequis : PHP 8.2 ou plus, Composer, MySQL 8.

```bash
composer install
```

Créer un fichier `.env.local` (non versionné) :

```dotenv
APP_SECRET=une_chaine_aleatoire
JWT_PASSPHRASE=une_autre_chaine_aleatoire
DATABASE_URL="mysql://utilisateur:motdepasse@127.0.0.1:3306/auparavant_back?serverVersion=8.0.32&charset=utf8mb4"
```

Puis générer les clés JWT, créer la base et lancer le serveur :

```bash
php bin/console lexik:jwt:generate-keypair
php bin/console doctrine:database:create
php bin/console doctrine:schema:create
php -d upload_max_filesize=10M -d post_max_size=40M -S localhost:8000 -t public
```

Par défaut, aucun email n'est envoyé (`MAILER_DSN=null://null`).

## Tests et qualité

```bash
php bin/console doctrine:database:create --env=test
php bin/console doctrine:schema:create --env=test
vendor/bin/phpunit

composer phpstan    # analyse statique
composer cs         # style de code
```

## Auteur

Projet développé par Christophe Le Laz.
