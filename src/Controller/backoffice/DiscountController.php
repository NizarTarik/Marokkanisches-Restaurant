<?php

namespace App\Controller\backoffice;

use App\Entity\Admin;
use App\Entity\Discount;
use App\Entity\Log;
use App\Entity\Meal;
use App\Entity\Rate;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Annotation\Route;

final class DiscountController extends AbstractController
{ // Discount index
	#[Route('/discount', name: 'discount_index')]
	public function index(ManagerRegistry $doctrine, Session $session): Response
	{
		$adminSession = $session->get('admin');
		if (!$adminSession) {
			return $this->redirectToRoute('auth_login');
		}

		$mealDiscounts = $doctrine->getRepository(Meal::class)->createQueryBuilder('m')
			->where('m.discount IS NOT NULL')
			->getQuery()
			->getResult();


		// Get the admin from the session
		$admin = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);

		// Get nav notifications
		$notifications = $doctrine->getRepository(Rate::class)->findBy(
			['status' => 0],
			['id' => 'DESC']
		);


		foreach ($mealDiscounts as $meal) {
			$discount = $meal->getDiscount();

			if ($discount && $discount->getFinaldate()) {
				$remainingDays = $discount->getFinaldate()->diff(new \DateTime())->days;
				$meal->remainingDays = $remainingDays;
			}
		}

