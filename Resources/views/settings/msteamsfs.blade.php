<div class="row">
    <div class="col-xs-12">
        <div class="alert alert-info">
            <strong>{{ __('MSTeams FS') }}</strong> &mdash; {{ __('ManagedFreeScout Microsoft Teams SSO') }}
        </div>
    </div>
</div>

{{-- License panel --}}
@include('msteamsfs::settings.partials.license')

{{-- Settings panel --}}
<div class="row">
    <div class="col-xs-12">
        <div class="panel panel-default">
            <div class="panel-heading">{{ __('Settings') }}</div>
            <div class="panel-body">
                <form class="form-horizontal margin-top margin-bottom" method="POST" action="">
                    {{ csrf_field() }}
                    <input type="hidden" name="settings[dummy]" value="1" />

                    <div class="form-group">
                        <label class="col-sm-2 control-label">{{ __('Backend Secret') }}</label>
                        <div class="col-sm-6">
                            <input type="text"
                                   class="form-control input-sized-lg"
                                   name="settings[msteamsfs.backend_secret]"
                                   value="{{ $settings['msteamsfs.backend_secret'] ?? '' }}"
                                   placeholder="64-character hex string">
                            <p class="form-help">{{ __('Provided by ManagedFreeScout. Required to verify SSO tokens.') }}</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-sm-2 control-label">{{ __('Allowed Domains') }}</label>
                        <div class="col-sm-6">
                            <input type="text"
                                   class="form-control input-sized-lg"
                                   name="settings[msteamsfs.allowed_domains]"
                                   value="{{ $settings['msteamsfs.allowed_domains'] ?? '' }}"
                                   placeholder="e.g. yourdomain.com, yourcompany.nl">
                            <p class="form-help">{{ __('Comma-separated list of email domains allowed to log in via Teams SSO. Leave blank to allow all authenticated users.') }}</p>
                        </div>
                    </div>

                    <div class="form-group margin-top-0 margin-bottom-0">
                        <div class="col-sm-6 col-sm-offset-2">
                            <button type="submit" class="btn btn-primary" name="action" value="msteamsfs_save">
                                {{ __('Save') }}
                            </button>
                        </div>
                    </div>

                </form>
            </div>
        </div>
    </div>
</div>
