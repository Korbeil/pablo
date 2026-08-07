<?php

declare(strict_types=1);

namespace Pablo\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The single-page dashboard.
 *
 * Strictly read-only, like every other PABLO read surface: nothing reachable
 * from here mutates task state, a worktree, or an issue tracker. The page
 * itself renders from the poller-written display cache, so no gh/git/orca
 * subprocess runs on page load — see Pablo\Dashboard\Dashboard.
 */
final class DashboardController extends AbstractController
{
    #[Route('/', name: 'dashboard', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('dashboard/index.html.twig');
    }
}
