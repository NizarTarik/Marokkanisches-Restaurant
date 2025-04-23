<?php

namespace App\Controller\backoffice;

use App\Entity\Admin;
use App\Entity\Discount;
use App\Entity\Log;
use App\Entity\Meal;
use App\Entity\Rate;
use App\Entity\Request;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request as HttpFoundationRequest;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Attribute\Route;

final class RequestController extends AbstractController
{

    // Controller
    #[Route('/requestindex', name: 'request_index')]
    public function index(ManagerRegistry $doctrine, Session $session): Response
    {
        $adminSession = $session->get('admin');

        if (!$adminSession) {
            return $this->redirectToRoute('auth_login');
        }

        $admin = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);

        // Check if the user has the required role
        $hasPermission = false;
        $allowedRoles = ['REFUSEREQUEST', 'ACCEPTREQUEST', 'ADDREQUEST'];

        foreach ($admin->getProfile()->getRoles() as $role) {
            if (in_array($role->getName(), $allowedRoles)) {
                $hasPermission = true;
                break;
            }
        }

        if (!$hasPermission) {
            $session->getFlashBag()->add('danger', 'You do not have permission to view requests. Please Check your profile');
            return $this->redirectToRoute('dashboard');
        }

        // Get nav notifications
        $notifications = $doctrine->getRepository(Rate::class)->findBy(
            ['status' => 0],
            ['id' => 'DESC']
        );

        $discounts = $doctrine->getRepository(Discount::class)->findAll();
        $requests = $doctrine->getRepository(Request::class)->findAll();

        // Calculate the remaining days for each discount
        foreach ($discounts as $discount) {
            if ($discount->getFinaldate()) {
                $remainingDays = $discount->getFinaldate()->diff(new \DateTime())->days;
                $discount->remainingDays = $remainingDays;
            }
        }

