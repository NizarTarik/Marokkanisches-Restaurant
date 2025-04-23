<?php

namespace App\Controller\backoffice;

use App\Entity\Admin;
use App\Entity\Log;
use App\Entity\Profile;
use App\Entity\Rate;
use App\Repository\RoleRepository;
use App\Repository\ProfileRepository as RepositoryProfileRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Annotation\Route;

class ProfileController extends AbstractController
{
    public function __construct(
        private RoleRepository $roleRepository,
        private RepositoryProfileRepository $profilRepository,

    ) {}

    // index
    #[Route('/profile', name: 'profile_index')]
    public function index(Session $session, ManagerRegistry $doctrine): Response
    {
        $adminSession = $session->get('admin');

        if (!$adminSession) {
            return $this->redirectToRoute('auth_login');
        }

        $admin = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);

        // Check if the user has the required role
        $hasPermission = false;
        $allowedRoles = ['ADDPROFILE', 'DELETEPROFILE', 'VALIDATEPROFILE', 'UPDATEPROFILE'];
        foreach ($admin->getProfile()->getRoles() as $role) {
            if (in_array($role->getName(), $allowedRoles)) {
                $hasPermission = true;
                break;
            }
        }

        if (!$hasPermission) {
            $session->getFlashBag()->add('danger', 'You do not have permission to view Profiles. Please Check your profile');
            return $this->redirectToRoute('dashboard');
        }

        // Get nav notifications
        $notifications = $doctrine->getRepository(Rate::class)->findBy(
            ['status' => 0],
            ['id' => 'DESC']
        );

        $users = $doctrine->getRepository(Admin::class)->findAll();

        $usersProfilesIds = array_map(fn($admin) => $admin->getProfile()?->getId(), $users);
        $profiles = $doctrine->getRepository(Profile::class)->findAll();


