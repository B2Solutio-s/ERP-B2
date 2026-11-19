<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Aviso de seguimiento</title></head>
<body style="margin:0;padding:0;background:#ffffff;font-family:Arial,Helvetica,sans-serif;color:#1c1c1c;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="padding:24px 16px;">
<table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:640px;">
    <tr><td style="padding:0 0 16px 0;font-size:18px;font-weight:bold;color:#2b2b40;">B2 · Recursos Humanos · Aviso</td></tr>
    <tr><td style="font-size:15px;line-height:1.55;">
        @if ($esCompromiso)
            <p style="margin:0 0 14px 0;">Estimados, les informamos que <strong>{{ $empleado->nombreCompleto() }}</strong>
                (DNI/CE {{ $empleado->documento_identidad }}) acumula <strong>{{ $aviso->cantidad }} compromisos no cumplidos</strong>.</p>
        @else
            <p style="margin:0 0 14px 0;">Estimados, les informamos que <strong>{{ $empleado->nombreCompleto() }}</strong>
                (DNI/CE {{ $empleado->documento_identidad }}) acumula <strong>{{ $aviso->cantidad }} memorándums</strong>.
                Con esto <strong>entra en evaluación la continuidad del trabajador</strong>.</p>
        @endif

        <table role="presentation" width="100%" cellpadding="6" cellspacing="0" border="0" style="border-collapse:collapse;font-size:13px;">
            <tr style="background:#eef0f7;text-align:left;">
                <th style="border:1px solid #d9dce8;">{{ $esCompromiso ? 'Compromiso' : 'Memorándum' }}</th>
                <th style="border:1px solid #d9dce8;">{{ $esCompromiso ? 'Plazo' : 'Fecha' }}</th>
                <th style="border:1px solid #d9dce8;">Estado</th>
            </tr>
            @foreach ($registros as $r)
                <tr>
                    <td style="border:1px solid #d9dce8;">
                        {{ $r->titulo }}
                        @if ($esCompromiso && $r->evaluacion_observacion)
                            <br><span style="color:#64748b;">Evaluación: {{ $r->evaluacion_observacion }}</span>
                        @endif
                    </td>
                    <td style="border:1px solid #d9dce8;white-space:nowrap;">
                        {{ $r->fecha_desde->format('d/m/Y') }}@if ($esCompromiso && $r->fecha_hasta) – {{ $r->fecha_hasta->format('d/m/Y') }}@endif
                    </td>
                    <td style="border:1px solid #d9dce8;">{{ $esCompromiso ? 'No cumplido' : 'Registrado' }}</td>
                </tr>
            @endforeach
        </table>

        <p style="margin:18px 0 0 0;color:#64748b;font-size:13px;">Este es un aviso automático del ERP de B2. Responder a este correo llega a Recursos Humanos.</p>
    </td></tr>
</table>
</td></tr></table>
</body>
</html>
