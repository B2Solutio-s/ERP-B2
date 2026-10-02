<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Area extends Model
{
    protected $fillable = [
        'nombre',
        'usa_campana',
        'usa_cargo',
        'tipo_contrato',
    ];

    protected function casts(): array
    {
        return [
            'usa_campana' => 'boolean',
            'usa_cargo' => 'boolean',
        ];
    }

    public function puestos()
    {
        return $this->hasMany(Puesto::class);
    }

    public function campanas()
    {
        return $this->hasMany(Campana::class);
    }

    public function cargos()
    {
        return $this->hasMany(Cargo::class);
    }
}
