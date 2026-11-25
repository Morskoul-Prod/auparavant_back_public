<?php

namespace App\Enum;

/**
 * Type de notification de commentaire ou de modération (les refus de photos sont calculés à part, depuis Picture).
 */
enum NotificationType: string
{
    case COMMENT_ON_MEMORY = 'comment_on_memory';
    case REPLY_TO_COMMENT = 'reply_to_comment';
    /** Souvenir supprimé par un administrateur ou un modérateur */
    case MEMORY_DELETED = 'memory_deleted';
    /** Commentaire supprimé ou masqué par un administrateur ou un modérateur */
    case COMMENT_REMOVED = 'comment_removed';
    /** Commentaire masqué puis affiché à nouveau par la modération */
    case COMMENT_RESTORED = 'comment_restored';

    /** Photo proposée supprimée par un administrateur ou un modérateur (file de signalements) */
    case PICTURE_REMOVED = 'picture_removed';

    /** Photo proposée validée par la modération (photo actuelle ou photo d'un souvenir) : prévient celui qui l'a proposée */
    case PICTURE_APPROVED = 'picture_approved';

    /** Signalement examiné par la modération : neutre, n'indique ni la décision ni le sort du contenu */
    case REPORT_REVIEWED = 'report_reviewed';

    /** Commentaire aimé : une seule notification non vue par commentaire, avec le nombre de likes depuis la dernière vue */
    case COMMENT_LIKED = 'comment_liked';

    /** Souvenir aimé : une seule notification non vue par souvenir, avec le nombre de likes depuis la dernière vue */
    case MEMORY_LIKED = 'memory_liked';

    /** Nouveau souvenir sur une localisation où le destinataire a déjà posté un souvenir */
    case LOCATION_NEW_MEMORY = 'location_new_memory';

    /** Un autre utilisateur propose de remplacer la photo actuelle que le destinataire avait déposée */
    case CURRENT_PICTURE_REPLACEMENT_PROPOSED = 'current_picture_replacement_proposed';

    /** La photo actuelle que le destinataire avait déposée a été remplacée (et supprimée) après validation */
    case CURRENT_PICTURE_REPLACED = 'current_picture_replaced';

    public function getLabel(): string
    {
        return match ($this) {
            self::COMMENT_ON_MEMORY => 'Nouveau commentaire sur un souvenir',
            self::REPLY_TO_COMMENT => 'Réponse à un commentaire',
            self::MEMORY_DELETED => 'Souvenir supprimé par la modération',
            self::COMMENT_REMOVED => 'Commentaire retiré par la modération',
            self::COMMENT_RESTORED => 'Commentaire rétabli par la modération',
            self::PICTURE_REMOVED => 'Photo supprimée par la modération',
            self::PICTURE_APPROVED => 'Photo validée par la modération',
            self::REPORT_REVIEWED => 'Signalement examiné par la modération',
            self::COMMENT_LIKED => 'Commentaire aimé',
            self::MEMORY_LIKED => 'Souvenir aimé',
            self::LOCATION_NEW_MEMORY => 'Nouveau souvenir sur un lieu commun',
            self::CURRENT_PICTURE_REPLACEMENT_PROPOSED => 'Remplacement de votre photo actuelle proposé',
            self::CURRENT_PICTURE_REPLACED => 'Votre photo actuelle a été remplacée',
        };
    }

    /** Notification émise par la modération (l'identité du modérateur n'est jamais exposée) */
    public function isModeration(): bool
    {
        return in_array($this, [self::MEMORY_DELETED, self::COMMENT_REMOVED, self::COMMENT_RESTORED, self::PICTURE_REMOVED, self::PICTURE_APPROVED, self::REPORT_REVIEWED], true);
    }

    /** Notification de like, regroupée par contenu : porte `likesCount` */
    public function isLike(): bool
    {
        return in_array($this, [self::COMMENT_LIKED, self::MEMORY_LIKED], true);
    }
}
