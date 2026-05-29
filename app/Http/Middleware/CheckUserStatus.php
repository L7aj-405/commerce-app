<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckUserStatus
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user === null) {
            return $next($request);
        }

        if ($user->status === UserStatus::Suspended) {
            Auth::logout();
            session()->flash('error', 'Your account has been suspended. Contact support.');
            return redirect(route('login'));
        }

        if ($user->status === UserStatus::Banned) {
            Auth::logout();
            session()->flash('error', 'Your account has been permanently banned.');
            return redirect(route('login'));
        }

        return $next($request);
    }
}
