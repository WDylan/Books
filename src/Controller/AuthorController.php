<?php
// Déclaration de l'espace de noms pour ce fichier, permettant son utilisation dans le projet.
namespace App\Controller;

// Importation des classes nécessaires depuis d'autres espaces de noms.
use App\Entity\Author;
use App\Repository\AuthorRepository;
use App\Repository\BookRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use JMS\Serializer\SerializerInterface;
use JMS\Serializer\SerializationContext;
use Nelmio\ApiDocBundle\Annotation\Model;
use Nelmio\ApiDocBundle\Annotation\Security;
use OpenApi\Attributes as OA;

// Déclaration de la classe AuthorController qui étend AbstractController de Symfony.
class AuthorController extends AbstractController
{
    /**
     * Cette méthode permet de récupérer l'ensemble des auteurs
     */
    // Définition d'une route pour obtenir tous les auteurs via une requête GET.
    #[Route('/api/authors', name: 'authors', methods: ['GET'])]
    #[OA\Response(
        response: 200,
        description: "Retourne la liste des auteurs",
        content: new OA\JsonContent(
            type: "array",
            items: new OA\Items(ref: new Model(type: Author::class, groups: ["getBooks"]))
        )
    )]
    #[OA\Parameter(
        name: "page",
        in: "query",
        description: "La page que l'on veut récupérer",
        schema: new OA\Schema(type: "integer")
    )]
    #[OA\Parameter(
        name: "limit",
        in: "query",
        description: "Le nombre d'élément qu' l'on veut récupérer",
        schema: new OA\Schema(type: "integer")
    )]
    #[OA\Tag(name: "Authors")]
    public function getAllAuthors(AuthorRepository $authorRepository, SerializerInterface $serializer, Request $request, TagAwareCacheInterface $cache): JsonResponse
    {
        $page = $request->get('page', 1);
        $limit = $request->get('limit', 3);

        $idCache = "getAllAuthors-" . $page . "-" . $limit;
        $jsonAuthorList = $cache->get($idCache, function (ItemInterface $item) use ($authorRepository, $page, $limit, $serializer) {
            $item->tag("authorsCache");
            $authorList = $authorRepository->findAllWithPagination($page, $limit);
            $context = SerializationContext::create()->setGroups(['getBooks']);
            return $serializer->serialize($authorList, 'json', $context);
        });
        // Retour d'une réponse JSON avec la liste des auteurs.
        return new JsonResponse($jsonAuthorList, Response::HTTP_OK, [], true);
    }

    /**
     * Cette méthode permet de récupérer un auteur spécifique par son ID
     */
    // Définition d'une route pour obtenir les détails d'un auteur spécifique via une requête GET.
    #[Route('/api/authors/{id}', name: 'detailAuthor', methods: ['GET'])]
    #[OA\Tag(name: "Authors")]
    public function getAuthorDetails(Author $author, SerializerInterface $serializer): JsonResponse
    {
        $context = SerializationContext::create()->setGroups(['getBooks']);
        // Sérialisation de l'auteur spécifié en JSON.
        $jsonAuthor = $serializer->serialize($author, 'json', $context);
        // Retour d'une réponse JSON avec les détails de l'auteur.
        return new JsonResponse($jsonAuthor, Response::HTTP_OK, [], true);
    }

    /**
     * Cette méthodde supprimer un auteur en fonction de son id
     */
    // Définition d'une route pour supprimer un auteur spécifique via une requête DELETE.
    #[Route('/api/authors/{id}', name: 'deleteAuthor', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN', message: 'Vous n\avez pas les droits suffisants pour modifier un Auteur.')]
    #[OA\Tag(name: "Authors")]
    public function deleteAuthor(Author $author, EntityManagerInterface $em, BookRepository $bookRepository, TagAwareCacheInterface $cachePool): JsonResponse
    {
        $cachePool->invalidateTags(["booksCache"]);
        // Récupération de tous les livres de l'auteur spécifié.
        $books = $bookRepository->findBy(['author' => $author]);
        // Suppression de chaque livre de l'auteur.
        foreach ($books as $book) {
            $em->remove($book);
        }
        // Suppression de l'auteur de la base de données.
        $em->remove($author);
        // Application des changements dans la base de données.
        $em->flush();
        // Retour d'une réponse JSON indiquant que l'auteur a été supprimé.
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Cette méthode permet de créer un nouveau auteur
     */
    // Définition d'une route pour créer un nouvel auteur via une requête POST.
    #[Route('/api/authors', name: 'createAuthor', methods: ['POST'])]
    #[OA\Post(
        path: "/api/authors",
        summary: "Crée un nouvel auteur",
        tags: ["Authors"],
        requestBody: new OA\RequestBody(
            description: "Données de l'auteur à créer",
            required: true,
            content: new OA\JsonContent(
                ref: new Model(type: Author::class, groups: ["create"])
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: "Auteur créé avec succès",
                content: new OA\JsonContent(
                    type: "object",
                    properties: [
                        new OA\Property(property: "id", type: "integer"),
                        new OA\Property(property: "firstName", type: "string"),
                        new OA\Property(property: "lastName", type: "string"),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: "Données invalides fournies"
            )
        ]
    )]
    #[IsGranted('ROLE_ADMIN', message: 'Vous n\avez pas les droits suffisants pour modifier un Auteur.')]
    #[OA\Tag(name: "Authors")]
    public function createAuthor(Request $request, SerializerInterface $serializer, EntityManagerInterface $em, UrlGeneratorInterface $urlGenerator, ValidatorInterface $validator, TagAwareCacheInterface $cachePool): JsonResponse
    {
        $cachePool->invalidateTags(["booksCache"]);
        // Désérialisation du contenu de la requête pour créer une nouvelle instance d'Author.
        $author = $serializer->deserialize($request->getContent(), Author::class, 'json');
        // Vérification des erreurs
        $errors = $validator->validate($author);

        if ($errors->count() > 0) {
            return new JsonResponse($serializer->serialize($errors, 'json'), JsonResponse::HTTP_BAD_REQUEST, [], true);
        }
        // Enregistrement de l'auteur dans la base de données.
        $em->persist($author);
        $em->flush();
        // Sérialisation de l'auteur créé en JSON.
        $context = SerializationContext::create()->setGroups(['getBooks']);
        $jsonAuthor = $serializer->serialize($author, 'json', $context);
        // Génération de l'URL vers les détails de l'auteur créé.
        $location = $urlGenerator->generate('detailAuthor', ['id' => $author->getId(), UrlGeneratorInterface::ABSOLUTE_URL]);
        // Retour d'une réponse JSON avec l'auteur créé et l'URL de ses détails.
        return new JsonResponse($jsonAuthor, Response::HTTP_CREATED, ['Location' => $location], true);
    }

    /**
     * Cette méthode permet de mettre à jour un auteur
     */
    // Définition d'une route pour mettre à jour un auteur spécifique via une requête PUT.
    #[Route('/api/authors/{id}', name: 'updateAuthor', methods: ['PUT'])]
    #[OA\Put(
        path: "/api/authors/{id}",
        summary: "Met à jour un auteur existant",
        tags: ["Authors"],
        requestBody: new OA\RequestBody(
            description: "Données de l'auteur à mettre à jour",
            required: true,
            content: new OA\JsonContent(
                ref: new Model(type: Author::class, groups: ["update"])
            )
        ),
        parameters: [
            new OA\Parameter(
                name: "id",
                in: "path",
                description: "ID de l'auteur à mettre à jour",
                required: true,
                schema: new OA\Schema(type: "integer")
            )
        ],
        responses: [
            new OA\Response(
                response: 204,
                description: "Auteur mis à jour avec succès",
                content: new OA\JsonContent(
                    type: "object",
                    properties: [
                        new OA\Property(property: "id", type: "integer"),
                        new OA\Property(property: "firstName", type: "string"),
                        new OA\Property(property: "lastName", type: "string"),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: "Données invalides fournies"
            ),
            new OA\Response(
                response: 404,
                description: "Auteur non trouvé"
            )
        ]
    )]
    #[IsGranted('ROLE_ADMIN', message: 'Vous n\avez pas les droits suffisants pour modifier un Auteur.')]
    #[OA\Tag(name: "Authors")]
    public function updateAuthor(Request $request, SerializerInterface $serializer, Author $currentAuthor, EntityManagerInterface $em, ValidatorInterface $validator, TagAwareCacheInterface $cachePool): JsonResponse
    {
        $newAuthor = $serializer->deserialize($request->getContent(), Author::class, 'json');
        $currentAuthor->setFirstName($newAuthor->getFirstName());
        $currentAuthor->setLastName($newAuthor->getLastName());

        $errors = $validator->validate($currentAuthor);
        if ($errors->count() > 0) {
            return new JsonResponse($serializer->serialize($errors, 'json'), JsonResponse::HTTP_BAD_REQUEST, [], true);
        }
        $em->persist($currentAuthor);
        $em->flush();
        $cachePool->invalidateTags(["booksCache"]);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
