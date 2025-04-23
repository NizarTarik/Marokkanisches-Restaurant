<?php

namespace App\Repository;

use App\Entity\Profile;
use App\Entity\Role;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Profile>
 */
class ProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Profile::class);
    }
    public function insertProfil($profilData, $roles): void
    {
        $entityManager = $this->getEntityManager();

        // Create a new Profil instance
        $profil = new Profile();
        $profil->setName($profilData['name']);
        $profil->setDescription($profilData['description']);
        $profil->setStatus(0);

        // Fetch the Role entities from the database based on the selected roles
        $selectedRoles = [];
        foreach ($roles as $roleId => $isSelected) {
            if ($isSelected === "on") {
                $role = $entityManager->getRepository(Role::class)->find($roleId);
                if ($role) {
                    $selectedRoles[] = $role;
                }
            }
        }

        // Associate the fetched Role entities with the Profil
        foreach ($selectedRoles as $role) {
            $profil->addRole($role);
        }

        // Persist the Profil entity along with its associated Role entities
        $entityManager->persist($profil);
        $entityManager->flush();
    }
    public function updateProfil(Profile $profile, $profilData, $roles): void
    {
        $entityManager = $this->getEntityManager();

        $profile->setName($profilData['name']);
        $profile->setDescription($profilData['description']);

        // Remove all existing roles
        foreach ($profile->getRoles() as $role) {
            $profile->removeRole($role);
        }

        // Fetch and associate new roles
        foreach ($roles as $roleId => $isSelected) {
            if ($isSelected === "on") {
                $role = $entityManager->getRepository(Role::class)->find($roleId);
                if ($role) {
                    $profile->addRole($role);
                }
            }
        }

        $entityManager->persist($profile);
        $entityManager->flush();
    }

    //    /**
    //     * @return Profile[] Returns an array of Profile objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('p.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Profile
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
