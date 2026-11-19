# ERP B2 — reglas para agentes de código (Codex, Claude Code, etc.)

ERP de RRHH de B2 (Laravel 12 + Livewire 4, MySQL, Docker/Sail). **Está en PRODUCCIÓN** desde 2026-10-14, con datos reales de personal, asistencia y contratos. App: http://192.168.1.53:8070. Responde y escribe la interfaz en español.

Este archivo contiene solo lo que NO se deduce leyendo el código. Si algo aquí contradice una orden directa del usuario, manda el usuario.

## 1. Producción: lo que nunca se hace sin pedirlo
- Nunca `migrate:fresh`, `db:wipe`, `migrate:rollback`, ni borrar/editar datos en bloque sin confirmación explícita.
- Antes de **cualquier migración o cambio de datos**: `bash scripts/backup.sh` (respaldo de la BD y de `storage/app`). Migrar con `php artisan migrate --force`.
- `.env` no se sube ni se imprime. No pongas tokens, contraseñas ni correos reales en el código, en los commits ni en la salida.
- No envíes correos reales para probar (hay gerencia@b2s.pe y trabajadores reales entre los destinatarios). Usa `Mail::fake()` / `Queue::fake()`.
- No hagas `git push --force`, `git reset --hard` ni descartes cambios que no sean tuyos.

## 2. Cómo ejecutar comandos (importante)
Todo va dentro del contenedor y **siempre como usuario `sail`**: `docker exec -u sail erp-b2-laravel.test-1 <comando>`. Sin `-u sail` se crean archivos de root y el panel responde 500.

| Si cambiaste… | Ejecuta |
|---|---|
| una ruta (`routes/web.php`) | `php artisan route:cache` (si no, el menú lateral falla en TODAS las páginas) |
| `.env` o `config/*` | `php artisan config:cache` y reinicia los contenedores que lo usan |
| una vista Blade / componente Livewire | `php artisan view:clear` |
| JS/CSS o **cualquier clase Tailwind nueva** (aunque sea común) | `npm run build` (`view:clear` no basta) |
| un Job/Mailable nuevo | `docker restart erp-b2-cola-1` |
| un comando programado (`routes/console.php`) | `docker restart erp-b2-programador-1` |

Contenedores: `laravel.test` (web, puerto 8070), `cola` (`queue:work`), `programador` (`schedule:work`; resumen diario 8:00 hora de Lima), `asistencia` (lector del biométrico), `reverb`, `mysql` (solo local, puerto 3310).
Al terminar: `curl -s -o /dev/null -w "%{http_code}" http://localhost:8070/login` debe dar 200 y no deben aparecer `production.ERROR` nuevos en `storage/logs/laravel-AAAA-MM-DD.log`.

## 3. Convenciones de código
- Componentes Livewire 4 de archivo único en `resources/views/components/**` (clase PHP + Blade). Antes de crear UI lee `.claude/skills/componente-livewire/SKILL.md` y `.claude/skills/erp-frontend/SKILL.md` (son markdown normal).
- Nombres de dominio, propiedades, métodos y textos en **español**. Reutiliza los átomos (`x-forms.*`, `x-ui.*`); los estilos repetidos van como clases en `resources/css/components.css`, no como cadenas de Tailwind sueltas.
- El título del módulo ya lo pinta el topbar (`#[Layout('layouts.panel', ['title' => '…'])]`): no repitas un `<h1>` igual dentro.
- Mensajes de validación en español ya son globales (`lang/es`). Si agregas un campo con nombre propio, añade su etiqueta en `lang/es/validation.php › attributes`.
- Trampas de Livewire/Blade en este proyecto:
  - **Nunca** un bloque `@php … @endphp` de varias líneas en la vista de un componente (rompe con "unexpected token endif"). Usa `@php(expr)` de una línea o un método de la clase.
  - Para guardar al salir de un campo usa `wire:model.live.blur` (`wire:model.blur` no envía nada).
  - Un texto de `wire:confirm` se calcula al dibujar: si depende de lo marcado, el campo debe usar `wire:model.live`.
  - Los campos que guarda JavaScript (comentarios de asistencia) van con `wire:ignore` y métodos `#[Renderless]`; si no, un refresco de la página borra lo que se está escribiendo.
  - Evita Alpine (`x-data`, `x-show`, `@click`) y confía en Livewire/CSS: ya falló varias veces en componentes que se redibujan.
