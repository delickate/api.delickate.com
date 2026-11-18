<?php
namespace Tests\Feature;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DisDebugTest extends TestCase
{
    use RefreshDatabase;
    public function test_dbg(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $c = Conversation::create([
            'low_user_id' => min($first->id,$second->id),
            'high_user_id' => max($first->id,$second->id),
            'type' => 'direct',
        ]);
        Sanctum::actingAs($first);
        $this->patchJson("/api/conversations/{$c->id}/disappearing", ['seconds' => 86400])->assertOk();
        $this->withoutExceptionHandling();
        try {
            $this->postJson("/api/conversations/{$c->id}/messages", ['body' => 'x']);
        } catch (\Throwable $e) {
            fwrite(STDERR, "\nEXC: ".get_class($e)." :: ".$e->getMessage()."\n");
        }
    }
}
