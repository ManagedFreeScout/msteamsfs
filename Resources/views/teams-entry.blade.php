@extends('layouts.app')

@section('content')
<div class="container">
    <div id="status">Initializing Teams SSO...</div>
</div>
<script src="https://res.cdn.office.net/teams-js/2.19.0/js/MicrosoftTeams.min.js"></script>
<script {!! \Helper::cspNonceAttr() !!}>
    if (typeof microsoftTeams === 'undefined') {
        document.getElementById('status').innerText = 'Microsoft Teams SDK not available. This page should run inside Teams tab.';
    } else {
        microsoftTeams.app.initialize().then(() => {
            document.getElementById('status').innerText = 'Requesting SSO token...';
            microsoftTeams.authentication.getAuthToken().then(token => {
                fetch('/teams-sso-login', {
                    method: 'POST',
                    credentials: 'include',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                    },
                    body: JSON.stringify({ token: token })
                }).then(function (r) {
                    if (r.ok) {
                        document.getElementById('status').innerText = 'Login successful, redirecting...';
                        window.location.href = '/';
                    } else {
                        document.getElementById('status').innerText = 'SSO login failed — opening fallback.';
                        window.location.href = '/teams-fallback';
                    }
                }).catch(function (err) {
                    document.getElementById('status').innerText = 'SSO request failed: ' + err;
                });
            }).catch(err => {
                document.getElementById('status').innerText = 'getAuthToken failed: ' + err;
            });
        });
    }
</script>
@endsection
