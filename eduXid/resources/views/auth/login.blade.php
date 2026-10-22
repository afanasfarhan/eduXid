<x-guest-layout>
    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <!-- Email Address -->
<div class="flex justify-center mb-6">
    <img src="{{ asset('img/eduxid-horizontal.png') }}" alt="eduXid Login" class="h-16 w-auto">
</div>
 
<div>
     <x-input-label for="login_id" value="{{ __('Login ID') }}" />
     <x-text-input id="login_id" class="block mt-1 w-full" type="text" name="login_id" :value="old('login_id')" required autofocus autocomplete="username" placeholder="Masukkan NIP/NIK untuk GTK, NISN untuk Murid" />
     <x-input-error :messages="$errors->get('login_id')" class="mt-2" />
 </div>
        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />

            <x-text-input id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="current-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Remember Me -->
        <div class="block mt-4">
            <label for="remember_me" class="inline-flex items-center">
                <input id="remember_me" type="checkbox" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" name="remember">
                <span class="ms-2 text-sm text-gray-600">{{ __('Remember me') }}</span>
            </label>
        </div>

        <div class="flex items-center justify-end mt-4">

            <x-primary-button class="ms-3">
                {{ __('Log in') }}
            </x-primary-button>
        </div>
    </form>

    <!-- Gerbang khusus SISWA: LMS Siswa (Cloud Login) -->
    <div class="mt-5 border-t border-gray-200 pt-4 text-center">
        <a href="{{ route('pwa.cloud.login') }}"
           class="inline-flex items-center gap-1.5 text-sm font-semibold text-indigo-600 hover:text-indigo-500 transition">
            🎓 Murid? Masuk ke LMS Murid →
        </a>
        <p class="text-xs text-gray-400 mt-1">Login di halaman ini tetap berlaku untuk GTK & Admin.</p>
    </div>
</x-guest-layout>