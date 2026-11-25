<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\EmailChangeService;
use App\Service\EmailVerificationService;
use App\Validator\PasswordPolicy;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class RegistrationController extends AbstractController
{
    /**
     * Crée un nouvel utilisateur
     * @param UserPasswordHasherInterface $userPasswordHasher
     * @param EntityManagerInterface $entityManager
     * @param Request $request
     * @return JsonResponse
     */
    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    #[OA\RequestBody(
        description: 'Données du compte à créer',
        content: new OA\JsonContent(
            required: ['pseudonym', 'email', 'password', 'terms_accepted'],
            properties: [
                new OA\Property(property: 'pseudonym', type: 'string', minLength: 2, maxLength: 30, description: 'Nom public, non unique. Certains mots sont réservés (admin, modération, support…), sans tenir compte de la casse, des accents, espaces, tirets et underscores', example: 'Jeanne'),
                new OA\Property(property: 'email', type: 'string', description: 'Unique ; mis en minuscules', example: 'jeanne@example.com'),
                new OA\Property(property: 'password', type: 'string', minLength: 6, example: 'motdepasse'),
                new OA\Property(property: 'terms_accepted', type: 'boolean', description: 'Doit valoir true : acceptation des CGU et de la politique de confidentialité (date enregistrée)', example: true),
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Compte créé (jamais le mot de passe ni son hash)',
        content: new OA\JsonContent(example: [
            'user' => ['id' => 5, 'pseudonym' => 'Jeanne', 'email' => 'jeanne@example.com', 'roles' => ['ROLE_USER']],
            'message' => 'Utilisateur enregistré',
        ])
    )]
    #[OA\Response(response: 400, description: 'Corps de requête absent ou JSON invalide')]
    #[OA\Response(
        response: 409,
        description: 'Email déjà utilisé',
        content: new OA\JsonContent(example: ['error' => 'L\'email est déjà utilisé par un autre utilisateur'])
    )]
    #[OA\Response(
        response: 422,
        description: 'Validation : messages regroupés par champ (pseudonym, email, password, terms_accepted)',
        content: new OA\JsonContent(example: [
            'message' => 'Données invalides',
            'errors' => ['terms_accepted' => ['Vous devez accepter les conditions d\'utilisation.'], 'pseudonym' => ['Ce pseudonyme est réservé, merci d\'en choisir un autre.'], 'password' => ['Votre mot de passe doit contenir au moins 6 caractères.']],
        ])
    )]
    #[OA\Tag(name: 'registration')]
    public function registerApi(UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager, ValidatorInterface $validator, Request $request, EmailVerificationService $emailVerification, EmailChangeService $emailChange): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON data'], Response::HTTP_BAD_REQUEST);
        }

        $pseudonym = is_string($data['pseudonym'] ?? null) ? $data['pseudonym'] : null;
        $email = is_string($data['email'] ?? null) ? mb_strtolower(trim($data['email'])) : null;
        $password = is_string($data['password'] ?? null) ? $data['password'] : null;

        $termsAccepted = ($data['terms_accepted'] ?? null) === true;

        $user = (new User())->setPseudonym($pseudonym)->setEmail($email ?? '');

        $errors = [];
        foreach ($validator->validate($user) as $violation) {
            $errors[$violation->getPropertyPath()][] = $violation->getMessage();
        }
        foreach ($validator->validate($password, new PasswordPolicy()) as $violation) {
            $errors['password'][] = $violation->getMessage();
        }
        if (!$termsAccepted) {
            $errors['terms_accepted'][] = 'Vous devez accepter les conditions d\'utilisation.';
        }
        if ($errors) {
            return $this->json(['message' => 'Données invalides', 'errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Adresse déjà inscrite, ou réservée par le lien de rétablissement d'un changement d'adresse (même réponse)
        if ($entityManager->getRepository(User::class)->findOneBy(['email' => $email]) || $emailChange->isReservedForRevert($email)) {
            return $this->json(['error' => 'L\'email est déjà utilisé par un autre utilisateur'], Response::HTTP_CONFLICT);
        }

        $user->setTermsAcceptedAt(new \DateTimeImmutable());
        $user->setPassword($userPasswordHasher->hashPassword($user, $password));

        $entityManager->persist($user);
        $entityManager->flush();
        $emailVerification->send($user);

        return $this->json([
            'user' => [
                'id' => $user->getId(),
                'pseudonym' => $user->getPseudonym(),
                'email' => $user->getEmail(),
                'roles' => $user->getRoles(),
                'emailVerified' => false,
                'pendingEmail' => null,
            ],
            'message' => 'Utilisateur enregistré',
        ], Response::HTTP_CREATED);
    }
}
