<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(User::with('ambulance')->orderBy('role')->orderBy('name')->get()->map->toApi()->values());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_DISPATCHER, User::ROLE_CREW])],
            'phone' => 'nullable|string|max:40',
            'ambulance_id' => 'nullable|integer|exists:ambulances,id|required_if:role,crew',
        ]);
        if ($data['role'] !== User::ROLE_CREW) {
            $data['ambulance_id'] = null;
        }

        return response()->json(User::create($data)->load('ambulance')->toApi(), 201);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json($user->load('ambulance')->toApi());
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'email' => ['sometimes', 'required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => 'nullable|string|min:8',
            'role' => ['sometimes', 'required', Rule::in([User::ROLE_ADMIN, User::ROLE_DISPATCHER, User::ROLE_CREW])],
            'phone' => 'nullable|string|max:40',
            'ambulance_id' => 'nullable|integer|exists:ambulances,id',
        ]);
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $user->fill($data)->save();

        return response()->json($user->load('ambulance')->toApi());
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_if($user->id === $request->user()->id, 422, 'You cannot delete your own account.');
        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'User deleted.']);
    }
}
