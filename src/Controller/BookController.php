<?php
// Déclaration de l'espace de noms pour ce contrôleur, permettant son utilisation dans l'application.
namespace App\Controller;

// Importation des classes nécessaires au fonctionnement du contrôleur.
use App\Entity\Book;
use App\Repository\AuthorRepository;
use App\Repository\BookRepository;
use App\Service\VersioningService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use JMS\Serializer\SerializerInterface;
use JMS\Serializer\SerializationContext;
use Nelmio\ApiDocBundle\Annotation\Model;
use Nelmio\ApiDocBundle\Annotation\Security;
use OpenApi\Attributes as OA;

// Définition de la classe BookController qui étend AbstractController, fournissant des fonctionnalités de base pour les contrôleurs dans Symfony.
class BookController extends AbstractController
{
    /**
     * Cette méthode permet de récupérer l'ensemble des livres
     */
    // Définition d'une route pour récupérer tous les livres. La méthode HTTP autorisée est GET.
    #[Route('/api/books', name: 'book', methods: ['GET'])]
    #[OA\Response(
        response: 200,
        description: "Retourne la liste des livres",
        content: new OA\JsonContent(
            type: "array",
            items: new OA\Items(ref: new Model(type: Book::class, groups: ["getBooks"]))
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
    #[OA\Tag(name: "Books")]
    public function getAllBooks(BookRepository $bookRepository, SerializerInterface $serializer, Request $request, TagAwareCacheInterface $cache, VersioningService $versioningService): JsonResponse
    {
        $page = $request->get('page', 1);
        $limit = $request->get('limit', 3);

        $idCache = "getAllBooks-" . $page . "-" . $limit;
        // Récupération de tous les livres via le répertoire.
        $jsonBookList = $cache->get($idCache, function (ItemInterface $item) use ($bookRepository, $page, $limit, $serializer) {
            $item->tag("booksCache");
            $bookList = $bookRepository->findAllWithPagination($page, $limit);
            $context = SerializationContext::create()->setGroups(['getBooks']);

            return $serializer->serialize($bookList, 'json', $context);
        });
        // Retour d'une réponse JSON contenant la liste des livres.
        return new JsonResponse($jsonBookList, Response::HTTP_OK, [], true);
    }

    /**
     * Cette méthode permet de récupérer un livre spécifique par son ID
     */
    // Définition d'une route pour récupérer les détails d'un livre spécifique par son ID. La méthode HTTP autorisée est GET.
    #[Route('/api/books/{id}', name: 'detailBook', methods: ['GET'])]
    #[OA\Tag(name: "Books")]
    public function getBookDetail(Book $book, SerializerInterface $serializer, VersioningService $versioningService): JsonResponse
    {
        $version = $versioningService->getVersion();
        $context = SerializationContext::create()->setGroups(['getBooks']);
        $context->setVersion($version);
        // Sérialisation du livre en JSON.
        $jsonBook = $serializer->serialize($book, 'json', $context);
        // Retour d'une réponse JSON contenant les détails du livre.
        return new JsonResponse($jsonBook, Response::HTTP_OK, [], true);
    }

    /**
     * Cette méthode supprimer un livre en fonction de son id
     */
    // Définition d'une route pour supprimer un livre spécifique par son ID. La méthode HTTP autorisée est DELETE.
    #[Route('/api/books/{id}', name: 'deleteBook', methods: ['DELETE'])]
    #[OA\Tag(name: "Books")]

    #[IsGranted('ROLE_ADMIN', message: 'Vous n\avez pas les droits suffisants pour supprimer un livre.')]
    public function deleteBook(Book $book, EntityManagerInterface $em, TagAwareCacheInterface $cachePool): JsonResponse
    {
        $cachePool->invalidateTags(["booksCache"]);
        // Suppression du livre de la base de données.
        $em->remove($book);
        $em->flush();
        // Retour d'une réponse JSON indiquant que le contenu n'existe plus.
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Cette méthodde permet de créer un nouveau livre
     */
    // Définition d'une route pour créer un nouveau livre. La méthode HTTP autorisée est POST.
    #[Route('/api/books', name: 'createBook', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN', message: 'Vous n\avez pas les droits suffisants pour créer un livre.')]
    #[OA\Post(
        path: "/api/books",
        summary: "Crée un nouveau livre",
        tags: ["Books"],
        requestBody: new OA\RequestBody(
            description: "Les informations du livre à créer",
            required: true,
            content: new OA\JsonContent(
                ref: new Model(type: Book::class, groups: ["create"])
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: "Livre crée avec succès",
                content: new OA\JsonContent(
                    type: "object",
                    properties: [
                        new OA\Property(property: "id", type: "integer"),
                        new OA\Property(property: "title", type: "string"),
                        new OA\Property(property: "coverText", type: "string"),
                        new OA\Property(property: "idAuthor", type: "integer"),
                        new OA\Property(property: "comment", type: "string"),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: "Erreur de validation",
            )
        ]
    )]
    #[OA\Tag(name: "Books")]
    public function createBook(Request $request, SerializerInterface $serializer, EntityManagerInterface $em, UrlGeneratorInterface $urlGenerator, AuthorRepository $authorRepository, ValidatorInterface $validator, TagAwareCacheInterface $cachePool): JsonResponse
    {
        $cachePool->invalidateTags(["booksCache"]);

        // Désérialisation du contenu de la requête pour créer une instance de Book.
        $book = $serializer->deserialize($request->getContent(), Book::class, 'json');
        // Vérification des erreurs
        $errors = $validator->validate($book);
        if ($errors->count() > 0) {
            return new JsonResponse($serializer->serialize($errors, 'json'), JsonResponse::HTTP_BAD_REQUEST, [], true);
        }
        // Persistance du livre dans la base de données.
        $em->persist($book);
        $em->flush();
        // Récupération des données envoyées avec la requête.
        $content = $request->toArray();
        // Récupération de l'ID de l'auteur, avec une valeur par défaut si non spécifié.
        $idAuthor = $content['idAuthor'] ?? -1;
        // Association de l'auteur au livre.
        $book->setAuthor($authorRepository->find($idAuthor));
        // Sérialisation du livre créé en JSON.
        $context = SerializationContext::create()->setGroups(['getBooks']);
        $jsonBook = $serializer->serialize($book, 'json', $context);
        // Génération de l'URL vers les détails du livre créé.
        $location = $urlGenerator->generate('detailBook', ['id' => $book->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
        // Retour d'une réponse JSON avec l'URL du livre créé.
        return new JsonResponse($jsonBook, Response::HTTP_CREATED, ['Location' => $location], true);
    }

    /**
     * Cette méthode permet de mettre à jour un livre
     */
    // Définition d'une route pour mettre à jour un livre spécifique par son ID. La méthode HTTP autorisée est PUT.
    #[Route('/api/books/{id}', name: 'updateBook', methods: ['PUT'])]
    #[OA\Put(
        path: "/api/books/{id}",
        summary: "Met à jour un livre existant",
        tags: ["Books"],
        requestBody: new OA\RequestBody(
            description: "Les informations du livre à mettre à jour",
            required: true,
            content: new OA\JsonContent(
                ref: new Model(type: Book::class, groups: ["update"])
            )
        ),
        parameters: [
            new OA\Parameter(
                name: "id",
                in: "path",
                description: "L'identifiant du livre à mettre à jour",
                required: true,
                schema: new OA\Schema(type: "integer")
            )
        ],
        responses: [
            new OA\Response(
                response: 204,
                description: "Livre mis à jour avec succès",
                content: new OA\JsonContent(
                    type: "object",
                    properties: [
                        new OA\Property(property: "id", type: "integer"),
                        new OA\Property(property: "title", type: "string"),
                        new OA\Property(property: "coverText", type: "string"),
                        new OA\Property(property: "idAuthor", type: "integer"),
                        new OA\Property(property: "comment", type: "string"),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: "Erreur de validation"
            ),
            new OA\Response(
                response: 404,
                description: "Livre non trouvé"
            )
        ]
    )]
    #[IsGranted('ROLE_ADMIN', message: 'Vous n\avez pas les droits suffisants pour modifier un livre.')]
    #[OA\Tag(name: "Books")]
    public function updateBook(Request $request, SerializerInterface $serializer, Book $currentBook, EntityManagerInterface $em, AuthorRepository $authorRepository, ValidatorInterface $validator, TagAwareCacheInterface $cache): JsonResponse
    {
        $newBook = $serializer->deserialize($request->getContent(), Book::class, 'json');
        $currentBook->setTitle($newBook->getTitle());
        $currentBook->setCoverText($newBook->getCoverText());

        // On vérifie les erreurs
        $errors = $validator->validate($currentBook);
        if ($errors->count() > 0) {
            return new JsonResponse($serializer->serialize($errors, 'json'), JsonResponse::HTTP_BAD_REQUEST, [], true);
        }

        $content = $request->toArray();
        $idAuthor = $content['idAuthor'] ?? -1;

        $currentBook->setAuthor($authorRepository->find($idAuthor));

        $em->persist($currentBook);
        $em->flush();

        // On vide le cache
        $cache->invalidateTags(["booksCache"]);

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }
}