        return $this->render('backoffice/request/index.html.twig', [
            'requests' => $requests,
            'admin' => $admin,
            'discounts' => $discounts,
            'notifications' => $notifications,


        ]);
    }



    // Create meal
    #[Route('/createrequest', name: 'request_create')]
    public function create(ManagerRegistry $doctrine, Session $session): Response
    {
        //session check
        $adminSession = $session->get('admin');
        if (!$adminSession) {
            return $this->redirectToRoute('auth_login');
        }
        //end session check

        // Get nav notifications
        $notifications = $doctrine->getRepository(Rate::class)->findBy(
            ['status' => 0],
            ['id' => 'DESC']
        );
        $admin = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);

        return $this->render('backoffice/request/create.html.twig', [
            'controller_name' => 'AdminController',
            'admin' => $admin,
            'notifications' => $notifications,

        ]);
    }

    // Store meal

    #[Route('/request/store', name: 'request_store', methods: ['POST'])]
    public function store(HttpFoundationRequest $request, ManagerRegistry $doctrine, Session $session): RedirectResponse
    {
        // session check
        $adminSession = $session->get('admin');

        if (!$adminSession) {
            return $this->redirectToRoute('auth_login');
        }
        $entityManager = $doctrine->getManager();
        $name = $request->request->get('name');
        $type = $request->request->get('type');
        $description = $request->request->get('description');
        $price = (float) $request->request->get('price');
        $image = $request->files->get('img');
        $imagePath = null;

        // Check if any required field is empty
        if (empty($image) || empty($name) || empty($type) || empty($description) || empty($price)) {
            $this->addFlash('error', 'Please fill in all required fields.');
            return $this->redirectToRoute('request_create');
        }


        // Validate and upload image
        $allowedExtensions = ['png', 'jpeg', 'jpg'];
        $extension = $image->guessExtension();
        if (!in_array($extension, $allowedExtensions)) {
            $this->addFlash('error', 'Invalid image format. Only PNG, JPEG, and JPG are allowed.');
            return $this->redirectToRoute('request_create');
        }

        $imageName = uniqid() . '.' . $extension;
        $image->move('uploads/images/', $imageName);
        $imagePath = 'uploads/images/' . $imageName;

        // Create Meal entity
        $meal = new Request();
        $meal->setName($name);
        $meal->setDescription($description);
        $meal->setType($type);
        $meal->setImage($imagePath);
        $meal->setPrice($price);

        // Check if discount checkbox is set
        if ($request->request->get('setdiscount')) {
            $startDate = $request->request->get('startdate');
            $finalDate = $request->request->get('finaledate');
            $priceForDiscount = (float) $request->request->get('priceForDiscount');
            // Validate data
            if (!$startDate || !$finalDate || !$priceForDiscount) {
                $this->addFlash('error', 'Discount fields must be all filled.');
                return $this->redirectToRoute('request_create');
            }
            if ($priceForDiscount > $price) {
                $this->addFlash('error', "Discount price must be lower than the meal's price.");
                return $this->redirectToRoute('request_create');
            }

            /*  if ($startDate < $today) {
                $this->addFlash('error', "The discount's start date cannot be in the past.");
                return $this->redirectToRoute('meal_create');
            }*/

            if ($finalDate < $startDate) {
                $this->addFlash('error', "The discount's end date cannot be earlier than the start date.");
                return $this->redirectToRoute('request_create');
            }

            $discount = new Discount();
            $discount->setDiscountPrice($priceForDiscount);
            $discount->setStartDate(new \DateTime($startDate));
            $discount->setFinalDate(new \DateTime($finalDate));

            // Persist Discount entity first
            $entityManager->persist($discount);
            $entityManager->flush(); // Save discount to get the ID

            $meal->setDiscount($discount);
        }

        //------- log ------//
        $log = new Log();
        $user = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);
        $log->setDate(new \DateTime());
        $log->setText(" created a new request ");
        $log->setUser($user);
        $log->setSection('Request');
        $entityManager->persist($log);
        $entityManager->persist($meal);
        $entityManager->flush();
        $this->addFlash('success', 'request created successfully!');
        return $this->redirectToRoute('request_index');
    }

    // Accept request

    #[Route('/request/accept/{id}', name: 'request_accept')]
    public function accept($id, HttpFoundationRequest $request, ManagerRegistry $doctrine, Session $session): RedirectResponse
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
            if ($role->getName() === 'ACCEPTREQUEST') {
                $hasPermission = true;
                break;
            }
        }

        if (!$hasPermission) {
            $session->getFlashBag()->add('danger', 'You do not have permission to accept requests. Please Check your profile');
            return $this->redirectToRoute('dashboard');
        }

        $entityManager = $doctrine->getManager();
        $request = $doctrine->getRepository(Request::class)->find($id);
        if (!$request) {
            $this->addFlash('error', 'request not found.');
            return $this->redirectToRoute('request_index');
        }
        $meal = new Meal();
        $meal->setName($request->getName());
        $meal->setDescription($request->getDescription());
        $meal->setType($request->getType());
        $meal->setImage($request->getImage());
        $meal->setPrice($request->getPrice());
        $meal->setDiscount($request->getDiscount());
        $meal->setStatus(0);
        //------- log ------//
        $log = new Log();
        $log->setDate(new \DateTime());
        $log->setText(" accepted a request ");
        $log->setUser($admin);
        $log->setSection('Request');

        $entityManager->persist($log);
        $entityManager->persist($meal);
        $entityManager->remove($request);
        $entityManager->flush();
        $this->addFlash('success', 'Request accepted successully and is now a meal');
        return $this->redirectToRoute('request_index');
    }

    //Delete request
    #[Route('/request/delete/{id}', name: 'request_delete')]
    public function delete(int $id, ManagerRegistry $doctrine, Session $session): RedirectResponse
    { // session check
        $adminSession = $session->get('admin');
        if (!$adminSession) {
            return $this->redirectToRoute('auth_login');
        } //end session check

        $admin = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);

        // Check if the user has the required role
        $hasPermission = false;
        foreach ($admin->getProfile()->getRoles() as $role) {
            if ($role->getName() === 'REFUSEREQUEST') {
                $hasPermission = true;
                break;
            }
        }

        if (!$hasPermission) {
            $session->getFlashBag()->add('danger', 'You do not have permission to refuse requests. Please Check your profile');
            return $this->redirectToRoute('dashboard');
        }
        $entityManager = $doctrine->getManager();
        $request = $entityManager->getRepository(Request::class)->find($id);

        if (!$request) {
            $this->addFlash('error', 'Request not found.');
            return $this->redirectToRoute('request_index');
        }

        $entityManager->remove($request);
        //------- log ------//
        $log = new Log();
        $user = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);
        $log->setDate(new \DateTime());
        $log->setText(" deleted a request ");
        $log->setUser($user);
        $log->setSection('Request');
        $entityManager->persist($log);
        $entityManager->flush();

        $this->addFlash('success', 'Request refused and deleted');
        return $this->redirectToRoute('request_index');
    }

    // delete multiple request
    #[Route('/requestDeleteMultiple', name: 'requestDeleteMultiple')]
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
            if ($role->getName() === 'REFUSEREQUEST') {
                $hasPermission = true;
                break;
            }
        }

        if (!$hasPermission) {
            $session->getFlashBag()->add('danger', 'You do not have permission to delete Refuse. Please check your profile');
            return $this->redirectToRoute('dashboard');
        }

        $requestId = $request->request->all('requestsToDelete', []);
        $entityManager = $doctrine->getManager();
        $request = $entityManager->getRepository(Request::class)->findBy(['id' => $requestId]);

        // If no request were found, show error
        if (empty($request)) {
            $this->addFlash('error', 'Request(s) not found. Please try again.');
            return $this->redirectToRoute('request_index');
        }

        foreach ($request as $request) {
            $entityManager->remove($request);
        }

        //------- log ------//
        $log = new Log();
        $user = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);
        $log->setDate(new \DateTime());
        $log->setText(" deleted a request ");
        $log->setUser($user);
        $log->setSection('Request');
        $entityManager->persist($log);
        $entityManager->flush();
        $this->addFlash('success', 'Request(s) reused and deleted successfully.');
        return $this->redirectToRoute('request_index');
    }
}
