<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/send-test-email', function () {
    Mail::to('test@example.com')->send(new TestEmail());
    return 'ایمیل تست با موفقیت ارسال شد!';
});