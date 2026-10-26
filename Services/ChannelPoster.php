<?php

namespace Modules\MSTeamsFS\Services;

use App\Conversation;
use App\Thread;

/**
 * Teams channel posting (msteamsfs card #339): one engine for the built-in rule per mailbox and the
 * Workflows action. It builds the field set the admin enabled and sends it to the hub, which builds
 * the Adaptive Card and posts it with the MFS Connect bot. Attachments and full message bodies are
 * never sent; the excerpt (off by default) is the only message text.
 */
class ChannelPoster
{
    const EVENTS = ['new_conversation', 'customer_reply'];

    const FIELDS = ['subject', 'status', 'mailbox', 'assignee', 'customer_name', 'customer_email', 'tags', 'excerpt'];

    const PRESETS = [
        'minimal'  => [],
        'standard' => ['subject', 'status', 'mailbox', 'assignee'],
        'full'     => ['subject', 'status', 'mailbox', 'assignee', 'customer_name', 'customer_email', 'tags', 'excerpt'],
    ];

    const EXCERPT_LENGTHS = [200, 500];

    // Results from the hub that mean the post did not happen and the admin should look.
    const PROBLEM_RESULTS = ['channel_inactive', 'channel_unsupported', 'bot_removed', 'channel_unknown', 'failed', 'unreachable', 'license_inactive', 'tenant_not_connected', 'bot_not_configured'];

    protected static $sentThisRequest = [];

    // A card configuration: which fields, excerpt length, which custom fields. Always normalised.
    public static function cardConfig($card = null)
    {
        $card = is_array($card) ? $card : [];
        $preset = isset(self::PRESETS[$card['preset'] ?? '']) ? $card['preset'] : 'standard';
        $fields = isset($card['fields']) && is_array($card['fields'])
            ? array_values(array_intersect(self::FIELDS, $card['fields']))
            : self::PRESETS[$preset];
        $length = (int) ($card['excerpt_length'] ?? 200);
        return [
            'preset'         => $preset,
            'fields'         => $fields,
            'excerpt_length' => in_array($length, self::EXCERPT_LENGTHS, true) ? $length : 200,
            'custom_fields'  => array_values(array_filter(array_map('intval', (array) ($card['custom_fields'] ?? [])))),
            'all_custom_fields' => !empty($card['all_custom_fields']),
        ];
    }

    public static function presetConfig($preset)
    {
        $preset = isset(self::PRESETS[$preset]) ? $preset : 'standard';
        return self::cardConfig([
            'preset' => $preset,
            'fields' => self::PRESETS[$preset],
            'all_custom_fields' => $preset === 'full',
        ]);
    }

    public static function tagsAvailable()
    {
        return class_exists('\Modules\Tags\Entities\Tag') && \Module::isActive('tags');
    }

    public static function customFieldsAvailable()
    {
        return class_exists('\Modules\CustomFields\Entities\CustomField') && \Module::isActive('customfields');
    }

    // The fields for one conversation, limited to what the card configuration enables.
    public static function buildFields(Conversation $conversation, array $card, $thread = null)
    {
        $on = array_flip($card['fields']);
        $f = [];
        if (isset($on['subject'])) {
            $f['subject'] = (string) $conversation->subject;
        }
        if (isset($on['status'])) {
            $f['status'] = (string) $conversation->getStatusName();
        }
        if (isset($on['mailbox'])) {
            $f['mailbox'] = $conversation->mailbox ? (string) $conversation->mailbox->name : '';
        }
        if (isset($on['assignee'])) {
            $f['assignee'] = $conversation->user ? (string) $conversation->user->getFullName() : '';
        }
        if (isset($on['customer_name'])) {
            $f['customer_name'] = $conversation->customer ? (string) $conversation->customer->getFullName(true) : '';
        }
        if (isset($on['customer_email'])) {
            $f['customer_email'] = (string) $conversation->customer_email;
        }
        if (isset($on['tags']) && self::tagsAvailable()) {
            $f['tags'] = \Modules\Tags\Entities\Tag::conversationTags($conversation)->pluck('name')->all();
        }
        if (isset($on['excerpt'])) {
            // Only customer messages and replies, never internal notes.
            $thread = $thread && in_array($thread->type, [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE])
                ? $thread
                : $conversation->getLastThread([Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE]);
            if ($thread) {
                $text = trim(preg_replace('/\s+/u', ' ', (string) $thread->getBodyAsText()));
                $f['excerpt'] = mb_strlen($text) > $card['excerpt_length']
                    ? rtrim(mb_substr($text, 0, $card['excerpt_length'] - 1)) . '…'
                    : $text;
            }
        }
        if (($card['custom_fields'] || $card['all_custom_fields']) && self::customFieldsAvailable()) {
            $all = $card['all_custom_fields'];
            $values = [];
            foreach (\Modules\CustomFields\Entities\CustomField::getCustomFieldsWithValues($conversation->mailbox_id, $conversation->id) as $cf) {
                if (($all || in_array((int) $cf->id, $card['custom_fields'], true)) && (string) $cf->getAsText() !== '') {
                    $values[] = ['name' => (string) $cf->name, 'value' => (string) $cf->getAsText()];
                }
            }
            if ($values) {
                $f['custom_fields'] = $values;
            }
        }
        return $f;
    }

