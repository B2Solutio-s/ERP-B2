{{-- Abre una caja de color.
     - Outlook de escritorio (motor de Word): tabla con bgcolor en cada celda (esquinas rectas: es lo único fiable
       cuando el alto depende del texto).
     - Resto de clientes (Gmail, Outlook web, móvil): border-radius.
     Variables: $r (radio / espacio superior e inferior), $w (ancho px), $color (hex sin #), $estilo (estilo del contenido) --}}
<!--[if mso]>
<table role="presentation" width="{{ $w }}" cellpadding="0" cellspacing="0" border="0" bgcolor="#{{ $color }}" style="width:{{ $w }}px;background-color:#{{ $color }};">
    <tr><td height="{{ $r }}" bgcolor="#{{ $color }}" style="height:{{ $r }}px;font-size:1px;line-height:1px;mso-line-height-rule:exactly;">&nbsp;</td></tr>
    <tr>
        <td bgcolor="#{{ $color }}" style="{{ $estilo }}">
<![endif]-->
<!--[if !mso]><!-->
<table role="presentation" width="{{ $w }}" cellpadding="0" cellspacing="0" border="0" bgcolor="#{{ $color }}" style="width:100%;max-width:{{ $w }}px;background-color:#{{ $color }};border-radius:{{ $r }}px;border-collapse:separate;">
    <tr><td style="height:{{ $r }}px;font-size:0;line-height:0;">&nbsp;</td></tr>
    <tr>
        <td style="{{ $estilo }}">
<!--<![endif]-->
