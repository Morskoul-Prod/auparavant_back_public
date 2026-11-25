# Changement d'adresse email sécurisé : conception

Date : 2026-11-12. Périmètre : back (Symfony) et front (React). Statut : à approuver.

## Intention et pourquoi

Aujourd'hui, changer d'email depuis le profil applique l'adresse **tout de suite**, simplement marquée « non confirmée », et ne prévient personne. L'essai de bout en bout a fait apparaître deux défauts :

1. **Faute de frappe** (`jean@gmial.com` qui existe) : le compte est lié à l'adresse d'un inconnu, qui peut taper son adresse dans « Mot de passe oublié » et prendre le compte.
2. **Piratage** : quelqu'un qui connaît le mot de passe change l'email, le confirme depuis sa boîte, puis réinitialise le mot de passe. Le propriétaire n'est averti de rien.

Le but : l'adresse du compte ne change qu'après preuve de contrôle de la **nouvelle** boîte, et la **précédente** boîte est prévenue avec un moyen de défaire le changement.

## Objectifs

- La nouvelle adresse reste **en attente** ; l'ancienne reste celle du compte (connexion, réinitialisation) jusqu'à confirmation.
- Ne pas confirmer ne casse rien : le changement expire, le compte continue de fonctionner.
- À la confirmation, un mail part vers l'**ancienne** adresse avec un lien « Ce n'était pas moi » (7 jours).
- Ce lien rétablit l'ancienne adresse et reprend la main sur le compte (sessions fermées, mot de passe invalidé, lien de réinitialisation envoyé).
- Corriger la suppression de compte après un « mot de passe oublié » (clé étrangère `RESTRICT`).

## Hors périmètre

- Prévenir lors d'un changement de **mot de passe** (même logique, à traiter ensuite).
- Annuler explicitement une demande en attente (demander un nouveau changement la remplace).
- Purge automatique des anciennes lignes de changement.
- Prévenir l'ancienne adresse dès la **demande** (seulement à la confirmation).
- Empêcher qu'une adresse libérée soit réinscrite avant un rétablissement (voir limites).

## Ce qui serait un échec même si ça « marche »

- Un message ou un délai qui révèle si une adresse est inscrite.
- Une adresse non confirmée qui sert à se connecter ou à réinitialiser le mot de passe.
- Un lien de rétablissement qui rend le compte au pirate (il doit invalider le mot de passe).
- Un lien ouvert par un scanner de mails qui défait un changement (le rétablissement exige un clic explicite sur la page).

## Contraintes

- Existant à respecter : jetons aléatoires de 256 bits, seul le SHA-256 est stocké ; envoi des mails après la réponse via `DeferredMailer` ; limiteurs de débit en `framework.yaml` ; `credentialsChangedAt` (claim `cca`) invalide les JWT et refresh tokens ; l'adresse sert d'identifiant de connexion (provider `email`).
- Le changement d'email exige déjà le mot de passe actuel (`AccountGuard`, 5 essais / 15 min).
- TDD : chaque comportement a un test écrit avant le code.
- Front : tests Vitest sur les fonctions pures seulement (pas de rendu de composants).

## Approches étudiées

**A. Table dédiée `email_change` (retenue).** Une ligne par demande : ancienne adresse, nouvelle adresse, jeton de confirmation, jeton de rétablissement, dates. + garde l'historique, donc **plusieurs liens de rétablissement valides** en même temps ; + `User` reste simple ; + auditable. − une entité et une migration de plus.

**B. Colonnes sur `User`** (`pendingEmail`, `previousEmail`, deux jetons). + moins de code. − un seul emplacement : après un deuxième changement, le lien de rétablissement du premier est perdu. Or c'est exactement le scénario d'un pirate qui change l'adresse deux fois de suite pour que l'alerte parte vers une adresse à lui. Écartée.

**C. Réutiliser le jeton de confirmation d'inscription.** + aucune route nouvelle. − mélange deux usages (bienvenue / changement) dans un même champ, et ne règle pas le rétablissement. Écartée.

## Décisions de conception

### Données

Entité `EmailChange` (table `email_change`) :
`id`, `user` (`onDelete: CASCADE`), `oldEmail`, `newEmail`, `createdAt`,
`confirmTokenHash` (SHA-256, nullable), `confirmExpiresAt` (24 h), `confirmedAt` (nullable),
`revertTokenHash` (nullable), `revertExpiresAt` (7 jours après confirmation), `revertedAt` (nullable).
Index sur les deux hachés.