		return $this->render('backoffice/discount/index.html.twig', [
			'admin' => $admin,
			'mealDiscounts' => $mealDiscounts,
			'notifications' => $notifications,

		]);
	}


	// discount delete
	#[Route('/discount/delete/{id}', name: 'discount_delete', methods: ['POST'])]
	public function delete(int $id, ManagerRegistry $doctrine, Session $session): RedirectResponse
	{
		// Session check
		$adminSession = $session->get('admin');
		if (!$adminSession) {
			return $this->redirectToRoute('auth_login');
		}

		$user = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);

		// Check if the user has the required role
		$hasPermission = false;
		foreach ($user->getProfile()->getRoles() as $role) {
			if ($role->getName() === 'DELETEDISCOUNT') {
				$hasPermission = true;
				break;
			}
		}

		if (!$hasPermission) {
			$session->getFlashBag()->add('danger', 'You do not have permission to remove discounts. Please Check your profile');
			return $this->redirectToRoute('dashboard');
		}

		$entityManager = $doctrine->getManager();
		$meal = $entityManager->getRepository(Meal::class)->find($id);
		$discount = $entityManager->getRepository(Discount::class)->find($meal->getDiscount()->getId());

		if (!$discount) {
			$this->addFlash('error', 'Discount not found.');
			return $this->redirectToRoute('discount_index');
		}


		if ($meal) {
			$meal->setdiscount(null);
			$entityManager->persist($meal);
		}

		$entityManager->remove($discount);
		//------- log ------//
		$log = new Log();
		$log->setDate(new \DateTime());
		$log->setText(" removed a discount ");
		$log->setUser($user);
		$log->setSection('Discount');
		$entityManager->persist($log);
		$entityManager->flush();

		$this->addFlash('success', 'Discount removed successfully.');
		return $this->redirectToRoute('discount_index');
	}



	// Discount edit
	#[Route('/discountedit{id}', name: 'discount_edit')]
	public function edit($id, ManagerRegistry $doctrine, Session $session): Response
	{
		// Session check
		$adminSession = $session->get('admin');
		if (!$adminSession) {
			return $this->redirectToRoute('auth_login');
		}
		$admin = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);

		// Check if the user has the required role
		$hasPermission = false;
		foreach ($admin->getProfile()->getRoles() as $role) {
			if ($role->getName() === 'UPDATEDISCOUNT') {
				$hasPermission = true;
				break;
			}
		}

		if (!$hasPermission) {
			$session->getFlashBag()->add('danger', 'You do not have permission to edit discounts. Please Check your profile');
			return $this->redirectToRoute('dashboard');
		}
		$notifications = $doctrine->getRepository(Rate::class)->findBy(
			['status' => 0],
			['id' => 'DESC']
		);

		$entityManager = $doctrine->getManager();
		$meal = $entityManager->getRepository(Meal::class)->find($id);

		if (!$meal) {
			$this->addFlash('error', 'meal not found.');
			return $this->redirectToRoute('meal_index');
		}


		return $this->render('backoffice/discount/edit.html.twig', [
			'meal' => $meal,
			'admin' => $admin,
			'notifications' => $notifications,

		]);
	}


	// update discount

	#[Route('/discountupdate{id}', name: 'discount_update', methods: ['POST'])]
	public function setDiscount(Request $request, $id, EntityManagerInterface $em, Session $session, ManagerRegistry $doctrine): Response
	{
		// Session check
		$adminSession = $session->get('admin');
		if (!$adminSession) {
			return $this->redirectToRoute('auth_login');
		}

		$discount = $doctrine->getRepository(Discount::class)->find($id);
		$meal = $doctrine->getRepository(Meal::class)->findOneBy([
			'discount' => $discount
		]);

		if (!$discount) {
			$this->addFlash('error', 'Discount not found.');
			return $this->redirectToRoute('discount_index');
		}
		// Get posted data
		$discountPrice = (float) $request->request->get('price');
		$startDate = $request->request->get('startdate');
		$finalDate = $request->request->get('finaldate');

		// Convert to DateTime objects
		$startDateObj = new \DateTime($startDate);
		$finalDateObj = new \DateTime($finalDate);
		$today = new \DateTime();

		// Validate data
		if ($discountPrice > $meal->getPrice()) {
			$this->addFlash('error', "You can't set the discount price higher than the meal's price. Please modify the meal's price first.");
			return $this->redirectToRoute('discount_edit', ['id' => $discount->getId()]);
		}

		if ($startDateObj > $today) {
			$this->addFlash('error', "The discount's start date cannot be in the past.");
			return $this->redirectToRoute('discount_edit', ['id' => $discount->getId()]);
		}

		if ($finalDateObj < $startDateObj) {
			$this->addFlash('error', "The discount's end date cannot be earlier than the start date.");
			return $this->redirectToRoute('discount_edit', ['id' => $discount->getId()]);
		}

		// Calculate remaining days
		$interval = $startDateObj->diff($finalDateObj);
		$remainingDays = $interval->days;

		// Set data
		$discount->setDiscountprice($discountPrice);
		$discount->setStartdate($startDateObj);
		$discount->setFinaldate($finalDateObj);

		// Persist
		$em->persist($discount);
		//------- log ------//
		$log = new Log();
		$user = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);
		$log->setDate(new \DateTime());
		$log->setText(" updated a discount ");
		$log->setUser($user);
		$log->setSection('Discount');
		$em->persist($log);
		$em->flush();

		$this->addFlash('success', 'Discount updated successfully');
		return $this->redirectToRoute('discount_index');
	}

	// Discount create
	#[Route('/discountcreate{id}', name: 'discount_create')]
	public function create($id, ManagerRegistry $doctrine, Session $session): Response
	{
		// Session check
		$adminSession = $session->get('admin');
		if (!$adminSession) {
			return $this->redirectToRoute('auth_login');
		}

		$admin = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);

		// Check if the user has the required role
		$hasPermission = false;
		foreach ($admin->getProfile()->getRoles() as $role) {
			if ($role->getName() === 'ADDDISCOUNT') {
				$hasPermission = true;
				break;
			}
		}

		if (!$hasPermission) {
			$session->getFlashBag()->add('danger', 'You do not have permission to set new discounts. Please Check your profile');
			return $this->redirectToRoute('dashboard');
		}
		$notifications = $doctrine->getRepository(Rate::class)->findBy(
			['status' => 0],
			['id' => 'DESC']
		);
		$entityManager = $doctrine->getManager();
		$meal = $entityManager->getRepository(Meal::class)->find($id);
		$admin = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);

		return $this->render('backoffice/discount/create.html.twig', [
			'admin' => $admin,
			'meal' => $meal,
			'notifications' => $notifications,


		]);
	}

	// discount store
	#[Route('/setDiscount{id}', name: 'discount_store', methods: ['POST'])]
	public function store(Request $request, $id, EntityManagerInterface $em, ManagerRegistry $doctrine, Session $session): Response
	{	// Session check
		$adminSession = $session->get('admin');
		if (!$adminSession) {
			return $this->redirectToRoute('auth_login');
		}
		$meal = $doctrine->getRepository(Meal::class)->find($id);
		if (!$meal) {
			$this->addFlash('error', 'Meal not found.');
			return $this->redirectToRoute('meal_index');
		}
		$newPrice = (float) $request->request->get('price');
		$startDate = new \DateTime($request->request->get('startdate'));
		$endDate = new \DateTime($request->request->get('finaledate'));
		if ($newPrice > $meal->getPrice()) {
			$this->addFlash('error', "Discount price cannot exceed the original price.");
			return $this->redirectToRoute('discount_create', ['id' => $meal->getId()]);
		}

		$discount = new Discount();
		$meal->setDiscount($discount);
		$discount->setDiscountprice($newPrice);
		$discount->setStartdate($startDate);
		$discount->setFinaldate($endDate);
		$em->persist($discount);

		//------- log ------//
		$log = new Log();
		$user = $doctrine->getRepository(Admin::class)->findOneBy(['email' => $adminSession['email']]);
		$log->setDate(new \DateTime());
		$log->setText("  setted a new discount ");
		$log->setUser($user);
		$log->setSection('Discount');

		$em->persist($log);
		$em->flush();
		$this->addFlash('success', 'Discount set successfully!');
		return $this->redirectToRoute('meal_index');
	}
}
