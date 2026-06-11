<?php

Route::group(['middleware' => ['web'], 'namespace' => 'Modules\MSTeamsFS\Http\Controllers'], function () {
    Route::get('/teams-sso-handoff', ['uses' => 'TeamsSsoController@handoff'])->name('msteamsfs.handoff');
});

Route::group(['middleware' => ['web', 'auth', 'roles'], 'roles' => ['admin'], 'namespace' => 'Modules\MSTeamsFS\Http\Controllers'], function () {
    Route::post('/admin/msteamsfs/license/manage', 'MSTeamsFSController@manageLicense')->name('msteamsfs.license.manage');
    Route::post('/admin/msteamsfs/module-license-action', 'MSTeamsFSController@handleModuleLicenseAction')->name('msteamsfs.module.license.action');
});
