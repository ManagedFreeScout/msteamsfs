@extends('layouts.app')

@section('title_full', __('Teams').' - '.$mailbox->name)

@section('sidebar')
    @include('partials/sidebar_menu_toggle')
    @include('mailboxes/sidebar_menu')
@endsection

@section('content')
@php
    $problemText = [
        'channel_inactive'     => __('The channel no longer accepts posts: it was deleted or archived, or the app was removed from it. Choose another channel.'),
        'channel_unsupported'  => __('This is a private or shared channel. For now MFS Connect posts in standard channels only. Choose another channel.'),
        'bot_removed'          => __('The MFS Connect app was removed from this team. Add it to the team again, or choose another channel.'),
        'channel_unknown'      => __('This channel is not known for your Microsoft 365 tenant. Click "Refresh channels" and choose again.'),
        'license_inactive'     => __('Your MFS Connect licence is not active, so nothing is posted.'),
        'tenant_not_connected' => __('This FreeScout is not connected to Microsoft Teams yet (Settings → MFS Connect).'),
        'bot_not_configured'   => __('Channel posting is not available on the MFS Connect hub right now.'),
    ];
    $lastProblem = ($rule && $rule->last_result && (in_array($rule->last_result, $problemResults, true) || strpos($rule->last_result, 'http_') === 0)) ? $rule->last_result : null;
    $fieldLabels = [
        'subject' => __('Subject'), 'status' => __('Status'), 'mailbox' => __('Mailbox'), 'assignee' => __('Assignee'),
        'customer_name' => __('Customer name'), 'customer_email' => __('Customer email'), 'tags' => __('Tags'), 'excerpt' => __('Excerpt of the message'),
    ];
    $sameChannelWorkflows = $rule && $rule->channel_id ? array_filter($workflowPosts, function ($w) use ($rule) { return $w['channel_id'] === $rule->channel_id; }) : [];