Cycle de vie : **en attente** (`confirmedAt` null) → **confirmée** (jeton de rétablissement posé) → éventuellement **rétablie**.
Une nouvelle demande supprime les demandes en attente du même utilisateur (les confirmées restent : elles portent des liens de rétablissement).

### Flux

1. **Demande** `PUT /api/secure/update/user/{id}/email` (inchangée dans ses protections) : crée l'`EmailChange`, envoie le mail de confirmation à la **nouvelle** adresse. `User.email` et `emailVerifiedAt` ne bougent pas, aucune session n'est touchée. La réponse ne renvoie plus de jeton ni de nouveau cookie ; elle donne `pendingEmail`.
2. **Confirmation** `POST /api/email/change/confirm {token}` (publique, limiteur par IP) : vérifie le jeton (existant, non expiré, non utilisé) et que la nouvelle adresse est toujours libre. Puis : `email` ← nouvelle, `emailVerifiedAt` ← maintenant, `credentialsChangedAt` ← maintenant, toutes les sessions révoquées (changer l'identifiant de connexion ferme les sessions, comme pour un mot de passe), jeton de confirmation effacé, jeton de rétablissement créé. Mail d'information à l'**ancienne** adresse avec le lien de rétablissement. L'utilisateur se reconnecte avec la nouvelle adresse.
3. **Renvoi** `POST /api/secure/email/resend` (existant) : s'il y a une demande en attente, régénère le jeton et renvoie le mail à la nouvelle adresse ; sinon comportement actuel (compte non confirmé). Limiteur inchangé (3 / 15 min).
4. **Rétablissement** `POST /api/email/change/revert {token}` (publique, limiteur par IP), déclenché par un **clic explicite** sur la page (jamais à l'ouverture du lien). Valable 7 jours, une seule fois. Il : remet `oldEmail` (vérifiée : le clic prouve le contrôle de cette boîte), remplace le mot de passe par un haché aléatoire inutilisable, met à jour `credentialsChangedAt`, révoque toutes les sessions, supprime les demandes de réinitialisation en cours et les demandes de changement en attente, invalide les autres liens de rétablissement, puis envoie un lien de réinitialisation du mot de passe à l'ancienne adresse. Si l'ancienne adresse est déjà prise par un autre compte : 409 « contactez-nous ».
   Le rétablissement fonctionne **même si d'autres changements ont eu lieu depuis** : le lien d'un changement restaure l'adresse de ce changement (c'est ce qui défait un pirate qui change deux fois de suite).

### API et payloads

- `pendingEmail` = nouvelle adresse de la demande **non confirmée** de l'utilisateur, même si son jeton a expiré (pour que « Renvoyer » reste proposé) ; null s'il n'y en a pas.
- Nouveau champ `pendingEmail` (string ou null) dans `data` du login et du refresh, dans `/api/secure/me`, dans l'inscription (null) et dans la réponse du changement d'email. `SessionUser::payload` devient la source unique (`me` l'utilise au lieu de dupliquer).
- Codes d'erreur : `invalid_token` (400), `email_taken` (409), 429 avec `Retry-After`.
- Pas de nouvelle route pour annuler : voir hors périmètre.

### Mails

1. Confirmation, vers la nouvelle adresse (texte « changement d'adresse », lien `FRONT_URL/confirm-new-email/{jeton}`, 24 h).
2. Information, vers l'ancienne adresse après confirmation : « Votre adresse est passée de X à Y le … Si ce n'est pas vous : » + lien `FRONT_URL/revert-email/{jeton}` (7 jours). L'adresse nouvelle y est affichée partiellement masquée.
3. Réinitialisation du mot de passe (existant) après un rétablissement.

Un composeur commun (`EmailComposer`) construit expéditeur, objet, gabarits HTML et texte ; les services existants l'utilisent aussi (suppression de la duplication).

### Front

- Store `user.pendingEmail`. Changer l'email dans le profil renseigne `pendingEmail`, ne modifie plus `email` et ne marque plus le compte non confirmé.
- Bandeau : si `pendingEmail`, « Confirmez votre nouvelle adresse X » avec « Renvoyer » (même hook et compte à rebours) ; sinon le bandeau actuel pour un compte non confirmé. Pendant l'attente, le compte reste pleinement utilisable.
- Carte « Email » du profil : affiche l'adresse actuelle et, dessous, « En attente de confirmation : Y ».
- Pages publiques (`AuthCard`, `noindex`, `no-referrer`) : `/confirm-new-email/:token` (confirme à l'ouverture, puis déconnecte localement et propose « Se connecter avec votre nouvelle adresse ») et `/revert-email/:token` (explique, bouton « Ce n'était pas moi, rétablir mon ancienne adresse », puis message sur le lien de réinitialisation envoyé).

### Prérequis corrigé en premier

`ResetPasswordRequest.user` passe en `onDelete: CASCADE` (migration). Sans cela, supprimer un compte ayant utilisé « mot de passe oublié » échoue en 500.

## Analyse des scénarios

| Scénario | Résultat |
|---|---|
| Je ne clique jamais | Rien ne change ; le lien expire ; je peux renvoyer ou refaire une demande. |
| Faute de frappe vers une adresse d'inconnu | L'inconnu peut confirmer le lien, mais le compte n'a pas encore changé d'adresse : sans confirmation il ne peut rien prendre. S'il confirme, l'ancienne adresse reçoit l'alerte. |
| Pirate qui connaît le mot de passe change l'adresse et confirme | Mail d'alerte à l'ancienne adresse ; le lien rétablit l'adresse, invalide le mot de passe, ferme les sessions. |
| Pirate qui change deux fois de suite | Chaque changement a son lien ; le premier fonctionne encore. |
| Scanner de mails qui ouvre le lien de rétablissement | Il ne fait que charger la page ; rien n'est défait sans clic. |
| Je clique « Ce n'était pas moi » par erreur | Mon adresse revient, mon mot de passe est à refaire (lien envoyé) ; pas de perte de données. |

## Limites connues (à documenter)

- **Réservation de l'ancienne adresse** (ajoutée après la relecture) : tant qu'un lien de rétablissement est valable (7 jours), l'ancienne adresse est réservée : inscription et changement d'adresse d'un autre compte répondent 409. Sans cela, un pirate bloquerait le rétablissement en s'inscrivant avec l'adresse libérée. Coût : un tiers ne peut pas réutiliser cette adresse pendant 7 jours.
- **Ancienne adresse jamais confirmée** (compte inscrit avec une faute de frappe puis corrigé) : aucune alerte ni lien de rétablissement n'est émis, puisque personne ne peut prouver que cette boîte est la sienne.
- **Liens de rétablissement** : le rétablissement du changement N n'invalide que les liens de N et des changements postérieurs ; ceux des changements antérieurs restent valables (sinon un pirate qui clique son propre lien tuerait celui de la victime).
- **Demande en attente** : annulée par tout changement ou réinitialisation du mot de passe.
- **Atomicité** : confirmation et rétablissement s'exécutent dans une transaction ; une violation d'unicité répond 409.
- Si le pirate contrôle aussi la boîte de l'ancienne adresse, l'alerte ne sert à rien (non traitable ici).
- Les lignes `email_change` s'accumulent (une en attente par utilisateur au plus, plus l'historique) : purge à prévoir plus tard.

## Tests (écrits avant le code)

Back : suppression d'un compte avec une demande de réinitialisation ; demande (adresse inchangée, compte toujours confirmé, mail à la nouvelle adresse, remplacement d'une demande en attente, conflit d'adresse) ; confirmation (échange, connexion avec la nouvelle adresse, sessions révoquées, jeton à usage unique, expiré, adresse prise entre-temps, mail d'alerte à l'ancienne adresse avec lien) ; renvoi (cible l'adresse en attente) ; rétablissement (adresse restaurée, ancien mot de passe refusé, sessions révoquées, demandes de réinitialisation purgées, mail de réinitialisation envoyé, usage unique, expiré, adresse prise → 409, chaîne de deux changements) ; `pendingEmail` dans les payloads.
Front : réducteur utilisateur (`pendingEmail`), fonctions pures du bandeau, erreurs de confirmation / rétablissement.

## Laissé au réalisateur

Noms des méthodes, détail des gabarits de mail, textes exacts des pages, ordre fin des migrations, structure des fichiers de tests.
