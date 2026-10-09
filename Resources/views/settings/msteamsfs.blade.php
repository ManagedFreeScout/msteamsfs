<div class="row">
    <div class="col-xs-12">
        <div class="alert alert-info">
            <strong>{{ __('MFS Connect') }}</strong> &mdash; {{ __('Your FreeScout helpdesk inside Microsoft Teams') }}
        </div>
    </div>
</div>

{{-- License panel --}}
@include('msteamsfs::settings.partials.license')

{{-- Microsoft 365 connection panel (card #276): only once the install is registered --}}
@if (($settings['msteamsfs.backend_secret'] ?? '') !== '')
    @include('msteamsfs::settings.partials.connection')
@endif

{{-- Settings panel --}}
<div class="row">
    <div class="col-xs-12">
        <div class="panel panel-default">
            <div class="panel-heading">{{ __('Settings') }}</div>
            <div class="panel-body">
                <form class="form-horizontal margin-top margin-bottom" method="POST" action="">
                    {{ csrf_field() }}
                    <input type="hidden" name="settings[dummy]" value="1" />

                    {{-- Backend Secret (card #276, 1.8.0): set automatically on the first license
                         activation and never shown. An empty field keeps the stored secret (see
                         settings.before_save); support can have it replaced. --}}
                    @php $mstfsSecretSet = ($settings['msteamsfs.backend_secret'] ?? '') !== ''; @endphp
                    <div class="form-group">
                        <label class="col-sm-2 control-label">{{ __('Backend Secret') }}</label>
                        <div class="col-sm-6">
                            <p class="form-control-static">
                                @if ($mstfsSecretSet)
                                    <span class="text-success"><i class="glyphicon glyphicon-ok"></i> {{ __('Set') }}</span>
                                    &mdash; {{ __('filled in automatically, you never need to see or copy it.') }}
                                @else
                                    {{ __('Not set yet. It is filled in automatically when you click Activate License above.') }}
                                @endif
                                <a href="#" id="msteamsfs-secret-replace">{{ $mstfsSecretSet ? __('Replace') : __('Enter manually') }}</a>
                            </p>
                            <div id="msteamsfs-secret-input" style="display:none">
                                <input type="text"
                                       class="form-control input-sized-lg"
                                       name="settings[msteamsfs.backend_secret]"
                                       value=""
                                       autocomplete="off"
                                       placeholder="{{ __('64-character secret from ManagedFreeScout support') }}">
                                <p class="form-help">{{ __('Only when ManagedFreeScout support asks you to. Leave empty to keep the current secret.') }}</p>
                            </div>
                        </div>
                    </div>
                    <script type="text/javascript" {!! \Helper::cspNonceAttr() !!}>
                        document.getElementById('msteamsfs-secret-replace').addEventListener('click', function (e) {
                            e.preventDefault();
                            document.getElementById('msteamsfs-secret-input').style.display = 'block';
                            this.style.display = 'none';
                        });
                    </script>

                    <div class="form-group">
                        <label class="col-sm-2 control-label">{{ __('Allowed Domains') }}</label>
                        <div class="col-sm-6">
                            <input type="text"
                                   class="form-control input-sized-lg"
                                   name="settings[msteamsfs.allowed_domains]"
                                   value="{{ $settings['msteamsfs.allowed_domains'] ?? '' }}"
                                   placeholder="e.g. yourdomain.com, yourcompany.nl">
                            <p class="form-help">{{ __('Comma-separated list of email domains allowed to log in via Teams SSO. Leave blank to allow all authenticated users. Required for automatic user creation below.') }}</p>
                        </div>
                    </div>

                    {{-- Auto-create users on first Teams sign-in (card #247, 1.6.0) --}}
                    @php
                        $mstfsAutoCreate  = !empty($settings['msteamsfs.auto_create_users']);
                        $mstfsDomainsSet  = trim($settings['msteamsfs.allowed_domains'] ?? '') !== '';
                        $mstfsMailboxIds  = $settings['msteamsfs.auto_create_mailboxes'] ?? [];
                        $mstfsMailboxNames = \App\Mailbox::whereIn('id', $mstfsMailboxIds)->orderBy('name')->pluck('name')->all();
                    @endphp
                    {{-- Always-visible status (card #247): ticking mailboxes or filling in domains alone does not switch it on --}}
                    <div class="form-group">
                        <label class="col-sm-2 control-label">{{ __('User Creation') }}</label>
                        <div class="col-sm-6">
                            @if (!$mstfsAutoCreate)
                                <div class="alert alert-danger margin-bottom-0"><strong>{{ __('Off') }}</strong> &mdash; {{ __('tick "Create Users" below and save to switch it on. Until then, people without a FreeScout account get "Access denied".') }}</div>
                            @elseif (!$mstfsDomainsSet)
                                <div class="alert alert-warning margin-bottom-0"><strong>{{ __('Not active') }}</strong> &mdash; {{ __('fill in Allowed Domains above. Automatic user creation only works for an explicit list of email domains, so guest accounts in your Microsoft tenant never get a FreeScout account.') }}</div>
                            @else
                                <div class="alert alert-success margin-bottom-0"><strong>{{ __('Active') }}</strong> &mdash; {{ __('new users from :domains get an account on their first Teams sign-in, with access to: :mailboxes', ['domains' => $settings['msteamsfs.allowed_domains'], 'mailboxes' => $mstfsMailboxNames ? implode(', ', $mstfsMailboxNames) : __('no mailboxes (assign them in the user profile)')]) }}</div>
                            @endif
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-sm-2 control-label">{{ __('Create Users') }}</label>
                        <div class="col-sm-6">
                            <div class="checkbox">
                                <label>
                                    <input type="checkbox" name="settings[msteamsfs.auto_create_users]" value="1" @if ($mstfsAutoCreate) checked @endif>
                                    {{ __('Automatically create a FreeScout user on their first Teams sign-in') }}
                                </label>
                            </div>
                            <p class="form-help">{{ __('Off by default. New users always get the role User (never Administrator) and access to the mailboxes ticked below. Administrators receive an email for every user created this way. Disabled or deleted users are never re-created.') }}</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-sm-2 control-label">{{ __('New users get access to') }}</label>
                        <div class="col-sm-6">
                            @foreach (\App\Mailbox::orderBy('name')->get() as $mstfsMailbox)
                                <div class="checkbox">
                                    <label>
                                        <input type="checkbox" name="settings[msteamsfs.auto_create_mailboxes][]" value="{{ $mstfsMailbox->id }}" @if (in_array($mstfsMailbox->id, $mstfsMailboxIds)) checked @endif>
                                        {{ $mstfsMailbox->name }} <span class="text-help">({{ $mstfsMailbox->email }})</span>
                                    </label>
                                </div>
                            @endforeach
                            <p class="form-help">{{ __('Mailboxes a user created on first Teams sign-in can access. Change individual users afterwards in their profile.') }}</p>
                        </div>
                    </div>

                    {{-- Extra sites allowed to show FreeScout in a frame (card #249, 1.6.2) --}}
                    <div class="form-group">
                        <label class="col-sm-2 control-label">{{ __('Additional allowed embedders') }}</label>
                        <div class="col-sm-6">
                            <textarea class="form-control input-sized-lg"
                                      name="settings[msteamsfs.extra_frame_ancestors]"
                                      rows="3"
                                      placeholder="https://helpcenter.example.com">{{ $settings['msteamsfs.extra_frame_ancestors'] ?? '' }}</textarea>
                            <p class="form-help">{{ __('Websites, besides Microsoft Teams, that may show FreeScout inside a frame. One per line, e.g. https://helpcenter.example.com or https://*.example.com. MFS Connect writes these into the Content-Security-Policy block it manages in .htaccess, so do not edit that block by hand.') }}</p>
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
