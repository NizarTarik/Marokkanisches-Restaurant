<?php

namespace App\Controller\backoffice;

use App\Entity\Admin;
use App\Entity\Log;
use App\Entity\Rate;
use Symfony\Component\HttpFoundation\Request as HttpFoundationRequest;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\Request;

final class ReviewsController extends AbstractController
{
    #[Route('/reviews', name: 'reviews_index')]
    public function index(ManagerRegistry $doctrine, Session $session): Response
    {
        $adminSession = $session->get('admin');
        if (!$adminSession) {
            return $this->redirectToRoute('auth_login');
        }

        $admin = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);

        $hasPermission = false;
        foreach ($admin->getProfile()->getRoles() as $role) {
            if ($role->getName() === 'DELETEREVIEW') {
                $hasPermission = true;
                break;
            }
        }

        if (!$hasPermission) {
            $session->getFlashBag()->add('danger', 'You do not have permission to view reviews. Please Check your profile');
            return $this->redirectToRoute('dashboard');
        }

        $entityManager = $doctrine->getManager();

        // Set status = 1 for all reviews
        $allReviews = $entityManager->getRepository(Rate::class)->findAll();
        foreach ($allReviews as $review) {
            $review->setStatus(1);
        }
        $entityManager->flush();

        // Get updated reviews, ordered by latest
        $reviews = $entityManager->getRepository(Rate::class)->findBy(
            [],
            ['id' => 'DESC']
        );

        // Get notifications (status = 0)
        $notifications = $doctrine->getRepository(Rate::class)->findBy(
            ['status' => 0],
            ['id' => 'DESC']
        );

        return $this->render('backoffice/reviews/index.html.twig', [
            'controller_name' => 'ReviewsController',
            'admin' => $admin,
            'reviews' => $reviews,
            'notifications' => $notifications,
        ]);
    }


    // reviews delete 
    #[Route('/reviews/delete/{id}', name: 'reviews_delete', methods: ['POST'])]
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
            if ($role->getName() === 'DELETEREVIEW') {
                $hasPermission = true;
                break;
            }
        }

        if (!$hasPermission) {
            $session->getFlashBag()->add('danger', 'You do not have permission to delete reviews. Please Check your profile');
            return $this->redirectToRoute('dashboard');
        }


        $entityManager = $doctrine->getManager();
        $review = $entityManager->getRepository(Rate::class)->find($id);

        if (!$review) {
            $this->addFlash('error', 'review not found.');
            return $this->redirectToRoute('reviews_index');
        }

        $entityManager->remove($review);
        //------- log ------//
        $log = new Log();
        $user = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);
        $log->setDate(new \DateTime());
        $log->setText(" deleted a review ");
        $log->setUser($user);
        $log->setSection('REVIEW');
        $entityManager->persist($log);
        $entityManager->flush();

        $this->addFlash('success', ' Review deleted successfully.');
        return $this->redirectToRoute('reviews_index');
    }
    // reviews delete multiple 
    #[Route('/reviewDeleteMultiple', name: 'reviewDeleteMultiple')]
    public function deleteMultiple(HttpFoundationRequest $request, ManagerRegistry $doctrine, Session $session): Response
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
            if ($role->getName() === 'DELETEREVIEW') {
                $hasPermission = true;
                break;
            }
        }

        if (!$hasPermission) {
            $session->getFlashBag()->add('danger', 'You do not have permission to delete reviews. Please check your profile');
            return $this->redirectToRoute('dashboard');
        }

        $reviewsId = $request->request->all('reviewsToDelete', []);
        $entityManager = $doctrine->getManager();
        $reviews = $entityManager->getRepository(Rate::class)->findBy(['id' => $reviewsId]);

        // If no meals were found, show error
        if (empty($reviews)) {
            $this->addFlash('error', 'review(s) not found. Please try again.');
            return $this->redirectToRoute('reviews_index');
        }

        foreach ($reviews as $review) {
            $entityManager->remove($review);
        }

        //------- log ------//
        $log = new Log();
        $user = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);
        $log->setDate(new \DateTime());
        $log->setText(" deleted reviews ");
        $log->setUser($user);
        $log->setSection('REVIEW');
        $entityManager->persist($log);
        $entityManager->flush();
        $this->addFlash('success', 'review(s) deleted successfully.');
        return $this->redirectToRoute('reviews_index');
    }
}
