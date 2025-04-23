<?php

namespace App\Controller\backoffice;

use App\Entity\Admin;
use App\Entity\Discount;
use App\Entity\Log;
use App\Entity\Meal;
use App\Entity\Rate;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Attribute\Route;

class MealController extends AbstractController
{
    // Controller
    #[Route('/mealindex', name: 'meal_index')]
    public function index(ManagerRegistry $doctrine, Session $session): Response
    {
        $adminSession = $session->get('admin');

        if (!$adminSession) {
            return $this->redirectToRoute('auth_login');
        }


        $admin = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);
        // Check if the user has the required role
        $allowedRoles = ['ADDMEAL', 'DELETEMEAL', 'VALIDATEMEAL', 'UPDATEMEAL'];
        $hasPermission = false;
        foreach ($admin->getProfile()->getRoles() as $role) {
            if (in_array($role->getName(), $allowedRoles)) {
                $hasPermission = true;
                break;
            }
        }


        if (!$hasPermission) {
            $session->getFlashBag()->add('danger', 'You do not have permission to view meals.Please check your profile');
            return $this->redirectToRoute('dashboard');
        }

        // Get nav notifications
        $notifications = $doctrine->getRepository(Rate::class)->findBy(
            ['status' => 0],
            ['id' => 'DESC']
        );


        $discounts = $doctrine->getRepository(Discount::class)->findAll();
        $meals = $doctrine->getRepository(Meal::class)->findAll();

        // Calculate the remaining days for each discount
        foreach ($discounts as $discount) {
            if ($discount->getFinaldate()) {
                $remainingDays = $discount->getFinaldate()->diff(new \DateTime())->days;
                $discount->remainingDays = $remainingDays;
            }
        }

