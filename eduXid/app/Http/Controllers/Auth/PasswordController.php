<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $schoolId = trim((string) DB::table('schools')->value('npsn'));
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => [
                'required', Password::min(12)->mixedCase()->numbers()->symbols(), 'confirmed',
                function (string $attribute, mixed $value, \Closure $fail) use ($user, $schoolId) {
                    if (!is_string($value) || $value === (string) $user->login_id
                        || ($schoolId !== '' && $value === $schoolId)
                        || Hash::check($value, (string) $user->password)) {
                        $fail('Password baru harus berbeda dari password lama dan identitas akun/sekolah.');
                    }
                },
            ],
        ]);

        // Perubahan password dan pelepasan kewajiban dilakukan bersamaan.
        // Menghindari loop redirect ketika must_change_password masih true.
        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
        ])->save();

        return back()->with('status', 'password-updated');
    }
}
