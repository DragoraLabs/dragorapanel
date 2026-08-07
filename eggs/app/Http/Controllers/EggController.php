<?php

namespace App\Http\Controllers;

use App\Models\Egg;
use App\Models\EggVariable;
use App\Models\Session;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EggController extends Controller
{
    private function getUser(Request $request): ?\App\Models\User
    {
        $header = $request->header('Authorization', '');
        if (!preg_match('/Bearer\s+(.+)$/i', $header, $m)) {
            return null;
        }
        $session = Session::where('token', $m[1])->valid()->with('user')->first();
        return $session?->user;
    }

    public function index(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user || !$user->isAdmin()) {
            return response()->json(['success' => false, 'error' => 'Unauthorized.'], 403);
        }
        $eggs = Egg::with('variables')->orderBy('name')->get();
        return response()->json(['success' => true, 'eggs' => $eggs]);
    }

    public function active(Request $request): JsonResponse
    {
        $eggs = Egg::where('is_active', true)->orderBy('name')->get(['id', 'uuid', 'name', 'description', 'type', 'docker_image', 'java_version', 'default_version', 'supported_versions']);
        return response()->json(['success' => true, 'eggs' => $eggs]);
    }

    public function show(Request $request, Egg $egg): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user || !$user->isAdmin()) {
            return response()->json(['success' => false, 'error' => 'Unauthorized.'], 403);
        }
        $egg->load('variables');
        return response()->json(['success' => true, 'egg' => $egg]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user || !$user->isAdmin()) {
            return response()->json(['success' => false, 'error' => 'Unauthorized.'], 403);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'author' => 'nullable|string|max:255',
            'type' => 'required|string|max:50',
            'docker_image' => 'nullable|string|max:255',
            'startup_command' => 'nullable|string',
            'config_files' => 'nullable|array',
            'default_version' => 'nullable|string|max:50',
            'java_version' => 'nullable|string|max:10',
            'supported_versions' => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ]);

        $data['supported_versions'] = $data['supported_versions'] ?? [];
        $egg = Egg::create($data);
        return response()->json(['success' => true, 'egg' => $egg]);
    }

    public function update(Request $request, Egg $egg): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user || !$user->isAdmin()) {
            return response()->json(['success' => false, 'error' => 'Unauthorized.'], 403);
        }

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'author' => 'nullable|string|max:255',
            'type' => 'sometimes|string|max:50',
            'docker_image' => 'nullable|string|max:255',
            'startup_command' => 'nullable|string',
            'config_files' => 'nullable|array',
            'default_version' => 'nullable|string|max:50',
            'java_version' => 'nullable|string|max:10',
            'supported_versions' => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ]);

        $egg->update(array_filter($data, fn($v) => $v !== null));
        return response()->json(['success' => true, 'egg' => $egg->fresh()]);
    }

    public function destroy(Request $request, Egg $egg): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user || !$user->isAdmin()) {
            return response()->json(['success' => false, 'error' => 'Unauthorized.'], 403);
        }
        $egg->delete();
        return response()->json(['success' => true]);
    }

    // ── Variables ──

    public function variablesIndex(Request $request, Egg $egg): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user || !$user->isAdmin()) {
            return response()->json(['success' => false, 'error' => 'Unauthorized.'], 403);
        }
        return response()->json(['success' => true, 'variables' => $egg->variables()->orderBy('sort_order')->get()]);
    }

    public function variablesStore(Request $request, Egg $egg): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user || !$user->isAdmin()) {
            return response()->json(['success' => false, 'error' => 'Unauthorized.'], 403);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'env_variable' => 'required|string|max:50',
            'default_value' => 'nullable|string',
            'rules' => 'nullable|string',
            'is_required' => 'nullable|boolean',
            'user_viewable' => 'nullable|boolean',
            'user_editable' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);

        $data['egg_id'] = $egg->id;
        $variable = EggVariable::create($data);
        return response()->json(['success' => true, 'variable' => $variable]);
    }

    public function variablesUpdate(Request $request, Egg $egg, EggVariable $variable): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user || !$user->isAdmin()) {
            return response()->json(['success' => false, 'error' => 'Unauthorized.'], 403);
        }

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'env_variable' => 'sometimes|string|max:50',
            'default_value' => 'nullable|string',
            'rules' => 'nullable|string',
            'is_required' => 'nullable|boolean',
            'user_viewable' => 'nullable|boolean',
            'user_editable' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);

        if ($variable->egg_id !== $egg->id) {
            return response()->json(['success' => false, 'error' => 'Variable not found.'], 404);
        }

        $variable->update(array_filter($data, fn($v) => $v !== null));
        return response()->json(['success' => true, 'variable' => $variable->fresh()]);
    }

    public function variablesDestroy(Request $request, Egg $egg, EggVariable $variable): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user || !$user->isAdmin()) {
            return response()->json(['success' => false, 'error' => 'Unauthorized.'], 403);
        }
        if ($variable->egg_id !== $egg->id) {
            return response()->json(['success' => false, 'error' => 'Variable not found.'], 404);
        }
        $variable->delete();
        return response()->json(['success' => true]);
    }
}