@endphp

    <div class="section-heading">{{ __('Teams') }}</div>

    @include('partials/flash_messages')

    <div class="row-container">
        <div class="row">
            <div class="col-md-7">
                <p class="margin-top">{{ __('Post this mailbox\'s conversations as cards into a Microsoft Teams channel. The MFS Connect app must be added to that team in Teams first.') }}</p>

                @if ($lastProblem)
                    <div class="alert alert-warning">
                        <strong>{{ __('Last post not delivered') }}</strong> ({{ $rule->last_result_at }}):
                        {{ $problemText[$lastProblem] ?? __('The last post failed. It is tried again with the next event.') }}
                    </div>
                @endif
                @if ($sameChannelWorkflows)
                    <div class="alert alert-info">
                        {{ __('A workflow also posts to this channel:') }}
                        @foreach ($sameChannelWorkflows as $w) <strong>{{ $w['workflow']->name }}</strong>@if (!$loop->last), @endif @endforeach.
                        {{ __('When both fire for the same event, the card is posted once.') }}
                    </div>
                @endif

                <form class="form-horizontal margin-top" method="POST" action="">
                    {{ csrf_field() }}

                    <div class="form-group">
                        <label class="col-sm-3 control-label">{{ __('Post to Teams') }}</label>
                        <div class="col-sm-9">
                            <div class="controls"><div class="onoffswitch-wrap"><div class="onoffswitch">
                                <input type="checkbox" name="enabled" value="1" id="mfs_enabled" class="onoffswitch-checkbox" @if ($rule && $rule->enabled) checked @endif>
                                <label class="onoffswitch-label" for="mfs_enabled"></label>
                            </div></div></div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-sm-3 control-label" for="mfs_channel">{{ __('Channel') }}</label>
                        <div class="col-sm-9">
                            <select class="form-control input-sized" name="channel_id" id="mfs_channel" data-current="{{ $rule->channel_id ?? '' }}">
                                <option value="">{{ __('Loading channels') }}…</option>
                            </select>
                            <button type="button" class="btn btn-default btn-sm" id="mfs_refresh" style="margin-top:6px">{{ __('Refresh channels') }}</button>
                            <div class="alert alert-warning hidden" id="mfs_public_warning" style="margin:8px 0 0;padding:8px 12px">{{ __('This channel is in a public team: anyone in your organisation can join the team and see these cards.') }}</div>
                            <p class="form-help" id="mfs_channel_help">{{ __('Only teams where the MFS Connect app has been added are listed.') }}</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-sm-3 control-label">{{ __('Post when') }}</label>
                        <div class="col-sm-9">
                            <div class="checkbox"><label><input type="checkbox" name="events[]" value="new_conversation" @if (in_array('new_conversation', $events)) checked @endif> {{ __('A new conversation arrives') }}</label></div>
                            <div class="checkbox"><label><input type="checkbox" name="events[]" value="customer_reply" @if (in_array('customer_reply', $events)) checked @endif> {{ __('The customer replies') }}</label></div>
                            <p class="form-help">{{ __('Status and assignee changes update the card that was posted. For more conditions, use Workflows: action "Post to Teams channel".') }}</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-sm-3 control-label" for="mfs_preset">{{ __('Card') }}</label>
                        <div class="col-sm-9">
                            <select class="form-control input-sized" name="preset" id="mfs_preset">
                                <option value="minimal" @if ($card['preset'] === 'minimal') selected @endif>{{ __('Minimal: number and link') }}</option>
                                <option value="standard" @if ($card['preset'] === 'standard') selected @endif>{{ __('Standard') }}</option>
                                <option value="full" @if ($card['preset'] === 'full') selected @endif>{{ __('Full') }}</option>
                            </select>
                            <p class="form-help">{{ __('Always on the card: what happened, the conversation number and an "Open in FreeScout" button. Attachments and full messages are never sent.') }}</p>
                            @foreach ($fieldLabels as $key => $label)
                                @if ($key === 'tags' && !$tagsAvailable) @continue @endif
                                <div class="checkbox"><label><input type="checkbox" class="mfs-field" name="fields[]" value="{{ $key }}" @if (in_array($key, $card['fields'])) checked @endif> {{ $label }}</label></div>
                            @endforeach
                            <div class="form-inline" style="margin:4px 0 8px 20px">
                                <label class="text-help small" for="mfs_excerpt_length">{{ __('Excerpt length') }}</label>
                                <select class="form-control input-sm" name="excerpt_length" id="mfs_excerpt_length">
                                    @foreach ([200, 500] as $n)<option value="{{ $n }}" @if ($card['excerpt_length'] === $n) selected @endif>{{ $n }} {{ __('characters') }}</option>@endforeach
                                </select>
                            </div>
                            @if (count($customFields))
                                <div class="text-help small" style="margin-top:8px">{{ __('Custom fields') }}</div>
                                @foreach ($customFields as $cf)
                                    <div class="checkbox"><label><input type="checkbox" class="mfs-cf" name="custom_fields[]" value="{{ $cf->id }}" data-name="{{ $cf->name }}" @if (in_array((int) $cf->id, $card['custom_fields'], true)) checked @endif> {{ $cf->name }}</label></div>
                                @endforeach
                            @endif
                            <div class="alert alert-warning" style="margin-top:10px;padding:8px 12px">{{ __('Everyone in this channel will see these fields.') }}</div>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="col-sm-9 col-sm-offset-3">
                            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                        </div>
                    </div>
                </form>

                @if ($workflowPosts)
                    <div class="margin-top">
                        <strong>{{ __('Workflows that post to Teams') }}</strong>
                        <ul id="mfs_workflows">
                            @foreach ($workflowPosts as $w)
                                <li data-channel="{{ $w['channel_id'] }}">{{ $w['workflow']->name }} <span class="mfs-wf-channel text-help"></span></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

            <div class="col-md-5">
                <div class="margin-top text-help small">{{ __('Preview') }} @if ($sample['number'] !== 1234) ({{ __('latest conversation') }} #{{ $sample['number'] }}) @else ({{ __('example') }}) @endif</div>
                <div id="mfs_preview" style="border:1px solid #e1e1e1;border-radius:6px;padding:12px 14px;margin-top:6px;background:#fff;font-size:13px;line-height:1.45;max-width:440px"></div>
            </div>
        </div>
    </div>
@endsection

@section('javascript')
    @parent
    (function () {
        var presets = @json($presets);
        var sample = @json($sample);
        var channelsUrl = @json(route('msteamsfs.mailbox.teams.channels', ['id' => $mailbox->id]));
        var token = $('meta[name="csrf-token"]').attr('content') || $('input[name="_token"]').val();
        var $sel = $('#mfs_channel'), current = $sel.data('current') || '', publicTeam = {};
        function publicWarning() { $('#mfs_public_warning').toggleClass('hidden', !publicTeam[$sel.val()]); }
        $sel.on('change', publicWarning);

        function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }

        function loadChannels(refresh) {
            $('#mfs_refresh').prop('disabled', true);
            $.post(channelsUrl, { _token: token, refresh: refresh ? '1' : '0' }).done(function (r) {
                $sel.empty().append($('<option value="">').text(@json(__('Choose a channel'))));
                var found = false, names = {};
                $.each(r.channels || [], function (i, c) {
                    var label = c.team_name + ' / ' + c.channel_name + (c.visibility === 'public' ? ' (' + @json(__('public team')) + ')' : '') + (c.status !== 'active' ? ' (' + @json(__('inactive')) + ')' : '');
                    publicTeam[c.channel_id] = c.visibility === 'public';
                    names[c.channel_id] = label;
                    var $o = $('<option>').val(c.channel_id).text(label);
                    if (c.channel_id === current) { $o.prop('selected', true); found = true; }
                    $sel.append($o);
                });
                if (current && !found) { $sel.append($('<option>').val(current).text(@json(__('Unknown channel'))).prop('selected', true)); }
                $('#mfs_workflows li').each(function () { $(this).find('.mfs-wf-channel').text('→ ' + (names[$(this).data('channel')] || @json(__('unknown channel')))); });
                var help = r.error ? @json(__('Channels could not be loaded')) + ' (' + r.error + ').'
                    : (!(r.channels || []).length ? @json(__('No channels yet. In Teams, add the MFS Connect app to a team, then click "Refresh channels".')) : @json(__('Only teams where the MFS Connect app has been added are listed.')));
                $('#mfs_channel_help').text(help).toggleClass('text-danger', !!r.error);
                publicWarning();
            }).fail(function () {
                $('#mfs_channel_help').text(@json(__('Channels could not be loaded'))).addClass('text-danger');
            }).always(function () { $('#mfs_refresh').prop('disabled', false); });
        }

        function renderPreview() {
            var on = $('.mfs-field:checked').map(function () { return this.value; }).get();
            var f = sample.fields || {}, facts = [];
            if (on.indexOf('status') >= 0 && f.status) facts.push([@json(__('Status')), f.status]);
            if (on.indexOf('mailbox') >= 0 && f.mailbox) facts.push([@json(__('Mailbox')), f.mailbox]);
            if (on.indexOf('assignee') >= 0) facts.push([@json(__('Assignee')), f.assignee || @json(__('Nobody'))]);
            var cust = [];
            if (on.indexOf('customer_name') >= 0 && f.customer_name) cust.push(f.customer_name);
            if (on.indexOf('customer_email') >= 0 && f.customer_email) cust.push(f.customer_email);
            if (cust.length) facts.push([@json(__('Customer')), cust.join(' · ')]);
            if (on.indexOf('tags') >= 0 && f.tags && f.tags.length) facts.push([@json(__('Tags')), f.tags.join(', ')]);
            $('.mfs-cf:checked').each(function () {
                var name = $(this).data('name');
                $.each(f.custom_fields || [], function (i, c) { if (c.name === name) facts.push([c.name, c.value]); });
            });
            var len = parseInt($('#mfs_excerpt_length').val(), 10) || 200, ex = f.excerpt || '';
            if (ex.length > len) ex = ex.substr(0, len - 1).replace(/\s+$/, '') + '…';
            var html = '<div style="color:#5b5fc7;font-weight:600;font-size:12px">' + esc(@json(__('New conversation'))) + '</div>'
                + '<div style="font-weight:600;font-size:15px;margin:2px 0 8px">#' + esc(sample.number) + (on.indexOf('subject') >= 0 && f.subject ? ' · ' + esc(f.subject) : '') + '</div>';
            if (facts.length) {
                html += '<table style="margin-bottom:8px">';
                $.each(facts, function (i, x) { html += '<tr><td style="color:#888;padding-right:12px;vertical-align:top">' + esc(x[0]) + '</td><td>' + esc(x[1]) + '</td></tr>'; });
                html += '</table>';
            }
            if (on.indexOf('excerpt') >= 0 && ex) html += '<div style="color:#666;border-top:1px solid #eee;padding-top:8px;margin-bottom:8px">' + esc(ex) + '</div>';
            html += '<div><span style="display:inline-block;border:1px solid #ccc;border-radius:4px;padding:4px 10px;font-size:12px">' + esc(@json(__('Open in FreeScout'))) + '</span></div>';
            $('#mfs_preview').html(html);
        }

        $('#mfs_preset').on('change', function () {
            var p = presets[this.value] || [];
            $('.mfs-field').each(function () { this.checked = p.indexOf(this.value) >= 0; });
            $('.mfs-cf').prop('checked', this.value === 'full');
            renderPreview();
        });
        $(document).on('change', '.mfs-field, .mfs-cf, #mfs_excerpt_length', renderPreview);
        $('#mfs_refresh').on('click', function () { current = $sel.val() || current; loadChannels(true); });
        loadChannels(false);
        renderPreview();
    })();
@endsection
