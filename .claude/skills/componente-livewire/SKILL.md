---
name: componente-livewire
description: Genera un nuevo componente Livewire 4 de archivo unico para el ERP (erp-b2), siguiendo sus convenciones establecidas — atomos reutilizables, CSS centralizado en components.css, naming de dominio en espanol. Usar cuando se pida crear un formulario, pagina del panel o componente de UI nuevo en este proyecto.
---

# Crear un componente Livewire (erp-b2)

Este proyecto usa componentes Livewire 4 de archivo único: cada uno vive directamente en `resources/views/components/**/*.blade.php`, sin carpeta `app/Livewire`. El archivo combina una clase PHP inline (`<?php ... new class extends Component { ... }; ?>`) seguida del markup Blade. Ejemplos de referencia: `resources/views/components/onboarding-form.blade.php` (página pública con formulario largo), `resources/views/components/auth/login-form.blade.php` (login), `resources/views/components/panel/dashboard.blade.php` (página autenticada del panel).

## Antes de generar código

Pregunta o infiere:
1. **Nombre y ubicación** (dot-path que usará `Route::livewire`): ¿es una página completa enrutable (`panel.algo`, `auth.algo`) o un átomo reutilizable (`ui.algo`, `forms.algo`)? Las páginas van bajo su carpeta de módulo (`panel/`, `auth/`); los átomos reutilizables van bajo `ui/` o `forms/`.
2. **¿Requiere autenticación?** Si es una página del panel, usa `#[Layout('layouts.panel')]` en la clase y `middleware('auth')` en la ruta.
3. **Campos/datos que maneja** y sus reglas de validación.

## Convenciones a seguir SIEMPRE

- **Idioma**: nombres de propiedades, métodos y mensajes de usuario en español (`nombres`, `apellido_paterno`, `guardar()`, `generarInvitacion()`), igual que el resto del código (`app/Models/Empleado.php`, `onboarding-form.blade.php`). Los nombres de clases/atributos del framework (`Component`, `#[Validate]`, `#[Layout]`) van en inglés porque son de Livewire/Laravel.
- **Reutiliza antes de escribir markup nuevo**: para formularios usa siempre `<x-forms.section title="...">` + `<x-forms.field label="..." name="..." type="..." :span="true" />` en vez de escribir `<label>`/`<input>`/`@error` a mano. Para selects usa `<x-forms.select label="..." name="..." :options="[...]" />`, para texto largo `<x-forms.textarea label="..." name="..." />`. Para tarjetas usa `<x-ui.card>` / `<x-ui.card centered>`. Para estados usa `<x-ui.badge color="green|amber|slate">`. Para formularios con varias pantallas usa `<x-ui.wizard-steps :pasos="[...]" :actual="$paso" />` (ver `onboarding-form.blade.php`). Para secciones donde el usuario agrega/quita filas repetidas (estudios, empleos, familiares) usa `<x-ui.repeater-card titulo="..." onRemove="metodoQuitar({{ $indice }})" :puede-quitar="count($array) > 1" wire:key="prefijo-{{ $indice }}">` — el `wire:key` es obligatorio en cada fila para que Livewire no confunda inputs al agregar/quitar.
- **No repitas Tailwind inline**: si un patrón visual se repite (una tarjeta de stat, una tabla, un badge nuevo, un layout de página), no lo escribas como cadena de utilidades sueltas en el Blade. Añade una clase a `resources/css/components.css` dentro del bloque `@layer components` (sigue el naming existente: `.stat-tile`, `.data-table`, `.card-panel`, `.form-input`) y referencia esa clase por nombre. La paleta de colores (`--color-primary`, `--color-sidebar`, etc.) vive en `resources/css/app.css` dentro de `@theme` — ver la skill [erp-frontend](../erp-frontend/SKILL.md).
- **Si un bloque de markup se repite 2+ veces dentro del componente nuevo**, extráelo a su propio archivo bajo `ui/`, `forms/` o `panel/` con `@props([...])`, en vez de copiar/pegar. Este proyecto prohíbe explícitamente el spaghetti/duplicación — mira `forms/field.blade.php` y `forms/section.blade.php` como el patrón a imitar. Si un componente hijo tiene un único elemento raíz, reenvía `$attributes` a ese elemento (`{{ $attributes->class([...]) }}`) para que atributos como `wire:key` no se pierdan.
- **Validación — caso simple (formulario de un solo paso)**: atributos `#[Validate('regla|regla2')]` sobre cada propiedad pública, y `$this->validate()` al inicio del método que procesa el submit.
- **Validación — caso wizard (formulario multi-paso)**: NO uses atributos `#[Validate]` (no permiten validar solo el paso actual). En su lugar, define un método `reglasPaso(int $paso): array` con un `match` que devuelve el array de reglas por paso, y un método `siguiente()` que hace `$this->validate($this->reglasPaso($this->paso))` antes de avanzar. **Importante:** si un paso no tiene reglas (ej. una sección opcional), `reglasPaso()` devuelve `[]` — no llames a `$this->validate([])` en ese caso, Livewire lanza `MissingRulesException` al recibir un array vacío (busca `$rules`/`rules()` como fallback). Salta la llamada a `validate()` cuando el array esté vacío. En el submit final, junta todas las reglas de todos los pasos (`array_merge`) y valida una sola vez. Ver `onboarding-form.blade.php` (`reglasPaso()`, `siguiente()`, `todasLasReglas()`) como referencia — este bug se detectó y corrigió ahí.
- **Arrays dinámicos (repeaters)**: guarda cada fila como un array asociativo (`public array $estudios = [];`), inicializa con una fila vacía en `mount()`, y usa `wire:model="estudios.{{ $indice }}.campo"` — los átomos `x-forms.field`/`x-forms.select` aceptan nombres con notación de punto sin cambios. Antes de persistir con `createMany()`, normaliza cadenas vacías `''` a `null` fila por fila (MySQL en modo estricto rechaza `''` en columnas `date`/`int` nullable) y descarta filas completamente vacías — ver el método `filasConDatos()` en `onboarding-form.blade.php`.
- **Botones con estado de carga**: usa `wire:loading.attr="disabled"` en el botón más `<span wire:loading.remove>Texto</span><span wire:loading>Cargando...</span>` en vez de un solo texto estático.
- **Layout de página completa** (no autenticada): envuelve con `<div class="page-shell"><div class="page-container">...</div></div>` (ver `login-form.blade.php`). Si el formulario es ancho (muchos campos, wizard), usa `<div class="page-shell"><div class="mx-auto max-w-3xl">...</div></div>` en vez de `page-container` (que está fijo a `max-w-2xl`). Las páginas del panel ya vienen envueltas por `layouts.panel` vía `#[Layout('layouts.panel')]`, no dupliques ese wrapper.

