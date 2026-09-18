<?php

namespace Tests\Feature;

use App\Http\Middleware\RequirePremium;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RequirePremiumMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_allows_premium_user_without_restrictions()
    {
        $user = User::factory()->create(['is_premium' => true]);
        $request = Request::create('/plan', 'GET', ['start_date' => Carbon::now()->addWeeks(2)->format('Y-m-d')]);
        $request->setUserResolver(fn () => $user);

        $middleware = new RequirePremium;
        $response = $middleware->handle($request, fn () => response('OK'), 'extended_planning');

        $this->assertEquals('OK', $response->getContent());
    }

    public function test_allows_non_premium_user_for_current_week_extended_planning()
    {
        $user = User::factory()->create(['is_premium' => false]);
        $request = Request::create('/plan', 'GET', ['start_date' => Carbon::today()->format('Y-m-d')]);
        $request->setUserResolver(fn () => $user);

        $middleware = new RequirePremium;
        $response = $middleware->handle($request, fn () => response('OK'), 'extended_planning');

        $this->assertEquals('OK', $response->getContent());
    }

    public function test_blocks_non_premium_user_for_future_week_extended_planning()
    {
        $user = User::factory()->create(['is_premium' => false]);
        $request = Request::create('/plan', 'GET', ['start_date' => Carbon::now()->addWeek()->format('Y-m-d')]);
        $request->setUserResolver(fn () => $user);

        $middleware = new RequirePremium;
        $response = $middleware->handle($request, fn () => response('OK'), 'extended_planning');

        $this->assertEquals(403, $response->getStatusCode());

        $responseData = json_decode($response->getContent(), true);
        $this->assertTrue($responseData['requires_premium']);
    }

    public function test_blocks_non_premium_user_for_strict_premium_route()
    {
        $user = User::factory()->create(['is_premium' => false]);
        $request = Request::create('/premium-only', 'GET');
        $request->setUserResolver(fn () => $user);

        // No feature specified, strictly requires premium
        $middleware = new RequirePremium;
        $response = $middleware->handle($request, fn () => response('OK'));

        $this->assertEquals(403, $response->getStatusCode());

        $responseData = json_decode($response->getContent(), true);
        $this->assertTrue($responseData['requires_premium']);
    }
}
