<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/', 'unique:users,phone'],
            'email' => ['required', 'email:rfc', 'max:254', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
            'dob' => ['required', 'date', 'before:today'],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'dob' => $validated['dob'],
        ]);

        return response()->json([
            'user' => $this->userData($user),
            'token' => $user->createToken($validated['device_name'] ?? 'chat-client')->plainTextToken,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'password' => ['required', 'string', 'max:128'],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ]);

        $user = User::where('phone', $validated['phone'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'phone' => ['The provided credentials are incorrect.'],
            ]);
        }

        return response()->json([
            'user' => $this->userData($user),
            'token' => $user->createToken($validated['device_name'] ?? 'chat-client')->plainTextToken,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function socketIdentity(Request $request): JsonResponse
    {
        return response()->json([
            'id' => $request->user()->id,
            'name' => $request->user()->name,
            'phone' => $request->user()->phone,
            'avatar_url' => $request->user()->avatar_url,
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:120'],
        ]);

        $user = $request->user();
        $user->update(['name' => trim($validated['name'])]);

        return response()->json(['user' => $this->userData($user->fresh())]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:128', 'different:current_password'],
        ]);

        $user = $request->user();
        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Your current password is incorrect.'],
            ]);
        }

        $user->update(['password' => $validated['password']]);

        // Sign other devices out; keep this session signed in.
        $currentTokenId = $request->user()->currentAccessToken()->id;
        $user->tokens()->where('id', '!=', $currentTokenId)->delete();

        return response()->json(['message' => 'Your password has been changed.']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
        ]);

        $user = User::where('phone', $validated['phone'])->first();

        // An account exists and has an email: email it a fresh 6-digit code.
        if ($user && $user->email) {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                ['token' => Hash::make($code), 'created_at' => Carbon::now()]
            );

            try {
                Mail::raw(
                    "Your DailyConnect password reset code is: {$code}\n\n"
                        ."Enter it in the app to set a new password. The code expires in 15 minutes.\n\n"
                        ."If you did not request this, you can ignore this email; your password stays unchanged.",
                    function ($message) use ($user) {
                        $message->to($user->email)->subject('Your DailyConnect password reset code');
                    }
                );
            } catch (\Throwable $exception) {
                // Never leak mail-delivery failures to the caller; log for the operator.
                report($exception);
            }

            return response()->json([
                'message' => 'We sent a 6-digit reset code to the email on file for this number.',
                'email_hint' => $this->maskEmail($user->email),
            ]);
        }

        // No account (or no email): stay vague so phone numbers cannot be probed.
        return response()->json([
            'message' => 'If that number has an account with an email on file, a reset code is on its way.',
            'email_hint' => null,
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
        ]);

        $invalid = function (): void {
            throw ValidationException::withMessages([
                'code' => ['This reset code is invalid or has expired. Request a new one.'],
            ]);
        };

        $user = User::where('phone', $validated['phone'])->first();
        if (! $user || ! $user->email) {
            $invalid();
        }

        $record = DB::table('password_reset_tokens')->where('email', $user->email)->first();
        if (! $record) {
            $invalid();
        }

        if (Carbon::parse($record->created_at)->addMinutes(15)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $invalid();
        }

        if (! Hash::check($validated['code'], $record->token)) {
            $invalid();
        }

        $user->update(['password' => $validated['password']]);
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        // A reset signs out every device, old sessions included.
        $user->tokens()->delete();

        return response()->json(['message' => 'Your password has been reset. Sign in with your new password.']);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message_edit_minutes' => ['required', 'integer', 'in:'.implode(',', User::MESSAGE_EDIT_MINUTE_OPTIONS)],
        ]);

        $user = $request->user();
        $user->forceFill(['message_edit_minutes' => (int) $validated['message_edit_minutes']])->save();

        return response()->json(['user' => $this->userData($user)]);
    }

    public function updateProfilePicture(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'profile_picture' => ['required', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:5120'],
        ]);

        $user = $request->user();
        $file = $validated['profile_picture'];

        if ($user->profile_picture_path) {
            $previousPicturePath = $user->profile_picture_path;
        }

        $fileName = sprintf('profile-pictures/%s/%s.%s', $user->id, md5(uniqid((string) $user->id, true)), strtolower($file->getClientOriginalExtension() ?: 'jpg'));
        $storedPath = $file->storeAs('', $fileName, ['disk' => 'public']);
        if (! $storedPath) {
            return response()->json(['message' => 'The profile picture could not be saved. Check the public storage configuration.'], 500);
        }

        $user->update(['profile_picture_path' => $storedPath]);
        if (isset($previousPicturePath)) {
            Storage::disk('public')->delete($previousPicturePath);
        }

        return response()->json([
            'message' => 'Profile picture updated.',
            'user' => $this->userData($user->fresh()),
        ]);
    }

    public function removeProfilePicture(Request $request): JsonResponse
    {
        $user = $request->user();
        $previousPicturePath = $user->profile_picture_path;
        if ($previousPicturePath) {
            $user->update(['profile_picture_path' => null]);
            Storage::disk('public')->delete($previousPicturePath);
        }

        return response()->json([
            'message' => 'Profile picture removed.',
            'user' => $this->userData($user->fresh()),
        ]);
    }

    public function profilePicture(User $user)
    {
        abort_unless(
            $user->profile_picture_path &&
                Storage::disk('public')->exists($user->profile_picture_path),
            404
        );

        return Storage::disk('public')->response(
            $user->profile_picture_path,
            null,
            ['Cache-Control' => 'public, max-age=86400'],
            'inline'
        );
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($domain === '') {
            return Str::limit($email, 2, '***');
        }

        $maskedLocal = mb_strlen($local) <= 2
            ? mb_substr($local, 0, 1).'*'
            : mb_substr($local, 0, 1).str_repeat('*', min(4, mb_strlen($local) - 2)).mb_substr($local, -1);

        $dot = strrpos($domain, '.');
        $maskedDomain = $dot === false
            ? mb_substr($domain, 0, 1).'***'
            : mb_substr($domain, 0, 1).'***'.substr($domain, $dot);

        return $maskedLocal.'@'.$maskedDomain;
    }

    private function userData(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'email' => $user->email,
            'dob' => $user->dob?->toDateString(),
            'avatar_url' => $user->avatar_url,
            'message_edit_minutes' => (int) ($user->message_edit_minutes ?? User::DEFAULT_MESSAGE_EDIT_MINUTES),
        ];
    }
}
