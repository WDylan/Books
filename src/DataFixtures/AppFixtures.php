<?php

namespace App\DataFixtures;

use App\Entity\Author;
use App\Entity\Book;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    private $userPasswordHasher;
    public function __construct(UserPasswordHasherInterface $userPasswordHasher)
    {
        $this->userPasswordHasher = $userPasswordHasher;
    }
    public function load(ObjectManager $manager): void
    {
        // Création d'un user
        $user = new User();
        $user->setEmail('user@bookapi.com');
        $user->setRoles(['ROLE_USER']);
        $user->setPassword(($this->userPasswordHasher)->hashPassword($user, 'password'));
        $manager->persist($user);

        // Créaton d'un user avec le rôle admin
        $userAdmin = new User();
        $userAdmin->setEmail('admin@bookapi.com');
        $userAdmin->setRoles(['ROLE_ADMIN']);
        $userAdmin->setPassword($this->userPasswordHasher->hashPassword($userAdmin, 'password'));
        $manager->persist($userAdmin);

        // Création des auteurs
        $listAuthor = [];
        for ($i = 0; $i < 10; $i++) {
            $author = new Author();
            $author->setFirstName('Prénom ' . $i);
            $author->setLastName('Nom ' . $i);
            $manager->persist($author);

            $listAuthor[] = $author;
        }

        // Création des livres
        for ($i = 0; $i < 20; $i++) {
            $book = new Book();
            $book->setTitle('Livre ' . $i);
            $book->setCoverText('Quatrième de couverture n° : ' . $i);
            $book->setComment('Commentaire du bibliothécaire n° : ' . $i);
            $book->setAuthor($listAuthor[array_rand($listAuthor)]);
            $manager->persist($book);
        }
        $manager->flush();
    }
}