## Registrar la ruta (si es una página)

Añade en `routes/web.php`:
```php
Route::livewire('/ruta', 'dot.path.al.componente')->name('nombre.ruta')->middleware('auth');
```
Omite `->middleware('auth')` si debe ser pública (como `/alta/{token}` o `/login`).

## Verificación

Después de crear el componente, levanta la app con Sail (`./vendor/bin/sail up -d`) y confírmalo visitando la ruta en `localhost:8000`. Si no hay entorno visual disponible para probarlo, dilo explícitamente en vez de asumir que funciona.

Este proyecto usa **PHPUnit clásico** (clases que extienden `Tests\TestCase`), no Pest, aunque `pestphp/pest-plugin` aparezca en `composer.json` (es solo un allow-plugin heredado, no está instalado el framework). Además `phpunit.xml` apunta a una base de datos `testing` que no existe en el contenedor MySQL de Sail — antes de confiar en `sail artisan test`, confirma que esa base exista o créala.

Para lógica no trivial (wizards, validación por pasos, repeaters), la forma más rápida de verificar sin depender de un navegador es usar `Livewire\Livewire::test('nombre.componente', [...])` dentro de una transacción que se revierte, para no ensuciar los datos de desarrollo:

```php
DB::beginTransaction();
try {
    $test = Livewire::test('onboarding-form', ['token' => $invitacion->token]);
    $test->set('campo', 'valor')->call('siguiente')->assertHasNoErrors()->assertSet('paso', 2);
    // ...
} finally {
    DB::rollBack();
}
```

Esto se puede correr por stdin de `sail artisan tinker`, pero **tinker no acepta un archivo completo con `<?php` por pipe directo** (el REPL lo interpreta mal). En su lugar escribe el script en un archivo dentro del proyecto (ej. `storage/app/_test.php`, montado por el volumen de Sail) y ejecútalo con `sail artisan tinker --execute="include storage_path('app/_test.php');"`; borra el archivo temporal al terminar.
