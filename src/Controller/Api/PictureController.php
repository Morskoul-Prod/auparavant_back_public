<?php

namespace App\Controller\Api;

use App\Entity\Memory;
use App\Entity\Picture;
use App\Enum\PictureStatus;
use App\Repository\MemoryRepository;
use App\Repository\PictureRepository;
use App\Security\RequiresVerifiedEmail;
use App\Security\Voter\MemoryVoter;
use App\Service\PictureRemovalValidator;
use App\Service\PictureTypeValidator;
use Doctrine\ORM\EntityManagerInterface;
use Nelmio\ApiDocBundle\Annotation\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Ce contrôleur gère toutes les opérations sur les photos.
 * - Lister toutes les photos
 * - Afficher une photo par son ID
 * - Ajouter des photos à un souvenir
 * - Mettre à jour une photo
 * - Supprimer une photo
 */
class PictureController extends AbstractController
{
    /**
     * Affiche toutes les photos
     */
    #[Route('/api/pictures', methods: ['GET'])]
    #[OA\Response(
        response: 200,
        description: 'Returns the picture list',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(ref: new Model(type: Picture::class, groups: ['get_picture', 'get_memory_id'])),
            example: [
                [
                    'id' => 1,
                    'imageName' => 'abc123.jpg',
                    'imageUrl' => '/uploads/memories/abc123.jpg',
                    'type' => 'other',
                    'position' => 0,
                    'memory' => ['id' => 2],
                ],
            ]
        )
    )]
    #[OA\Tag(name: 'picture')]
    public function index(PictureRepository $pictureRepository): Response
    {
        // Route publique : uniquement les photos approuvées
        $pictures = $pictureRepository->findBy(['status' => PictureStatus::APPROVED]);

        return $this->json($pictures, 200, [], ['groups' => ['get_picture', 'get_memory_id']]);
    }

    /**
     * Affiche une photo à partir de son id
     */
    #[Route('/api/picture/{id<\d+>}', methods: ['GET'])]
    #[OA\Response(
        response: 200,
        description: 'Returns a single picture',
        content: new OA\JsonContent(ref: new Model(type: Picture::class, groups: ['get_picture', 'get_memory_id']))
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Tag(name: 'picture')]
    public function read(?Picture $picture = null): Response
    {
        if (!$picture || !$picture->isApproved()) {
            return $this->json(['error' => 'Photo inexistante'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['picture' => $picture], Response::HTTP_OK, [], ['groups' => ['get_picture', 'get_memory_id']]);
    }

    /**
     * Envoie des photos pour un souvenir
     * Utilise VichUploader pour gérer les fichiers
     */
    #[Route('/api/secure/upload/pictures/{id<\d+>}', methods: ['POST'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID of the memory', schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        description: 'Pictures to upload with metadata',
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(properties: [
                new OA\Property(property: 'pictures[]', type: 'array', items: new OA\Items(type: 'string', format: 'binary')),
                new OA\Property(property: 'pictures_meta', type: 'string', description: 'JSON metadata', example: '[{"type":"other","position":0,"credit":"\u00a9 John Doe"}]'),
            ])
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Returns the uploaded pictures',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'pictures', type: 'array', items: new OA\Items(ref: new Model(type: Picture::class, groups: ['get_picture']))),
                new OA\Property(property: 'message', type: 'string'),
            ]
        )
    )]
    #[OA\Tag(name: 'picture')]
    #[RequiresVerifiedEmail]
    public function uploadPictures(
        Memory $memory,
        Request $request,
        EntityManagerInterface $entityManager,
        PictureTypeValidator $pictureTypeValidator,
    ): Response {
        if (!$this->isGranted(MemoryVoter::EDIT, $memory)) {
            return $this->json(['error' => 'Non autorisé'], Response::HTTP_FORBIDDEN);
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $pictureFiles = $request->files->get('pictures', []);
        $picturesMeta = json_decode($request->request->get('pictures_meta', '[]'), true);

        if (empty($pictureFiles)) {
            return $this->json(['error' => 'Aucune photo fournie'], Response::HTTP_BAD_REQUEST);
        }

        $picturesMeta = is_array($picturesMeta) ? $picturesMeta : [];
        $types = [];
        foreach ($pictureFiles as $index => $uploadedFile) {
            if ($uploadedFile instanceof UploadedFile) {
                $types[$index] = is_array($picturesMeta[$index] ?? null) ? ($picturesMeta[$index]['type'] ?? Picture::TYPE_OTHER) : Picture::TYPE_OTHER;
            }
        }
        if ($error = $pictureTypeValidator->validateNewTypes($memory, $types)) {
            return $error;
        }

        $newPictures = [];
        $currentPosition = $memory->getPictures()->count();

        foreach ($pictureFiles as $index => $uploadedFile) {
            if ($uploadedFile instanceof UploadedFile) {
                $picture = new Picture();

                // VichUploader gère l'upload automatiquement
                $picture->setImageFile($uploadedFile);

                // Métadonnées
                $meta = is_array($picturesMeta[$index] ?? null) ? $picturesMeta[$index] : [];
                $picture->setType($meta['type'] ?? Picture::TYPE_OTHER);
                $picture->setPosition($meta['position'] ?? $currentPosition + $index);
                // Envoi par l'auteur du souvenir : approuvé d'office
                $picture->setStatus(PictureStatus::APPROVED);
                $picture->setUploadedBy($user);

                if (isset($meta['credit'])) {
                    $picture->setCopyrightHolder($meta['credit']);
                }

                $memory->addPicture($picture);
                $newPictures[] = $picture;
            }
        }

        $entityManager->flush();

        return $this->json(
            ['pictures' => $newPictures, 'message' => 'Photo(s) ajoutée(s) avec succès'],
            Response::HTTP_CREATED,
            [],
            ['groups' => ['get_picture']]
        );
    }

    /**
     * Modifie une photo (remplace l'image et/ou met à jour les métadonnées)
     */
    #[Route('/api/secure/update/picture/{id<\d+>}', methods: ['POST'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID of the picture', schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        description: 'Picture to update',
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(properties: [
                new OA\Property(property: 'picture', type: 'string', format: 'binary', description: 'New image file (optional)'),
                new OA\Property(property: 'type', type: 'string', description: 'Picture type'),
                new OA\Property(property: 'position', type: 'integer', description: 'Position'),
                new OA\Property(property: 'credit', type: 'string', description: 'Photo credits (e.g. \u00a9 Photographer name)'),
            ])
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Returns the updated picture',
        content: new OA\JsonContent(ref: new Model(type: Picture::class, groups: ['get_picture']))
    )]
    #[OA\Tag(name: 'picture')]
    public function updatePicture(
        Picture $picture,
        Request $request,
        EntityManagerInterface $entityManager,
        PictureTypeValidator $pictureTypeValidator,
    ): Response {
        if (!$this->isGranted(MemoryVoter::EDIT, $picture->getMemory())) {
            return $this->json(['error' => 'Non autorisé'], Response::HTTP_FORBIDDEN);
        }

        if ($request->request->has('type') && ($error = $pictureTypeValidator->validateTypeChange($picture, $request->request->get('type')))) {
            return $error;
        }

        // Mettre à jour l'image si fournie
        $newFile = $request->files->get('picture');
        if ($newFile instanceof UploadedFile) {
            // VichUploader supprime automatiquement l'ancienne image et upload la nouvelle
            $picture->setImageFile($newFile);
        }

        // Mettre à jour les métadonnées
        if ($request->request->has('type')) {
            $picture->setType($request->request->get('type'));
        }
        if ($request->request->has('position')) {
            $picture->setPosition((int) $request->request->get('position'));
        }
        if ($request->request->has('credit')) {
            $picture->setCopyrightHolder($request->request->get('credit'));
        }

        $entityManager->flush();

        return $this->json(
            ['picture' => $picture, 'message' => 'Photo mise à jour'],
            Response::HTTP_OK,
            [],
            ['groups' => ['get_picture']]
        );
    }

    /**
     * Supprime une photo
     * VichUploader supprime automatiquement le fichier grâce à orphanRemoval
     */
    #[Route('/api/secure/delete/picture/{id<\d+>}', methods: ['DELETE'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Picture deleted')]
    #[OA\Tag(name: 'picture')]
    public function delete(
        Picture $picture,
        EntityManagerInterface $entityManager,
        MemoryRepository $memoryRepository,
        PictureRemovalValidator $pictureRemovalValidator,
    ): Response {
        $memory = $picture->getMemory();
        if (!$this->isGranted(MemoryVoter::EDIT, $memory)) {
            return $this->json(['error' => 'Non autorisé'], Response::HTTP_FORBIDDEN);
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        // Mêmes règles que le retrait en modification : pas la photo d'un autre utilisateur
        if ($error = $pictureRemovalValidator->forbiddenFor($user, [$picture])) {
            return $error;
        }

        // Vérifier qu'il reste au moins une photo visible (les propositions en attente ne comptent pas)
        if ($picture->isApproved() && count($memory->getApprovedPictures()) <= 1) {
            return $this->json(
                ['error' => 'Impossible de supprimer la dernière photo. Un souvenir doit contenir au moins une photo.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // VichUploader supprime automatiquement le fichier
        $memory->removePicture($picture);
        // ... ni la dernière photo « avant » ou « autre » du souvenir (rien n'est enregistré avant ce contrôle)
        if ($error = $pictureRemovalValidator->lastPictureError($memory, [$picture])) {
            return $error;
        }
        $entityManager->flush();

        return $this->json(['message' => 'Photo supprimée'], Response::HTTP_OK);
    }

    /**
     * Réordonne les photos d'un souvenir
     */
    #[Route('/api/secure/reorder/pictures/{id<\d+>}', methods: ['POST'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID of the memory', schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        description: 'New order of pictures',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'order', type: 'array', items: new OA\Items(type: 'integer'), example: [3, 1, 2]),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Pictures reordered')]
    #[OA\Tag(name: 'picture')]
    public function reorderPictures(
        Memory $memory,
        Request $request,
        EntityManagerInterface $entityManager,
        PictureRepository $pictureRepository,
    ): Response {
        if (!$this->isGranted(MemoryVoter::EDIT, $memory)) {
            return $this->json(['error' => 'Non autorisé'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $order = $data['order'] ?? [];

        foreach ($order as $position => $pictureId) {
            $picture = $pictureRepository->find($pictureId);
            if ($picture && $picture->getMemory() === $memory) {
                $picture->setPosition($position);
            }
        }

        $entityManager->flush();

        return $this->json(['message' => 'Ordre mis à jour'], Response::HTTP_OK);
    }
}
