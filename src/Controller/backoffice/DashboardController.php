<?php

namespace App\Controller\backoffice;

use App\Entity\Admin;
use App\Entity\Discount;
use App\Entity\Log;
use App\Entity\Meal;
use App\Entity\Profile;
use App\Entity\Rate;
use App\Entity\Request;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'dashboard')]
    public function index(ManagerRegistry $doctrine, Session $session): Response
    {
        $adminSession = $session->get('admin');
        if (!$adminSession) {
            return $this->redirectToRoute('auth_login');
        }

        $admin = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);
        $notifications = $doctrine->getRepository(Rate::class)->findBy(['status' => 0], ['id' => 'DESC']);

        $totalUsers = count($doctrine->getRepository(Admin::class)->findAll());
        $totalProfiles = count($doctrine->getRepository(Profile::class)->findAll());
        $totalMeals = count($doctrine->getRepository(Meal::class)->findAll());
        $totalDiscounts = count($doctrine->getRepository(Discount::class)->findAll());
        $totalRequests = count($doctrine->getRepository(Request::class)->findAll());
        $totalReviews = count($doctrine->getRepository(Rate::class)->findAll());
        $totalLogs = count($doctrine->getRepository(Log::class)->findAll());


        return $this->render('backoffice/dashboard/index.html.twig', [
            'admin' => $admin,
            'notifications' => $notifications,
            'totalUsers' => $totalUsers,
            'totalProfiles' => $totalProfiles,
            'totalMeals' => $totalMeals,
            'totalDiscounts' => $totalDiscounts,
            'totalRequests' => $totalRequests,
            'totalReviews' => $totalReviews,
            'totalLogs' => $totalLogs,
        ]);
    }
}
