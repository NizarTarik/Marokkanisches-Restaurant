<?php

namespace App\Controller\backoffice;

use App\Entity\Admin;
use App\Entity\Log;
use App\Entity\Profile;
use App\Entity\Rate;
use App\Repository\AdminRepository;
use App\Repository\ProfileRepository;
use App\Repository\RoleRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Attribute\Route;

class AdminController extends AbstractController
{
    public function __construct(
        private RoleRepository $roleRepository,
        private ProfileRepository $profilRepository,
        private AdminRepository $adminRepository,

    ) {}
    // Admin index
    #[Route('/user', name: 'admin_index')]
    public function index(ManagerRegistry $doctrine, Session $session): Response
    {
        // session check
        $adminSession = $session->get('admin');


        if (!$adminSession) {
            return $this->redirectToRoute('auth_login');
        } //end session check


        $admin = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);

        // Check if the user has the required role
        $allowedRoles = ['ADDUSER', 'DELETEUSER', 'VALIDATEUSER', 'UPDATEUSER'];
        $hasPermission = false;
        foreach ($admin->getProfile()->getRoles() as $role) {
            if (in_array($role->getName(), $allowedRoles)) {
                $hasPermission = true;
                break;
            }
        }

        if (!$hasPermission) {
            $session->getFlashBag()->add('danger', 'You do not have permission to view users. Please Check your profile');
            return $this->redirectToRoute('dashboard');
        }

        $notifications = $doctrine->getRepository(Rate::class)->findBy(
            ['status' => 0],
            ['id' => 'DESC']
        );
        $admins = $doctrine->getRepository(Admin::class)->findAll();
        $filteredAdmins = array_filter($admins, function ($a) use ($admin) {
            return $a->getId() !== $admin->getId();
        });


