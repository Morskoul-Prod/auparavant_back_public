<?php
require_once 'vendor/autoload.php';

use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;
use App\Entity\Location;
use App\Entity\Place;
use App\Entity\Memory;
use App\Entity\Picture;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

$kernel = new Kernel('dev', true);
$kernel->boot();

$container = $kernel->getContainer();
$entityManager = $container->get(EntityManagerInterface::class);
$passwordHasher = $container->get(UserPasswordHasherInterface::class);

// Créer un utilisateur de test
$user = new User();
$user->setEmail('test@example.com');
$user->setPseudonym('Testeur');
$user->setRoles(['ROLE_USER']);
$user->setPassword($passwordHasher->hashPassword($user, 'password'));
$entityManager->persist($user);

// Créer une localisation
$location = new Location();
$location->setArea('Île-de-France');
$location->setDepartment('Paris');
$location->setDistrict('Quartier latin');
$location->setStreet('Place du Panthéon');
$location->setCity('Paris');
$location->setZipcode('75005');
$location->setLatitude(48.846198);
$location->setLongitude(2.346105);
$entityManager->persist($location);

// Créer un lieu
$place = new Place();
$place->setName('Panthéon');
$place->setType('Monument historique');
$place->setLocation($location);
$entityManager->persist($place);

// Créer un souvenir
$memory = new Memory();
$memory->setTitle('Le Panthéon de Paris');
$memory->setContent('Visite du Panthéon, monument emblématique de Paris.');
$memory->setMainPicture('pantheon1.jpg');
$memory->setUser($user);
$memory->setPlace($place);
$memory->setLocation($location);
$memory->setPictureDate(new DateTime('2023-06-15'));
$entityManager->persist($memory);

// Créer des photos supplémentaires
$picture1 = new Picture();
$picture1->setPicture('pantheon2.jpg');
$picture1->setMemory($memory);
$entityManager->persist($picture1);

$picture2 = new Picture();
$picture2->setPicture('pantheon3.jpg');
$picture2->setMemory($memory);
$entityManager->persist($picture2);

// Sauvegarder
$entityManager->flush();

echo "Données de test créées avec succès !\n";
echo "Utilisateur: test@example.com / password\n";
echo "Souvenir créé avec 2 photos supplémentaires.\n";
