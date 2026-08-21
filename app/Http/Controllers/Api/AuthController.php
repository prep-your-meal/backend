<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use OpenApi\Attributes as OA;

class AuthController extends Controller
{
    #[OA\Post(
        path: '/auth/login',
        summary: 'Local password login (for testing)',
        tags: ['Auth']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['email', 'password'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email'),
                new OA\Property(property: 'password', type: 'string'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Login successful, returns Sanctum token')]
    #[OA\Response(response: 401, description: 'Invalid credentials')]
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid credentials',
            ], 401);
        }

        // Generate Sanctum Token
        $token = $user->createToken('pwa-token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'token' => $token,
            'user' => new UserResource($user),
        ]);
    }

    #[OA\Post(
        path: '/auth/register',
        summary: 'Register a new user',
        tags: ['Auth']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['name', 'email', 'password', 'password_confirmation'],
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'John Doe'),
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'john@example.com'),
                new OA\Property(property: 'password', type: 'string', format: 'password', example: 'secret123'),
                new OA\Property(property: 'password_confirmation', type: 'string', format: 'password', example: 'secret123'),
            ]
        )
    )]
    #[OA\Response(response: 201, description: 'User registered successfully')]
    #[OA\Response(response: 422, description: 'Validation errors')]
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'User registered successfully.',
            'token' => $token,
            'user' => new UserResource($user),
        ], 201);
    }

    #[OA\Get(
        path: '/user',
        summary: 'Get the authenticated user details',
        security: [['bearerAuth' => []]],
        tags: ['User']
    )]
    #[OA\Response(response: 200, description: 'Authenticated user data')]
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => new UserResource($request->user()),
        ]);
    }

    #[OA\Post(
        path: '/auth/logout',
        summary: 'Logout user and revoke current token',
        security: [['bearerAuth' => []]],
        tags: ['Auth']
    )]
    #[OA\Response(response: 200, description: 'Successfully logged out')]
    public function logout(Request $request): JsonResponse
    {
        // Revokes the token that was used to authenticate the current request
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Successfully logged out.',
        ]);
    }

    #[OA\Delete(
        path: '/user',
        summary: 'Delete user account permanently',
        security: [['bearerAuth' => []]],
        tags: ['User']
    )]
    #[OA\Response(response: 200, description: 'Account permanently deleted')]
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        // Revoke all tokens
        $user->tokens()->delete();

        // Delete the user (cascading will handle meal plans and favorite relationships
        // if constrained()->cascadeOnDelete() is set in migrations)
        $user->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Account permanently deleted.',
        ]);
    }

    #[OA\Get(
        path: '/auth/{provider}/redirect',
        summary: 'Get the OAuth redirect URL for the frontend',
        tags: ['Auth']
    )]
    #[OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['github', 'google']))]
    #[OA\Response(response: 200, description: 'Returns the URL to redirect the user to')]
    public function redirectToProvider($provider)
    {
        if (! in_array($provider, ['github', 'google'])) {
            return response()->json(['status' => 'error', 'message' => 'Provider not supported'], 400);
        }

        /** @var AbstractProvider $driver */
        $driver = Socialite::driver($provider);
        // stateless() is crucial for API/PWA flows!
        $url = $driver->stateless()->redirect()->getTargetUrl();

        return response()->json([
            'status' => 'success',
            'url' => $url,
        ]);
    }

    #[OA\Get(
        path: '/auth/{provider}/callback',
        summary: 'Handle OAuth callback from provider',
        tags: ['Auth']
    )]
    #[OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['github', 'google']))]
    #[OA\Response(response: 302, description: 'Redirects back to PWA with token')]
    public function handleProviderCallback($provider)
    {
        try {
            /** @var AbstractProvider $driver */
            $driver = Socialite::driver($provider);
            $socialUser = $driver->stateless()->user();

            // Find or create the user
            $user = User::firstOrCreate(
                [
                    'provider' => $provider,
                    'provider_id' => $socialUser->getId(),
                ],
                [
                    'name' => $socialUser->getName() ?? $socialUser->getNickname(),
                    'email' => $socialUser->getEmail(),
                    // Leave password null for OAuth users
                ]
            );

            $token = $user->createToken('pwa-token')->plainTextToken;

            // Redirect back to your Vue.js PWA with the token in the URL
            // In production, define FRONTEND_URL in your .env (e.g. https://my-pwa.com)
            $frontendUrl = config('FRONTEND_URL', 'http://localhost:5173');

            return redirect()->to("{$frontendUrl}/auth/callback?token={$token}");

        } catch (\Exception $e) {
            Log::error("OAuth Callback Error ({$provider}): ".$e->getMessage());
            $frontendUrl = config('FRONTEND_URL', 'http://localhost:5173');

            return redirect()->to("{$frontendUrl}/auth/error");
        }
    }

    #[OA\Post(
        path: '/auth/forgot-password',
        summary: 'Send a password reset link',
        tags: ['Auth']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(required: ['email'], properties: [
            new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user@example.com'),
        ])
    )]
    #[OA\Response(response: 200, description: 'Reset link sent')]
    #[OA\Response(response: 400, description: 'User not found')]
    public function sendResetLinkEmail(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $status = Password::sendResetLink($request->only('email'));

        return $status === Password::RESET_LINK_SENT
            ? response()->json(['status' => 'success', 'message' => __($status)])
            : response()->json(['status' => 'error', 'message' => __($status)], 400);
    }

    #[OA\Post(
        path: '/auth/reset-password',
        summary: 'Reset the password using a token',
        tags: ['Auth']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(required: ['email', 'password', 'password_confirmation', 'token'], properties: [
            new OA\Property(property: 'token', type: 'string'),
            new OA\Property(property: 'email', type: 'string', format: 'email'),
            new OA\Property(property: 'password', type: 'string', format: 'password'),
            new OA\Property(property: 'password_confirmation', type: 'string', format: 'password'),
        ])
    )]
    #[OA\Response(response: 200, description: 'Password reset successfully')]
    #[OA\Response(response: 400, description: 'Invalid token or mismatch')]
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
            }
        );

        return $status === Password::PASSWORD_RESET
            ? response()->json(['status' => 'success', 'message' => __($status)])
            : response()->json(['status' => 'error', 'message' => __($status)], 400);
    }
}