        return $this->render('backoffice/admin/index.html.twig', [
            'controller_name' => 'AdminController',
            'admin' => $admin,
            'admins' => $admins,
            'notifications' => $notifications,

        ]);
    }


    // Admin delete 
    #[Route('/admin/delete/{id}', name: 'admin_delete')]
    public function delete(int $id, ManagerRegistry $doctrine,  Session $session): RedirectResponse
    { // session check
        $adminSession = $session->get('admin');
        if (!$adminSession) {
            return $this->redirectToRoute('auth_login');
        } //end session check


        $admin = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);

        // Check if the user has the required role
        $hasPermission = false;
        foreach ($admin->getProfile()->getRoles() as $role) {
            if ($role->getName() === 'DELETEUSER') {
                $hasPermission = true;
                break;
            }
        }
        if (!$hasPermission) {
            $session->getFlashBag()->add('danger', 'You do not have permission to delete users. Please Check your profile');
            return $this->redirectToRoute('dashboard');
        }
        $entityManager = $doctrine->getManager();
        $deletedadmin = $entityManager->getRepository(Admin::class)->find($id);

        if (!$deletedadmin) {
            $this->addFlash('error', 'Admin not found.');
            return $this->redirectToRoute('admin_index');
        }
        if ($deletedadmin == $admin) {
            // Clear session
            $session->remove('admin');
            $session->invalidate();

            // Delete logs of this admin
            foreach ($deletedadmin->getLogs() as $log) {
                $entityManager->remove($log);
            }

            // Now delete the admin
            $entityManager->remove($deletedadmin);

            $entityManager->flush(); // Commit all changes

            $this->addFlash('success', 'Your user account is deleted successfully. Contact admins to create a new account.');

            return $this->redirectToRoute('auth_login');
        }


        // Delete logs of this admin
        foreach ($deletedadmin->getLogs() as $log) {
            $entityManager->remove($log);
        }
        $entityManager->remove($deletedadmin);
        //------- log ------//
        $log = new Log();
        $user = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);
        $log->setDate(new \DateTime());
        $log->setText(" deleted a user ");
        $log->setUser($user);
        $log->setSection('User');

        $entityManager->persist($log);
        $entityManager->flush();

        $this->addFlash('success', 'Admin ' . $admin->getFirstname() . ' ' . $admin->getLastname() . ' deleted successfully.');
        return $this->redirectToRoute('admin_index');
    }



    // Admin edit
    #[Route('/adminedit{id}', name: 'admin_edit')]
    public function edit($id, ManagerRegistry $doctrine,  Session $session): Response
    {
        //session check

        $adminSession = $session->get('admin');
        if (!$adminSession) {
            return $this->redirectToRoute('auth_login');
        } //end session check

        $admin = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);


        $notifications = $doctrine->getRepository(Rate::class)->findBy(
            ['status' => 0],
            ['id' => 'DESC']
        );
        $edited_admin = $doctrine->getRepository(Admin::class)->find($id);
        $profilsListe = $this->profilRepository->findAll();
        $profile = $edited_admin->getProfile();
        $rolesListe = $this->roleRepository->findAllGroupedByCategory();


        return $this->render('backoffice/admin/edit.html.twig', [
            'controller_name' => 'AdminController',
            'admin' => $admin,
            'edited_admin' => $edited_admin,
            'profils' => $profilsListe,
            'rolesListe' => $rolesListe,
            'profile' => $profile,
            'notifications' => $notifications,

        ]);
    }

    // Admin update
    #[Route('/adminupdate/{id}', name: 'admin_update', methods: ['POST'])]
    public function updateProfile($id, Request $request, ManagerRegistry $doctrine, Session $session): RedirectResponse
    {
        //session check
        $adminSession = $session->get('admin');
        if (!$adminSession) {
            return $this->redirectToRoute('auth_login');
        } //end session check

        $entityManager = $doctrine->getManager();
        $admin = $doctrine->getRepository(Admin::class)->find($id);

        if (!$admin) {
            $this->addFlash('error', 'No active admin found.');
            return $this->redirectToRoute('admin_index');
        }

        $firstname = $request->request->get('firstname');
        $lastname = $request->request->get('lastname');
        $email = $request->request->get('email');
        $password = $request->request->get('password');
        $phone = $request->request->get('phone');
        $address = $request->request->get('address');
        $image = $request->files->get('img');
        $imagePath = $admin->getImg();
        $profile =  $request->request->get('profile');
        $profile = $doctrine->getRepository(Profile::class)->find($profile);

        if (empty($firstname) || empty($lastname) || empty($email) || empty($password) || empty($profile) || empty($address) || empty($phone)) {
            $this->addFlash('error', 'Please fill in all required fields.');
            return $this->redirectToRoute('admin_edit', ['id' => $id]);
        }
        if ($image) {
            $allowedExtensions = ['png', 'jpeg', 'jpg'];
            $extension = $image->guessExtension();

            if (!in_array($extension, $allowedExtensions)) {
                $this->addFlash('error', 'Invalid image format. Only PNG, JPEG, and JPG are allowed.');
                return $this->redirectToRoute('admin_index');
            }

            // Handle image upload
            $imageName = uniqid() . '.' . $extension;
            $image->move('uploads/images/backoffice', $imageName);
            $imagePath = 'uploads/images/backoffice/' . $imageName;
        }

        $admin->setFirstName($firstname);
        $admin->setLastname($lastname);
        $admin->setEmail($email);
        $admin->setImg($imagePath);
        $admin->setPhone($phone);
        $admin->setAddress($address);
        $admin->setPassword($password);
        $admin->setProfile($profile);
        $entityManager->persist($admin);
        //------- log ------//
        $log = new Log();
        $user = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);
        $log->setDate(new \DateTime());
        $log->setText(" updated a user ");
        $log->setUser($user);
        $log->setSection('User');
        $entityManager->persist($log);
        $entityManager->flush();
        $this->addFlash('success', 'User  ' . $firstname . ' ' . $lastname . '  updated successfully.');
        return $this->redirectToRoute('admin_index');
    }


    // create admin
    #[Route('/admincreate', name: 'admin_create')]
    public function create(ManagerRegistry $doctrine, Session $session): Response
    { // session check
        $adminSession = $session->get('admin');
        if (!$adminSession) {
            return $this->redirectToRoute('auth_login');
        }  // session check
        $admin = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);

        // Check if the user has the required role
        $hasPermission = false;
        foreach ($admin->getProfile()->getRoles() as $role) {
            if ($role->getName() === 'ADDUSER') {
                $hasPermission = true;
                break;
            }
        }

        if (!$hasPermission) {
            $session->getFlashBag()->add('danger', 'You do not have permission to create new users. Please Check your profile');
            return $this->redirectToRoute('dashboard');
        }

        // Get nav notifications
        $notifications = $doctrine->getRepository(Rate::class)->findBy(
            ['status' => 0],
            ['id' => 'DESC']
        );

        $profilsListe = $this->profilRepository->findby(['status' => 1]);

        return $this->render('backoffice/admin/create.html.twig', [
            'controller_name' => 'AdminController',
            'admin' => $admin,
            'profils' => $profilsListe,
            'notifications' => $notifications,


        ]);
    }
    // Admin Store
    #[Route('/admin/store', name: 'admin_store')]
    public function store(Request $request, ManagerRegistry $doctrine, Session $session): RedirectResponse
    {
        // Session check
        $adminSession = $session->get('admin');
        if (!$adminSession) {
            return $this->redirectToRoute('auth_login');
        }

        $entityManager = $doctrine->getManager();

        // Get form data
        $firstname = trim($request->request->get('firstname'));
        $lastname = trim($request->request->get('lastname'));
        $email = trim($request->request->get('email'));
        $password = $request->request->get('password');
        $confirmPassword = $request->request->get('Confirmpassword');
        $address = trim($request->request->get('address'));
        $phone = trim($request->request->get('phone'));
        $profile = $request->request->get('profile');
        $image = $request->files->get('img');

        // Check if all fields are filled
        if (
            empty($firstname) || empty($lastname) || empty($email) || empty($password) ||
            empty($confirmPassword) || empty($address) || empty($phone) || empty($profile)
        ) {
            $this->addFlash('error', 'Please fill in all required fields.');
            return $this->redirectToRoute('admin_create');
        }



        $phone = (int) $phone;

        // Check if passwords match
        if ($password !== $confirmPassword) {
            $this->addFlash('error', 'Passwords do not match.');
            return $this->redirectToRoute('admin_create');
        }

        // Check if email is already registered
        $existingAdmin = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $email]);
        if ($existingAdmin) {
            $this->addFlash('error', 'Admin with this email already exists.');
            return $this->redirectToRoute('admin_create');
        }

        // Retrieve profile
        $profile = $doctrine->getRepository(Profile::class)->find($profile);
        if (!$profile) {
            $this->addFlash('error', 'Invalid profile selected.');
            return $this->redirectToRoute('admin_create');
        }

        // Handle image upload
        $imagePath = null;
        if ($image) {
            $allowedExtensions = ['png', 'jpeg', 'jpg'];
            $extension = $image->guessExtension();

            if (!in_array($extension, $allowedExtensions)) {
                $this->addFlash('error', 'Invalid image format. Only PNG, JPEG, and JPG are allowed.');
                return $this->redirectToRoute('admin_create');
            }

            $imageName = uniqid() . '.' . $extension;
            $image->move('uploads/images/backoffice', $imageName);
            $imagePath = 'uploads/images/backoffice/' . $imageName;
        }

        // Create the new admin
        $admin = new Admin();
        $admin->setFirstName($firstname);
        $admin->setLastname($lastname);
        $admin->setEmail($email);
        $admin->setPassword($password);
        $admin->setImg($imagePath);
        $admin->setProfile($profile);
        $admin->setStatus(0);
        $admin->setPhone($phone);
        $admin->setAddress($address);
        $entityManager->persist($admin);
        //------- log ------//
        $log = new Log();
        $user = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);
        $log->setDate(new \DateTime());
        $log->setText(" created a new user ");
        $log->setUser($user);
        $log->setSection('User');
        $entityManager->persist($log);
        $entityManager->flush();
        $this->addFlash('success', 'User ' . $firstname . ' ' . $lastname . ' created successfully!');
        return $this->redirectToRoute('admin_index');
    }


    #[Route('/user/validate/{id}', name: 'user_validate')]
    public function validate(int $id, ManagerRegistry $doctrine, Session $session): RedirectResponse
    { // session check
        $adminSession = $session->get('admin');
        if (!$adminSession) {
            return $this->redirectToRoute('auth_login');
        } //end session check

        $admin = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);

        // Check if the user has the required role
        $hasPermission = false;
        foreach ($admin->getProfile()->getRoles() as $role) {
            if ($role->getName() === 'VALIDATEUSER') {
                $hasPermission = true;
                break;
            }
        }

        if (!$hasPermission) {
            $session->getFlashBag()->add('danger', 'You do not have permission to validate users. Please Check your profile');
            return $this->redirectToRoute('dashboard');
        }
        $entityManager = $doctrine->getManager();
        $user = $entityManager->getRepository(Admin::class)->find($id);

        if (!$user) {
            $this->addFlash('error', 'User not found.');
            return $this->redirectToRoute('admin_index');
        }
        $user->setStatus(1);
        //------- log ------//
        $log = new Log();
        $user = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);
        $log->setDate(new \DateTime());
        $log->setText(" validated a user ");
        $log->setUser($user);
        $log->setSection('User');
        $entityManager->persist($log);
        $entityManager->flush();

        $this->addFlash('success', 'User validated successfully.');
        return $this->redirectToRoute('admin_index');
    }
}
