<?php

namespace App\Http\Controllers;

use App\Domains\Foundation\Models\User;
use App\Domains\Foundation\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a user's photo from the private disk. The route requires a signed-in
 * user; the viewer must be active and allowed by UserPolicy::viewFoto (their
 * own photo, or a user they may view). Only a path inside the photo folder is
 * ever served.
 */
class FotoPenggunaController extends Controller
{
    public function __invoke(Request $request, User $user, UserService $userService): StreamedResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();

        abort_unless($viewer->status_aktif, 403);
        Gate::forUser($viewer)->authorize('viewFoto', $user);

        $path = $userService->pathFotoUntukDisajikan($user);

        abort_if($path === null, 404);

        return Storage::disk(UserService::FOTO_DISK)->response($path, null, [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
