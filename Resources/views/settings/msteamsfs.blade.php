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
