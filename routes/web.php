<?php

use App\Models\AsistenciaDescuento;
use App\Models\CandidatoDocumento;
use App\Models\EmpleadoDocumentoCese;
use App\Models\EmpleadoDocumentoContrato;
use App\Models\User;
use App\Models\Vacacion;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return redirect()->route(Auth::check() ? Auth::user()->rutaInicial() : 'login');
});

Route::livewire('/alta/{token}', 'onboarding-form')->name('onboarding.form');

Route::get('/alta-enviado', function () {
    return view('onboarding-enviado', ['nombre' => session('onboarding_nombre', '')]);
})->name('onboarding.enviado');

Route::livewire('/login', 'auth.login-form')->name('login')->middleware('guest');

Route::post('/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');

Route::livewire('/panel/perfil', 'panel.perfil')->name('perfil')->middleware('auth');

Route::get('/perfil/foto/{usuario}', function (User $usuario) {
    abort_unless(Auth::id() === $usuario->id || Auth::user()->esAdmin(), 403);
    abort_unless($usuario->tieneFoto() && Storage::disk('local')->exists($usuario->foto_ruta), 404);

    return Storage::disk('local')->response($usuario->foto_ruta, 'perfil.jpg', ['Cache-Control' => 'private, max-age=86400', 'X-Content-Type-Options' => 'nosniff']);
})->name('perfil.foto')->middleware('auth');

Route::livewire('/panel/control-gth', 'panel.control-gth')->name('control-gth')->middleware('auth');

Route::livewire('/panel/control-ingresos', 'panel.control-ingresos')->name('control-ingresos')->middleware('auth');

Route::livewire('/panel/gestion-candidatos', 'panel.gestion-candidatos')->name('gestion-candidatos')->middleware('auth');
Route::livewire('/panel/proceso-capacitacion', 'panel.proceso-capacitacion')->name('proceso-capacitacion')->middleware('auth');

Route::livewire('/panel/catalogos', 'panel.catalogos')->name('catalogos')->middleware('auth');

Route::livewire('/panel/asistencia', 'panel.asistencia')->name('asistencia')->middleware('auth');

Route::livewire('/panel/asistencia-mensual', 'panel.asistencia-mensual')->name('asistencia-mensual')->middleware('auth');

Route::livewire('/panel/vacaciones', 'panel.vacaciones')->name('vacaciones')->middleware('auth');
Route::livewire('/panel/delivery-ejecutivos', 'panel.delivery-ejecutivos')->name('delivery-ejecutivos')->middleware('auth');

Route::get('/panel/vacaciones/documentos/{vacacion}', function (Vacacion $vacacion) {
    abort_unless(Auth::user()->esAdmin() && $vacacion->documento_ruta, 403);

    return Storage::disk('local')->response($vacacion->documento_ruta, $vacacion->documento_nombre);
})->name('vacaciones.documento')->middleware('auth');

Route::get('/panel/asistencia-mensual/sustentos/{descuento}', function (AsistenciaDescuento $descuento) {
    abort_unless(Auth::user()->esAdmin() && $descuento->sustento_ruta, 403);

    return Storage::disk('local')->response($descuento->sustento_ruta, $descuento->sustento_nombre);
})->name('asistencia.sustento')->middleware('auth');

Route::livewire('/panel/resultados-campana', 'panel.resultados-campana')->name('resultados-campana')->middleware('auth');

Route::livewire('/panel/usuarios', 'panel.usuarios')->name('usuarios')->middleware('auth');

Route::livewire('/panel/registro-ingresos', 'panel.registro-ingresos')->name('registro-ingresos')->middleware('auth');

Route::get('/panel/registro-ingresos/documentos/{documento}', function (CandidatoDocumento $documento) {
    abort_unless(Auth::user()->esAdmin() || Auth::user()->esGth(), 403);

    return Storage::disk('local')->response($documento->ruta, $documento->nombre_original);
})->name('registro-ingresos.documento')->middleware('auth');

Route::get('/panel/seguimiento/documentos/{registro}', function (App\Models\SeguimientoRegistro $registro) {
    abort_unless(Auth::user()->esAdmin() || Auth::user()->esGth(), 403);

    return Storage::disk('local')->response($registro->documento_ruta, $registro->documento_nombre);
})->name('seguimiento.documento')->middleware('auth');

Route::get('/panel/registro-ingresos/documentos-cese/{documento}', function (EmpleadoDocumentoCese $documento) {
    abort_unless(Auth::user()->esAdmin(), 403);

    return Storage::disk('local')->response($documento->ruta, $documento->nombre_original);
})->name('registro-ingresos.documento-cese')->middleware('auth');

Route::get('/panel/registro-ingresos/contratos-firmados/{documento}', function (EmpleadoDocumentoContrato $documento) {
    abort_unless(Auth::user()->esAdmin(), 403);

    return Storage::disk('local')->response($documento->ruta, $documento->nombre_original);
})->name('registro-ingresos.documento-contrato')->middleware('auth');
