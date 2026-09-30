<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cargo extends Model
{
    protected $fillable = [
        'nombre',
        'campana_id',
    ];

    public function campana()
    {
        return $this->belongsTo(Campana::class);
    }
}
