<?php

use Illuminate\Support\Facades\Broadcast;

// Default Laravel model channel
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/*
|--------------------------------------------------------------------------
| Auction Engine — Private Channels
|--------------------------------------------------------------------------
*/

// Private per-user channel — used for outbid notifications (OutbidNotification event)
Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
