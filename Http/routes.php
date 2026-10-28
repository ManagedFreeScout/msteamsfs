<?php

Route::group(['middleware' => ['web'], 'namespace' => 'Modules\MSTeamsFS\Http\Controllers'], function () {
    Route::get('/teams-sso-handoff', ['uses' => 'TeamsSsoController@handoff'])->name('msteamsfs.handoff');
});

Route::group(['middleware' => ['web', 'auth', 'roles'], 'roles' => ['admin'], 'namespace' => 'Modules\MSTeamsFS\Http\Controllers'], function () {
    Route::post('/admin/msteamsfs/license/manage', 'MSTeamsFSController@manageLicense')->name('msteamsfs.license.manage');
    Route::post('/admin/msteamsfs/module-license-action', 'MSTeamsFSController@handleModuleLicenseAction')->name('msteamsfs.module.license.action');
    Route::post('/admin/msteamsfs/connection', 'MSTeamsFSController@connection')->name('msteamsfs.connection');
});

// Mailbox settings → MFS Connect: channel posting (msteamsfs card #339). Permission checked in the controller
// (admin, or a user who may change this mailbox's settings).
Route::group(['middleware' => ['web', 'auth'], 'namespace' => 'Modules\MSTeamsFS\Http\Controllers'], function () {
    Route::get('/mailbox/msteamsfs/{id}/mfsconnect', 'TeamsChannelsController@show')->name('msteamsfs.mailbox.teams');
    Route::post('/mailbox/msteamsfs/{id}/mfsconnect', 'TeamsChannelsController@save');
    Route::post('/mailbox/msteamsfs/{id}/mfsconnect/channels', 'TeamsChannelsController@channels')->name('msteamsfs.mailbox.teams.channels');
    // The page was called "Teams" up to 1.10.0; renamed so it is not mistaken for FreeScout's Teams module (agent teams).
    Route::get('/mailbox/msteamsfs/{id}/teams', function ($id) {
        return redirect()->route('msteamsfs.mailbox.teams', ['id' => (int) $id], 301);
    });
});
