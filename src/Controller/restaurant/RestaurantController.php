<?php

namespace App\Controller\restaurant;

use App\Entity\Discount;
use App\Entity\Meal;
use App\Entity\Rate;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class RestaurantController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function index(ManagerRegistry $doctrine): Response
    {
        $rating = $doctrine->getRepository(Rate::class)->findAll();
        $meals = $doctrine->getRepository(Meal::class)->createQueryBuilder('m')
            ->where('m.status = 1')
            ->getQuery()
            ->getResult();
        $mealdiscounts = $doctrine->getRepository(Meal::class)->createQueryBuilder('m')
            ->where('m.discount IS NOT NULL AND m.status = 1')
            ->getQuery()
            ->getResult();

        return $this->render('Restaurant/index.html.twig', [
            'controller_name' => 'RestaurantController',
            'mealdiscounts' => $mealdiscounts,
            'rating' => $rating,
            'meals' => $meals
        ]);
    }


    // rate Store
    #[Route('/rate/store', name: 'rate_store')]
    public function store(Request $request, ManagerRegistry $doctrine): RedirectResponse
    {

        $entityManager = $doctrine->getManager();

        // Get form data

        $email = $request->request->get('email');
        $description = $request->request->get('description');
        $stars = $request->request->get('stars');
        $first_name = $request->request->get('first_name');
        $last_name = $request->request->get('last_name');

        // Check if all fields are filled
        if (
            empty($email) || empty($description) || empty($stars)
        ) {
            $this->addFlash('error', 'Please fill in all required fields.');
            return $this->redirectToRoute('home');
        }


        // Create the new rate
        $rate = new Rate();
        $rate->setEmail($email);
        $rate->setDescription($description);
        $rate->setStars($stars);
        $rate->setFirstname($first_name);
        $rate->setStatus(0);
        $rate->setLastname($last_name);
        $rate->setDate(new \DateTime());
        $entityManager->persist($rate);
        $entityManager->flush();
        $this->addFlash('success', 'Thank you for your feedback!');
        return $this->redirectToRoute('home');
    }
}
