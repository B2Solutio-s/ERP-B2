---
name: erp-frontend
description: Sistema de diseno visual del ERP (erp-b2) — paleta de colores, sidebar oscuro, topbar, tarjetas, inputs, botones, badges y wizard/stepper, basado en la referencia visual tipo "Mystic admin". Usar junto con la skill "componente-livewire" siempre que se cree o restyle UI/frontend en este proyecto (layouts, sidebar, formularios multi-paso, dashboards).
---

# Sistema de diseno frontend (erp-b2)

Esta skill documenta el lenguaje visual que debe seguir el frontend del ERP, tomado de una referencia de tema tipo "Mystic admin" (sidebar oscuro + acento azul + tarjetas blancas). Es el complemento visual de [componente-livewire](../componente-livewire/SKILL.md): esa skill dicta la estructura del componente (clase Livewire, atomos, validacion); esta dicta como debe verse.

**Los valores hexadecimales de abajo son una aproximacion visual tomada de una captura de referencia, no del CSS fuente del tema.** Si el usuario provee la hoja de estilos original o mas capturas, ajusta los tokens antes de darlos por definitivos.

## Paleta de referencia

| Uso | Aproximado | Notas |
|---|---|---|
| Sidebar (fondo) | `#2b2b40` | navy/indigo muy oscuro |
| Sidebar (item activo) | `#363a54` | fondo del row activo, con barra/acento a la izquierda |
| Fondo de pagina (body) | `#f4f5f9` | gris muy claro, casi lavanda |
| Primario / acento (botones, pasos activos, links) | `#4e73f8` | azul vivido, no el indigo-600 que usa hoy `components.css` |
| Exito (badges, "STRONG", checks) | `#1bc943` (o `#22c55e`) | verde |
| Texto sobre fondo claro | `#1f2937` / `#64748b` | slate-800 / slate-500, igual que ya se usa |
| Input (fondo) | `#eef0f7` | gris claro, sin borde visible, radius grande |
| Tarjeta (fondo) | `#ffffff` | radius 2xl, `shadow-sm`, sin borde marcado |

Esto es un cambio de paleta respecto a lo que ya existe en `resources/css/components.css` (que usa `indigo-600`/`slate-*`). **No mezclar las dos paletas** dentro de una misma pantalla: si el usuario pide aplicar este estilo, migrar los tokens relevantes en `@theme` (ver abajo), no anadir una segunda paleta en paralelo.

## Donde vive cada cosa (Tailwind 4, CSS-first)

Este proyecto usa Tailwind 4 con `@theme` en `resources/css/app.css` (no hay `tailwind.config.js`). Para introducir esta paleta como tokens reutilizables (`bg-primary`, `text-primary`, etc.), extender el bloque `@theme`:

```css
@theme {
    --color-primary: #4e73f8;
    --color-primary-content: #ffffff;
    --color-sidebar: #2b2b40;
    --color-sidebar-active: #363a54;
    --color-surface: #ffffff;
    --color-app-bg: #f4f5f9;
    --color-input-bg: #eef0f7;
    --color-success: #1bc943;
}
```

Los patrones visuales reutilizables (tarjeta, sidebar, stepper, badge) van como clases en `resources/css/components.css` dentro de `@layer components`, siguiendo el naming ya existente (`.card-panel`, `.stat-tile`, `.badge-*`) — no como utilidades Tailwind repetidas inline en el Blade (ver regla de [componente-livewire](../componente-livewire/SKILL.md)).

## Patrones de componentes de la referencia

- **Sidebar**: fondo oscuro (`--color-sidebar`), logo arriba, items de menu con icono + texto, submenus indentados. El item/submenu activo lleva fondo `--color-sidebar-active` y un acento (borde o texto) en `--color-primary`.
- **Topbar**: fondo blanco, buscador en pill gris claro, iconos de accion (notificaciones con badge rojo, mensajes), avatar circular a la derecha.
- **Tarjeta (`card-panel`)**: fondo blanco, `rounded-2xl`, `shadow-sm`, sin borde marcado (o `ring-1 ring-slate-100` muy sutil). Titulo de tarjeta en texto oscuro, parte del titulo puede ir en negrita para jerarquia ("Wizard Form | **Style - 1**").
- **Inputs**: fondo `--color-input-bg`, sin borde visible, `rounded-lg`, label en negrita arriba, texto de ayuda (ej. "STRONG") alineado a la derecha del label en `--color-success`.
- **Botones**: primario = fondo `--color-primary`, texto blanco, `rounded-lg`. Secundario ("Back") = fondo blanco/gris muy claro, texto slate, sin fondo fuerte.
- **Wizard / stepper** — hay 4 variantes en la referencia; el proyecto usa **Estilo 3 (tabs con circulo + label)** como default, implementado en `ui/wizard-steps.blade.php` (`@props(['pasos', 'actual'])`): una barra `wizard-tabs` con un segmento por paso, cada uno con circulo numerado + label del paso visible (no oculto detras de una leyenda separada). El paso activo es un segmento entero en `--color-primary` con circulo de borde blanco; los pasos completados muestran un check en circulo `--color-success`; el resto queda en gris. Para 6+ pasos con labels largos, la barra hace `overflow-x-auto` (scroll horizontal) en vez de recortar o apilar texto — ver `onboarding-form.blade.php` (formulario de 7 pasos) como referencia real.
  - Otras variantes documentadas por si se pide explicitamente un estilo distinto:
    - *Estilo 1 (tabs con flecha tipo breadcrumb)*: paso activo con texto/borde inferior en `--color-primary`, resto en gris, separadores en punta de flecha.
    - *Estilo 2 (circulos minimalistas sin label)*: circulos numerados conectados por linea horizontal, sin texto junto al circulo — el paso actual se indica aparte (ej. leyenda "Paso X de Y").
    - *Estilo 4 (panel lateral solido)*: columna lateral con fondo `--color-primary`, cada paso es circulo blanco + label, el paso activo tiene una pill/fondo mas claro alrededor de todo el row.
  - Extraer siempre el stepper como su propio componente (nunca repetir el markup en cada formulario multi-paso) — coherente con la regla de "extraer si se repite 2+ veces" de componente-livewire.

## Verificacion

Despues de aplicar estos tokens/estilos, levantar con Sail (`./vendor/bin/sail up -d`) y revisar visualmente en `localhost:8000` que no queden mezclados estilos de la paleta vieja (indigo/slate) con la nueva (`--color-primary` azul). Si el usuario solo pidio la skill (no la migracion visual completa), no aplicar los cambios de `@theme`/`components.css` de forma no solicitada — usar esta guia solo cuando se pida trabajo de frontend/UI.
