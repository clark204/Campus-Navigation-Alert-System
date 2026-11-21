<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CNAS Administrator Contact (defaults)
    |--------------------------------------------------------------------------
    |
    | The admin edits these on /admin/settings (saved in the settings table).
    | These values are only the fallback for a field that was never saved.
    | Read them through App\Services\ContactDetails, not config() directly.
    | The phone is written for people; the tel: link is derived from it.
    |
    */

    'contact' => [
        'email' => 'clarklagumbay0@gmail.com',
        'phone' => '+63 900-000-000',
        'facebook' => 'https://www.facebook.com/tagoloancommunitycollegeofficial',
        'address' => 'Tagoloan, Misamis Oriental, Philippines',
    ],

];
