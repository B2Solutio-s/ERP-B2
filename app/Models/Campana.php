<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Campana extends Model
{
    protected $fillable = [
        'nombre',
        'puesto_id',
    ];

    public function puesto()
    {
        return $this->belongsTo(Puesto::class);
    }

    public function cargos()
    {
        return $this->hasMany(Cargo::class);
    }
}
