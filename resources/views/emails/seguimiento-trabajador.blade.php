<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $registro->esCompromiso() ? 'Compromiso' : 'Memorándum' }}</title></head>
<body style="margin:0;padding:0;background:#ffffff;font-family:Arial,Helvetica,sans-serif;color:#1c1c1c;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="padding:24px 16px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;">
    <tr><td style="padding:0 0 16px 0;font-size:18px;font-weight:bold;color:#2b2b40;">B2 · Recursos Humanos</td></tr>
    <tr><td style="font-size:15px;line-height:1.55;">
        <p style="margin:0 0 14px 0;font-weight:bold;">Hola {{ $primerNombre }},</p>
        @if ($registro->esCompromiso())
            <p style="margin:0 0 14px 0;">Te enviamos la copia firmada de tu <strong>compromiso</strong>
                «{{ $registro->titulo }}», con plazo del {{ $registro->fecha_desde->format('d/m/Y') }}
                al {{ $registro->fecha_hasta?->format('d/m/Y') }}.</p>
        @else
            <p style="margin:0 0 14px 0;">Te enviamos la copia firmada del <strong>memorándum</strong>
                «{{ $registro->titulo }}», de fecha {{ $registro->fecha_desde->format('d/m/Y') }}.</p>
        @endif
        <p style="margin:0 0 14px 0;">El documento va adjunto en este correo.</p>
        <p style="margin:0 0 14px 0;">Si tienes dudas, escríbenos a rrhh@b2s.pe.</p>
        <p style="margin:18px 0 0 0;">Saludos,<br>Recursos Humanos · B2 Solutions</p>
    </td></tr>
</table>
</td></tr></table>
</body>
</html>
