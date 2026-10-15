{{-- Cápsula de tamaño conocido con esquinas redondeadas.
     - Outlook de escritorio: forma VML de tamaño fijo con el texto centrado (igual que el botón principal).
     - Resto: tabla con border-radius.
     Variables: $w, $h (px), $arc (% VML = radio / alto), $r (radio px), $color, $texto, $fs (tamaño de letra),
                opcionales: $txt (color de letra), $peso, $href (si es un enlace) --}}
@php
    $txt = $txt ?? 'ffffff';
    $peso = $peso ?? 'bold';
@endphp
<!--[if mso]>
<v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word"{!! isset($href) ? ' href="'.$href.'"' : '' !!} arcsize="{{ $arc }}%" fillcolor="#{{ $color }}" stroke="f" style="height:{{ $h }}px;v-text-anchor:middle;width:{{ $w }}px;">
<w:anchorlock/>
<v:textbox inset="3px,0,3px,0"><center style="color:#{{ $txt }};font-family:Arial,sans-serif;font-size:{{ $fs }}px;font-weight:{{ $peso }};">{{ $texto }}</center></v:textbox>
</v:roundrect>
<![endif]-->
<!--[if !mso]><!-->
<table role="presentation" width="{{ $w }}" cellpadding="0" cellspacing="0" border="0" bgcolor="#{{ $color }}" style="width:100%;max-width:{{ $w }}px;background-color:#{{ $color }};border-radius:{{ $r }}px;border-collapse:separate;">
    <tr><td style="height:{{ $r }}px;font-size:0;line-height:0;">&nbsp;</td></tr>
    <tr>
        <td align="center" style="padding:0 6px;text-align:center;font-family:Arial,Helvetica,sans-serif;font-size:{{ $fs }}px;line-height:19px;font-weight:{{ $peso }};color:#{{ $txt }};word-break:{{ isset($href) ? 'break-all' : 'normal' }};">
            @if (isset($href))<a href="{{ $href }}" target="_blank" style="color:#{{ $txt }};text-decoration:none;">{{ $texto }}</a>@else{{ $texto }}@endif
        </td>
    </tr>
    <tr><td style="height:{{ $r }}px;font-size:0;line-height:0;">&nbsp;</td></tr>
</table>
<!--<![endif]-->
