<?php

namespace Tests\Feature;

use App\Models\CandidatoReclutamiento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReclutamientoCandidatosTest extends TestCase
{
    use RefreshDatabase;

    public function test_puede_crear_un_candidato_en_la_base_de_reclutamiento(): void
    {
        $candidato = CandidatoReclutamiento::create([
            'anio' => 2026,
            'mes_tipologia' => 'CAMPAÑA',
            'mes' => 'SEPTIEMBRE',
            'dia' => 15,
            'fecha_gestion' => '2026-09-15',
            'base' => 'BASE',
            'agente_reclutador' => 'ANGELICA',
            'campana' => 'MÓVIL',
            'dni_ce' => '12345678',
            'fecha_nacimiento' => '1995-04-10',
            'edad' => 31,
            'nombres' => 'Juan Pérez',
            'numero_celular' => '987654321',
            'distrito' => 'San Miguel',
            'experiencia' => '2 años',
            'observaciones' => 'Llamada inicial',
            'tipificacion' => 'INTERESADO - APTO',
            'subtipificacion_rechazo' => 'NO CONTESTA',
            'fuente' => 'WhatsApp',
            'aceptacion_entrevista' => 'SI',
            'fecha_entrevista' => '2026-09-20',
            'asistio_entrevista' => 'SI',
            'fecha_ingreso' => null,
        ]);

        $this->assertDatabaseHas('reclutamiento_candidatos', [
            'id' => $candidato->id,
            'dni_ce' => '12345678',
            'nombres' => 'Juan Pérez',
        ]);
    }

    public function test_crea_un_candidato_con_fechas_vacias_como_nulos(): void
    {
        $candidato = CandidatoReclutamiento::create([
            'anio' => 2026,
            'mes_tipologia' => 'CAMPAÑA',
            'mes' => 'SEPTIEMBRE',
            'dia' => 15,
            'fecha_gestion' => '',
            'base' => 'BASE',
            'agente_reclutador' => 'ANGELICA',
            'campana' => 'MÓVIL',
            'dni_ce' => '87654321',
            'fecha_nacimiento' => '',
            'edad' => 31,
            'nombres' => 'Ana García',
            'numero_celular' => '987654322',
            'distrito' => 'San Miguel',
            'experiencia' => '1 año',
            'observaciones' => 'Sin gestión',
            'tipificacion' => 'NO INTERESADO',
            'subtipificacion_rechazo' => 'NO CONTESTA',
            'fuente' => 'WhatsApp',
            'aceptacion_entrevista' => 'NO',
            'fecha_entrevista' => '',
            'asistio_entrevista' => 'NO',
            'fecha_ingreso' => null,
        ]);

        $this->assertDatabaseHas('reclutamiento_candidatos', [
            'id' => $candidato->id,
            'dni_ce' => '87654321',
            'nombres' => 'Ana García',
        ]);
        $this->assertNull($candidato->refresh()->fecha_gestion);
        $this->assertNull($candidato->refresh()->fecha_entrevista);
        $this->assertNull($candidato->refresh()->fecha_capacitacion);
    }

    public function test_detecta_duplicados_por_dni_o_nombre_y_telefono(): void
    {
        $original = CandidatoReclutamiento::create([
            'anio' => 2026,
            'mes_tipologia' => 'CAMPAÑA',
            'mes' => 'SEPTIEMBRE',
            'dia' => 15,
            'fecha_gestion' => '2026-09-15',
            'base' => 'BASE',
            'agente_reclutador' => 'ANGELICA',
            'campana' => 'MÓVIL',
            'dni_ce' => '12345678',
            'fecha_nacimiento' => '1995-04-10',
            'edad' => 31,
            'nombres' => 'Juan Pérez',
            'numero_celular' => '987654321',
            'distrito' => 'San Miguel',
            'experiencia' => '2 años',
            'observaciones' => 'Llamada inicial',
            'tipificacion' => 'INTERESADO - APTO',
            'subtipificacion_rechazo' => 'NO CONTESTA',
            'fuente' => 'WhatsApp',
            'aceptacion_entrevista' => 'SI',
            'fecha_entrevista' => '2026-09-20',
            'asistio_entrevista' => 'SI',
            'fecha_ingreso' => null,
        ]);

        $duplicate = new CandidatoReclutamiento([
            'dni_ce' => '12345678',
            'nombres' => 'Juan Pérez',
            'numero_celular' => '987654321',
        ]);

        $this->assertSame($original->id, $duplicate->findDuplicateByIdentity()?->id);
    }
}
