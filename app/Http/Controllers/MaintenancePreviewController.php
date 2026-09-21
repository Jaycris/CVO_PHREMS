<?php

namespace App\Http\Controllers;

use App\Services\MaintenanceMode;
use Symfony\Component\HttpFoundation\Response;

/**
 * The maintenance page as everyone else would see it, with the current note.
 *
 * Whoever switches maintenance on can never see the page themselves — they are
 * let through — so this is how they check what they are about to show people.
 */
class MaintenancePreviewController extends Controller
{
    public function __invoke(): Response
    {
        return response()->view('maintenance', MaintenanceMode::viewData());
    }
}
