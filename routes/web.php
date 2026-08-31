<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(Auth::check() ? 'panel' : 'login');
});

Route::livewire('/alta/{token}', 'onboarding-form')->name('onboarding.form');

Route::livewire('/login', 'auth.login-form')->name('login')->middleware('guest');

Route::post('/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');

Route::livewire('/panel', 'panel.dashboard')->name('panel')->middleware('auth');
