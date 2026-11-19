<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmDeliveryResumen extends Model
{
    protected $table = 'crm_delivery_resumenes';

    protected $fillable = [
        'desde', 'hasta', 'vista', 'crm_user_id', 'nombre', 'supervisor',
        'activadas', 'flujo', 'proyectadas', 'total', 'cf_sin_igv', 'sincronizado_at',
    ];

    protected function casts(): array
    {
        return ['sincronizado_at' => 'datetime', 'desde' => 'date:Y-m-d', 'hasta' => 'date:Y-m-d'];
    }
}
