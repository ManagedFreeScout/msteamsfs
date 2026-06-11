@php
    $licenseStatus = $settings['license_status'] ?? ['valid' => false, 'status' => 'inactive', 'message' => ''];
    $isValid = $licenseStatus['valid'] ?? false;
    $status = $licenseStatus['status'] ?? 'inactive';
    $licenseKey = $licenseStatus['license_key'] ?? '';
    $message = $licenseStatus['message'] ?? '';
@endphp

<div class="row">
    <div class="col-xs-12">
        <div class="panel panel-default">
            <div class="panel-heading">{{ __('License') }}</div>
            <div class="panel-body">
                <div class="license-status-message">
                    @if($isValid)
                        <div class="alert alert-success">
                            <i class="glyphicon glyphicon-ok"></i> {{ __('License is active.') }}
                        </div>
                    @else
                        <div class="alert alert-warning">
                            <i class="glyphicon glyphicon-warning-sign"></i> {{ __('License is not active.') }}
                        </div>
                    @endif
                </div>

                <form id="license-management-form" class="form-horizontal margin-top">
                    {{ csrf_field() }}
                    <input type="hidden" name="action" id="license-action" value="">

                    <div class="form-group">
                        <label for="license_key" class="col-sm-2 control-label">{{ __('License Key') }}</label>
                        <div class="col-sm-6">
                            <input type="text" class="form-control" name="license_key" id="license_key_input"
                                    value="{{ $settings['license_status']['license_key'] ?? '' }}"
                                    placeholder="{{ __('Enter your license key') }}">
                            <div class="help-block">{{ __('Enter your license key to activate the module') }}</div>
                            <div class="help-block">By using this module you agree to our <a href="https://managedfreescout.com/license-terms" target="_blank">Software License Terms</a>.</div>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="col-sm-2"></div>
                        <div class="col-sm-10">
                            <button type="button" class="btn btn-success" id="activate-license-btn">
                                <i class="glyphicon glyphicon-ok-circle"></i> {{ __('Activate License') }}
                            </button>
                            @if($settings['license_status']['valid'])
                                <button type="button" class="btn btn-danger" id="deactivate-license-btn">
                                    <i class="glyphicon glyphicon-remove-circle"></i> {{ __('Deactivate License') }}
                                </button>
                            @endif
                        </div>
                    </div>
                </form>

                <div id="license-ajax-response" class="mt-3" style="display: none;"></div>
            </div>

            <script type="text/javascript" {!! \Helper::cspNonceAttr() !!}>
                function performLicenseAction(action) {
                    const licenseKey = document.getElementById('license_key_input').value.trim();
                    const responseDiv = document.getElementById('license-ajax-response');
                    const actionInput = document.getElementById('license-action');

                    // Hide previous response
                    responseDiv.style.display = 'none';
                    responseDiv.innerHTML = '';

                    // Validate license key for activation
                    if (action === 'activate' && !licenseKey) {
                        showLicenseError('{{ __("License key is required.") }}');
                        return false;
                    }

                    // Show loading state
                    const buttons = document.querySelectorAll('.btn[data-license-action]');
                    buttons.forEach(button => {
                        button.disabled = true;
                        button.classList.add('disabled');
                    });

                    // Set action and prepare form data
                    actionInput.value = action;
                    const formData = new FormData();
                    formData.append('_token', '{{ csrf_token() }}');
                    formData.append('action', action);
                    formData.append('license_key', licenseKey);

                    fetch('{{ route("msteamsfs.license.manage") }}', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success') {
                            showLicenseSuccess(data.message || '{{ __("Operation completed successfully.") }}');

                            // Reload the page to update the license status display after a delay
                            setTimeout(() => {
                                window.location.reload();
                            }, 1500);
                        } else {
                            showLicenseError(data.message || '{{ __("An error occurred during the operation.") }}');
                        }
                    })
                    .catch(error => {
                        console.error('License API Error:', error);
                        showLicenseError('{{ __("An error occurred while processing your request. Please try again.") }}');
                    })
                    .finally(() => {
                        // Re-enable buttons
                        buttons.forEach(button => {
                            button.disabled = false;
                            button.classList.remove('disabled');
                        });
                    });

                    return false;
                }

                function showLicenseSuccess(message) {
                    const responseDiv = document.getElementById('license-ajax-response');
                    responseDiv.innerHTML = `
                        <div class="alert alert-success" role="alert">
                            <i class="glyphicon glyphicon-ok-sign"></i>
                            ${message}
                            <button type="button" class="close" data-dismiss="alert">&times;</button>
                        </div>
                    `;
                    responseDiv.style.display = 'block';
                }

                function showLicenseError(message) {
                    const responseDiv = document.getElementById('license-ajax-response');
                    responseDiv.innerHTML = `
                        <div class="alert alert-danger" role="alert">
                            <i class="glyphicon glyphicon-exclamation-sign"></i>
                            ${message}
                            <button type="button" class="close" data-dismiss="alert">&times;</button>
                        </div>
                    `;
                    responseDiv.style.display = 'block';
                }

                // Add event listeners after DOM is loaded
                document.addEventListener('DOMContentLoaded', function() {
                    const activateBtn = document.getElementById('activate-license-btn');
                    const deactivateBtn = document.getElementById('deactivate-license-btn');

                    if (activateBtn) {
                        activateBtn.setAttribute('data-license-action', 'activate');
                        activateBtn.addEventListener('click', function() {
                            performLicenseAction('activate');
                        });
                    }

                    if (deactivateBtn) {
                        deactivateBtn.setAttribute('data-license-action', 'deactivate');
                        deactivateBtn.addEventListener('click', function() {
                            performLicenseAction('deactivate');
                        });
                    }
                });
            </script>
