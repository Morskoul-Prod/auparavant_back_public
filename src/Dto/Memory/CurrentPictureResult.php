<?php

namespace App\Dto\Memory;

use App\Entity\Picture;

/** Photo actuelle ajoutée ou proposée, et le message à renvoyer à l'utilisateur */
final class CurrentPictureResult
{
    public function __construct(
        public readonly Picture $picture,
        public readonly string $message,
    ) {
    }
}
