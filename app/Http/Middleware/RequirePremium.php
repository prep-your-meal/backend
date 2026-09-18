<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class RequirePremium
{
    /**
     * Handle an incoming request and check for premium feature access.
     *
     * @param  Closure(Request): (Response)  $next
     * @param  string|null  $feature  The specific premium feature being requested.
     */
    public function handle(Request $request, Closure $next, ?string $feature = null): Response
    {
        $user = $request->user();

        // Premium users have unrestricted access to all features
        if ($user && $user->isPremium()) {
            return $next($request);
        }

        // Evaluate specific restrictions for non-premium users based on the requested feature
        if ($feature === 'extended_planning') {
            // Extract the date from route parameters or various request payloads
            $targetDate = $request->route('date')
                ?? $request->input('start_date')
                ?? $request->input('week_start');

            // Fallback to today if no explicit date is provided
            $targetDate = $targetDate ? Carbon::parse($targetDate) : Carbon::today();

            if (! $targetDate->isCurrentWeek()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Planning for future or past weeks is only available for premium members.',
                    'requires_premium' => true,
                ], 403);
            }

            // Allow access if the target date remains within the current week
            return $next($request);
        }

        // Example for future features:
        // if ($feature === 'extra_meals') { ... }

        // Generic fallback for strict premium routes without a specific feature parameter
        return response()->json([
            'status' => 'error',
            'message' => 'This feature requires a premium membership.',
            'requires_premium' => true,
        ], 403);
    }
}
