<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Per-user notification channel (used by Laravel's broadcast notifications).
Broadcast::channel('App.Models.User.{id}', fn (User $user, $id) => (int) $user->id === (int) $id);

// Live fleet map and incident updates: dispatchers and admins only.
Broadcast::channel('fleet', fn (User $user) => in_array($user->role, [User::ROLE_ADMIN, User::ROLE_DISPATCHER], true));

// Offers and trip updates for one ambulance's crew.
Broadcast::channel('ambulance.{id}', fn (User $user, $id) => $user->role === User::ROLE_CREW && (int) $user->ambulance_id === (int) $id);
