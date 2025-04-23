<?php

namespace App\Controller\backoffice;

use App\Entity\Admin;
use App\Entity\Log;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;

class AuthController extends AbstractController
{
    // Login Route
    #[Route('/login', name: 'auth_login')]
    public function login(ManagerRegistry $doctrine): Response
    {


        return $this->render('backoffice/auth/login.html.twig', [
            'controller_name' => 'AuthController',

        ]);
    }

    // User Login
    #[Route('/admin/login', name: 'auth_check')]
    public function authcheck(Request $request, ManagerRegistry $doctrine, SessionInterface $session): Response
    {

        $email = $request->request->get('email');
        $password = $request->request->get('password');
        // find user
        $user = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $email]);

        if ($user) {
            // Check  password
            if ($user->getPassword() === $password) {
                // Store user data in a session
                $session->set('admin', [
                    'firstName' => $user->getFirstName(),
                    'lastName' => $user->getLastName(),
                    'email' => $user->getEmail(),

                ]);

                //------- log ------//
                $userSession = $session->get('admin');
                $entityManager = $doctrine->getManager();
                $log = new Log();
                $user = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $userSession['email']]);
                $log->setDate(new \DateTime());
                $log->setText(" logged in ");
                $log->setUser($user);
                $log->setSection('User');
                $entityManager->persist($log);
                $entityManager->flush();

                // Redirect to dashboard
                return $this->redirectToRoute('dashboard');
            } else {
                // If password is incorrect
                $this->addFlash('error', 'Invalid password.');
                return $this->redirectToRoute('auth_login');
            }
        } else {
            // If no user found with the email
            $this->addFlash('error', 'No user with this email exist.');
            return $this->redirectToRoute('auth_login');
        }
    }


    // Logout
    #[Route('/admin/logout', name: 'admin_logout')]
    public function logout(SessionInterface $session, ManagerRegistry $doctrine): RedirectResponse
    {
        $this->addFlash('success', 'You have logged out successfully.');

        //------- log ------//
        $adminSession = $session->get('admin');
        $entityManager = $doctrine->getManager();
        $log = new Log();
        $user = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);
        $log->setDate(new \DateTime());
        $log->setText(" logged out ");
        $log->setUser($user);
        $log->setSection('User');
        $entityManager->persist($log);
        $entityManager->flush();

        // Clear session
        $session->remove('admin');
        $session->invalidate();
        return $this->redirectToRoute('auth_login');
    }
}
