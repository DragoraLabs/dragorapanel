<?php

namespace App\Http\Controllers;

use App\Models\Egg;
use App\Models\EggVariable;
use App\Models\Session;
use App\Services\ApiAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EggController extends Controller
{
    private function getUser(Request $request): ?\App\Models\User
    {
        return ApiAuth::user($request);
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
            'docker_images' => 'nullable|array',
            'features' => 'nullable|array',
            'config' => 'nullable|array',
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
            'docker_images' => 'nullable|array',
            'features' => 'nullable|array',
            'config' => 'nullable|array',
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

    // ── PTDL v2 Export/Import ──

    public function exportPtdl(Request $request, Egg $egg): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user || !$user->isAdmin()) {
            return response()->json(['success' => false, 'error' => 'Unauthorized.'], 403);
        }

        $egg->load('variables');
        $dockerImages = $egg->docker_images ?: [];
        if (empty($dockerImages) && $egg->docker_image) {
            $dockerImages = ['Default' => $egg->docker_image];
        }

        $ptdl = [
            '_comment' => 'DO NOT EDIT: FILE GENERATED AUTOMATICALLY BY DRAGORAPANEL PANEL',
            'meta' => [
                'version' => 'PTDL_v2',
                'update_url' => null,
            ],
            'uuid' => $egg->uuid,
            'name' => $egg->name,
            'author' => $egg->author ?? '',
            'description' => $egg->description ?? '',
            'features' => $egg->features ?? ['eula', 'java_version'],
            'docker_images' => $dockerImages,
            'startup' => $egg->startup_command ?? 'java -Xms128M -Xmx{{SERVER_MEMORY}}M -jar server.jar nogui',
            'config' => $egg->config ?? [
                'files' => '{}',
                'startup' => '{"done": "Done ("}',
                'logs' => '{}',
                'stop' => 'stop',
            ],
            'scripts' => [
                'installation' => [
                    'script' => $egg->install_script ?? '',
                    'container' => $egg->install_container ?? '',
                    'entrypoint' => $egg->install_entrypoint ?? 'bash',
                ],
            ],
            'variables' => $egg->variables->map(fn($v) => [
                'name' => $v->name,
                'description' => $v->description ?? '',
                'env_variable' => $v->env_variable,
                'default_value' => $v->default_value ?? '',
                'user_viewable' => (bool) $v->user_viewable,
                'user_editable' => (bool) $v->user_editable,
                'rules' => $v->rules ?? '',
                'field_type' => $v->field_type ?? 'text',
            ])->toArray(),
        ];

        return response()->json(['success' => true, 'ptdl' => $ptdl]);
    }

    public function importPtdl(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user || !$user->isAdmin()) {
            return response()->json(['success' => false, 'error' => 'Unauthorized.'], 403);
        }

        $data = $request->validate([
            'ptdl' => 'required|array',
            'ptdl.name' => 'required|string|max:255',
            'ptdl.author' => 'nullable|string|max:255',
            'ptdl.description' => 'nullable|string',
            'ptdl.features' => 'nullable|array',
            'ptdl.docker_image' => 'nullable|string|max:255',
            'ptdl.docker_images' => 'nullable|array',
            'ptdl.startup' => 'nullable|string',
            'ptdl.config' => 'nullable|array',
            'ptdl.scripts.installation.script' => 'nullable|string',
            'ptdl.scripts.installation.container' => 'nullable|string',
            'ptdl.scripts.installation.entrypoint' => 'nullable|string',
            'ptdl.variables' => 'nullable|array',
        ]);

        $ptdl = $data['ptdl'];
        $dockerImages = $ptdl['docker_images'] ?? [];
        if (empty($dockerImages) && !empty($ptdl['docker_image'])) {
            $dockerImages = ['Default' => $ptdl['docker_image']];
        }
        $firstImage = is_array($dockerImages) ? reset($dockerImages) : null;
        if (!$firstImage && is_string($ptdl['docker_image'] ?? null)) {
            $firstImage = $ptdl['docker_image'];
        }
        $install = $ptdl['scripts']['installation'] ?? [];

        $egg = Egg::create([
            'uuid' => $ptdl['uuid'] ?? null,
            'name' => $ptdl['name'],
            'author' => $ptdl['author'] ?? '',
            'description' => $ptdl['description'] ?? '',
            'type' => 'minecraft',
            'docker_images' => $dockerImages ?: null,
            'docker_image' => $firstImage ?: 'eclipse-temurin:21-jre',
            'features' => $ptdl['features'] ?? ['eula', 'java_version'],
            'config' => $ptdl['config'] ?? null,
            'startup_command' => $ptdl['startup'] ?? null,
            'install_script' => $install['script'] ?? null,
            'install_container' => $install['container'] ?? null,
            'install_entrypoint' => $install['entrypoint'] ?? 'bash',
            'is_active' => true,
        ]);

        if (!empty($ptdl['variables'])) {
            foreach ($ptdl['variables'] as $i => $v) {
                $egg->variables()->create([
                    'name' => $v['name'] ?? 'Variable ' . ($i + 1),
                    'description' => $v['description'] ?? '',
                    'env_variable' => $v['env_variable'] ?? 'VAR_' . ($i + 1),
                    'default_value' => $v['default_value'] ?? '',
                    'rules' => $v['rules'] ?? '',
                    'is_required' => false,
                    'user_viewable' => $v['user_viewable'] ?? true,
                    'user_editable' => $v['user_editable'] ?? true,
                    'field_type' => $v['field_type'] ?? 'text',
                    'sort_order' => $i,
                ]);
            }
        }

        return response()->json(['success' => true, 'egg' => $egg->fresh()->load('variables')]);
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
