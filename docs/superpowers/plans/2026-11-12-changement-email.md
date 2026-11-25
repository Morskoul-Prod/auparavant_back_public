# Changement d'adresse email sécurisé : plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal :** L'adresse email d'un compte ne change qu'après confirmation de la nouvelle boîte, et l'ancienne boîte reçoit une alerte avec un lien de rétablissement.

**Architecture :** Une entité `EmailChange` (une ligne par demande) porte deux jetons hachés (confirmation 24 h, rétablissement 7 jours). `EmailChangeService` orchestre demande, confirmation, renvoi et rétablissement ; trois routes publiques ou protégées l'exposent. Le front ajoute `pendingEmail` au store, un bandeau, la carte du profil et deux pages publiques.

**Tech Stack :** Symfony 7.4 + Doctrine + PHPUnit (back, `Auparavant-Back`), React 18 + TypeScript + Redux Toolkit + Vitest (front, `Auparavant-front`). Branche `feat/emails` dans les deux dépôts.

**Spec :** `docs/superpowers/specs/2026-11-12-changement-email-design.md` (dépôt back). Les chemins sont relatifs au dépôt indiqué en tête de tâche (`[Back]` ou `[Front]`).

## Global Constraints

- Jetons : `bin2hex(random_bytes(32))` ; seul `hash('sha256', $token)` est stocké. Confirmation valable **24 h**, rétablissement **7 jours** après confirmation, usage unique.
- Les mails partent par `DeferredMailer::queue()` (après la réponse HTTP). Expéditeur : paramètres `mail_from_address` / `mail_from_name`.
- Les limiteurs de débit réutilisent `emailVerifyLimiter` (20 / 15 min / IP) pour confirmation et rétablissement ; `emailResendLimiter` (3 / 15 min / utilisateur) pour le renvoi.
- Codes d'erreur : `invalid_token` (400), `email_taken` (409), 429 avec `Retry-After`.
- Une adresse non confirmée ne sert jamais à se connecter ni à réinitialiser le mot de passe : `User.email` ne change qu'à la confirmation.
- Textes et commentaires en français. Front : pages d'authentification via `AuthCard` (`noindex`, `no-referrer`).
- TDD : chaque comportement a un test écrit et vu échouer avant le code. Un commit par tâche, message terminé par `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Commandes : back `php bin/phpunit` (base de test mise à jour par `php bin/console doctrine:schema:update --force --env=test`) ; front `npx tsc --noEmit`, `npx vitest run`, `npm run lint:hooks`.

## Review Focus

Cas que la spec suggère sans qu'une tâche évidente les teste ; chacun a son test dans la tâche indiquée :

1. La nouvelle adresse est prise par un autre compte **entre la demande et la confirmation** : rien ne change, 409 `email_taken` (Tâche 5).
2. L'ancienne adresse est prise par un autre compte au moment du rétablissement : rien ne change, 409 (Tâche 7).
3. **Deux changements successifs** : le lien de rétablissement du premier fonctionne encore après le second (Tâche 7).
4. Demande vers l'adresse **déjà courante** (casse différente comprise) : aucune demande créée (Tâche 3).
5. Suppression d'un compte qui a des changements d'adresse en attente ou confirmés : pas de 500 (Tâche 3).
6. Un lien de confirmation **déjà utilisé ou expiré**, ou un jeton absent / vide : 400 `invalid_token` sans effet (Tâches 5 et 7).

## Structure des fichiers

Back (créer) : `src/Service/EmailComposer.php`, `src/Service/EmailMasker.php`, `src/Service/EmailChangeService.php`, `src/Service/EmailChangeOutcome.php`, `src/Entity/EmailChange.php`, `src/Repository/EmailChangeRepository.php`, `src/Controller/Api/EmailChangeController.php`, `templates/emails/email_change_confirm.{html,txt}.twig`, `templates/emails/email_change_notice.{html,txt}.twig`, `migrations/Version…` (deux), `tests/Api/EmailChangeTest.php`, `tests/Service/EmailMaskerTest.php`.
Back (modifier) : `src/Entity/ResetPasswordRequest.php`, `src/Repository/ResetPasswordRequestRepository.php`, `src/Service/PasswordResetService.php`, `src/Service/EmailVerificationService.php`, `src/Service/SessionUser.php`, `src/Controller/Api/UserController.php`, `src/Controller/Api/RegistrationController.php`, `src/Controller/Api/EmailVerificationController.php`, `config/packages/security.yaml`, `config/services.yaml`, `templates/emails/email_verification.{html,txt}.twig`, tests existants listés dans les tâches, `CLAUDE.md`.
Front (créer) : `src/utils/emailChange.ts` + `.test.ts`, `src/components/PasswordReset/ConfirmEmailChange.tsx`, `src/components/PasswordReset/RevertEmail.tsx`, `src/store/userReducer.test.ts`.
Front (modifier) : `src/types/user.d.ts`, `src/services/auth.service.ts`, `src/store/userReducer.ts`, `src/hooks/useAuth.ts`, `src/components/Profile/{EmailForm,Profile}.tsx`, `src/components/Layout/EmailVerificationBanner.tsx`, `src/utils/emailVerification.ts` + test, `src/main.tsx`, `CLAUDE.md`.

---

### Task 1 : [Back] Suppression d'un compte après « mot de passe oublié »

**Files :** Modify `src/Entity/ResetPasswordRequest.php`, `tests/Api/PasswordResetTest.php` ; Create `migrations/Version…` (générée).

**Interfaces :** Produces : `reset_password_request.user_id` en `ON DELETE CASCADE`.

- [ ] **Step 1 : test qui échoue.** Dans `PasswordResetTest` : `testSuppressionDuCompteApresUneDemandeDeReinitialisation` : créer un utilisateur, `$this->requestToken($user)`, puis appeler `$this->deleteAccount($user)` (helper d'`ApiTestCase`, attend un 2xx) ; vérifier que `$this->em->getRepository(User::class)->find($id)` est null après `$this->em->clear()`.
- [ ] **Step 2 :** `php bin/phpunit --filter testSuppressionDuCompteApresUneDemande` → échoue (500 / violation de clé étrangère).
- [ ] **Step 3 :** `#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]` sur `ResetPasswordRequest::$user` ; `php bin/console doctrine:migrations:diff` puis relire la migration (uniquement le changement de clé étrangère) ; `php bin/console doctrine:migrations:migrate -n` ; `php bin/console doctrine:schema:update --force --env=test`.
- [ ] **Step 4 :** le test passe, puis `php bin/phpunit` complet.
- [ ] **Step 5 : commit** `Mot de passe oublié : la suppression d'un compte supprime ses demandes de réinitialisation.`

