@php
    $cids = [];
    $cid = function (string $archivo) use ($message, &$cids) {
        return $cids[$archivo] ??= $message->embed(resource_path("mail/{$archivo}.png"));
    };
@endphp
<!DOCTYPE html>
<html lang="es" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light only">
    <!--[if gte mso 9]>
    <xml>
        <o:OfficeDocumentSettings>
            <o:AllowPNG/>
            <o:PixelsPerInch>96</o:PixelsPerInch>
        </o:OfficeDocumentSettings>
    </xml>
    <![endif]-->
    <title>{{ $v['titulo'] }}</title>
</head>
<body style="margin:0;padding:0;background-color:#ffffff;font-family:'Open Sans',Arial,Helvetica,sans-serif;color:#1c1c1c;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#ffffff;">
        <tr>
            <td align="center" style="padding:0;">
                <table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:640px;background-color:#ffffff;">

                    {{-- Encabezado con ondas --}}
                    <tr>
                        <td style="padding:0;line-height:0;font-size:0;">
                            <img src="{{ $cid('encabezado-modelo') }}" width="640" alt="B2 · Tu momento es AHORA" style="display:block;width:100%;max-width:640px;height:auto;border:0;">
                        </td>
                    </tr>

                    {{-- Título + logo --}}
                    <tr>
                        <td style="padding:6px 0 0 0;line-height:0;font-size:0;">
                            <img src="{{ $cid($v['imagen']) }}" width="640" alt="{{ $v['alt'] }}" style="display:block;width:100%;max-width:640px;height:auto;border:0;">
                        </td>
                    </tr>

                    {{-- Saludo y texto --}}
                    <tr>
                        <td style="padding:22px 38px 0 38px;font-size:15px;line-height:1.55;color:#1c1c1c;">
                            <p style="margin:0 0 18px 0;font-size:16px;font-weight:bold;color:#000000;">Hola {{ $primerNombre }},</p>
                            <table role="presentation" width="440" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:440px;">
                                <tr>
                                    <td style="font-size:15px;line-height:1.55;color:#1c1c1c;">
                                        {{ $v['intro'] }}
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Botón (VML para Outlook, píldora para el resto) --}}
                    <tr>
                        <td align="center" style="padding:30px 38px 26px 38px;">
                            <!--[if mso]>
                            <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $url }}" style="height:44px;v-text-anchor:middle;width:270px;" arcsize="50%" stroke="f" fillcolor="#5b0bf8">
                                <w:anchorlock/>
                                <center style="color:#ffffff;font-family:Arial,sans-serif;font-size:18px;font-weight:bold;">{{ $v['boton'] }}</center>
                            </v:roundrect>
                            <![endif]-->
                            <!--[if !mso]><!-- -->
                            <a href="{{ $url }}" target="_blank" style="display:inline-block;min-width:200px;padding:11px 36px;font-family:'Open Sans',Arial,Helvetica,sans-serif;font-size:18px;font-weight:bold;line-height:22px;color:#ffffff;text-decoration:none;text-align:center;background-color:#5b0bf8;border-radius:50px;">{{ $v['boton'] }}</a>
                            <!--<![endif]-->
                        </td>
                    </tr>

                    {{-- Antes de empezar --}}
                    <tr>
                        <td style="padding:0 38px 8px 38px;">
                            @include('emails.partes.caja-ini', ['r' => 16, 'w' => 564, 'color' => 'f2f4fd', 'estilo' => 'padding:2px 22px 4px 22px;font-size:15px;font-weight:bold;color:#000000;'])
                            Antes de empezar, ten a la mano
                            </td></tr>
                            <tr><td colspan="3" bgcolor="#f2f4fd" style="padding:8px 14px 0 14px;">
                                <table role="presentation" width="536" cellpadding="0" cellspacing="0" border="0" style="width:536px;max-width:100%;">
                                    <tr>
                                        @foreach ([['DNI', 96, 47], ['Datos de tus familiares', 148, 66], ['Tus estudios y empleos', 148, 66], ['Sistema de pensiones', 144, 66]] as [$texto, $ancho, $alto])
                                            <td width="{{ $ancho }}" align="center" valign="middle" style="width:{{ $ancho }}px;padding:0 4px;">
                                                @include('emails.partes.capsula', ['w' => $ancho - 8, 'h' => $alto, 'arc' => (int) round(14 / $alto * 100), 'r' => 14, 'color' => 'c958fe', 'texto' => $texto, 'fs' => 14])
                                            </td>
                                        @endforeach
                                    </tr>
                                </table>
                            @include('emails.partes.caja-fin', ['r' => 16, 'w' => 564, 'color' => 'f2f4fd'])
                        </td>
                    </tr>

                    {{-- ¿Cómo funciona? --}}
                    <tr>
                        <td style="padding:0 38px 8px 38px;">
                            @include('emails.partes.caja-ini', ['r' => 16, 'w' => 564, 'color' => 'f2f4fd', 'estilo' => 'padding:2px 22px 4px 22px;font-size:14px;line-height:1.6;color:#1c1c1c;'])
                            <p style="margin:0 0 6px 0;font-size:15px;font-weight:bold;color:#000000;">¿Cómo funciona?</p>
                            <ul style="margin:0;padding-left:22px;">
                                <li>Son 8 pasos y toma unos 10 a 15 minutos.</li>
                                <li>Al final firmas digitalmente con el dedo o el mouse.</li>
                                <li>Este enlace es <strong>personal</strong> y de un solo uso. <strong>Vence el {{ $expiraEn->timezone('America/Lima')->format('d/m/Y') }}.</strong></li>
                            </ul>
                            @include('emails.partes.caja-fin', ['r' => 16, 'w' => 564, 'color' => 'f2f4fd'])
                        </td>
                    </tr>

                    {{-- Dudas --}}
                    <tr>
                        <td style="padding:0 38px 0 38px;">
                            @include('emails.partes.caja-ini', ['r' => 16, 'w' => 564, 'color' => 'e1f5ff', 'estilo' => 'padding:0 22px;font-size:14px;line-height:1.5;color:#1c1c1c;'])
                            Si tienes dudas, escríbenos a <a href="mailto:rrhh@b2s.pe" style="color:#5b0bf8;font-weight:bold;text-decoration:underline;">rrhh@b2s.pe</a>.
                            @include('emails.partes.caja-fin', ['r' => 16, 'w' => 564, 'color' => 'e1f5ff'])
                        </td>
                    </tr>

                    {{-- Firma --}}
                    <tr>
                        <td style="padding:18px 38px 22px 38px;font-size:14px;line-height:1.5;color:#1c1c1c;">
                            Saludos,<br>
                            <strong style="color:#000000;">Recursos Humanos – B2 Solutions</strong>
                        </td>
                    </tr>

                    {{-- Pie --}}
                    <tr>
                        <td style="padding:0;line-height:0;font-size:0;">
                            <img src="{{ $cid('pie-modelo') }}" width="640" alt="B2 · Tu momento es AHORA" style="display:block;width:100%;max-width:640px;height:auto;border:0;">
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
