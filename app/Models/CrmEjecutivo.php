<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Ejecutivo del CRM, vinculado (o no) a un trabajador del ERP. */
class CrmEjecutivo extends Model
{
    protected $table = 'crm_ejecutivos';

    protected $fillable = ['crm_user_id', 'nombre', 'correo', 'correo_corporativo', 'empleado_id'];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class);
    }
}
