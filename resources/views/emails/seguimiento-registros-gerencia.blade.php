<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Seguimiento de trabajador</title></head>
<body style="margin:0;padding:0;background:#ffffff;font-family:Arial,Helvetica,sans-serif;color:#1c1c1c;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="padding:24px 16px;">
<table role="presentation" width="680" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:680px;">
    <tr><td style="padding:0 0 12px 0;font-size:18px;font-weight:bold;color:#2b2b40;">B2 · Recursos Humanos · Seguimiento</td></tr>
    <tr><td style="font-size:14px;line-height:1.55;">
        <p style="margin:0 0 12px 0;">Estimados, les compartimos {{ $registros->count() === 1 ? 'el siguiente registro' : 'los siguientes '.$registros->count().' registros' }}
            de seguimiento de <strong>{{ $empleado->nombreCompleto() }}</strong> (DNI/CE {{ $empleado->documento_identidad }}).</p>

        @if ($envio->mensaje)
            <p style="margin:0 0 12px 0;padding:8px 10px;background:#f4f5f9;border-left:3px solid #4e73f8;white-space:pre-line;">{{ $envio->mensaje }}</p>
        @endif

        <table role="presentation" width="100%" cellpadding="6" cellspacing="0" border="0" style="border-collapse:collapse;font-size:13px;">
            <tr style="background:#eef0f7;text-align:left;">
                <th style="border:1px solid #d9dce8;">Tipo</th>
                <th style="border:1px solid #d9dce8;">Registro</th>
                <th style="border:1px solid #d9dce8;white-space:nowrap;">Plazo / fecha</th>
                <th style="border:1px solid #d9dce8;">Meta</th>
                <th style="border:1px solid #d9dce8;">Estado</th>
            </tr>
            @foreach ($registros as $fila)
                @php($r = $fila['registro'])
                <tr>
                    <td style="border:1px solid #d9dce8;">{{ $r->esCompromiso() ? 'Compromiso' : 'Memorándum' }}</td>
                    <td style="border:1px solid #d9dce8;">
                        {{ $r->titulo }}
                        @if ($r->descripcion)<br><span style="color:#64748b;">{{ $r->descripcion }}</span>@endif
                        @if ($r->evaluacion_observacion)<br><span style="color:#64748b;">Evaluación: {{ $r->evaluacion_observacion }}</span>@endif
                    </td>
                    <td style="border:1px solid #d9dce8;white-space:nowrap;">
                        {{ $r->fecha_desde->format('d/m/Y') }}@if ($r->esCompromiso() && $r->fecha_hasta) – {{ $r->fecha_hasta->format('d/m/Y') }}@endif
                    </td>
                    <td style="border:1px solid #d9dce8;">{{ collect([$r->meta_q ? $r->meta_q.' Q' : null, $r->meta_sots ? $r->meta_sots.' SOT' : null])->filter()->implode(' · ') ?: '—' }}</td>
                    <td style="border:1px solid #d9dce8;font-weight:bold;">{{ $fila['estado'] }}</td>
                </tr>
            @endforeach
        </table>

        <p style="margin:14px 0 0 0;font-size:13px;color:#475569;">
            Acumulado del trabajador: <strong>{{ $noCumplidos }}</strong> {{ $noCumplidos === 1 ? 'compromiso no cumplido' : 'compromisos no cumplidos' }} y <strong>{{ $memorandums }}</strong> {{ $memorandums === 1 ? 'memorándum' : 'memorándums' }}.
        </p>

        @if ($envio->adjuntar_documentos)
            <p style="margin:8px 0 0 0;font-size:13px;color:#475569;">Los documentos firmados van adjuntos a este correo.</p>
        @endif

        <p style="margin:18px 0 0 0;color:#64748b;font-size:12px;">Envío manual desde el ERP de B2. Responder a este correo llega a Recursos Humanos.</p>
    </td></tr>
</table>
</td></tr></table>
</body>
</html>
