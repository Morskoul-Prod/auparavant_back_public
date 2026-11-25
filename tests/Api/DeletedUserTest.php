<?php

namespace App\Tests\Api;

use App\Entity\Comment;
use App\Entity\CommentLike;
use App\Entity\Notification;
use App\Entity\Report;
use App\Entity\User;
use App\Enum\ReportReason;
use App\Enum\ReportTargetType;
use App\Tests\Support\ApiTestCase;

/**
 * Un utilisateur supprimé (il n'y a pas de notion de bannissement dans l'application) ne doit rien casser :
 * commentaires anonymisés, notifications et signalements toujours lisibles.
 */
class DeletedUserTest extends ApiTestCase
{
    public function testSupprimerUnUtilisateurTresActifNeCassePasLesLecturesNiLesAutresUtilisateurs(): void
    {
        $owner = $this->createUser();
        $ghost = $this->createUser();
        $other = $this->createUser();
        $moderator = $this->createUser(['ROLE_MODERATOR']);
        $memory = $this->createMemory($owner);
        $ghostMemory = $this->createMemory($ghost);

        $root = (new Comment())->setMemory($memory)->setAuthor($ghost)->setContent('Racine du fantôme');
        $this->em->persist($root);
        $this->em->flush();
        $reply = (new Comment())->setMemory($memory)->setAuthor($other)->setContent('Réponse')->setParent($root);
        $this->em->persist($reply);
        $this->em->flush();
        $this->em->persist((new CommentLike())->setComment($reply)->setUser($ghost));
        $this->em->persist((new CommentLike())->setComment($root)->setUser($other));
        // Le fantôme a signalé le commentaire d'un autre ; on l'a signalé aussi
        $this->em->persist((new Report(ReportTargetType::COMMENT, $reply->getId(), ReportReason::SPAM))->setReporter($ghost));
        $this->em->persist((new Report(ReportTargetType::COMMENT, $root->getId(), ReportReason::OFFENSIVE))->setReporter($other));
        $this->em->flush();
        [$memoryId, $rootId, $ghostId, $ghostMemoryId] = [$memory->getId(), $root->getId(), $ghost->getId(), $ghostMemory->getId()];
        [$ownerHeaders, $otherHeaders, $moderatorHeaders, $ghostHeaders] = array_map($this->authHeaders(...), [$owner, $other, $moderator, $ghost]);

        // L'autre utilisateur répond au fantôme : le propriétaire et le fantôme sont notifiés
        $this->client->request('POST', '/api/secure/memory/' . $memoryId . '/comments', server: $otherHeaders, content: json_encode(['content' => 'Bonjour', 'parentId' => $rootId]));
        $this->assertResponseStatusCodeSame(201);

        $this->deleteAccount($ghost);
        $this->assertResponseIsSuccessful();

        // Liste des commentaires
        $this->client->request('GET', '/api/memory/' . $memoryId . '/comments');
        $this->assertResponseIsSuccessful();
        $first = $this->json()['comments'][0];
        $this->assertSame('Racine du fantôme', $first['content']);
        $this->assertSame(['id' => null, 'displayName' => 'Utilisateur supprimé'], $first['author']);
        $this->assertSame(2, $first['repliesCount']);
        $this->assertSame(1, $first['likesCount']);

        // Son propre souvenir a disparu avec ses commentaires ; ses likes et signalements aussi
        $this->em->clear();
        $this->assertNull($this->em->find(\App\Entity\Memory::class, $ghostMemoryId));
        $this->assertSame(1, $this->em->getRepository(Report::class)->count([]));
        $this->assertSame(1, $this->em->getRepository(CommentLike::class)->count([]));

        // Notifications du propriétaire (celles dont l'acteur est supprimé : CommentNotificationTest)
        $this->client->request('GET', '/api/secure/notifications/comments', server: $ownerHeaders);
        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $this->json());

        // Contributions de l'autre utilisateur
        $this->client->request('GET', '/api/secure/my-contributions', server: $otherHeaders);
        $this->assertResponseIsSuccessful();

        // File de modération avec une cible dont l'auteur n'existe plus
        $this->client->loginUser($this->em->find(User::class, $moderator->getId()));
        $this->client->request('GET', '/back/reports/');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('tbody', 'utilisateur supprimé');

        // Un modérateur supprime le commentaire orphelin d'auteur : pas de notification ni d'erreur
        $this->client->request('DELETE', '/api/secure/comments/' . $rootId, server: $moderatorHeaders);
        $this->assertResponseStatusCodeSame(204);
        $this->assertSame(0, $this->em->getRepository(Notification::class)->count(['type' => \App\Enum\NotificationType::COMMENT_REMOVED]));
    }

    public function testRepondreAUnCommentaireAnonymiseNeNotifiePersonneDeSupprime(): void
    {
        $owner = $this->createUser();
        $ghost = $this->createUser();
        $other = $this->createUser();
        $memory = $this->createMemory($owner);
        $root = (new Comment())->setMemory($memory)->setAuthor($ghost)->setContent('Racine');
        $this->em->persist($root);
        $this->em->flush();
        [$memoryId, $rootId] = [$memory->getId(), $root->getId()];
        $otherHeaders = $this->authHeaders($other);

        $this->em->remove($this->em->find(User::class, $ghost->getId()));
        $this->em->flush();
        $this->em->clear(); // comme entre deux requêtes : plus de référence en mémoire vers l'utilisateur supprimé

        $this->client->request('POST', '/api/secure/memory/' . $memoryId . '/comments', server: $otherHeaders, content: json_encode(['content' => 'Réponse', 'parentId' => $rootId]));
        $this->assertResponseStatusCodeSame(201);
        // Seul le propriétaire du souvenir est notifié (l'auteur du racine n'existe plus)
        $this->assertSame(1, $this->em->getRepository(Notification::class)->count([]));
    }
}
