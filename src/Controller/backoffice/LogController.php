<?php

namespace App\Controller\backoffice;

use App\Entity\Admin;
use App\Entity\Log;
use App\Entity\Rate;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Annotation\Route;


final class LogController extends AbstractController
{
    #[Route('/log', name: 'log_index')]
    public function index(ManagerRegistry $doctrine, Session $session): Response
    {
        // Session check
        $adminSession = $session->get('admin');
        if (!$adminSession) {
            return $this->redirectToRoute('auth_login');
        }

        $admin = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);

        // Check if the user has the required role
        $hasDeleteLogRole = false;
        foreach ($admin->getProfile()->getRoles() as $role) {
            if ($role->getName() === 'DELETELOG') {
                $hasDeleteLogRole = true;
                break;
            }
        }

        if (!$hasDeleteLogRole) {
            $session->getFlashBag()->add('danger', 'You do not have permission to view logs.');
            return $this->redirectToRoute('dashboard');
        }

        // Get nav notifications
        $notifications = $doctrine->getRepository(Rate::class)->findBy(
            ['status' => 0],
            ['id' => 'DESC']
        );

        $logs = $doctrine->getRepository(Log::class)->findAll();

        return $this->render('backoffice/log/index.html.twig', [
            'controller_name' => 'LogController',
            'admin' => $admin,
            'logs' => $logs,
            'notifications' => $notifications,

        ]);
    }

    #[Route('/logDelete/{id}', name: 'log_delete')]
    public function delete($id, ManagerRegistry $doctrine, Session $session): Response
    {
        // session check
        $adminSession = $session->get('admin');
        if (!$adminSession) {
            return $this->redirectToRoute('auth_login');
        }

        $admin = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);

        // Check if the user has the required role
        $hasPermission = false;
        foreach ($admin->getProfile()->getRoles() as $role) {
            if ($role->getName() === 'DELETELOG') {
                $hasPermission = true;
                break;
            }
        }

        if (!$hasPermission) {
            $session->getFlashBag()->add('danger', 'You do not have permission to delete logs. Please Check your profile');
            return $this->redirectToRoute('dashboard');
        }


        $entityManager = $doctrine->getManager();
        $log = $entityManager->getRepository(Log::class)->find($id);

        if (!$log) {
            $this->addFlash('error', 'Log not found.');
            return $this->redirectToRoute('log_index');
        }


        $entityManager->remove($log);
        $entityManager->flush();

        $this->addFlash('success', 'Log  deleted successfully.');
        return $this->redirectToRoute('log_index');
    }

    #[Route('/logDeleteMultiple', name: 'logDeleteMultiple')]
    public function deleteMultiple(Request $request, ManagerRegistry $doctrine, Session $session): Response
    {
        // session check
        $adminSession = $session->get('admin');
        if (!$adminSession) {
            return $this->redirectToRoute('auth_login');
        }

        $admin = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);

        // Check if the user has the required role
        $hasPermission = false;
        foreach ($admin->getProfile()->getRoles() as $role) {
            if ($role->getName() === 'DELETELOG') {
                $hasPermission = true;
                break;
            }
        }

        if (!$hasPermission) {
            $session->getFlashBag()->add('danger', 'You do not have permission to delete logs. Please check your profile');
            return $this->redirectToRoute('dashboard');
        }

        // ✅ Retrieve selected log IDs
        $logIds = $request->request->all('logsToDelete', []);

        if (empty($logIds)) {
            $this->addFlash('error', 'No logs selected.');
            return $this->redirectToRoute('log_index');
        }

        $entityManager = $doctrine->getManager();
        $logs = $entityManager->getRepository(Log::class)->findBy(['id' => $logIds]);

        // If no logs were found, show error
        if (empty($logs)) {
            $this->addFlash('error', 'Log(s) not found. Please try again.');
            return $this->redirectToRoute('log_index');
        }

        foreach ($logs as $log) {
            $entityManager->remove($log);
        }

        $entityManager->flush();

        $this->addFlash('success', 'Logs deleted successfully.');
        return $this->redirectToRoute('log_index');
    }
}
