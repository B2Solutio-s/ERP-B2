<?php

use App\Models\CandidatoDocumento;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

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

Route::livewire('/panel/proceso-reclutamiento', 'panel.proceso-reclutamiento')->name('proceso-reclutamiento')->middleware('auth');

Route::livewire('/panel/catalogos', 'panel.catalogos')->name('catalogos')->middleware('auth');

Route::livewire('/panel/usuarios', 'panel.usuarios')->name('usuarios')->middleware('auth');

Route::livewire('/panel/registro-ingresos', 'panel.registro-ingresos')->name('registro-ingresos')->middleware('auth');

Route::get('/panel/registro-ingresos/documentos/{documento}', function (CandidatoDocumento $documento) {
    abort_unless(Auth::user()->esAdmin(), 403);

    return Storage::disk('local')->response($documento->ruta, $documento->nombre_original);
})->name('registro-ingresos.documento')->middleware('auth');