    /**
     * Post one event for one conversation to one channel. Returns the hub's result: posted, replied,
     * updated, skipped, or one of PROBLEM_RESULTS. $ruleId: the built-in rule, to record its last result.
     */
    public static function post($channelId, $event, Conversation $conversation, array $card, $thread = null, $ruleId = null)
    {
        $key = $conversation->id . '|' . $channelId . '|' . $event;
        if (isset(self::$sentThisRequest[$key])) {
            return 'skipped';
        }
        self::$sentThisRequest[$key] = true;

        $response = app(LicenseService::class)->hubPost('/teams/bot/post', [
            'channel_id'      => (string) $channelId,
            'conversation_id' => (int) $conversation->id,
            'number'          => (int) $conversation->number,
            'event'           => $event,
            'fields'          => self::buildFields($conversation, $card, $thread),
        ]);
        if ($response === null) {
            $result = 'unreachable';
        } else {
            $result = (string) ($response['data']['result'] ?? $response['data']['error'] ?? ('http_' . $response['status']));
        }

        if (in_array($result, ['posted', 'replied'], true)) {
            \DB::table('msteamsfs_channel_posts')->updateOrInsert(
                ['conversation_id' => $conversation->id, 'channel_id' => (string) $channelId],
                ['card' => json_encode($card), 'updated_at' => now(), 'created_at' => now()]
            );
        }
        if (in_array($result, self::PROBLEM_RESULTS, true) || strpos($result, 'http_') === 0) {
            \Log::warning("MFS Connect: Teams channel post for conversation #{$conversation->number} ({$event}) not delivered: {$result}");
        }
        if ($ruleId) {
            \DB::table('msteamsfs_channel_rules')->where('id', $ruleId)->update(['last_result' => substr($result, 0, 40), 'last_result_at' => now()]);
        }
        return $result;
    }

    // Status or assignee changed: update the cards of every channel this conversation was posted to.
    public static function updateCards(Conversation $conversation, $event)
    {
        $rule = self::ruleFor($conversation->mailbox_id);
        foreach (\DB::table('msteamsfs_channel_posts')->where('conversation_id', $conversation->id)->get() as $row) {
            $card = self::cardConfig(json_decode((string) $row->card, true));
            $ruleId = ($rule && $rule->channel_id === $row->channel_id) ? $rule->id : null;
            self::post($row->channel_id, $event, $conversation, $card, null, $ruleId);
        }
    }

    public static function ruleFor($mailboxId)
    {
        return \DB::table('msteamsfs_channel_rules')->where('mailbox_id', $mailboxId)->first();
    }

    public static function ruleWants($rule, $event)
    {
        if (!$rule || !$rule->enabled || !$rule->channel_id) {
            return false;
        }
        $events = json_decode((string) $rule->events, true);
        return is_array($events) && in_array($event, $events, true);
    }

    /**
     * Channels the bot can post to in this install's Microsoft 365 tenant, from the hub.
     * Returns ['channels' => [...]] or ['error' => code].
     */
    public static function channels($refresh = false)
    {
        $cacheKey = 'msteamsfs.channels';
        if (!$refresh && ($cached = \Cache::get($cacheKey))) {
            return $cached;
        }
        $response = app(LicenseService::class)->hubPost('/teams/bot/channels', ['refresh' => (bool) $refresh]);
        if ($response === null) {
            return ['error' => 'unreachable'];
        }
        if ($response['status'] !== 200) {
            return ['error' => (string) ($response['data']['error'] ?? ('http_' . $response['status']))];
        }
        $out = ['channels' => array_values((array) ($response['data']['channels'] ?? []))];
        \Cache::put($cacheKey, $out, 2); // minutes: a short cache, the dropdown fetches fresh often
        return $out;
    }

    // Workflows in a mailbox that post to Teams (for the duplicate and dead-channel warnings).
    public static function workflowPosts($mailboxId)
    {
        if (!class_exists('\Modules\Workflows\Entities\Workflow') || !\Module::isActive('workflows')) {
            return [];
        }
        $out = [];
        $workflows = \Modules\Workflows\Entities\Workflow::where('mailbox_id', $mailboxId)
            ->where('actions', \Helper::sqlLikeOperator(), '%"type":"msteamsfs_teams"%')->get();
        foreach ($workflows as $workflow) {
            foreach ((array) $workflow->actions as $ands) {
                foreach ((array) $ands as $action) {
                    if (($action['type'] ?? '') === 'msteamsfs_teams' && !empty($action['value'])) {
                        $out[] = ['workflow' => $workflow, 'channel_id' => (string) $action['value']];
                    }
                }
            }
        }
        return $out;
    }
}
