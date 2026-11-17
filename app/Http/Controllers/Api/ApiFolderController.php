<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiCollection;
use App\Models\ApiFolder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Folders for the API Collections desktop app. Folders nest to any depth and
 * each one may hold sub-folders and collections.
 */
class ApiFolderController extends Controller
{
    public const MAX_DEPTH = 50;

    /** The signed-in user's whole tree: every folder and collection (without requests). */
    public function workspace(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        return response()->json([
            'folders' => ApiFolder::where('user_id', $userId)
                ->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'parent_id', 'name', 'sort_order', 'created_at', 'updated_at']),
            'collections' => ApiCollection::where('user_id', $userId)
                ->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'folder_id', 'name', 'description', 'sort_order', 'created_at', 'updated_at']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'parent_id' => ['nullable', 'integer'],
        ]);

        $userId = (int) $request->user()->id;
        $parentId = isset($validated['parent_id']) ? (int) $validated['parent_id'] : null;
        if ($parentId !== null) {
            $this->ownedFolder($userId, $parentId);
            if ($this->depthOf($userId, $parentId) + 1 >= self::MAX_DEPTH) {
                throw ValidationException::withMessages([
                    'parent_id' => ['Folders can be nested at most '.self::MAX_DEPTH.' levels deep.'],
                ]);
            }
        }

        $folder = ApiFolder::create([
            'user_id' => $userId,
            'parent_id' => $parentId,
            'name' => trim($validated['name']),
            'sort_order' => $this->nextSortOrder($userId, $parentId),
        ]);

        return response()->json(['folder' => $folder->fresh()], 201);
    }

    /** Rename and/or move a folder (send parent_id: null to move it to the top level). */
    public function update(Request $request, ApiFolder $folder): JsonResponse
    {
        $this->authorizeFolder($request, $folder);
        $userId = (int) $folder->user_id;

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'parent_id' => ['sometimes', 'nullable', 'integer'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        if (array_key_exists('name', $validated)) {
            $folder->name = trim($validated['name']);
        }

        if (array_key_exists('parent_id', $validated)) {
            $parentId = $validated['parent_id'] === null ? null : (int) $validated['parent_id'];
            if ($parentId !== null) {
                $this->ownedFolder($userId, $parentId);
                $descendants = $this->descendantIds($userId, $folder->id);
                if ($parentId === $folder->id || in_array($parentId, $descendants, true)) {
                    throw ValidationException::withMessages([
                        'parent_id' => ['A folder cannot be moved into itself or one of its sub-folders.'],
                    ]);
                }
                $ownDepth = $this->depthOf($userId, $folder->id);
                $subtreeHeight = 0;
                foreach ($descendants as $id) {
                    $subtreeHeight = max($subtreeHeight, $this->depthOf($userId, $id) - $ownDepth);
                }
                if ($this->depthOf($userId, $parentId) + 1 + $subtreeHeight >= self::MAX_DEPTH) {
                    throw ValidationException::withMessages([
                        'parent_id' => ['Folders can be nested at most '.self::MAX_DEPTH.' levels deep.'],
                    ]);
                }
            }
            if ($parentId !== $folder->parent_id) {
                $folder->parent_id = $parentId;
                $folder->sort_order = $this->nextSortOrder($userId, $parentId);
            }
        }

        if (array_key_exists('sort_order', $validated)) {
            $folder->sort_order = $validated['sort_order'];
        }

        $folder->save();

        return response()->json(['folder' => $folder->fresh()]);
    }

    /** Deletes the folder with all of its sub-folders and collections. */
    public function destroy(Request $request, ApiFolder $folder): JsonResponse
    {
        $this->authorizeFolder($request, $folder);
        $userId = (int) $folder->user_id;

        $ids = [$folder->id, ...$this->descendantIds($userId, $folder->id)];
        // Deepest folders first so the parent_id foreign key is never violated.
        usort($ids, fn (int $a, int $b) => $this->depthOf($userId, $b) <=> $this->depthOf($userId, $a));

        DB::transaction(function () use ($ids, $userId) {
            ApiCollection::where('user_id', $userId)->whereIn('folder_id', $ids)->delete();
            foreach ($ids as $id) {
                ApiFolder::where('user_id', $userId)->whereKey($id)->delete();
            }
        });

        return response()->json(['message' => 'Folder deleted.', 'deleted_folder_ids' => $ids]);
    }

    /* ------------------------------------------------------------------ */

    /** @var array<int, int|null>|null parent_id keyed by folder id */
    private ?array $parents = null;

    private function authorizeFolder(Request $request, ApiFolder $folder): void
    {
        abort_unless((int) $folder->user_id === (int) $request->user()->id, 404);
    }

    private function ownedFolder(int $userId, int $id): ApiFolder
    {
        $folder = ApiFolder::where('user_id', $userId)->find($id);
        if (! $folder) {
            throw ValidationException::withMessages(['parent_id' => ['The selected folder does not exist.']]);
        }

        return $folder;
    }

    private function parentMap(int $userId): array
    {
        return $this->parents ??= ApiFolder::where('user_id', $userId)
            ->pluck('parent_id', 'id')
            ->map(fn ($parentId) => $parentId === null ? null : (int) $parentId)
            ->all();
    }

    private function depthOf(int $userId, int $folderId): int
    {
        $parents = $this->parentMap($userId);
        $depth = 0;
        $current = $parents[$folderId] ?? null;
        while ($current !== null && $depth <= self::MAX_DEPTH) {
            $depth++;
            $current = $parents[$current] ?? null;
        }

        return $depth;
    }

    /** @return array<int, int> */
    private function descendantIds(int $userId, int $folderId): array
    {
        $children = [];
        foreach ($this->parentMap($userId) as $id => $parentId) {
            if ($parentId !== null) {
                $children[$parentId][] = (int) $id;
            }
        }

        $result = [];
        $stack = $children[$folderId] ?? [];
        while ($stack) {
            $id = array_pop($stack);
            if (isset($result[$id])) {
                continue;
            }
            $result[$id] = $id;
            array_push($stack, ...($children[$id] ?? []));
        }

        return array_values($result);
    }

    private function nextSortOrder(int $userId, ?int $parentId): int
    {
        return (int) ApiFolder::where('user_id', $userId)->where('parent_id', $parentId)->max('sort_order') + 1;
    }
}
