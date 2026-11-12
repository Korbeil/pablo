<?php

declare(strict_types=1);

namespace Pablo\Controller;

use Pablo\Dashboard\Dashboard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The single-page dashboard.
 *
 * Rendering it never changes anything: task state and worktrees are only
 * touched by the confirmed actions of the live modals (New task, Force
 * state, Skip CI, Close), and the issue tracker never is. The page itself renders from the
 * poller-written display cache, so no gh/git/orca subprocess runs on page
 * load — see Pablo\Dashboard\Dashboard.
 */
final class DashboardController extends AbstractController
{
    public function __construct(private readonly Dashboard $dashboard)
    {
    }

    #[Route('/', name: 'dashboard', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('dashboard/index.html.twig', [
            'project_errors' => $this->dashboard->projectErrors(),
        ]);
    }
}
