<?php

namespace Tests\Feature;

use App\Models\ApiCollection;
use App\Models\ApiFolder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiCollectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_folders_nest_to_many_levels_and_appear_in_workspace(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $root = $this->postJson('/api/folders', ['name' => 'Projects'])->assertCreated()->json('folder');
        $child = $this->postJson('/api/folders', ['name' => 'Shop', 'parent_id' => $root['id']])->assertCreated()->json('folder');
        $grandchild = $this->postJson('/api/folders', ['name' => 'v2', 'parent_id' => $child['id']])->assertCreated()->json('folder');

        $this->postJson('/api/collections', ['name' => 'Orders', 'folder_id' => $grandchild['id']])->assertCreated();
        $this->postJson('/api/collections', ['name' => 'Loose'])->assertCreated();

        $workspace = $this->getJson('/api/workspace')->assertOk();
        $this->assertCount(3, $workspace->json('folders'));
        $this->assertCount(2, $workspace->json('collections'));
        $this->assertArrayNotHasKey('items', $workspace->json('collections.0'));
    }

    public function test_folder_cannot_be_moved_into_its_own_descendant(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $a = ApiFolder::create(['user_id' => $user->id, 'name' => 'A']);
        $b = ApiFolder::create(['user_id' => $user->id, 'name' => 'B', 'parent_id' => $a->id]);
        $c = ApiFolder::create(['user_id' => $user->id, 'name' => 'C', 'parent_id' => $b->id]);

        $this->patchJson("/api/folders/{$a->id}", ['parent_id' => $c->id])->assertUnprocessable();
        $this->patchJson("/api/folders/{$a->id}", ['parent_id' => $a->id])->assertUnprocessable();

        $this->patchJson("/api/folders/{$c->id}", ['parent_id' => null, 'name' => 'C2'])
            ->assertOk()
            ->assertJsonPath('folder.parent_id', null)
            ->assertJsonPath('folder.name', 'C2');
    }

    public function test_deleting_a_folder_removes_its_whole_subtree(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $a = ApiFolder::create(['user_id' => $user->id, 'name' => 'A']);
        $b = ApiFolder::create(['user_id' => $user->id, 'name' => 'B', 'parent_id' => $a->id]);
        $keep = ApiFolder::create(['user_id' => $user->id, 'name' => 'Keep']);
        ApiCollection::create(['user_id' => $user->id, 'folder_id' => $b->id, 'name' => 'Deep']);
        ApiCollection::create(['user_id' => $user->id, 'folder_id' => $keep->id, 'name' => 'Kept']);

        $this->deleteJson("/api/folders/{$a->id}")->assertOk();

        $this->assertSame(['Keep'], ApiFolder::pluck('name')->all());
        $this->assertSame(['Kept'], ApiCollection::pluck('name')->all());
    }

    public function test_users_cannot_touch_each_others_folders_or_collections(): void
    {
        $owner = User::factory()->create();
        $folder = ApiFolder::create(['user_id' => $owner->id, 'name' => 'Private']);
        $collection = ApiCollection::create(['user_id' => $owner->id, 'name' => 'Secret']);

        Sanctum::actingAs(User::factory()->create());

        $this->patchJson("/api/folders/{$folder->id}", ['name' => 'x'])->assertNotFound();
        $this->deleteJson("/api/folders/{$folder->id}")->assertNotFound();
        $this->getJson("/api/collections/{$collection->id}")->assertNotFound();
        $this->getJson("/api/collections/{$collection->id}/export")->assertNotFound();
        $this->postJson('/api/folders', ['name' => 'x', 'parent_id' => $folder->id])->assertUnprocessable();
        $this->postJson('/api/collections', ['name' => 'x', 'folder_id' => $folder->id])->assertUnprocessable();
        $this->getJson('/api/workspace')->assertJsonCount(0, 'folders')->assertJsonCount(0, 'collections');
    }

    public function test_collection_items_are_saved_normalised_and_exported_then_reimported(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $collection = $this->postJson('/api/collections', ['name' => 'Users API'])->json('collection');

        $this->patchJson("/api/collections/{$collection['id']}", [
            'variables' => [['key' => 'base', 'value' => 'https://example.test']],
            'items' => [
                ['type' => 'folder', 'name' => 'Auth', 'items' => [
                    [
                        'type' => 'request',
                        'name' => 'Login',
                        'method' => 'post',
                        'url' => '{{base}}/login',
                        'headers' => [['key' => 'Accept', 'value' => 'application/json']],
                        'body' => ['mode' => 'json', 'raw' => '{"a":1}'],
                        'auth' => ['type' => 'bearer', 'bearer' => ['token' => 'abc']],
                        'unexpected' => 'dropped',
                    ],
                ]],
            ],
        ])->assertOk()
            ->assertJsonPath('collection.items.0.items.0.method', 'POST')
            ->assertJsonPath('collection.items.0.items.0.headers.0.enabled', true)
            ->assertJsonMissingPath('collection.items.0.items.0.unexpected');

        $export = $this->getJson("/api/collections/{$collection['id']}/export")
            ->assertOk()
            ->assertJsonPath('format', 'api-collections')
            ->json();

        $imported = $this->postJson('/api/collections/import', ['data' => $export])
            ->assertCreated()
            ->assertJsonPath('source', 'native')
            ->assertJsonPath('collection.name', 'Users API')
            ->assertJsonPath('collection.variables.0.key', 'base')
            ->assertJsonPath('collection.items.0.items.0.name', 'Login')
            ->assertJsonPath('collection.items.0.items.0.auth.bearer.token', 'abc');

        $this->assertNotSame($collection['id'], $imported->json('collection.id'));

        $postman = $this->getJson("/api/collections/{$collection['id']}/export?format=postman")
            ->assertOk()
            ->assertJsonPath('info.schema', 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json')
            ->assertJsonPath('item.0.item.0.request.body.options.raw.language', 'json')
            ->json();

        $this->postJson('/api/collections/import', ['data' => $postman])
            ->assertCreated()
            ->assertJsonPath('source', 'postman')
            ->assertJsonPath('collection.items.0.items.0.body.mode', 'json')
            ->assertJsonPath('collection.items.0.items.0.auth.type', 'bearer');
    }

    public function test_postman_v21_collection_is_imported_into_a_folder(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $folder = ApiFolder::create(['user_id' => $user->id, 'name' => 'Imports']);

        $postman = [
            'info' => [
                'name' => 'Petstore',
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'auth' => ['type' => 'apikey', 'apikey' => [
                ['key' => 'key', 'value' => 'X-Api-Key'],
                ['key' => 'value', 'value' => 'secret'],
            ]],
            'variable' => [['key' => 'host', 'value' => 'https://pets.test']],
            'item' => [
                ['name' => 'Pets', 'item' => [
                    ['name' => 'List pets', 'request' => [
                        'method' => 'GET',
                        'header' => [['key' => 'Accept', 'value' => 'application/json', 'disabled' => true]],
                        'url' => ['host' => ['{{host}}'], 'path' => ['pets'], 'query' => [['key' => 'limit', 'value' => '10']]],
                    ]],
                    ['name' => 'Add pet', 'request' => [
                        'method' => 'POST',
                        'url' => '{{host}}/pets',
                        'body' => ['mode' => 'urlencoded', 'urlencoded' => [['key' => 'name', 'value' => 'Rex']]],
                        'auth' => ['type' => 'noauth'],
                    ]],
                ]],
                ['name' => 'Search', 'request' => [
                    'method' => 'POST',
                    'url' => ['raw' => '{{host}}/graphql'],
                    'body' => ['mode' => 'graphql', 'graphql' => ['query' => '{ pets { id } }', 'variables' => '']],
                ]],
            ],
        ];

        $response = $this->postJson('/api/collections/import', ['data' => $postman, 'folder_id' => $folder->id])
            ->assertCreated()
            ->assertJsonPath('source', 'postman')
            ->assertJsonPath('collection.folder_id', $folder->id)
            ->assertJsonPath('collection.auth.type', 'apikey')
            ->assertJsonPath('collection.auth.apikey.value', 'secret')
            ->assertJsonPath('collection.variables.0.value', 'https://pets.test')
            ->assertJsonPath('collection.items.0.type', 'folder')
            ->assertJsonPath('collection.items.0.items.0.url', '{{host}}/pets?limit=10')
            ->assertJsonPath('collection.items.0.items.0.headers.0.enabled', false)
            ->assertJsonPath('collection.items.0.items.0.auth.type', 'inherit')
            ->assertJsonPath('collection.items.0.items.1.auth.type', 'none')
            ->assertJsonPath('collection.items.0.items.1.body.urlencoded.0.value', 'Rex')
            ->assertJsonPath('collection.items.1.body.mode', 'json');

        $this->assertStringContainsString('pets { id }', $response->json('collection.items.1.body.raw'));
    }

    public function test_unknown_import_format_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/collections/import', ['data' => ['hello' => 'world']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('data');
    }

    public function test_collection_can_be_moved_duplicated_and_deleted(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $folder = ApiFolder::create(['user_id' => $user->id, 'name' => 'Target']);
        $collection = $this->postJson('/api/collections', [
            'name' => 'Movable',
            'items' => [['type' => 'request', 'name' => 'Ping', 'url' => 'https://x.test', 'id' => 'req_123456']],
        ])->json('collection');

        $this->patchJson("/api/collections/{$collection['id']}", ['folder_id' => $folder->id])
            ->assertOk()
            ->assertJsonPath('collection.folder_id', $folder->id);

        $copy = $this->postJson("/api/collections/{$collection['id']}/duplicate")
            ->assertCreated()
            ->assertJsonPath('collection.name', 'Movable copy')
            ->assertJsonPath('collection.items.0.name', 'Ping');
        $this->assertNotSame('req_123456', $copy->json('collection.items.0.id'));

        $this->deleteJson("/api/collections/{$collection['id']}")->assertOk();
        $this->assertSame(1, ApiCollection::count());
    }

    public function test_routes_require_authentication(): void
    {
        $this->getJson('/api/workspace')->assertUnauthorized();
        $this->postJson('/api/folders', ['name' => 'x'])->assertUnauthorized();
        $this->postJson('/api/collections/import', ['data' => []])->assertUnauthorized();
    }
}
