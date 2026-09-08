<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Attribute\Route;

// ComfreePHP comparison benchmark endpoints.
final class BenchController extends AbstractController
{
    #[Route('/bench/page', name: 'bench_page')]
    public function page(): Response
    {
        return $this->render('bench/page.html.twig', [
            'items' => [
                ['rank' => 1, 'title' => 'Item One', 'description' => 'Placeholder description for the first item in the list.', 'meta' => '$120'],
                ['rank' => 2, 'title' => 'Item Two', 'description' => 'Placeholder description for the second item in the list.', 'meta' => '$98'],
                ['rank' => 3, 'title' => 'Item Three', 'description' => 'Placeholder description for the third item in the list.', 'meta' => '$76'],
                ['rank' => 4, 'title' => 'Item Four', 'description' => 'Placeholder description for the fourth item in the list.', 'meta' => '$54'],
            ],
        ]);
    }

    #[Route('/bench/json', name: 'bench_json')]
    public function ping(): JsonResponse
    {
        return $this->json(['pong' => true, 'time' => date('Y-m-d H:i:s')]);
    }

    #[Route('/bench/info', name: 'bench_info')]
    public function info(): JsonResponse
    {
        return $this->json([
            'framework' => 'Symfony ' . Kernel::VERSION,
            'php' => PHP_VERSION,
            'included_files' => count(get_included_files()),
            'peak_memory_mb' => round(memory_get_peak_usage() / 1048576, 1),
        ]);
    }
}
