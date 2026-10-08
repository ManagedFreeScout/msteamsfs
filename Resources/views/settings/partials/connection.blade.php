{{-- Microsoft 365 connection (card #276, 1.8.0): links this install to the customer's
     Microsoft 365 tenant with a one-day code entered in the Teams tab. --}}
<div class="row">
    <div class="col-xs-12">
        <div class="panel panel-default">
            <div class="panel-heading">{{ __('Microsoft 365 connection') }}</div>
            <div class="panel-body">
                <div id="msteamsfs-conn-status"><i class="glyphicon glyphicon-refresh"></i> {{ __('Checking…') }}</div>

                <div id="msteamsfs-conn-steps" style="display:none" class="margin-top">
                    <p>{{ __('To connect, get a code here and enter it in Microsoft Teams. The first time anyone in your organisation opens the "FreeScout for Teams" app, it asks for this code. You only need to do this once.') }}</p>
                    <button type="button" class="btn btn-primary" id="msteamsfs-conn-code-btn">
                        <i class="glyphicon glyphicon-link"></i> {{ __('Get connection code') }}
                    </button>
                    <div id="msteamsfs-conn-code" style="display:none" class="margin-top">
                        <div style="font:22px monospace;letter-spacing:2px;padding:10px 14px;background:#f5f5f5;border:1px solid #ddd;border-radius:4px;display:inline-block" id="msteamsfs-conn-code-value"></div>
                        <p class="form-help">{{ __('Valid for 24 hours and for one connection. Only share it with colleagues in your own organisation.') }}</p>
                    </div>
                </div>

                <div id="msteamsfs-conn-error" class="alert alert-danger margin-top" style="display:none"></div>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript" {!! \Helper::cspNonceAttr() !!}>
    document.addEventListener('DOMContentLoaded', function () {
        var statusEl = document.getElementById('msteamsfs-conn-status');
        var stepsEl  = document.getElementById('msteamsfs-conn-steps');
        var codeBox  = document.getElementById('msteamsfs-conn-code');
        var codeEl   = document.getElementById('msteamsfs-conn-code-value');
        var errorEl  = document.getElementById('msteamsfs-conn-error');
        var codeBtn  = document.getElementById('msteamsfs-conn-code-btn');

        function call(action) {
            var formData = new FormData();
            formData.append('_token', '{{ csrf_token() }}');
            formData.append('action', action);
            return fetch('{{ route("msteamsfs.connection") }}', {
                method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'}
            }).then(function (r) { return r.json(); });
        }
        function showError(msg) {
            errorEl.textContent = msg;
            errorEl.style.display = 'block';
        }
        function render(data) {
            if (data.tenant_connected) {
                statusEl.className = 'alert alert-success margin-bottom-0';
                statusEl.innerHTML = '<i class="glyphicon glyphicon-ok"></i> ';
                statusEl.appendChild(document.createTextNode('{{ __("Connected. Agents can sign in from the FreeScout for Teams app.") }}'));
                stepsEl.style.display = 'none';
            } else {
                statusEl.className = 'alert alert-warning margin-bottom-0';
                statusEl.innerHTML = '<i class="glyphicon glyphicon-warning-sign"></i> ';
                statusEl.appendChild(document.createTextNode('{{ __("Not connected to your Microsoft 365 organisation yet.") }}'));
                stepsEl.style.display = 'block';
            }
        }

        call('status').then(function (data) {
            if (data.status !== 'success') { statusEl.style.display = 'none'; showError(data.message); return; }
            render(data);
        }).catch(function () { statusEl.style.display = 'none'; showError('{{ __("Could not check the connection.") }}'); });

        codeBtn.addEventListener('click', function () {
            errorEl.style.display = 'none';
            codeBtn.disabled = true;
            call('code').then(function (data) {
                codeBtn.disabled = false;
                if (data.status !== 'success') { showError(data.message); return; }
                if (data.tenant_connected) { render(data); return; }
                codeEl.textContent = data.code;
                codeBox.style.display = 'block';
            }).catch(function () { codeBtn.disabled = false; showError('{{ __("Could not get a connection code.") }}'); });
        });
    });
</script>
