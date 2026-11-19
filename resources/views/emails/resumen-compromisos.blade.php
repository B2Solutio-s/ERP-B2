<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Resumen de compromisos</title></head>
<body style="margin:0;padding:0;background:#ffffff;font-family:Arial,Helvetica,sans-serif;color:#1c1c1c;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="padding:24px 16px;">
<table role="presentation" width="680" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:680px;">
    <tr><td style="padding:0 0 12px 0;font-size:18px;font-weight:bold;color:#2b2b40;">B2 · Recursos Humanos · Resumen de compromisos</td></tr>
    <tr><td style="font-size:14px;line-height:1.55;">
        <p style="margin:0 0 14px 0;">Estimados, este es el resumen de compromisos de ejecutivos al <strong>{{ $fecha }}</strong>.</p>

        @foreach ([['Vencidos sin evaluar', $vencidos, '#be123c'], ['Por vencer en los próximos días', $porVencer, '#b45309']] as [$titulo, $lista, $color])
            @if ($lista->isNotEmpty())
                <p style="margin:18px 0 6px 0;font-size:14px;font-weight:bold;color:{{ $color }};">{{ $titulo }} ({{ $lista->count() }})</p>
                <table role="presentation" width="100%" cellpadding="6" cellspacing="0" border="0" style="border-collapse:collapse;font-size:13px;">
                    <tr style="background:#eef0f7;text-align:left;">
                        <th style="border:1px solid #d9dce8;">Ejecutivo</th>
                        <th style="border:1px solid #d9dce8;">Compromiso</th>
                        <th style="border:1px solid #d9dce8;white-space:nowrap;">Plazo</th>
                        <th style="border:1px solid #d9dce8;">Meta</th>
                        <th style="border:1px solid #d9dce8;">Estado</th>
                    </tr>
                    @foreach ($lista as $n)
                        @php($r = $n['registro'])
                        <tr>
                            <td style="border:1px solid #d9dce8;">{{ $n['trabajador'] }}</td>
                            <td style="border:1px solid #d9dce8;">{{ $r->titulo }}</td>
                            <td style="border:1px solid #d9dce8;white-space:nowrap;">{{ $r->fecha_desde->format('d/m') }} – {{ $r->fecha_hasta->format('d/m/Y') }}</td>
                            <td style="border:1px solid #d9dce8;">{{ collect([$r->meta_q ? $r->meta_q.' Q' : null, $r->meta_sots ? $r->meta_sots.' SOT' : null])->filter()->implode(' · ') ?: '—' }}</td>
                            <td style="border:1px solid #d9dce8;color:{{ $color }};font-weight:bold;">{{ $n['plazo']['texto'] }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        @endforeach

        <p style="margin:18px 0 0 0;color:#64748b;font-size:12px;">Aviso automático del ERP de B2, enviado cada día a las 8:00 a. m. solo cuando hay compromisos por atender. Responder a este correo llega a Recursos Humanos.</p>
    </td></tr>
</table>
</td></tr></table>
</body>
</html>
