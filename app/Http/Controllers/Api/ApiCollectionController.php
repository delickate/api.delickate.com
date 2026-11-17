<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiCollection;
use App\Models\ApiFolder;
use App\Support\Collections\CollectionFormat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * API collections for the desktop app: each collection holds requests and
 * request folders, and can be exported, imported (native or Postman) and moved
 * between folders.
 */
class ApiCollectionController extends Controller
{
    public function __construct(private readonly CollectionFormat $format)
    {
    }

    public function show(Request $request, ApiCollection $collection): JsonResponse
    {
        $this->authorizeCollection($request, $collection);

        return response()->json(['collection' => $this->data($collection)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'folder_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:20000'],
            'items' => ['sometimes', 'array'],
            'variables' => ['sometimes', 'array'],
            'auth' => ['sometimes', 'array'],
        ]);

        $userId = (int) $request->user()->id;
        $folderId = $this->folderId($userId, $validated['folder_id'] ?? null);

        $collection = ApiCollection::create([
            'user_id' => $userId,
            'folder_id' => $folderId,
            'name' => trim($validated['name']),
            'description' => $validated['description'] ?? '',
            'items' => $this->format->normalizeItems($validated['items'] ?? []),
            'variables' => $this->format->normalizeVariables($validated['variables'] ?? []),
            'auth' => $this->format->normalizeAuth($validated['auth'] ?? []),
            'sort_order' => $this->nextSortOrder($userId, $folderId),
        ]);

        return response()->json(['collection' => $this->data($collection->fresh())], 201);
    }

    /** Partial update: rename, move (folder_id), or save requests/variables/auth. */
    public function update(Request $request, ApiCollection $collection): JsonResponse
    {
        $this->authorizeCollection($request, $collection);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'folder_id' => ['sometimes', 'nullable', 'integer'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'items' => ['sometimes', 'array'],
            'variables' => ['sometimes', 'array'],
            'auth' => ['sometimes', 'array'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        if (array_key_exists('name', $validated)) {
            $collection->name = trim($validated['name']);
        }
        if (array_key_exists('description', $validated)) {
            $collection->description = $validated['description'] ?? '';
        }
        if (array_key_exists('folder_id', $validated)) {
            $folderId = $this->folderId($collection->user_id, $validated['folder_id']);
            if ($folderId !== $collection->folder_id) {
                $collection->folder_id = $folderId;
                $collection->sort_order = $this->nextSortOrder($collection->user_id, $folderId);
            }
        }
        if (array_key_exists('items', $validated)) {
            $collection->items = $this->format->normalizeItems($validated['items']);
        }
        if (array_key_exists('variables', $validated)) {
            $collection->variables = $this->format->normalizeVariables($validated['variables']);
        }
        if (array_key_exists('auth', $validated)) {
            $collection->auth = $this->format->normalizeAuth($validated['auth']);
        }
        if (array_key_exists('sort_order', $validated)) {
            $collection->sort_order = $validated['sort_order'];
        }

        $collection->save();

        return response()->json(['collection' => $this->data($collection->fresh())]);
    }

    public function destroy(Request $request, ApiCollection $collection): JsonResponse
    {
        $this->authorizeCollection($request, $collection);
        $collection->delete();

        return response()->json(['message' => 'Collection deleted.']);
    }

    public function duplicate(Request $request, ApiCollection $collection): JsonResponse
    {
        $this->authorizeCollection($request, $collection);

        $copy = $collection->replicate(['sort_order']);
        $copy->name = mb_substr($collection->name.' copy', 0, 160);
        // Fresh ids so the copy's requests never clash with the original's.
        $copy->items = $this->format->normalizeItems($this->withoutIds($collection->items ?? []));
        $copy->sort_order = $this->nextSortOrder($collection->user_id, $collection->folder_id);
        $copy->save();

        return response()->json(['collection' => $this->data($copy->fresh())], 201);
    }

    /** ?format=native (default) or ?format=postman (Postman collection v2.1). */
    public function export(Request $request, ApiCollection $collection): JsonResponse
    {
        $this->authorizeCollection($request, $collection);
        $format = $request->query('format', 'native');
        if (! in_array($format, ['native', 'postman'], true)) {
            throw ValidationException::withMessages(['format' => ['Choose native or postman.']]);
        }

        $data = $this->data($collection);
        $document = $format === 'postman' ? $this->format->toPostman($data) : $this->format->toNative($data);

        return response()->json($document, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Body: data (an exported collection or a Postman v2.0/v2.1 collection) and optional folder_id. */
    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'data' => ['required', 'array'],
            'folder_id' => ['nullable', 'integer'],
        ]);

        $userId = (int) $request->user()->id;
        $folderId = $this->folderId($userId, $validated['folder_id'] ?? null);
        $imported = $this->format->import($validated['data']);

        $collection = ApiCollection::create([
            'user_id' => $userId,
            'folder_id' => $folderId,
            'name' => $imported['name'],
            'description' => $imported['description'],
            'items' => $imported['items'],
            'variables' => $imported['variables'],
            'auth' => $imported['auth'],
            'sort_order' => $this->nextSortOrder($userId, $folderId),
        ]);

        return response()->json([
            'collection' => $this->data($collection->fresh()),
            'source' => $imported['source'],
        ], 201);
    }

    /* ------------------------------------------------------------------ */

    private function authorizeCollection(Request $request, ApiCollection $collection): void
    {
        abort_unless((int) $collection->user_id === (int) $request->user()->id, 404);
    }

    private function folderId(int $userId, mixed $folderId): ?int
    {
        if ($folderId === null) {
            return null;
        }
        if (! ApiFolder::where('user_id', $userId)->whereKey((int) $folderId)->exists()) {
            throw ValidationException::withMessages(['folder_id' => ['The selected folder does not exist.']]);
        }

        return (int) $folderId;
    }

    private function nextSortOrder(int $userId, ?int $folderId): int
    {
        return (int) ApiCollection::where('user_id', $userId)->where('folder_id', $folderId)->max('sort_order') + 1;
    }

    private function withoutIds(array $items): array
    {
        return array_map(function ($item) {
            if (! is_array($item)) {
                return $item;
            }
            unset($item['id']);
            if (isset($item['items']) && is_array($item['items'])) {
                $item['items'] = $this->withoutIds($item['items']);
            }

            return $item;
        }, $items);
    }

    private function data(ApiCollection $collection): array
    {
        return [
            'id' => $collection->id,
            'folder_id' => $collection->folder_id,
            'name' => $collection->name,
            'description' => $collection->description ?? '',
            'items' => $collection->items ?? [],
            'variables' => $collection->variables ?? [],
            'auth' => $collection->auth ?? $this->format->normalizeAuth([]),
            'sort_order' => $collection->sort_order,
            'created_at' => $collection->created_at?->toIso8601String(),
            'updated_at' => $collection->updated_at?->toIso8601String(),
        ];
    }
}
