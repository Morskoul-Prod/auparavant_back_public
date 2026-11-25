<?php

namespace App\Service;

/** Issue d'une confirmation ou d'un rétablissement de changement d'adresse email. */
enum EmailChangeOutcome: string
{
    case Done = 'done';
    // Jeton vide, inconnu, expiré ou déjà utilisé
    case Invalid = 'invalid';
    // Une autre personne possède désormais l'adresse à attribuer
    case EmailTaken = 'email_taken';
}
