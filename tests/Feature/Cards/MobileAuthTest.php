<?php

namespace Tests\Feature\Cards;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MobileAuthTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(int $roleId): User
    {
        return User::factory()->create([
            'role_id' => $roleId,
            'password' => Hash::make('secret123'),
        ]);
    }

    public function test_waiter_and_card_cashier_can_login_and_receive_role(): void
    {
        foreach ([User::ROLE_WAITER, User::ROLE_CARD_CASHIER] as $roleId) {
            $user = $this->makeUser($roleId);

            $token = $this->postJson('/api/barman-login', ['email' => $user->email, 'password' => 'secret123'])
                ->assertOk()
                ->assertJsonPath('user.id', $user->id)
                ->assertJsonPath('user.role_id', $roleId)
                ->json('user.token');

            $this->getJson('/api/getuserdetails/'.$token, ['token' => $token])
                ->assertOk()
                ->assertJsonPath('user.role_id', $roleId);
        }
    }

    public function test_other_roles_cannot_login_on_mobile(): void
    {
        $user = $this->makeUser(User::ROLE_KITCHEN);

        $this->postJson('/api/barman-login', ['email' => $user->email, 'password' => 'secret123'])
            ->assertForbidden();
    }

    public function test_user_details_rejects_token_of_role_no_longer_allowed(): void
    {
        $user = $this->makeUser(User::ROLE_WAITER);
        $token = $user->createToken('test')->plainTextToken;
        $user->update(['role_id' => User::ROLE_KITCHEN]);

        $this->getJson('/api/getuserdetails/'.$token, ['token' => $token])->assertUnauthorized();
    }
}