        return $this->render('backoffice/meal/index.html.twig', [
            'meals' => $meals,
            'admin' => $admin,
            'discounts' => $discounts,
            'notifications' => $notifications,

        ]);
    }




    // Create meal
    #[Route('/createmeal', name: 'meal_create')]
    public function create(ManagerRegistry $doctrine, Session $session): Response
    {
        //session check
        $adminSession = $session->get('admin');
        if (!$adminSession) {
            return $this->redirectToRoute('auth_login');
        }
        //end session check
        $admin = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);

        // Check if the user has the required role
        $hasPermission = false;
        foreach ($admin->getProfile()->getRoles() as $role) {
            if ($role->getName() === 'ADDMEAL') {
                $hasPermission = true;
                break;
            }
        }


        if (!$hasPermission) {
            $session->getFlashBag()->add('danger', 'You do not have permission to add new meals. Please check your profile');
            return $this->redirectToRoute('dashboard');
        }

        // Get nav notifications
        $notifications = $doctrine->getRepository(Rate::class)->findBy(
            ['status' => 0],
            ['id' => 'DESC']
        );

        return $this->render('backoffice/meal/create.html.twig', [
            'controller_name' => 'AdminController',
            'admin' => $admin,
            'notifications' => $notifications,

        ]);
    }



    // Store meal
    #[Route('/meal/store', name: 'meal_store', methods: ['POST'])]
    public function store(Request $request, ManagerRegistry $doctrine, Session $session): RedirectResponse
    {
        // session check
        $adminSession = $session->get('admin');

        if (!$adminSession) {
            return $this->redirectToRoute('auth_login');
        }
        $today = new \DateTime();
        $entityManager = $doctrine->getManager();
        $name = $request->request->get('name');
        $type = $request->request->get('type');
        $description = $request->request->get('description');
        $price = (float) $request->request->get('price'); // Ensure numeric value
        $image = $request->files->get('img');
        $imagePath = null;

        // Check if any required field is empty
        if (empty($image) || empty($name) || empty($type) || empty($description) || empty($price)) {
            $this->addFlash('error', 'Please fill in all required fields.');
            return $this->redirectToRoute('meal_create');
        }


        // Validate and upload image
        $allowedExtensions = ['png', 'jpeg', 'jpg'];
        $extension = $image->guessExtension();
        if (!in_array($extension, $allowedExtensions)) {
            $this->addFlash('error', 'Invalid image format. Only PNG, JPEG, and JPG are allowed.');
            return $this->redirectToRoute('meal_create');
        }

        $imageName = uniqid() . '.' . $extension;
        $image->move('uploads/images/', $imageName);
        $imagePath = 'uploads/images/' . $imageName;

        // Create Meal entity
        $meal = new Meal();
        $meal->setName($name);
        $meal->setDescription($description);
        $meal->setType($type);
        $meal->setImage($imagePath);
        $meal->setStatus(0);
        $meal->setPrice($price);

        // Check if discount checkbox is set
        if ($request->request->get('setdiscount')) {
            $startDate = $request->request->get('startdate');
            $finalDate = $request->request->get('finaledate');
            $priceForDiscount = (float) $request->request->get('priceForDiscount');
            // Validate data
            if (!$startDate || !$finalDate || !$priceForDiscount) {
                $this->addFlash('error', 'Discount fields must be all filled.');
                return $this->redirectToRoute('meal_create');
            }
            if ($priceForDiscount > $price) {
                $this->addFlash('error', "Discount price must be lower than the meal's price.");
                return $this->redirectToRoute('meal_create');
            }

            /*  if ($startDate < $today) {
                $this->addFlash('error', "The discount's start date cannot be in the past.");
                return $this->redirectToRoute('meal_create');
            }*/

            if ($finalDate < $startDate) {
                $this->addFlash('error', "The discount's end date cannot be earlier than the start date.");
                return $this->redirectToRoute('meal_create');
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
        $log->setText(" created a new meal ");
        $log->setUser($user);
        $log->setSection('Meal');
        $entityManager->persist($log);
        $entityManager->persist($meal);
        $entityManager->flush();
        $this->addFlash('success', 'Meal created successfully!');
        return $this->redirectToRoute('meal_index');
    }

    //Delete meal
    #[Route('/meal/delete/{id}', name: 'meal_delete', methods: ['POST'])]
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
            if ($role->getName() == 'DELETEMEAL') {
                $hasPermission = true;
                break;
            }
        }


        if (!$hasPermission) {
            $session->getFlashBag()->add('danger', 'You do not have permission to delete meals. Please check your profile');
            return $this->redirectToRoute('dashboard');
        }
        $entityManager = $doctrine->getManager();
        $meal = $entityManager->getRepository(Meal::class)->find($id);

        if (!$meal) {
            $this->addFlash('error', 'Meal not found.');
            return $this->redirectToRoute('meal_index');
        }

        $entityManager->remove($meal);
        //------- log ------//
        $log = new Log();
        $user = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);
        $log->setDate(new \DateTime());
        $log->setText(" deleted a meal ");
        $log->setUser($user);
        $log->setSection('Meal');
        $entityManager->persist($log);
        $entityManager->flush();
        $this->addFlash('success', 'Meal deleted successfully.');
        return $this->redirectToRoute('meal_index');
    }


    #[Route('/mealDeleteMultiple', name: 'mealDeleteMultiple')]
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
            if ($role->getName() === 'DELETEMEAL') {
                $hasPermission = true;
                break;
            }
        }

        if (!$hasPermission) {
            $session->getFlashBag()->add('danger', 'You do not have permission to delete Meals. Please check your profile');
            return $this->redirectToRoute('dashboard');
        }

        $mealsId = $request->request->all('mealsToDelete', []);
        $entityManager = $doctrine->getManager();
        $meals = $entityManager->getRepository(Meal::class)->findBy(['id' => $mealsId]);

        // If no meals were found, show error
        if (empty($meals)) {
            $this->addFlash('error', 'Meal(s) not found. Please try again.');
            return $this->redirectToRoute('meal_index');
        }

        foreach ($meals as $meal) {
            $entityManager->remove($meal);
        }

        //------- log ------//
        $log = new Log();
        $user = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);
        $log->setDate(new \DateTime());
        $log->setText(" deleted a meal ");
        $log->setUser($user);
        $log->setSection('Meal');
        $entityManager->persist($log);
        $entityManager->flush();
        $this->addFlash('success', 'Meal(s) deleted successfully.');
        return $this->redirectToRoute('meal_index');
    }
    // edit meal
    #[Route('/mealedit{id}', name: 'meal_edit')]
    public function edit($id, ManagerRegistry $doctrine, Session $session): Response
    {
        // session check
        $adminSession = $session->get('admin');
        if (!$adminSession) {
            return $this->redirectToRoute('auth_login');
        }
        //end session check
        $admin = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);
        // Check if the user has the required role
        $hasPermission = false;
        foreach ($admin->getProfile()->getRoles() as $role) {
            if ($role->getName() === 'UPDATEMEAL') {
                $hasPermission = true;
                break;
            }
        }


        if (!$hasPermission) {
            $session->getFlashBag()->add('danger', 'You do not have permission to edit meals. Please check your profile');
            return $this->redirectToRoute('dashboard');
        }

        // Get nav notifications
        $notifications = $doctrine->getRepository(Rate::class)->findBy(
            ['status' => 0],
            ['id' => 'DESC']
        );


        $entityManager = $doctrine->getManager();
        $mealRepository = $entityManager->getRepository(Meal::class);
        $meal = $mealRepository->find($id);
        if (!$meal) {
            $this->addFlash('error', 'Meal not found.');
            return $this->redirectToRoute('meal_index');
        }

        return $this->render('backoffice/meal/edit.html.twig', [
            'meal' => $meal,
            'admin' => $admin,
            'notifications' => $notifications,

        ]);
    }



    // Update Route
    #[Route('/meal/update{id}', name: 'meal_update', methods: ['POST'])]
    public function update($id, Request $request, ManagerRegistry $doctrine, Session $session): RedirectResponse
    {
        // session check
        $adminSession = $session->get('admin');
        if (!$adminSession) {
            return $this->redirectToRoute('auth_login');
        }

        $entityManager = $doctrine->getManager();
        $mealRepository = $entityManager->getRepository(Meal::class);
        $meal = $mealRepository->find($id);

        if (!$meal) {
            $this->addFlash('error', 'Meal not found.');
            return $this->redirectToRoute('meal_index');
        }

        // Get form data
        $name = $request->request->get('name');
        $description = $request->request->get('description');
        $price = $request->request->get('price');
        $type = $request->request->get('type');

        // Check if any required field is empty
        if (empty($name) || empty($description) || empty($price) || empty($type)) {
            $this->addFlash('error', 'Please fill in all required fields.');
            return $this->redirectToRoute('meal_edit', ['id' => $id]);
        }

        // Process image upload (if any)
        $currentImagePath = $meal->getImage();
        $image = $request->files->get('img');
        if ($image) {
            $allowedExtensions = ['png', 'jpeg', 'jpg'];
            $extension = $image->guessExtension();
            if (!in_array($extension, $allowedExtensions)) {
                $this->addFlash('error', 'Invalid image format. Only PNG, JPEG, and JPG are allowed.');
                return $this->redirectToRoute('meal_edit', ['id' => $id]);
            }

            $imageName = uniqid() . '.' . $extension;
            $image->move('uploads/images/meals/', $imageName);
            $meal->setImage('uploads/images/meals/' . $imageName);
        } else {
            $meal->setImage($currentImagePath);
        }

        $meal->setName($name);
        $meal->setDescription($description);
        $meal->setType($type);
        $meal->setPrice($price);
        $discount = $meal->getDiscount();
        // Check if discount checkbox is set
        if ($request->request->get('setdiscount')) {

            $startDate = $request->request->get('startdate');
            $finalDate = $request->request->get('finaledate');
            $priceForDiscount = (float) $request->request->get('priceForDiscount');
            // Validate data
            if (!$startDate || !$finalDate || !$priceForDiscount) {
                $this->addFlash('error', 'Discount fields must be all filled.');
                return $this->redirectToRoute('meal_edit', ['id' => $id]);
            }
            if ($priceForDiscount > $price) {
                $this->addFlash('error', "Discount price must be lower than the meal's price.");
                return $this->redirectToRoute('meal_edit', ['id' => $id]);
            }

            /*  if ($startDate < $today) {
                $this->addFlash('error', "The discount's start date cannot be in the past.");
                return $this->redirectToRoute('meal_create');
            }*/

            if ($finalDate < $startDate) {
                $this->addFlash('error', "The discount's end date cannot be earlier than the start date.");
                return $this->redirectToRoute('meal_edit', ['id' => $id]);
            }

            if ($discount) {
                $discount->setDiscountPrice($priceForDiscount);
                $discount->setStartDate(new \DateTime($startDate));
                $discount->setFinalDate(new \DateTime($finalDate));
                $entityManager->persist($discount);
            } else {
                $discount = new Discount();
                $discount->setDiscountPrice($priceForDiscount);
                $discount->setStartDate(new \DateTime($startDate));
                $discount->setFinalDate(new \DateTime($finalDate));
                $entityManager->persist($discount);
                $meal->setDiscount($discount);
            }
        } else {
            if ($discount) {
                $meal->setDiscount(null);
                $entityManager->remove($discount);
                $entityManager->flush();
            }
        }
        //------- log ------//
        $log = new Log();
        $user = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);
        $log->setDate(new \DateTime());
        $log->setText(" updated a  meal ");
        $log->setUser($user);
        $log->setSection('Meal');
        $entityManager->persist($log);
        $entityManager->flush();

        $this->addFlash('success', 'Meal updated successfully.');
        return $this->redirectToRoute('meal_index');
    }

    // Validate 

    #[Route('/meal/validate/{id}', name: 'meal_validate')]
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
            if ($role->getName() === 'VALIDATEMEAL') {
                $hasPermission = true;
                break;
            }
        }

        if (!$hasPermission) {
            $session->getFlashBag()->add('danger', 'You do not have permission to validate meala.Please check your profile');
            return $this->redirectToRoute('dashboard');
        }

        $entityManager = $doctrine->getManager();
        $meal = $entityManager->getRepository(Meal::class)->find($id);

        if (!$meal) {
            $this->addFlash('error', 'Meal not found.');
            return $this->redirectToRoute('meal_index');
        }
        $meal->setStatus(1);
        //------- log ------//
        $log = new Log();
        $user = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);
        $log->setDate(new \DateTime());
        $log->setText(" validated a meal ");
        $log->setUser($user);
        $log->setSection('Meal');
        $entityManager->persist($log);
        $entityManager->flush();

        $this->addFlash('success', 'Meal validated successfully.');
        return $this->redirectToRoute('meal_index');
    }
}
