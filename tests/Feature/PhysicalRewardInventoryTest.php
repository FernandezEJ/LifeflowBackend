<?php

namespace Tests\Feature;

use App\Models\Reward;
use App\Models\User;
use App\Models\UserVoucher;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhysicalRewardInventoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
    }

    private function signIn(User $user): void
    {
        app('auth')->forgetGuards();
        $this->withToken($user->createToken('test')->plainTextToken);
    }

    private function data(array $changes = []): array
    {
        return [...['name' => 'LifeFlow shirt', 'category' => 'apparel', 'points_cost' => 100,
            'status' => 'active', 'variants' => [
                ['size' => 'S', 'stock_quantity' => 5], ['size' => 'M', 'stock_quantity' => 10],
                ['size' => 'L', 'stock_quantity' => 8], ['size' => 'XL', 'stock_quantity' => 0],
            ]], ...$changes];
    }

    private function apparel(): Reward
    {
        $this->signIn(User::factory()->create(['role' => 'admin']));
        $id = $this->postJson('/api/admin/rewards', $this->data())->assertCreated()
            ->assertJsonPath('reward.stock_quantity', 23)->assertJsonPath('reward.voucher_value', null)
            ->assertJsonCount(4, 'reward.variants')->json('reward.id');

        return Reward::findOrFail($id);
    }

    private function donor(int $balance = 1000): User
    {
        $user = User::factory()->create();
        DB::table('point_transactions')->insert(['user_id' => $user->id, 'type' => 'donation_reward',
            'amount' => $balance, 'event_key' => 'test-credit', 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }

    public static function categories(): array
    {
        return [['merchandise'], ['vouchers']];
    }

    #[DataProvider('categories')]
    public function test_single_stock_categories_and_conditional_value(string $category): void
    {
        $this->signIn(User::factory()->create(['role' => 'super_admin']));
        $data = $this->data(['category' => $category, 'stock_quantity' => 7, 'voucher_value' => '125.50']);
        $id = $this->postJson('/api/admin/rewards', $data)->assertCreated()
            ->assertJsonPath('reward.stock_quantity', 7)->assertJsonCount(0, 'reward.variants')
            ->assertJsonPath('reward.voucher_value', $category === 'vouchers' ? '125.50' : null)->json('reward.id');
        $this->signIn($this->donor());
        $this->getJson('/api/rewards?category='.$category)->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/rewards/'.$id.'/redeem', ['request_key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('voucher.reward.size', null);
        $this->assertSame(6, Reward::findOrFail($id)->stock_quantity);
    }

    public function test_vouchers_require_value_and_legacy_creation_is_not_available(): void
    {
        $this->signIn(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/admin/rewards', $this->data(['category' => 'vouchers', 'stock_quantity' => 1]))
            ->assertUnprocessable()->assertJsonValidationErrors('voucher_value');
        foreach (Reward::LEGACY_CATEGORIES as $category) {
            $this->postJson('/api/admin/rewards', $this->data(['category' => $category, 'stock_quantity' => 1, 'voucher_value' => 100]))
                ->assertUnprocessable()->assertJsonValidationErrors('category');
            $legacy = Reward::factory()->create(['category' => $category, 'stock_quantity' => 4]);
            $this->putJson('/api/admin/rewards/'.$legacy->id, $this->data(['category' => $category,
                'stock_quantity' => 4, 'expected_stock' => 4, 'voucher_value' => 125]))->assertOk()
                ->assertJsonPath('reward.category', $category);
            $this->getJson('/api/admin/rewards?category='.$category)->assertOk()->assertJsonCount(1, 'data');
        }
    }

    public static function invalidVariants(): array
    {
        return [[[]], [[['size' => 'XXL', 'stock_quantity' => 1]]],
            [[['size' => 'S', 'stock_quantity' => -1]]], [[['size' => 'S', 'stock_quantity' => 1.5]]],
            [[['size' => 'S', 'stock_quantity' => 1], ['size' => 'S', 'stock_quantity' => 2]]]];
    }

    #[DataProvider('invalidVariants')]
    public function test_invalid_inventory_is_rejected_without_writes(array $variants): void
    {
        $this->signIn(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/admin/rewards', $this->data(['variants' => $variants]))->assertUnprocessable();
        $this->assertDatabaseCount('rewards', 0);
        $this->assertDatabaseCount('reward_variants', 0);
    }

    public function test_purchase_is_atomic_idempotent_and_size_is_immutable_through_claim(): void
    {
        $reward = $this->apparel();
        $staff = User::factory()->create(['role' => 'super_admin']);
        $owner = $this->donor();
        $this->signIn($owner);
        $size = $reward->variants()->where('size', 'S')->firstOrFail();
        $key = (string) Str::uuid();
        $request = ['request_key' => $key, 'variant_id' => $size->id];
        $id = $this->postJson('/api/rewards/'.$reward->id.'/redeem', $request)->assertOk()
            ->assertJsonPath('voucher.reward.size', 'S')->assertJsonPath('summary.current_balance', 900)
            ->assertJsonPath('reward.stock_quantity', 22)->json('voucher.id');
        $this->postJson('/api/rewards/'.$reward->id.'/redeem', $request)->assertOk()->assertJsonPath('voucher.id', $id);
        $this->postJson('/api/rewards/'.$reward->id.'/redeem', [...$request, 'variant_id' => $size->id + 1])->assertUnprocessable();
        $this->assertSame(4, $size->fresh()->stock_quantity);
        $this->assertSame([4, 10, 8, 0], $reward->variants()->orderBy('id')->pluck('stock_quantity')->all());
        $this->assertDatabaseCount('user_vouchers', 1);
        $this->assertDatabaseCount('point_transactions', 2);
        $this->signIn($staff);
        $this->putJson('/api/admin/rewards/'.$reward->id, $this->data(['expected_stock' => 23,
            'expected_inventory_version' => 1]))->assertConflict();
        $this->putJson('/api/admin/rewards/'.$reward->id, $this->data(['expected_stock' => 22,
            'expected_inventory_version' => 2, 'variants' => [['size' => 'M', 'stock_quantity' => 10]]]))
            ->assertOk()->assertJsonPath('reward.stock_quantity', 10);
        $this->assertFalse($size->fresh()->enabled);
        $this->deleteJson('/api/admin/rewards/'.$reward->id)->assertOk();
        $this->signIn($owner);
        $this->getJson('/api/rewards')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/vouchers/'.$id)->assertOk()->assertJsonPath('voucher.reward.size', 'S');
        $this->postJson('/api/vouchers/'.$id.'/activate')->assertOk()->assertJsonPath('voucher.expires_at', null);
        $code = Crypt::decryptString(UserVoucher::findOrFail($id)->claim_code_encrypted);
        $before = DB::table('point_transactions')->get()->toArray();
        $this->travel(400)->days();
        $this->getJson('/api/vouchers/'.$id)->assertOk()->assertJsonPath('voucher.claim_code', $code);
        $this->signIn($staff);
        $this->postJson('/api/admin/rewards/claims/verify', ['claim_code' => $code])->assertOk()
            ->assertJsonPath('claim.reward.size', 'S')->assertJsonPath('claim.claim_status', 'pending_claim');
        $this->postJson('/api/admin/rewards/claims/confirm', ['claim_code' => $code])->assertOk()
            ->assertJsonPath('claim.claim_status', 'claimed');
        $this->postJson('/api/admin/rewards/claims/confirm', ['claim_code' => $code])->assertConflict();
        $this->assertSame(10, $reward->fresh()->stock_quantity);
        $this->assertSame(0, $size->fresh()->stock_quantity);
        $this->assertEquals($before, DB::table('point_transactions')->get()->toArray());
        $this->signIn($owner);
        $this->getJson('/api/vouchers/'.$id)->assertOk()->assertJsonPath('voucher.claim_status', 'claimed')
            ->assertJsonPath('voucher.reward.size', 'S');
        $this->signIn($this->donor());
        $this->getJson('/api/vouchers/'.$id)->assertNotFound();
    }

    public function test_missing_foreign_sold_out_and_disabled_sizes_cannot_spend(): void
    {
        $reward = $this->apparel();
        $foreign = $this->apparel()->variants()->firstOrFail();
        $disabled = $reward->variants()->create(['size' => '2XL', 'stock_quantity' => 5, 'enabled' => false]);
        $this->signIn($this->donor());
        foreach ([null, $foreign->id, $disabled->id, $reward->variants()->where('size', 'XL')->value('id')] as $id) {
            $this->postJson('/api/rewards/'.$reward->id.'/redeem', ['request_key' => (string) Str::uuid(), 'variant_id' => $id])
                ->assertUnprocessable();
        }
        $this->getJson('/api/rewards/'.$reward->id)->assertOk()->assertJsonCount(4, 'reward.variants');
        $this->assertDatabaseCount('user_vouchers', 0);
        $this->assertDatabaseCount('point_transactions', 1);
        $this->assertSame(23, $reward->fresh()->stock_quantity);
    }

    public function test_insufficient_points_roll_back_variant_parent_and_purchase(): void
    {
        $reward = $this->apparel();
        $this->signIn($this->donor(50));
        $this->postJson('/api/rewards/'.$reward->id.'/redeem', ['request_key' => (string) Str::uuid(),
            'variant_id' => $reward->variants()->firstOrFail()->id])->assertUnprocessable();
        $this->assertDatabaseCount('user_vouchers', 0);
        $this->assertSame(23, $reward->fresh()->stock_quantity);
        $this->assertSame(5, $reward->variants()->where('size', 'S')->firstOrFail()->stock_quantity);
    }

    public function test_equal_total_size_edit_conflicts_and_analytics_use_aggregate_stock(): void
    {
        $reward = $this->apparel();
        $edit = $this->data(['expected_stock' => 23, 'expected_inventory_version' => 1,
            'variants' => [['size' => 'M', 'stock_quantity' => 23]]]);
        $this->putJson('/api/admin/rewards/'.$reward->id, $edit)->assertOk();
        $this->putJson('/api/admin/rewards/'.$reward->id, $edit)->assertConflict();
        $this->getJson('/api/admin/rewards/analytics')->assertOk()->assertJsonPath('summary.active_inventory', 23);
        $this->signIn($this->donor());
        $id = $reward->variants()->where('size', 'M')->value('id');
        $voucher = $this->postJson('/api/rewards/'.$reward->id.'/redeem',
            ['request_key' => (string) Str::uuid(), 'variant_id' => $id])->assertOk()->json('voucher.id');
        $this->signIn(User::factory()->create(['role' => 'admin']));
        $this->putJson('/api/admin/rewards/'.$reward->id, $this->data(['category' => 'merchandise',
            'expected_stock' => 22, 'expected_inventory_version' => 3, 'stock_quantity' => 22]))->assertOk();
        $this->getJson('/api/admin/rewards/redemptions?category=apparel')->assertOk()
            ->assertJsonPath('data.0.id', $voucher)->assertJsonPath('data.0.size', 'M');
        $this->getJson('/api/admin/rewards/analytics')->assertOk()->assertJsonPath('summary.active_inventory', 22);
    }
}
