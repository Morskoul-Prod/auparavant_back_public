<?php

namespace App\Service;

use App\Dto\Memory\CurrentPictureResult;
use App\Entity\Memory;
use App\Entity\Picture;
use App\Entity\User;
use App\Enum\PictureStatus;
use App\Exception\ApiResponseException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ajoute la photo actuelle (type « after ») d'un souvenir pour le slider avant/après.
 *
 * Approuvée d'office pour l'auteur du souvenir quand il n'y a pas encore de photo actuelle, sinon en attente de
 * validation par un administrateur ou un modérateur. Si une photo actuelle approuvée existe déjà, la nouvelle
 * (toujours en attente, même pour l'auteur) la remplace à sa validation : l'ancienne reste visible d'ici là.
 */
class CurrentPictureAdder
{
    private const MAX_CREDIT_LENGTH = 255;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ApiValidationErrorResponder $validationErrorResponder,
        private readonly PictureFileValidator $pictureFileValidator,
        private readonly BeforePictureChooser $beforePictureChooser,
        private readonly ModerationNotifier $moderationNotifier,
    ) {
    }

    /**
     * @param bool $isAuthor l'utilisateur est l'auteur du souvenir
     *
     * @throws ApiResponseException 400 fichier absent, 409 proposition déjà en attente, 422 fichier ou champs invalides
     */
    public function add(Memory $memory, User $user, bool $isAuthor, Request $request): CurrentPictureResult
    {
        // Photo actuelle déjà en place : la nouvelle est un remplacement, toujours modéré
        $current = $memory->getApprovedAfterPicture();
        if ($memory->getPendingAfterPictureOf($user)) {
            throw ApiResponseException::error('Vous avez déjà proposé une photo actuelle pour ce souvenir : elle est en attente de validation.', Response::HTTP_CONFLICT);
        }

        $file = $request->files->get('picture');
        if (!$file instanceof UploadedFile) {
            throw ApiResponseException::error('Champ manquant : picture');
        }

        // Mêmes règles que le formulaire d'image du back-office : 10 Mo, JPEG/PNG/GIF/WebP
        $errors = $this->validationErrorResponder->formatViolations('picture', $this->pictureFileValidator->validate($file));

        $credit = $request->request->get('credit');
        if ($credit !== null && (!is_string($credit) || mb_strlen($credit) > self::MAX_CREDIT_LENGTH)) {
            $errors['credit'][] = 'Le crédit photo ne peut pas dépasser 255 caractères.';
        }

        // Photo « avant » du comparateur choisie parmi les photos du souvenir (seulement s'il n'en a pas encore)
        $suggestedBefore = null;
        $beforeId = $request->request->get('before_picture_id');
        if ($beforeId !== null && $beforeId !== '') {
            $suggestedBefore = $this->beforePictureChooser->find($memory, $beforeId);
            if ($suggestedBefore === null) {
                $errors['before_picture_id'][] = $this->beforePictureChooser->errorMessage();
            } elseif ($this->beforePictureChooser->hasBefore($memory)) {
                $errors['before_picture_id'][] = 'Ce souvenir possède déjà une photo « avant » : le comparateur l\'utilise.';
            }
        }
        if ($errors) {
            throw new ApiResponseException($this->validationErrorResponder->createResponse($errors));
        }

        // Après les photos existantes (toutes, y compris les propositions en attente)
        $position = array_reduce($memory->getPictures()->toArray(), fn (int $max, Picture $p) => max($max, $p->getPosition() + 1), 0);
        $autoApproved = $isAuthor && $current === null;

        $picture = (new Picture())
            ->setType(Picture::TYPE_AFTER)
            ->setPosition($position)
            ->setStatus($autoApproved ? PictureStatus::APPROVED : PictureStatus::PENDING)
            ->setUploadedBy($user)
            ->setReplaces($current)
            ->setCopyrightHolder(is_string($credit) && trim($credit) !== '' ? trim($credit) : null);
        $picture->setImageFile($file);

        $memory->addPicture($picture);
        if ($suggestedBefore !== null) {
            // Photo approuvée d'office : le choix s'applique tout de suite ; sinon il attend la validation du modérateur
            if ($autoApproved) {
                $this->beforePictureChooser->apply($memory, $suggestedBefore);
            } else {
                $picture->setSuggestedBefore($suggestedBefore);
            }
        }
        if ($current !== null) {
            $this->moderationNotifier->currentPictureReplacementProposed($picture);
        }
        $this->entityManager->flush();

        return new CurrentPictureResult($picture, match (true) {
            $autoApproved => 'Photo actuelle ajoutée',
            $current !== null => 'Remplacement de la photo actuelle proposé, en attente de validation (l\'ancienne reste visible)',
            default => 'Photo actuelle proposée, en attente de validation',
        });
    }
}
