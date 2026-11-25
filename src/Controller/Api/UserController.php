<?php

namespace App\Controller\Api;

use App\Dto\UpdateUserDto;
use App\Entity\User;
use App\Repository\EmailChangeRepository;
use App\Repository\UserRepository;
use App\Service\AccountGuard;
use App\Service\EmailChangeService;
use App\Service\LikeNotifier;
use App\Service\OrphanLocationCleaner;
use App\Service\RefreshTokenManager;
use App\Service\SessionUser;
use App\Validator\PasswordPolicy;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Nelmio\ApiDocBundle\Annotation\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Ce contrôleur regroupe les méthodes de gestion des utilisateurs et de leurs données :
 * liste (administrateurs), session courante, lecture, modification du profil, de l'email et du mot de passe,
 * et suppression du compte.
 */
class UserController extends AbstractController
{
    /**
     * Affiche tous les utilisateurs
     *
     * @param UserRepository $userRepository
     * @return Response
     */
    #[Route('/api/users', methods: ['GET'])]
    #[OA\Response(
        response: 200,
        description: 'Returns the user list',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(ref: new Model(type: User::class, groups: ['get_user', 'get_user_private'])),
            example: [
                [
                    'id' => 1,
                    'displayName' => 'Aurélien',
                    'pseudonym' => 'Aurélien',
                    'email' => 'utilisateur@example.com',
                    'roles' => [
                        'ROLE_USER',
                        'ROLE_ADMIN',
                    ],
                ],
                [
                    'id' => 2,
                    'displayName' => 'Christophe',
                    'pseudonym' => 'Christophe',
                    'email' => 'utilisateur@example.com',
                    'roles' => [
                        'ROLE_USER',
                    ],
                ],
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'JWT absent ou invalide')]
    #[OA\Response(response: 403, description: 'Réservé aux administrateurs')]
    #[OA\Tag(name: 'user')]
    #[IsGranted('ROLE_ADMIN')]
    public function index(UserRepository $userRepository)
    {
        // Fonction qui permet de lister tous les utilisateurs
        $users = $userRepository->findAll();

        return $this->json($users, 200, [], ['groups' => ['get_user', 'get_user_private']]);
    }

    /**
     * Utilisateur connecté (identité et rôles), pour recharger la session du front sans nouveau login
     */
    #[Route('/api/secure/me', methods: ['GET'])]
    #[OA\Response(
        response: 200,
        description: 'Même bloc que `data` dans la réponse de /api/login_check. `roles` ne concerne que l\'utilisateur connecté : ROLE_USER, éventuellement ROLE_MODERATOR ou ROLE_ADMIN.',
        content: new OA\JsonContent(example: [
            'id' => 5, 'pseudonym' => 'Jeanne', 'email' => 'jeanne@example.com', 'roles' => ['ROLE_USER', 'ROLE_MODERATOR'], 'emailVerified' => true, 'pendingEmail' => null,
        ])
    )]
    #[OA\Response(response: 401, description: 'JWT absent ou invalide')]
    #[OA\Tag(name: 'user')]
    public function me(SessionUser $sessionUser): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json($sessionUser->payload($user));
    }

    /**
     * Affiche un utilisateur à partir de son id
     *
     * @param User $user
     * @return Response
     */
    #[Route('/api/user/{id<\d+>}', methods: ['GET'])]
    #[OA\Response(
        response: 200,
        description: 'Returns a single user',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(ref: new Model(type: User::class, groups: ['get_user', 'get_user_private'])),
            example: [
                [
                    'id' => 1,
                    'displayName' => 'Aurélien',
                    'pseudonym' => 'Aurélien',
                    'email' => 'utilisateur@example.com',
                    'roles' => [
                        'ROLE_USER',
                        'ROLE_ADMIN',
                    ],
                ],
            ]
        )
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID of the user',
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Tag(name: 'user')]
    public function read(?User $user = null)
    {
        // Si l'utilisateur n'existe pas, on renvoie un message et une erreur 404
        if (!$user) {
            return $this->json(
                'Erreur : Utilisateur inexistant',
                404
            );
        }
        // Si il trouve un utilisateur avec l'id indique, il renvoie un code 200 et toutes les infos qu'il faut
        // Email, nom et rôles : uniquement pour l'intéressé et les administrateurs
        $current = $this->getUser();
        $private = $current === $user || $this->isGranted('ROLE_ADMIN');

        return $this->json(
            $user,
            200,
            [],
            ['groups' => $private ? ['get_user', 'get_user_private'] : ['get_user']]
        );
    }

    /**
     * Modifie le compte de l'utilisateur connecté. Seul le pseudonyme est modifiable ; tout autre champ est refusé.
     */
    #[Route('/api/secure/update/user/{id<\d+>}', methods: ['PUT'])]
    #[OA\RequestBody(
        description: 'Seul `pseudonym` est accepté (mêmes règles qu\'à l\'inscription). Tout autre champ (email, password, roles, id, createdAt…) est refusé en 422.',
        content: new OA\JsonContent(
            required: ['pseudonym'],
            properties: [
                new OA\Property(property: 'pseudonym', type: 'string', minLength: 2, maxLength: 30, example: 'John'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Compte mis à jour',
        content: new OA\JsonContent(example: [
            'user' => ['id' => 5, 'displayName' => 'John', 'pseudonym' => 'John', 'email' => 'john@example.com', 'roles' => ['ROLE_USER']],
            'message' => 'Utilisateur mis à jour',
        ])
    )]
    #[OA\Response(response: 400, description: 'Corps absent ou JSON invalide')]
    #[OA\Response(response: 401, description: 'JWT absent ou invalide')]
    #[OA\Response(response: 403, description: 'Le compte n\'est pas celui de l\'utilisateur connecté')]
    #[OA\Response(response: 404, description: 'Utilisateur inexistant')]
    #[OA\Response(
        response: 422,
        description: 'Validation ou champ interdit, messages regroupés par champ',
        content: new OA\JsonContent(example: [
            'message' => 'Données invalides',
            'errors' => ['pseudonym' => ['Ce pseudonyme est réservé, merci d\'en choisir un autre.'], 'email' => ['Ce champ ne peut pas être modifié.']],
        ])
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID of the user',
        schema: new OA\Schema(type: 'integer')
    )]
    #[OA\Tag(name: 'user')]
    public function update(Request $request, EntityManagerInterface $entityManager, ValidatorInterface $validator, ?User $user = null): Response
    {
        if (!$user) {
            return $this->json(['error' => 'Utilisateur inexistant'], Response::HTTP_NOT_FOUND);
        }
        if ($this->getUser() !== $user) {
            return $this->json(['error' => 'Vous n\'êtes pas autorisé à modifier ce compte.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON data'], Response::HTTP_BAD_REQUEST);
        }

        $errors = [];
        foreach (array_diff(array_keys($data), UpdateUserDto::FIELDS) as $field) {
            $errors[$field][] = 'Ce champ ne peut pas être modifié.';
        }

        $dto = new UpdateUserDto();
        if (is_string($data['pseudonym'] ?? null)) {
            $dto->pseudonym = trim($data['pseudonym']);
        } elseif (isset($data['pseudonym'])) {
            $errors['pseudonym'][] = 'Le pseudonyme doit être une chaîne de caractères.';
        }
        if (!isset($errors['pseudonym'])) {
            foreach ($validator->validate($dto) as $violation) {
                $errors[$violation->getPropertyPath()][] = $violation->getMessage();
            }
        }
        if ($errors) {
            return $this->json(['message' => 'Données invalides', 'errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user->setPseudonym($dto->pseudonym);
        $user->setUpdatedAt(new DateTimeImmutable());
        $entityManager->flush();

        return $this->json(['user' => $user, 'message' => 'Utilisateur mis à jour'], Response::HTTP_OK, [], ['groups' => ['get_user', 'get_user_private']]);
    }

    /**
     * Précautions communes aux routes sensibles du compte : 404 si le compte n'existe pas, 403 s'il n'est pas
     * celui du token, 429 si trop de tentatives. Renvoie null quand tout est en ordre.
     */
    private function guardAccount(Request $request, ?User $user, AccountGuard $guard): ?Response
    {
        if (!$user) {
            return $this->json(['error' => 'Utilisateur inexistant'], Response::HTTP_NOT_FOUND);
        }
        if ($this->getUser() !== $user) {
            return $this->json(['error' => 'Vous n\'êtes pas autorisé à modifier ce compte.'], Response::HTTP_FORBIDDEN);
        }

        return $guard->rateLimit($request, $user);
    }

    private function invalidData(array $errors): Response
    {
        return $this->json(['message' => 'Données invalides', 'errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Demande le changement d'email de l'utilisateur connecté (mot de passe actuel exigé) ; la nouvelle adresse
     * reste en attente jusqu'à sa confirmation par le lien envoyé à cette adresse
     */
    #[Route('/api/secure/update/user/{id<\d+>}/email', methods: ['PUT'])]
    #[OA\RequestBody(content: new OA\JsonContent(
        required: ['email', 'currentPassword'],
        properties: [
            new OA\Property(property: 'email', type: 'string', description: 'Mis en minuscules', example: 'nouveau@example.com'),
            new OA\Property(property: 'currentPassword', type: 'string', example: 'motdepasse'),
        ]
    ))]
    #[OA\Response(
        response: 200,
        description: 'Demande enregistrée : la nouvelle adresse reste en attente de confirmation (lien envoyé à cette adresse), l\'adresse du compte et les sessions ne changent pas. `pendingEmail` est null si l\'adresse demandée est déjà celle du compte.',
        content: new OA\JsonContent(example: [
            'user' => ['id' => 5, 'displayName' => 'Jeanne', 'pseudonym' => 'Jeanne', 'email' => 'jeanne@example.com', 'roles' => ['ROLE_USER'], 'emailVerified' => true],
            'pendingEmail' => 'nouveau@example.com',
            'message' => 'Un email de confirmation a été envoyé à la nouvelle adresse.',
        ])
    )]
    #[OA\Response(response: 400, description: 'Corps absent ou JSON invalide')]
    #[OA\Response(response: 401, description: 'JWT absent, invalide ou périmé')]
    #[OA\Response(response: 403, description: 'Le compte n\'est pas celui de l\'utilisateur connecté')]
    #[OA\Response(response: 404, description: 'Utilisateur inexistant')]
    #[OA\Response(response: 409, description: 'Email déjà utilisé', content: new OA\JsonContent(example: ['error' => 'L\'email est déjà utilisé par un autre utilisateur']))]
    #[OA\Response(
        response: 422,
        description: 'Validation par champ (email, currentPassword)',
        content: new OA\JsonContent(example: ['message' => 'Données invalides', 'errors' => ['currentPassword' => ['Mot de passe actuel incorrect.']]])
    )]
    #[OA\Response(response: 429, description: 'Trop de tentatives (5 par 15 minutes et par utilisateur, 20 par IP) ; en-tête Retry-After en secondes, dupliqué dans `retryAfter`')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Tag(name: 'user')]
    public function updateEmail(Request $request, EntityManagerInterface $entityManager, ValidatorInterface $validator, AccountGuard $guard, EmailChangeService $emailChange, ?User $user = null): Response
    {
        if ($response = $this->guardAccount($request, $user, $guard)) {
            return $response;
        }
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON data'], Response::HTTP_BAD_REQUEST);
        }

        $errors = [];
        if ($error = $guard->currentPasswordError($user, $data['currentPassword'] ?? null)) {
            $errors['currentPassword'][] = $error;
        }
        $email = is_string($data['email'] ?? null) ? mb_strtolower(trim($data['email'])) : null;
        foreach ($validator->validate($email, [
            new Assert\NotBlank(message: 'L\'adresse électronique est obligatoire.'),
            new Assert\Email(message: 'L\'adresse électronique {{ value }} n\'est pas valide.'),
            new Assert\Length(max: 180, maxMessage: 'L\'adresse électronique ne peut pas dépasser {{ limit }} caractères.'),
        ]) as $violation) {
            $errors['email'][] = $violation->getMessage();
        }
        if ($errors) {
            return $this->invalidData($errors);
        }

        $conflict = ['error' => 'L\'email est déjà utilisé par un autre utilisateur'];
        $other = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        // Adresse déjà prise, ou réservée par le lien de rétablissement d'un autre compte
        if (($other && $other !== $user) || $emailChange->isReservedForRevert($email, $user)) {
            return $this->json($conflict, Response::HTTP_CONFLICT);
        }

        // La nouvelle adresse reste en attente : l'adresse du compte ne change qu'après le clic sur le lien reçu
        $isChange = $email !== mb_strtolower($user->getEmail());
        if ($isChange) {
            $emailChange->request($user, $email);
        }

        return $this->json([
            'user' => $user,
            'pendingEmail' => $emailChange->pendingEmail($user),
            'message' => $isChange ? 'Un email de confirmation a été envoyé à la nouvelle adresse.' : 'Adresse inchangée.',
        ], Response::HTTP_OK, [], ['groups' => ['get_user', 'get_user_private']]);
    }

    /**
     * Change le mot de passe de l'utilisateur connecté (mot de passe actuel exigé)
     */
    #[Route('/api/secure/update/user/{id<\d+>}/password', methods: ['PUT'])]
    #[OA\RequestBody(content: new OA\JsonContent(
        required: ['currentPassword', 'newPassword'],
        properties: [
            new OA\Property(property: 'currentPassword', type: 'string', example: 'motdepasse'),
            new OA\Property(property: 'newPassword', type: 'string', minLength: 6, maxLength: 4096, example: 'nouveaumotdepasse'),
        ]
    ))]
    #[OA\Response(
        response: 200,
        description: 'Mot de passe changé. Les anciens JWT sont invalidés : `token` est le nouveau JWT à stocker.',
        content: new OA\JsonContent(example: [
            'user' => ['id' => 5, 'displayName' => 'Jeanne', 'pseudonym' => 'Jeanne', 'email' => 'jeanne@example.com', 'roles' => ['ROLE_USER']],
            'token' => 'eyJ0eXAi…',
            'message' => 'Mot de passe mis à jour',
        ])
    )]
    #[OA\Response(response: 400, description: 'Corps absent ou JSON invalide')]
    #[OA\Response(response: 401, description: 'JWT absent, invalide ou périmé')]
    #[OA\Response(response: 403, description: 'Le compte n\'est pas celui de l\'utilisateur connecté')]
    #[OA\Response(response: 404, description: 'Utilisateur inexistant')]
    #[OA\Response(
        response: 422,
        description: 'Validation par champ (currentPassword, newPassword)',
        content: new OA\JsonContent(example: ['message' => 'Données invalides', 'errors' => ['newPassword' => ['Votre mot de passe doit contenir au moins 6 caractères.']]])
    )]
    #[OA\Response(response: 429, description: 'Trop de tentatives (5 par 15 minutes et par utilisateur, 20 par IP) ; en-tête Retry-After en secondes, dupliqué dans `retryAfter`')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Tag(name: 'user')]
    public function updatePassword(Request $request, EntityManagerInterface $entityManager, ValidatorInterface $validator, AccountGuard $guard, UserPasswordHasherInterface $hasher, JWTTokenManagerInterface $jwtManager, RefreshTokenManager $refreshTokens, EmailChangeRepository $emailChanges, ?User $user = null): Response
    {
        if ($response = $this->guardAccount($request, $user, $guard)) {
            return $response;
        }
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON data'], Response::HTTP_BAD_REQUEST);
        }

        $errors = [];
        if ($error = $guard->currentPasswordError($user, $data['currentPassword'] ?? null)) {
            $errors['currentPassword'][] = $error;
        }
        $newPassword = is_string($data['newPassword'] ?? null) ? $data['newPassword'] : null;
        foreach ($validator->validate($newPassword, new PasswordPolicy()) as $violation) {
            $errors['newPassword'][] = $violation->getMessage();
        }
        if ($errors) {
            return $this->invalidData($errors);
        }

        $user->setPassword($hasher->hashPassword($user, $newPassword));
        $user->setCredentialsChangedAt(new DateTimeImmutable());
        $user->setUpdatedAt(new DateTimeImmutable());
        $entityManager->flush();

        // Les autres sessions sont fermées ; celle-ci reçoit un nouveau refresh token avec le nouveau JWT
        // Une demande de changement d'adresse faite avant ce changement de mot de passe est annulée
        $emailChanges->removePendingForUser($user);
        $refreshTokens->revokeAll($user);
        $response = $this->json(['user' => $user, 'token' => $jwtManager->create($user), 'message' => 'Mot de passe mis à jour'], Response::HTTP_OK, [], ['groups' => ['get_user', 'get_user_private']]);
        $refreshTokens->attach($response, $user);

        return $response;
    }

    /**
     * Supprime le compte de l'utilisateur connecté (mot de passe actuel exigé dans le corps)
     */
    #[Route('/api/secure/delete/user/{id<\d+>}', methods: ['DELETE'])]
    #[OA\RequestBody(content: new OA\JsonContent(
        required: ['currentPassword'],
        properties: [new OA\Property(property: 'currentPassword', type: 'string', example: 'motdepasse')]
    ))]
    #[OA\Response(response: 200, description: 'Compte supprimé', content: new OA\JsonContent(example: ['message' => 'Utilisateur supprimé']))]
    #[OA\Response(response: 400, description: 'Corps absent ou JSON invalide')]
    #[OA\Response(response: 401, description: 'JWT absent, invalide ou périmé')]
    #[OA\Response(response: 403, description: 'Le compte n\'est pas celui de l\'utilisateur connecté')]
    #[OA\Response(response: 404, description: 'Utilisateur inexistant')]
    #[OA\Response(response: 409, description: 'Le compte est le seul administrateur (code last_admin) : il ne peut pas être supprimé')]
    #[OA\Response(
        response: 422,
        description: 'Mot de passe actuel absent ou incorrect',
        content: new OA\JsonContent(example: ['message' => 'Données invalides', 'errors' => ['currentPassword' => ['Mot de passe actuel incorrect.']]])
    )]
    #[OA\Response(response: 429, description: 'Trop de tentatives (5 par 15 minutes et par utilisateur, 20 par IP) ; en-tête Retry-After en secondes, dupliqué dans `retryAfter`')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Tag(name: 'user')]
    public function delete(Request $request, EntityManagerInterface $entityManager, OrphanLocationCleaner $orphanLocationCleaner, LikeNotifier $likeNotifier, AccountGuard $guard, UserRepository $userRepository, ?User $user = null): Response
    {
        if ($response = $this->guardAccount($request, $user, $guard)) {
            return $response;
        }
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON data'], Response::HTTP_BAD_REQUEST);
        }
        if ($error = $guard->currentPasswordError($user, $data['currentPassword'] ?? null)) {
            return $this->invalidData(['currentPassword' => [$error]]);
        }

        // Le seul administrateur ne peut pas supprimer son compte (sinon le back-office n'aurait plus d'administrateur)
        if ($userRepository->isLastAdmin($user)) {
            return $this->json(['error' => 'Vous êtes le seul administrateur : donnez d\'abord ce rôle à un autre compte.', 'code' => 'last_admin'], Response::HTTP_CONFLICT);
        }

        // Les souvenirs de l'utilisateur partent en cascade : leurs Location/Place vides sont supprimés avec
        $orphanLocationCleaner->cleanAfterRemoval($user->getMemories()->toArray());
        $likeNotifier->forgetLiker($user);
        $entityManager->remove($user);
        $entityManager->flush();

        return $this->json(['message' => 'Utilisateur supprimé']);
    }
}
