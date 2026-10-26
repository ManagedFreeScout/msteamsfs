<?php

namespace Modules\MSTeamsFS\Http\Controllers;

use App\Conversation;
use App\Mailbox;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\MSTeamsFS\Services\ChannelPoster;

/**
 * Mailbox settings → Teams (msteamsfs card #339): the built-in rule that posts this mailbox's new
 * conversations and customer replies as cards into one Teams channel.
 */
class TeamsChannelsController extends Controller
{
    protected function mailboxOrAbort($id)
    {
        $mailbox = Mailbox::findOrFail($id);
        $user = auth()->user();
        if (!$user || !($user->isAdmin() || $user->can('updateSettings', $mailbox))) {
            abort(403);
        }
        return $mailbox;
    }

    public function show($id)
    {
        $mailbox = $this->mailboxOrAbort($id);
        $rule = ChannelPoster::ruleFor($mailbox->id);
        $card = ChannelPoster::cardConfig($rule ? json_decode((string) $rule->card, true) : null);
        $events = $rule ? (json_decode((string) $rule->events, true) ?: []) : ChannelPoster::EVENTS;
        $customFields = ChannelPoster::customFieldsAvailable()
            ? \Modules\CustomFields\Entities\CustomField::getMailboxCustomFields($mailbox->id)
            : collect();

        // Preview data: the latest conversation of this mailbox with every field filled in, so the
        // preview can show what each choice adds. Only shown to this mailbox's admins.
        $latest = Conversation::where('mailbox_id', $mailbox->id)->where('state', Conversation::STATE_PUBLISHED)
            ->orderBy('created_at', 'desc')->first();
        $full = ChannelPoster::cardConfig(['preset' => 'full', 'fields' => ChannelPoster::FIELDS, 'excerpt_length' => 500, 'all_custom_fields' => true]);
        $sample = $latest
            ? ['number' => $latest->number, 'fields' => ChannelPoster::buildFields($latest, $full), 'custom_field_ids' => $this->customFieldIds($mailbox, $latest)]
            : ['number' => 1234, 'fields' => [
                'subject' => __('Example: cannot log in'), 'status' => __('Active'), 'mailbox' => $mailbox->name, 'assignee' => '',
                'customer_name' => 'Jane Doe', 'customer_email' => 'jane@example.com', 'tags' => ['example'],
                'excerpt' => __('Hello, since this morning I cannot log in to the portal. Can you help?'),
            ], 'custom_field_ids' => []];

        return view('msteamsfs::mailbox.teams', [
            'mailbox'        => $mailbox,
            'rule'           => $rule,
            'card'           => $card,
            'events'         => $events,
            'customFields'   => $customFields,
            'tagsAvailable'  => ChannelPoster::tagsAvailable(),
            'workflowPosts'  => ChannelPoster::workflowPosts($mailbox->id),
            'sample'         => $sample,
            'presets'        => ChannelPoster::PRESETS,
            'problemResults' => ChannelPoster::PROBLEM_RESULTS,
        ]);
    }

    protected function customFieldIds($mailbox, $conversation)
    {
        if (!ChannelPoster::customFieldsAvailable()) {
            return [];
        }
        $ids = [];
        foreach (\Modules\CustomFields\Entities\CustomField::getCustomFieldsWithValues($mailbox->id, $conversation->id) as $cf) {
            if ((string) $cf->getAsText() !== '') {
                $ids[(string) $cf->name] = (int) $cf->id;
            }
        }
        return $ids;
    }

    public function save(Request $request, $id)
    {
        $mailbox = $this->mailboxOrAbort($id);
        $rule = ChannelPoster::ruleFor($mailbox->id);
        $channelId = trim((string) $request->input('channel_id', ''));

        // A newly chosen channel must be one the bot knows in this install's own tenant.
        if ($channelId !== '' && (!$rule || $rule->channel_id !== $channelId)) {
            $list = ChannelPoster::channels(true);
            $known = array_column($list['channels'] ?? [], 'channel_id');
            if (!in_array($channelId, $known, true)) {
                \Session::flash('flash_error_floating', __('That Teams channel is not available. Click "Refresh channels" and choose again.'));
                return redirect()->route('msteamsfs.mailbox.teams', ['id' => $mailbox->id]);
            }
        }

        $events = array_values(array_intersect(ChannelPoster::EVENTS, (array) $request->input('events', [])));
        $card = ChannelPoster::cardConfig([
            'preset'         => $request->input('preset'),
            'fields'         => (array) $request->input('fields', []),
            'excerpt_length' => $request->input('excerpt_length'),
            'custom_fields'  => (array) $request->input('custom_fields', []),
        ]);
        $values = [
            'enabled'    => $request->input('enabled') && $channelId !== '' && $events,
            'channel_id' => $channelId !== '' ? $channelId : null,
            'events'     => json_encode($events),
            'card'       => json_encode($card),
            'updated_at' => now(),
        ];
        if (!$rule || $rule->channel_id !== $values['channel_id']) {
            $values['last_result'] = null;
            $values['last_result_at'] = null;
        }
        if ($rule) {
            \DB::table('msteamsfs_channel_rules')->where('id', $rule->id)->update($values);
        } else {
            \DB::table('msteamsfs_channel_rules')->insert(array_merge($values, ['mailbox_id' => $mailbox->id, 'created_at' => now()]));
        }
        \Session::flash('flash_success_floating', __('Settings saved'));
        return redirect()->route('msteamsfs.mailbox.teams', ['id' => $mailbox->id]);
    }

    // Channel dropdown (opened, or "Refresh channels").
    public function channels(Request $request, $id)
    {
        $this->mailboxOrAbort($id);
        return response()->json(ChannelPoster::channels($request->input('refresh') === '1'));
    }
}
