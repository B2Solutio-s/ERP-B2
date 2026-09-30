<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('reclutamiento.candidatos', function () {
    return true;
});

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