        return $this->render('backoffice/profile/index.html.twig', [
            'controller_name' => 'ProfileController',
            'admin' => $admin,
            'profiles' => $profiles,
            'users_profiles_ids' => $usersProfilesIds,
            'notifications' => $notifications,


        ]);
    }
    // Validate 

    #[Route('/profile/validate/{id}', name: 'profile_validate')]
    public function validate(int $id, ManagerRegistry $doctrine, Session $session): RedirectResponse
    { // session check
        $adminSession = $session->get('admin');
        if (!$adminSession) {
            return $this->redirectToRoute('auth_login');
        } //end session check

        $user = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);

        // Check if the user has the required role
        $hasPermission = false;
        foreach ($user->getProfile()->getRoles() as $role) {
            if ($role->getName() === 'VALIDATEPROFILE') {
                $hasPermission = true;
                break;
            }
        }

        if (!$hasPermission) {
            $session->getFlashBag()->add('danger', 'You do not have permission to validate profiles. Please Check your profile');
            return $this->redirectToRoute('dashboard');
        }

        $entityManager = $doctrine->getManager();
        $profile = $entityManager->getRepository(Profile::class)->find($id);

        if (!$profile) {
            $this->addFlash('error', 'Profile not found.');
            return $this->redirectToRoute('profile_index');
        }
        $profile->setStatus(1);
        //------- log ------//
        $log = new Log();
        $log->setDate(new \DateTime());
        $log->setText(" validated a profile ");
        $log->setUser($user);
        $log->setSection('Profile');
        $entityManager->persist($log);
        $entityManager->flush();

        $this->addFlash('success', 'Profile validated successfully.');
        return $this->redirectToRoute('profile_index');
    }
    // add
    #[Route('/profileAdd', name: 'profile_add')]
    public function addprofile(Request $request, Session $session, ManagerRegistry $doctrine): Response
    {
        $adminSession = $session->get('admin');

        if (!$adminSession) {
            return $this->redirectToRoute('auth_login');
        }

        $admin = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);

        // Check if the user has the required role
        $hasPermission = false;
        foreach ($admin->getProfile()->getRoles() as $role) {
            if ($role->getName() === 'ADDPROFILE') {
                $hasPermission = true;
                break;
            }
        }

        if (!$hasPermission) {
            $session->getFlashBag()->add('danger', 'You do not have permission to add new profiles. Please Check your profile');
            return $this->redirectToRoute('dashboard');
        }

        // Get nav notifications
        $notifications = $doctrine->getRepository(Rate::class)->findBy(
            ['status' => 0],
            ['id' => 'DESC']
        );


        $rolesListe = $this->roleRepository->findAllGroupedByCategory();
        $data = ["name" => '', "description" => ''];

        if ($request->getMethod() == 'POST') {

            $data = $request->request->all();
            $entityManager = $doctrine->getManager();
            if (empty($data['name']) || empty($data['description'])) {
                $this->addFlash('error', 'Please fill in all required fields.');
                return $this->redirectToRoute('profile_add');
            }
            $roles = $data['roles'] ?? [];

            $this->profilRepository->insertProfil($data, $roles);
            //------- log ------//
            $log = new Log();
            $user = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);
            $log->setDate(new \DateTime());
            $log->setText(" created a new profile ");
            $log->setUser($user);
            $log->setSection('Profile');
            $entityManager->persist($log);
            $entityManager->flush();

            return $this->redirectToRoute('profile_index');
        }

        return $this->render('backoffice/profile/add.html.twig', [
            'controller_name' => 'ProfileController',
            'rolesListe' => $rolesListe,
            'data' => $data,
            'admin' => $admin,
            'notifications' => $notifications,

        ]);
    }
    // Edit
    #[Route('/profileEdit{id}', name: 'profile_edit')]
    public function editProfile($id, Request $request, Session $session, ManagerRegistry $doctrine): Response
    {
        $adminSession = $session->get('admin');

        if (!$adminSession) {
            return $this->redirectToRoute('auth_login');
        }

        $admin = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);

        // Check if the user has the required role
        $hasPermission = false;
        foreach ($admin->getProfile()->getRoles() as $role) {
            if ($role->getName() === 'UPDATEPROFILE') {
                $hasPermission = true;
                break;
            }
        }

        if (!$hasPermission) {
            $session->getFlashBag()->add('danger', 'You do not have permission to update profiles. Please Check your profile');
            return $this->redirectToRoute('dashboard');
        }

        // Get nav notifications
        $notifications = $doctrine->getRepository(Rate::class)->findBy(
            ['status' => 0],
            ['id' => 'DESC']
        );


        $profile = $this->profilRepository->find($id);
        if (!$profile) {
            $this->addFlash('error', 'Profile not found');
        }
        $entityManager = $doctrine->getManager();
        $rolesListe = $this->roleRepository->findAllGroupedByCategory();
        $data = [
            "name" => $profile->getName(),
            "description" => $profile->getDescription()
        ];

        if ($request->getMethod() == 'POST') {
            $data = $request->request->all();
            if (empty($data['name']) || empty($data['description'])) {
                $this->addFlash('error', 'Please fill in all required fields.');
                return $this->redirectToRoute('profile_edit', ['id' => $id]);
            }
            $roles = $data['roles'] ?? [];
            $this->profilRepository->updateProfil($profile, $data, $roles);
            //------- log ------//
            $log = new Log();
            $user = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);
            $log->setDate(new \DateTime());
            $log->setText(" updated a profile ");
            $log->setUser($user);
            $log->setSection('Profile');
            $entityManager->persist($log);
            $entityManager->flush();

            $this->addFlash('success', 'Profile updated successfully');

            return $this->redirectToRoute('profile_index');
        }

        return $this->render('backoffice/profile/edit.html.twig', [
            'controller_name' => 'ProfileController',
            'rolesListe' => $rolesListe,
            'data' => $data,
            'profile' => $profile,
            'admin' => $admin,
            'notifications' => $notifications,


        ]);
    }

    //Delete
    #[Route('/profileDelete/{id}', name: 'profile_delete')]
    public function deleteProfile($id, Session $session, ManagerRegistry $doctrine): Response
    {
        $adminSession = $session->get('admin');

        if (!$adminSession) {
            return $this->redirectToRoute('auth_login');
        }

        $user = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);

        // Check if the user has the required role
        $hasPermission = false;
        foreach ($user->getProfile()->getRoles() as $role) {
            if ($role->getName() === 'DELETEPROFILE') {
                $hasPermission = true;
                break;
            }
        }

        if (!$hasPermission) {
            $session->getFlashBag()->add('danger', 'You do not have permission to delete profiles. Please Check your profile');
            return $this->redirectToRoute('dashboard');
        }


        $entityManager = $doctrine->getManager();
        $profile = $this->profilRepository->find($id);

        if (!$profile) {
            $this->addFlash('error', 'Profile not found');
            return $this->redirectToRoute('profile_index');
        }

        // Remove associated roles
        foreach ($profile->getRoles() as $role) {
            $profile->removeRole($role);
        }

        // Delete the profile
        $entityManager->remove($profile);
        //------- log ------//
        $log = new Log();
        $log->setDate(new \DateTime());
        $log->setText(" deleted a profile ");
        $log->setUser($user);
        $log->setSection('Profile');
        $entityManager->persist($log);
        $entityManager->flush();
        $this->addFlash('success', 'Profile deleted successfully');
        return $this->redirectToRoute('profile_index');
    }
}