- Fechas: la app guarda en UTC; las fechas de negocio son **America/Lima**.
- Acceso a módulos nuevos: siempre Admin **más** el rol que el usuario nombre. Valida en `mount()` y en cada acción que escribe, no solo ocultando el menú.

## 4. Reglas de negocio y seguridad que no se negocian
- **GTH nunca ve remuneración, movilidad, sueldos de empleos anteriores, descuentos ni montos de pago**, en ninguna pantalla, ficha, PDF o exportación. Ocúltalos con `! Auth::user()->esAdmin()` y valida también en el servidor. (Ya aplicado en `FichaCompleta`, `panel/ficha-ingreso`, `pdf/ficha-ingreso`, `registro-ingresos`.)
- La pestaña **Contrato** del modal «Datos de…» es solo Admin (GTH recibe 403). La pestaña **Seguimiento** y el módulo «Seguimiento de ejecutivos» son Admin + GTH; GTH no puede cambiar el correo de gerencia.
- Los documentos de Seguimiento viven en `storage/app/private/seguimiento` (disco `local`, privado) y se sirven solo por rutas con control de rol.

## 5. Integración con el CRM (otro sistema)
- El CRM está en `/home/b2admin/apps/crm-forza` (Laravel, también en producción, puerto 8080, rama `develop`, **tiene cambios sin commit de otras personas: no los toques**). Sus comandos van dentro de `crm-forza-app-1` (`docker exec -e HOME=/tmp …`) y tiene caché de config y rutas.
- El ERP solo **lee** el CRM por el endpoint `GET /api/erp/delivery-resumen` (token Bearer `CRM_API_TOKEN`) mediante `App\Services\CrmDelivery`. Nunca recalcules las cifras del CRM en el ERP ni leas su base de datos directamente.
- Vínculo ejecutivo CRM ↔ trabajador del ERP: tabla `crm_ejecutivos`. La llave razonable es el «Correo corporativo» del CRM = `empleados.email_corporativo` (el correo de inicio de sesión del CRM no sirve para cruzar).

## 6. Pruebas
- **NO ejecutes `php artisan test` / `phpunit` en este entorno.** La config está en caché (`bootstrap/cache/config.php`) y puede ignorar la base `testing` de `phpunit.xml`; la única prueba que existe (`tests/Feature/ReclutamientoCandidatosTest.php`) usa `RefreshDatabase`, que borraría la base de PRODUCCIÓN. Esa prueba además quedó obsoleta (la grilla de reclutamiento se eliminó).
- Para probar usa un script en `storage/app/_t.php` ejecutado con `php artisan tinker --execute="include storage_path('app/_t.php');"`, dentro de `DB::beginTransaction()` … `DB::rollBack()` (en un `try/finally`), usando `Livewire::test()`. Borra el script al terminar.
- En esos scripts `assertSee` falla (pide configuración de PHPUnit): compara con `str_contains($t->html(), '…')`. `Livewire::test()` tampoco detecta si el navegador no envía una petición (por ejemplo `wire:model.blur`); si algo depende del navegador, pide al usuario que lo confirme.

## 7. Git
- Haz commit solo cuando el usuario lo pida. Mensajes en inglés, estilo de los commits recientes (`git log --oneline`).
- Las imágenes sueltas de la raíz (`rrhh (2).png`, `logo svg b2 blanco.svg`) están en `.gitignore` a propósito: no las toques ni las subas. Tampoco subas archivos con datos personales (`/*.xlsx`, `.docx`, `.pdf`). Si `git status` muestra algo sin seguimiento que no creaste tú, ignóralo y avisa; no es motivo para detenerte.
- **Un solo agente editando a la vez.** Antes de cambiar de herramienta deja el árbol limpio con un commit, y trabaja en una rama (`git switch -c nombre`) para poder comparar con `git diff` y deshacer.
