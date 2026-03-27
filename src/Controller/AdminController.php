<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\StickerPackRepository;
use App\Repository\StickerRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
class AdminController extends AbstractController
{
    #[Route('/login', name: 'admin_login', methods: ['GET', 'POST'])]
    public function login(Request $request, UserRepository $userRepository): Response
    {
        if ($this->getAdminUser($request, $userRepository)) {
            return $this->redirectToRoute('admin_dashboard');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            $username = trim($request->request->get('username', ''));
            $password = $request->request->get('password', '');

            $user = $userRepository->findOneBy(['username' => $username]);

            if ($user && $user->isAdmin() && null !== $user->getPassword() && password_verify($password, $user->getPassword())) {
                $request->getSession()->set('admin_user_id', $user->getId());

                return $this->redirectToRoute('admin_dashboard');
            }

            $error = 'Invalid username or password.';
        }

        return $this->render('admin/login.html.twig', ['error' => $error]);
    }

    #[Route('/logout', name: 'admin_logout')]
    public function logout(Request $request): Response
    {
        $request->getSession()->remove('admin_user_id');

        return $this->redirectToRoute('landing');
    }

    #[Route('', name: 'admin_dashboard')]
    public function dashboard(
        Request $request,
        UserRepository $userRepository,
        StickerRepository $stickerRepository,
        StickerPackRepository $packRepository,
    ): Response {
        $admin = $this->getAdminUser($request, $userRepository);
        if (!$admin) {
            return $this->redirectToRoute('admin_login');
        }

        $totalUsers = count($userRepository->findAll());
        $totalPacks = count($packRepository->findAll());
        $totalStickers = count($stickerRepository->findAll());
        $today = new \DateTime('today');
        $stickersToday = $stickerRepository->countSince($today);

        return $this->render('admin/dashboard.html.twig', [
            'admin' => $admin,
            'totalUsers' => $totalUsers,
            'totalPacks' => $totalPacks,
            'totalStickers' => $totalStickers,
            'stickersToday' => $stickersToday,
        ]);
    }

    #[Route('/password', name: 'admin_password', methods: ['GET', 'POST'])]
    public function changePassword(Request $request, UserRepository $userRepository, EntityManagerInterface $entityManager): Response
    {
        $admin = $this->getAdminUser($request, $userRepository);
        if (!$admin) {
            return $this->redirectToRoute('admin_login');
        }

        $saved = false;
        $error = null;

        if ($request->isMethod('POST')) {
            $newPassword = $request->request->get('new_password', '');
            $confirmPassword = $request->request->get('confirm_password', '');

            if (strlen($newPassword) < 8) {
                $error = 'Password must be at least 8 characters.';
            } elseif ($newPassword !== $confirmPassword) {
                $error = 'Passwords do not match.';
            } else {
                $admin->setPassword(password_hash($newPassword, PASSWORD_BCRYPT));
                $entityManager->flush();
                $saved = true;
            }
        }

        return $this->render('admin/password.html.twig', [
            'admin' => $admin,
            'saved' => $saved,
            'error' => $error,
        ]);
    }

    #[Route('/users', name: 'admin_users')]
    public function users(Request $request, UserRepository $userRepository): Response
    {
        $admin = $this->getAdminUser($request, $userRepository);
        if (!$admin) {
            return $this->redirectToRoute('admin_login');
        }

        $users = $userRepository->findAll();

        return $this->render('admin/users.html.twig', ['admin' => $admin, 'users' => $users]);
    }

    #[Route('/users/{id}', name: 'admin_user_edit', methods: ['GET', 'POST'])]
    public function editUser(int $id, Request $request, UserRepository $userRepository, EntityManagerInterface $entityManager): Response
    {
        $admin = $this->getAdminUser($request, $userRepository);
        if (!$admin) {
            return $this->redirectToRoute('admin_login');
        }

        $user = $userRepository->find($id);
        if (!$user) {
            throw $this->createNotFoundException();
        }

        $saved = false;

        if ($request->isMethod('POST')) {
            $action = $request->request->get('action');

            if ('toggle_ban' === $action) {
                $user->setBanned(!$user->isBanned());
            } elseif ('toggle_admin' === $action) {
                if ($user->getId() !== $admin->getId()) {
                    $user->setIsAdmin(!$user->isAdmin());
                    if ($user->isAdmin() && null === $user->getPassword()) {
                        // Set a temporary password the new admin must change
                        $tempPassword = bin2hex(random_bytes(4));
                        $user->setPassword(password_hash($tempPassword, PASSWORD_BCRYPT));
                        $request->getSession()->set('temp_password_'.$user->getId(), $tempPassword);
                    }
                }
            } elseif ('reset_password' === $action) {
                $tempPassword = bin2hex(random_bytes(4));
                $user->setPassword(password_hash($tempPassword, PASSWORD_BCRYPT));
                $request->getSession()->set('temp_password_'.$user->getId(), $tempPassword);
            } else {
                $dailyLimit = (int) $request->request->get('daily_limit', 5);
                $user->setDailyLimit($dailyLimit);
            }

            $entityManager->flush();
            $saved = true;
        }

        $tempPassword = $request->getSession()->get('temp_password_'.$user->getId());
        $request->getSession()->remove('temp_password_'.$user->getId());

        return $this->render('admin/user_edit.html.twig', [
            'admin' => $admin,
            'user' => $user,
            'saved' => $saved,
            'tempPassword' => $tempPassword,
        ]);
    }

    #[Route('/stickers', name: 'admin_stickers')]
    public function stickers(Request $request, UserRepository $userRepository, StickerRepository $stickerRepository): Response
    {
        $admin = $this->getAdminUser($request, $userRepository);
        if (!$admin) {
            return $this->redirectToRoute('admin_login');
        }

        $stickers = $stickerRepository->findBy([], ['createdAt' => 'DESC'], 100);

        return $this->render('admin/stickers.html.twig', ['admin' => $admin, 'stickers' => $stickers]);
    }

    private function getAdminUser(Request $request, UserRepository $userRepository): ?User
    {
        $userId = $request->getSession()->get('admin_user_id');
        if (null === $userId) {
            return null;
        }

        $user = $userRepository->find($userId);
        if (null === $user || !$user->isAdmin()) {
            $request->getSession()->remove('admin_user_id');

            return null;
        }

        return $user;
    }
}
