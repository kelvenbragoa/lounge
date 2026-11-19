<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthMobileController extends Controller
{
    private const MOBILE_ROLES = [User::ROLE_WAITER, User::ROLE_CARD_CASHIER];

    public function login(Request $request){
        $loginUserData = $request->validate([
            'email'=>'required|string|email',
            'password'=>'required|min:8'
        ]);

        if (Auth::attempt($loginUserData)) {
            $user = User::where('email',$loginUserData['email'])->first();
            if (!in_array((int) $user->role_id, self::MOBILE_ROLES, true)) {
                return response()->json([
                    'message' => 'Usuario no autorizado'
                ],403);
            }
            $token = $user->createToken($user->name.'-AuthToken')->plainTextToken;

            return response()->json([
                'user' => $this->userPayload($user, $token),
                'access_token' => $token,
            ]);
        }else{
            return response()->json([
                'message' => 'Usuario/Password incorrectos'
            ],403);
        }
    }

    public function getuserdetails($token){
        $user = Auth::user();

        if (!in_array((int) $user->role_id, self::MOBILE_ROLES, true)) {
            return response()->json([
                'message' => 'Usuario no autorizado'
            ],401);
        }

        return response()->json([
            'user' => $this->userPayload($user, $token),
            'access_token' => $token,
        ]);
    }

    private function userPayload(User $user, string $token): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role_id' => (int) $user->role_id,
            'role_name' => optional($user->role)->name,
            'token' => $token,
        ];
    }
}