### Task 2 : [Back] `EmailComposer` et `EmailMasker`

**Files :** Create `src/Service/EmailComposer.php`, `src/Service/EmailMasker.php`, `tests/Service/EmailMaskerTest.php` ; Modify `src/Service/PasswordResetService.php`, `src/Service/EmailVerificationService.php`.

**Interfaces :** Produces :
- `EmailComposer::create(string $to, string $subject, string $htmlTemplate, string $textTemplate, array $context = []): TemplatedEmail` (expéditeur issu des paramètres `mail_from_*`, injectés par `#[Autowire]`).
- `EmailMasker::mask(string $email): string` (statique) : garde le premier caractère de la partie locale, puis `***`, puis `@domaine` inchangé ; `a@b.fr` → `a***@b.fr`.

- [ ] **Step 1 : tests.** `EmailMaskerTest` (PHPUnit `TestCase`, sans noyau) : `jean.dupont@gmail.com` → `j***@gmail.com` ; `a@b.fr` → `a***@b.fr` ; chaîne sans `@` → renvoyée inchangée.
- [ ] **Step 2 :** lancer le test → échoue (classe absente).
- [ ] **Step 3 :** implémenter `EmailMasker`. Implémenter `EmailComposer` (une méthode, construit le `TemplatedEmail` comme le font aujourd'hui `PasswordResetService::sendResetLink` et `EmailVerificationService::send`). Les deux services l'utilisent à la place de leur construction manuelle (leurs constructeurs perdent `$fromAddress` / `$fromName`). `EmailVerificationService::send` perd aussi le paramètre `$emailChanged` et les branches `emailChanged` des gabarits `email_verification.*` : il ne sert plus qu'à l'inscription (le texte de changement d'adresse passe dans de nouveaux gabarits à la Tâche 3).
- [ ] **Step 4 :** `php bin/phpunit` : tout passe sauf `EmailVerificationTest::testChangerDEmailDemandeUneNouvelleConfirmation` (réécrit à la Tâche 3 ; le marquer `markTestSkipped('réécrit tâche 3')` pour l'instant).
- [ ] **Step 5 : commit** `Emails : composeur commun, masquage d'adresse.`

### Task 3 : [Back] Entité `EmailChange` et demande de changement

**Files :** Create `src/Entity/EmailChange.php`, `src/Repository/EmailChangeRepository.php`, `src/Service/EmailChangeService.php`, `templates/emails/email_change_confirm.html.twig`, `.txt.twig`, `tests/Api/EmailChangeTest.php`, migration ; Modify `src/Controller/Api/UserController.php` (`updateEmail`), `config/services.yaml` (`$frontUrl` pour `EmailChangeService`), `tests/Api/AccountSecurityTest.php`, `tests/Api/EmailVerificationTest.php`.

**Interfaces :** Produces :
- `EmailChange` : champs de la spec (`user` en `onDelete: CASCADE`, `oldEmail`, `newEmail`, `createdAt`, `confirmTokenHash`, `confirmExpiresAt`, `confirmedAt`, `revertTokenHash`, `revertExpiresAt`, `revertedAt`) ; index sur les deux hachés ; constructeur `__construct(User $user, string $oldEmail, string $newEmail, string $confirmTokenHash, \DateTimeImmutable $confirmExpiresAt)` ; getters correspondants ; `isConfirmable(\DateTimeImmutable $now): bool` (non confirmée, jeton présent et non expiré) ; `confirm(\DateTimeImmutable $now, string $revertTokenHash, \DateTimeImmutable $revertExpiresAt): void` (pose `confirmedAt`, efface le jeton de confirmation) ; `isRevertable(\DateTimeImmutable $now): bool` (confirmée, non rétablie, jeton présent et non expiré) ; `markReverted(\DateTimeImmutable $now): void` (pose `revertedAt`, efface le jeton) ; `regenerateConfirmation(string $hash, \DateTimeImmutable $expiresAt): void`.
- `EmailChangeRepository` : `findPendingForUser(User): ?EmailChange` (non confirmée, la plus récente), `findOneByConfirmTokenHash(string): ?EmailChange`, `findOneByRevertTokenHash(string): ?EmailChange`, `removePendingForUser(User): void`, `clearRevertTokensForUser(User): void` (met `revertTokenHash` à null sur toutes les lignes du compte non rétablies).
- `EmailChangeService::request(User $user, string $newEmail): void` ; `EmailChangeService::pendingEmail(User $user): ?string` ; constante `EmailChangeService::CONFIRM_LIFETIME_HOURS = 24`.
- `PUT /api/secure/update/user/{id}/email` : même validation et même garde qu'avant ; si l'adresse est déjà prise par un autre compte → 409 (inchangé) ; si elle est égale à l'adresse courante (insensible à la casse) → 200 sans rien créer ; sinon `request()`. Réponse : `{user, pendingEmail, message}` (pas de `token`, pas de cookie, aucune session touchée, `User` inchangé).

- [ ] **Step 1 : tests** (`EmailChangeTest`, utiliser `createUser`, `givePassword`, `authHeaders`, `assertEmailCount`, `getMailerMessage` comme `EmailVerificationTest`) :
  - `testLaDemandeLaisseLAdresseEtLaConfirmationInchangees` : après `PUT …/email` (`nouveau@example.com`, mot de passe correct) → 200, `pendingEmail` = `nouveau@example.com`, `User.email` inchangé, `isEmailVerified()` toujours vrai, un mail part vers la nouvelle adresse (objet « Confirmez votre nouvelle adresse email », texte contenant `/confirm-new-email/` suivi de 64 hexadécimaux, ne contient pas « Merci de votre inscription »), un seul `EmailChange` existe.
  - `testUneNouvelleDemandeRemplaceLaPrecedenteEnAttente` : deux demandes successives (vider `cache.rate_limiter` entre elles) → une seule ligne en attente, vers la seconde adresse.
  - `testDemandeVersLAdresseCouranteNeCreeRien` : même adresse avec une casse différente → 200, aucune ligne, aucun mail.
  - `testDemandeVersUneAdressePriseRepond409`.
  - `testLeJwtResteValideApresLaDemande` : le JWT émis avant la demande permet encore `GET /api/secure/me`.
  - `testSuppressionDeCompteAvecDesChangementsEnAttente` : après une demande, `deleteAccount` réussit.
  - Réécrire `AccountSecurityTest::testChangementDEmailReussi` (le test devient : 200, `user.email` toujours l'ancien, `pendingEmail` renseigné, pas de `token`) et **supprimer** `testAncienJwtInvalideApresChangementDEmail` (le comportement passe à la Tâche 5) ; remplacer le test skippé de la Tâche 2 par ceux ci-dessus.
- [ ] **Step 2 :** lancer `php bin/phpunit --filter 'EmailChangeTest|AccountSecurityTest'` → les nouveaux échouent.
- [ ] **Step 3 :** créer entité, dépôt, migration (`doctrine:migrations:diff`, relue, migrate dev, `schema:update` test), `EmailChangeService::request` (supprime les demandes en attente du compte, crée la ligne avec jeton de 64 hexadécimaux, `flush`, file le mail via `EmailComposer` + `DeferredMailer` vers la **nouvelle** adresse, lien `FRONT_URL/confirm-new-email/{jeton}`) et les gabarits de confirmation (texte repris des branches `emailChanged` retirées en Tâche 2 : « Nous avons bien pris en compte le changement d'adresse email… », bouton « Confirmer ma nouvelle adresse »). Brancher `UserController::updateEmail` ; retirer ses dépendances devenues inutiles (`JWTTokenManagerInterface`, `RefreshTokenManager`, `EmailVerificationService`) et mettre à jour l'annotation OpenAPI de la réponse.
- [ ] **Step 4 :** `php bin/phpunit` complet au vert.
- [ ] **Step 5 : commit** `Changement d'email : la nouvelle adresse reste en attente de confirmation.`

### Task 4 : [Back] `pendingEmail` dans les réponses de session

**Files :** Modify `src/Service/SessionUser.php`, `src/Controller/Api/UserController.php` (`me`), `src/Controller/Api/RegistrationController.php`, `tests/Api/CurrentUserTest.php`, `tests/Api/RefreshTokenTest.php`, `tests/Api/RegistrationTest.php`, `tests/Api/EmailChangeTest.php`.

**Interfaces :** Consumes : `EmailChangeService::pendingEmail(User): ?string`. Produces : `SessionUser::payload(User): array` renvoie en plus la clé `pendingEmail` (`?string`), après `emailVerified`. `SessionUser` reçoit `EmailChangeService` par le constructeur. `GET /api/secure/me` renvoie exactement `SessionUser::payload` (plus de duplication). L'inscription renvoie `'pendingEmail' => null`.

- [ ] **Step 1 : tests.** Mettre à jour les listes de clés attendues des trois tests existants (`pendingEmail` ajouté en dernier) ; dans `EmailChangeTest` : `testPendingEmailDansMeEtDansLeLogin` : sans demande → `null` dans `/api/secure/me` ; après une demande → la nouvelle adresse dans `/api/secure/me` et dans `data` de `/api/login_check`.
- [ ] **Step 2 :** lancer ces tests → échec.
- [ ] **Step 3 :** implémenter ; `me` appelle `$sessionUser->payload($user)`.
- [ ] **Step 4 :** `php bin/phpunit` complet au vert.
- [ ] **Step 5 : commit** `Session : pendingEmail dans les réponses de connexion, de rafraîchissement et de /me.`

### Task 5 : [Back] Confirmation du changement et alerte à l'ancienne adresse

**Files :** Create `src/Service/EmailChangeOutcome.php`, `src/Controller/Api/EmailChangeController.php`, `templates/emails/email_change_notice.html.twig`, `.txt.twig` ; Modify `src/Service/EmailChangeService.php`, `config/packages/security.yaml` (remplacer `^/api/email/verify` par `^/api/email/` en `PUBLIC_ACCESS`), `tests/Api/EmailChangeTest.php`.

**Interfaces :** Produces :
- `enum EmailChangeOutcome: string { case Done = 'done'; case Invalid = 'invalid'; case EmailTaken = 'email_taken'; }`.
- `EmailChangeService::confirm(string $token): EmailChangeOutcome`. Constante `REVERT_LIFETIME_DAYS = 7`. Succès : `User.email` ← nouvelle adresse, `emailVerifiedAt` et `credentialsChangedAt` et `updatedAt` ← maintenant, `RefreshTokenManager::revokeAll`, ligne confirmée avec un jeton de rétablissement, mail d'alerte vers l'**ancienne** adresse. `Invalid` pour jeton vide, inconnu, expiré ou déjà utilisé ; `EmailTaken` si un autre compte a pris l'adresse (rien ne change).
- `POST /api/email/change/confirm {token}` : 200 `{message}`, 400 `{code: 'invalid_token'}`, 409 `{code: 'email_taken'}`, 429 ; limiteur `emailVerifyLimiter` par IP.
- Mail d'alerte : objet « Votre adresse email a été modifiée » ; contient l'ancienne et la nouvelle adresse **masquée** (`EmailMasker::mask`), la date, le lien `FRONT_URL/revert-email/{jeton}` et sa durée de 7 jours.

- [ ] **Step 1 : tests** (`EmailChangeTest`) :
  - `testLaConfirmationEchangeLAdresse` : demande, extraction du jeton du mail, `POST /api/email/change/confirm` → 200 ; `User.email` = nouvelle adresse, vérifiée ; `POST /api/login_check` fonctionne avec la nouvelle adresse et échoue avec l'ancienne.
  - `testLaConfirmationFermeLesSessions` : un JWT et un refresh token émis avant sont refusés (`/api/secure/me` → 401 avec l'ancien JWT).
  - `testLaConfirmationAlerteLAncienneAdresse` : mail vers l'ancienne adresse, objet attendu, texte contenant `/revert-email/` + 64 hexadécimaux et l'adresse nouvelle masquée, pas l'adresse nouvelle en clair.
  - `testJetonDeConfirmationAUsageUnique` (second appel → 400 `invalid_token`), `testJetonDeConfirmationExpire` (forcer `confirmExpiresAt` dans le passé → 400), `testJetonVideOuInconnu` (400, y compris corps sans `token`).
  - `testAdressePriseEntreLaDemandeEtLaConfirmation` : un autre compte prend l'adresse après la demande → 409 `email_taken`, l'adresse du premier compte inchangée.
  - `testConfirmationLimitee` : au-delà de 20 appels par IP → 429 avec `Retry-After`.
  - Rétablir ici `testAncienJwtInvalideApresChangementDEmail` sous la forme « ancien JWT invalide après confirmation » si non couvert par le test des sessions.
- [ ] **Step 2 :** lancer `--filter EmailChangeTest` → échec.
- [ ] **Step 3 :** implémenter service, enum, contrôleur, gabarits d'alerte, accès public.
- [ ] **Step 4 :** `php bin/phpunit` complet au vert.
- [ ] **Step 5 : commit** `Changement d'email : confirmation par lien, alerte envoyée à l'ancienne adresse.`

### Task 6 : [Back] Renvoi du lien de confirmation d'un changement en attente

**Files :** Modify `src/Service/EmailChangeService.php`, `src/Controller/Api/EmailVerificationController.php` (`resend`), `tests/Api/EmailChangeTest.php`.

**Interfaces :** Produces : `EmailChangeService::resendPending(User $user): bool` (régénère jeton et échéance de la demande en attente la plus récente, renvoie le mail à la nouvelle adresse ; false s'il n'y en a pas). `POST /api/secure/email/resend` : s'il existe une demande en attente, appelle `resendPending` (même limiteur, 200) **avant** le comportement actuel ; la réponse 409 « déjà confirmée » ne s'applique qu'en l'absence de demande en attente.

- [ ] **Step 1 : tests :** `testLeRenvoiCibleLaNouvelleAdresseEnAttente` (compte confirmé avec demande en attente : `POST /api/secure/email/resend` → 200, un mail vers la nouvelle adresse, l'ancien jeton refusé, le nouveau accepté) ; `testLeRenvoiSansDemandeEnAttenteInchange` (compte confirmé → 409 `already_verified`, compte non confirmé → 200 comme avant) ; `testRenvoiApresExpiration` (jeton expiré, renvoi → nouveau jeton valable).
- [ ] **Step 2 :** lancer → échec. **Step 3 :** implémenter. **Step 4 :** `php bin/phpunit` au vert.
- [ ] **Step 5 : commit** `Changement d'email : renvoi du lien de confirmation à l'adresse en attente.`

### Task 7 : [Back] Rétablissement de l'ancienne adresse

**Files :** Modify `src/Service/EmailChangeService.php`, `src/Controller/Api/EmailChangeController.php`, `src/Repository/ResetPasswordRequestRepository.php`, `tests/Api/EmailChangeTest.php`.

**Interfaces :** Consumes : `PasswordResetService::sendResetLink(string $email): void` ; `RefreshTokenManager::revokeAll`. Produces :
- `ResetPasswordRequestRepository::removeAllForUser(User $user): void`.
- `EmailChangeService::revert(string $token): EmailChangeOutcome`. Succès : `User.email` ← `oldEmail` de la ligne, `emailVerifiedAt` ← maintenant, mot de passe remplacé par le haché de `bin2hex(random_bytes(32))`, `credentialsChangedAt` ← maintenant, sessions révoquées, `ResetPasswordRequest` du compte supprimées, demandes en attente supprimées (`removePendingForUser`), tous les jetons de rétablissement du compte invalidés (`clearRevertTokensForUser`), ligne marquée rétablie ; puis `PasswordResetService::sendResetLink($oldEmail)`. `Invalid` pour jeton vide, inconnu, expiré, déjà utilisé ; `EmailTaken` si l'ancienne adresse appartient à un autre compte (rien ne change).
- `POST /api/email/change/revert {token}` : 200 / 400 `invalid_token` / 409 `email_taken` / 429 (même limiteur que la confirmation).

- [ ] **Step 1 : tests :**
  - `testLeRetablissementRestaureLAdresseEtVerrouilleLeCompte` : demande + confirmation + rétablissement → 200 ; `User.email` = ancienne adresse ; `login_check` avec l'ancien mot de passe échoue ; sessions refusées ; un mail de réinitialisation part vers l'ancienne adresse (lien `/reset-password/`) et le lien permet de choisir un mot de passe puis de se connecter.
  - `testLeRetablissementPurgeLesDemandesDeReinitialisationEnCours` : une demande de réinitialisation faite avant (jeton extrait) est refusée par `POST /api/password/reset` après le rétablissement (hors du jeton du nouveau mail).
  - `testLeRetablissementUsageUniqueEtExpiration` : second appel 400 ; `revertExpiresAt` forcé dans le passé → 400 ; jeton vide / inconnu → 400.
  - `testChaineDeDeuxChangements` : changement A→B confirmé, puis B→C confirmé ; le lien de rétablissement du **premier** change ramène l'adresse à A (et invalide aussi le second lien).
  - `testRetablissementImpossibleSiLAncienneAdresseEstPrise` : un autre compte s'inscrit avec A après le premier changement → 409 `email_taken`, adresse inchangée.
  - `testLeRetablissementSupprimeLesDemandesEnAttente`.
- [ ] **Step 2 :** lancer → échec. **Step 3 :** implémenter. **Step 4 :** `php bin/phpunit` complet au vert.
- [ ] **Step 5 : commit** `Changement d'email : rétablissement de l'ancienne adresse depuis l'alerte.`

### Task 8 : [Front] Types, service et store

**Files :** Modify `src/types/user.d.ts`, `src/services/auth.service.ts`, `src/store/userReducer.ts`, `src/hooks/useAuth.ts` ; Create `src/store/userReducer.test.ts`, `src/utils/emailChange.ts`, `src/utils/emailChange.test.ts`. (Branche front `feat/emails`.)

**Interfaces :** Produces :
- `IUser.pendingEmail?: string | null`.
- `AuthService.changeEmail(userId, email, currentPassword): Promise<EmailChangeResponse>` avec `interface EmailChangeResponse { user: IUser & { displayName: string }; pendingEmail: string | null; message: string }` ; n'enregistre plus de jeton. `AuthService.confirmEmailChange(token: string): Promise<{ message: string }>` (`POST /email/change/confirm`) ; `AuthService.revertEmailChange(token: string): Promise<{ message: string }>` (`POST /email/change/revert`).
- Store : `state.user.pendingEmail: string | null` (défaut `null`) ; action `pendingEmailChanged(pending: string | null)` qui **remplace** `emailUpdated` (supprimée) ; `login`, `restoreSession` et `register` lisent `user.pendingEmail ?? null` ; `logout` et `deleteUser.fulfilled` le remettent à `null`. `useAuth().changeEmail` dispatche `pendingEmailChanged(result.pendingEmail)`.
- `classifyEmailChangeError(err: unknown): 'invalid' | 'taken' | 'unavailable'` dans `utils/emailChange.ts` : 400 → `invalid`, 409 → `taken`, sinon `unavailable`.

- [ ] **Step 1 : tests.** `emailChange.test.ts` : 400 → `invalid`, 409 → `taken`, 429 / 500 / réseau → `unavailable` (même fabrique `httpError` que `emailVerification.test.ts`). `userReducer.test.ts` : état initial `pendingEmail === null` ; `pendingEmailChanged('a@b.fr')` le renseigne **sans toucher** `email` ni `emailVerified` ; `logout` le remet à `null` (importer `userReducer` et ses actions ; si l'import échoue sous Vitest à cause de `import.meta.env`, le noter et mocker `../services/api.service`).
- [ ] **Step 2 :** `npx vitest run` → échec. **Step 3 :** implémenter. **Step 4 :** `npx tsc --noEmit`, `npx vitest run`, `npm run lint:hooks` au vert.
- [ ] **Step 5 : commit** `Front : adresse en attente dans le store, appels de confirmation et de rétablissement.`

### Task 9 : [Front] Pages de confirmation et de rétablissement

**Files :** Create `src/components/PasswordReset/ConfirmEmailChange.tsx`, `src/components/PasswordReset/RevertEmail.tsx` ; Modify `src/main.tsx`.

**Interfaces :** Consumes : `AuthService.confirmEmailChange` / `revertEmailChange`, `classifyEmailChangeError`, `AuthCard` et ses helpers, `useAuth().logout`. Produces : routes publiques `/confirm-new-email/:token` et `/revert-email/:token`.

- [ ] **Step 1 : `ConfirmEmailChange`** : appelle `confirmEmailChange` une fois à l'ouverture (garde `useRef`, comme `VerifyEmail`). États : chargement ; succès (« Nouvelle adresse confirmée », invite à se reconnecter avec la nouvelle adresse ; si `state.user.logged`, appeler `logout()` de `useAuth` car le back a fermé toutes les sessions ; lien « Se connecter ») ; `invalid` (« Lien invalide ou expiré » + lien vers le profil / la connexion) ; `taken` (« Cette adresse est désormais utilisée par un autre compte ») ; `unavailable` (« Réessayez dans un instant, votre lien reste valable »).
- [ ] **Step 2 : `RevertEmail`** : **n'appelle rien à l'ouverture.** Affiche l'explication (« Vous n'êtes pas à l'origine du changement d'adresse ? Rétablissez votre ancienne adresse. Par sécurité, votre mot de passe sera invalidé et un lien pour en choisir un nouveau vous sera envoyé ») et un bouton « Ce n'était pas moi, rétablir mon ancienne adresse » ; au clic, appelle `revertEmailChange` ; succès → message « Adresse rétablie. Un email pour choisir un nouveau mot de passe vient de vous être envoyé » + lien `/forgot-password` au cas où il ne viendrait pas ; mêmes erreurs que ci-dessus (`taken` → « contactez-nous à `LEGAL.contactEmail` »).
- [ ] **Step 3 :** déclarer les deux routes dans `main.tsx` (à côté de `/verify-email/:token`).
- [ ] **Step 4 :** `npx tsc --noEmit`, `npx vitest run`, `npm run lint:hooks` au vert.
- [ ] **Step 5 : commit** `Front : pages de confirmation du changement d'adresse et de rétablissement.`

### Task 10 : [Front] Bandeau et profil pour une adresse en attente

**Files :** Modify `src/utils/emailVerification.ts` + `.test.ts`, `src/components/Layout/EmailVerificationBanner.tsx`, `src/components/Profile/Profile.tsx`, `src/components/Profile/EmailForm.tsx`.

**Interfaces :** Produces : `emailBannerKind(state: { logged: boolean; sessionChecked: boolean; emailVerified: boolean; pendingEmail: string | null }): 'pending' | 'unverified' | null` : `null` si non connecté ou session non vérifiée ; `'pending'` s'il y a une adresse en attente (prioritaire, même compte non confirmé) ; `'unverified'` si le compte n'est pas confirmé ; sinon `null`.

- [ ] **Step 1 : test** de `emailBannerKind` : les cinq combinaisons ci-dessus, dont « en attente ET non confirmé → `pending` ».
- [ ] **Step 2 :** lancer → échec. **Step 3 :** implémenter `emailBannerKind` ; le bandeau l'utilise : variante `pending` = « Confirmez votre nouvelle adresse **X** (l'adresse actuelle reste valable en attendant) » avec le même bouton « Renvoyer » et son compte à rebours (`useResendVerification` inchangé : le back renvoie vers la bonne adresse) ; variante `unverified` = texte actuel.
- [ ] **Step 4 : profil.** `Profile` affiche sous l'adresse, quand `pendingEmail` existe, « En attente de confirmation : X ». `EmailForm` : le toast devient « Un email de confirmation a été envoyé à X. Votre adresse actuelle reste valable tant que vous ne l'avez pas confirmée. »
- [ ] **Step 5 :** `npx tsc --noEmit`, `npx vitest run`, `npm run lint:hooks` au vert ; commit `Front : bandeau et profil pour une adresse en attente de confirmation.`

### Task 11 : Documentation et vérification finale

**Files :** Modify `CLAUDE.md` (back), `CLAUDE.md` (front).

- [ ] **Step 1 :** back : compléter la section « Emails transactionnels… » (table `email_change`, trois routes, rétablissement qui verrouille le mot de passe, limites connues de la spec, `pendingEmail` dans `SessionUser`, `EmailComposer`, purge à prévoir). Front : compléter le paragraphe « Emails » (store `pendingEmail`, bandeau `emailBannerKind`, pages `/confirm-new-email/:token` et `/revert-email/:token`, clic explicite du rétablissement).
- [ ] **Step 2 : vérification complète.** Back : `php bin/phpunit` (tout vert, noter le nombre) ; `php bin/console lint:container` ; `php bin/console doctrine:schema:validate` (mapping synchronisé). Front : `npx tsc --noEmit`, `npx vitest run`, `npm run lint:hooks`.
- [ ] **Step 3 : parcours manuel** (MAMP + back + front) : changer d'email → bandeau « en attente », ancienne adresse toujours valable pour se connecter ; confirmer depuis la nouvelle boîte → reconnexion obligatoire avec la nouvelle adresse, alerte reçue sur l'ancienne ; cliquer le lien de rétablissement → page d'explication, clic, adresse restaurée, mail de réinitialisation reçu, ancien mot de passe refusé.
- [ ] **Step 4 : commit** `Docs : changement d'adresse email sécurisé.` dans chaque dépôt.
