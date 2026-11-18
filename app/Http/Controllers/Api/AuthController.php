<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);

        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Email or password is incorrect.']);
        }

        return response()->json([
            'token' => $user->createToken('dispatch-app')->plainTextToken,
            'user' => $user->load('ambulance')->toApi(),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('ambulance');

        return response()->json([
            'user' => $user->toApi(),
            'unread_notifications' => $user->unreadNotifications()->count(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Signed out.']);
    }
}
