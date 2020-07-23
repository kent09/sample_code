<?php 

Route::group([ 'domain' => $domain, 'middleware' => ['auth'] ], function () {

    Route::post('/billing', 'UserSubscriptionController@store'); 
    Route::post('/billing/confirm', 'UserSubscriptionController@processOrder');
    Route::post('/changeplan', 'UserSubscriptionController@changePlan');
    Route::get('/billing', 'UserSubscriptionController@index');

});