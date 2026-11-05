<?php

namespace App\Services\Work;

use App\Enums\MembershipStatus;
use App\Enums\Permission;
use App\Enums\TaskCategory;
use App\Enums\TaskStatus;
use App\Models\CalendarFeed;
use App\Models\Farm;
use App\Models\FarmMembership;
use App\Models\ProductionCycle;
use App\Models\Schedule;
use App\Models\Task;
use App\Services\Work\Ics\IcsWriter;
use App\Support\Access\FarmContext;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * One-way phone calendar subscription (ICS). Each user + farm has one secret link; the token is stored as a SHA-256 hash (that is what the public feed
 * looks up) plus an encrypted copy so Settings can show the link again. Turning it off or making a new link kills the old one immediately.
 * The feed shows exactly what GET /calendar shows that user (same visibility), from 30 days back to 180 days ahead.
 */
class CalendarFeedService
{
    public const PRODUCT = 'Farmvest';

    public const DAYS_BACK = 30;

    public const DAYS_AHEAD = 180;

    private const EVENT_MINUTES = 30;

    public function __construct(private CalendarService $calendar, private WorkSupport $support) {}

    /** @return array{enabled: bool, include_milestones: bool, https_url: ?string, webcal_url: ?string} */
    public function show(FarmContext $ctx): array
    {
        $ctx->authorize(Permission::TaskView);

        return $this->describe(CalendarFeed::where('farm_id', $ctx->farm->id)->where('user_id', $ctx->membership->user_id)->first());
    }

    /**
     * Turns the feed on, or issues a new link (the old one stops working). Without `regenerate` an already-active link is kept and only
     * the milestone option changes.
     *
     * @return array{0: array{enabled: bool, include_milestones: bool, https_url: ?string, webcal_url: ?string}, 1: bool} the feed and whether a new link was issued
     */
    public function enable(FarmContext $ctx, array $input): array
    {
        $ctx->authorize(Permission::TaskView);

        return DB::transaction(function () use ($ctx, $input) {
            $feed = CalendarFeed::where('farm_id', $ctx->farm->id)->where('user_id', $ctx->membership->user_id)->lockForUpdate()->first()
                ?? new CalendarFeed(['farm_id' => $ctx->farm->id, 'user_id' => $ctx->membership->user_id]);
            $issue = ! $feed->exists || ! $feed->enabled || $feed->token_hash === null || ! empty($input['regenerate']);
            if ($issue) {
                $token = bin2hex(random_bytes(24));
                $feed->forceFill(['token_hash' => hash('sha256', $token), 'token_encrypted' => Crypt::encryptString($token), 'enabled' => true]);
            }
            if (array_key_exists('include_milestones', $input)) {
                $feed->include_milestones = (bool) $input['include_milestones'];
            }
            $feed->save();

            return [$this->describe($feed), $issue];
        });
    }

    /** Turns the feed off; the link stops working at once. */
    public function disable(FarmContext $ctx): array
    {
        $ctx->authorize(Permission::TaskView);
        $feed = CalendarFeed::where('farm_id', $ctx->farm->id)->where('user_id', $ctx->membership->user_id)->first();
        $feed?->forceFill(['enabled' => false, 'token_hash' => null, 'token_encrypted' => null])->save();

        return $this->describe($feed?->refresh());
    }

    /** The iCalendar text for a token, or null when the token is unknown, the feed is off, or the member lost access (the caller answers 404). */
    public function render(string $token): ?string
    {
        $feed = CalendarFeed::where('token_hash', hash('sha256', $token))->where('enabled', true)->first();
        if (! $feed) {
            return null;
        }
        $farm = Farm::find($feed->farm_id);
        $membership = FarmMembership::active()->where('farm_id', $feed->farm_id)->where('user_id', $feed->user_id)->with('user')->first();
        if (! $farm || ! $membership || $membership->status !== MembershipStatus::Active || ! $membership->user || $membership->user->isSuspended()) {
            return null;
        }
        $membership->setRelation('farm', $farm);
        $ctx = new FarmContext($farm, $membership);
        if (! $ctx->can(Permission::TaskView)) {
            return null;
        }

        $now = CarbonImmutable::now();
        $today = $this->support->today($farm, $now);
        $from = CarbonImmutable::parse($today, 'UTC')->subDays(self::DAYS_BACK)->toDateString();
        $to = CarbonImmutable::parse($today, 'UTC')->addDays(self::DAYS_AHEAD)->toDateString();
        $result = $this->calendar->collect($ctx, ['from' => $from, 'to' => $to, 'include_milestones' => $feed->include_milestones, 'include_upcoming_repeats' => true]);
        $items = array_values(array_filter($result['items'], fn ($i) => $i['kind'] !== 'task' || $i['task']->status !== TaskStatus::Cancelled));

        $batchIds = collect($items)->map(fn ($i) => $i['kind'] === 'task' ? $i['task']->production_cycle_id : ($i['production_cycle_id'] ?? null))->filter()->unique()->all();
        $batches = ProductionCycle::where('farm_id', $farm->id)->whereIn('id', $batchIds)->pluck('name', 'id');
        $schedules = Schedule::where('farm_id', $farm->id)->whereIn('id', collect($items)->where('kind', 'upcoming_repeat')->pluck('schedule_id')->unique()->all())->get()->keyBy('id');

        $tz = $farm->timezone;
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'farmvest.local';
        $stamp = $now->utc()->format('Ymd\THis\Z');
        $lines = [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//'.self::PRODUCT.'//Farm tasks//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'X-WR-CALNAME:'.IcsWriter::escape($farm->name.' tasks'), 'X-WR-TIMEZONE:'.$tz, 'REFRESH-INTERVAL;VALUE=DURATION:PT1H', 'X-PUBLISHED-TTL:PT1H',
            ...$this->timezone($tz, CarbonImmutable::parse($from, 'UTC'), CarbonImmutable::parse($to, 'UTC')),
        ];
        $base = (string) config('identity.invitations.frontend_url');
        foreach ($items as $item) {
            array_push($lines, ...match ($item['kind']) {
                'task' => $this->taskEvent($item['task'], $farm, $batches[$item['task']->production_cycle_id] ?? null, $host, $base),
                'upcoming_repeat' => $this->repeatEvent($item, $schedules[$item['schedule_id']] ?? null, $farm, $batches[$item['production_cycle_id'] ?? ''] ?? null, $host, $base, $stamp),
                default => $this->milestoneEvent($item, $host, $stamp),
            });
        }
        $lines[] = 'END:VCALENDAR';

        return IcsWriter::document($lines);
    }

    /** @return array{enabled: bool, include_milestones: bool, https_url: ?string, webcal_url: ?string} */
    private function describe(?CalendarFeed $feed): array
    {
        if (! $feed || ! $feed->enabled || $feed->token_encrypted === null) {
            return ['enabled' => false, 'include_milestones' => (bool) $feed?->include_milestones, 'https_url' => null, 'webcal_url' => null];
        }
        $url = url('/api/v1/calendar-feed/'.Crypt::decryptString($feed->token_encrypted).'.ics');
        $https = preg_replace('#^http://#', 'https://', $url);

        return ['enabled' => true, 'include_milestones' => $feed->include_milestones, 'https_url' => $https, 'webcal_url' => preg_replace('#^https?://#', 'webcal://', $url)];
    }

    /** @return list<string> */
    private function taskEvent(Task $task, Farm $farm, ?string $batch, string $host, string $base): array
    {
        $done = $task->status === TaskStatus::Completed;
        $uid = $task->schedule_id !== null ? 'occ-'.$task->schedule_id.'-'.$task->occurrence_date->toDateString() : 'task-'.$task->id;
        $time = $task->due_time !== null ? substr($task->due_time, 0, 5) : null;
        $notes = array_filter([$task->instructions, $done ? 'Status: completed' : null]);
        $lines = $this->event($uid.'@'.$host, gmdate('Ymd\THis\Z', $task->updated_at->getTimestamp()), ($done ? "\u{2713} " : '').$task->title, $task->due_date->toDateString(), $time, $farm->timezone,
            $task->category->label(), $batch, $notes, $base.config('identity.invitations.task_path').'/'.$task->id);
        if (! $done) {
            array_push($lines, ...$this->alarms($task->title, $farm->timezone, $task->due_date->toDateString(), $time, $task->due_at, $task->reminder_presets, $task->reminder_offsets));
        }
        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /** @return list<string> */
    private function repeatEvent(array $item, ?Schedule $schedule, Farm $farm, ?string $batch, string $host, string $base, string $stamp): array
    {
        $time = $item['time'];
        $lines = $this->event('occ-'.$item['schedule_id'].'-'.$item['date'].'@'.$host, $schedule ? gmdate('Ymd\THis\Z', $schedule->updated_at->getTimestamp()) : $stamp, $item['title'], $item['date'], $time, $farm->timezone,
            TaskCategory::from($item['category'])->label(), $batch, array_filter([$schedule?->instructions]), $base.'/calendar');
        if ($schedule) {
            array_push($lines, ...$this->alarms($item['title'], $farm->timezone, $item['date'], $time, $this->support->dueAt($farm, $item['date'], $time), $schedule->reminder_presets, $schedule->reminder_offsets));
        }
        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /** @return list<string> */
    private function milestoneEvent(array $item, string $host, string $stamp): array
    {
        $end = CarbonImmutable::parse($item['end_date'] ?? $item['date'], 'UTC')->addDay()->format('Ymd');

        return [
            'BEGIN:VEVENT', 'UID:ms-'.$item['code'].'-'.$item['source']['id'].'-'.$item['date'].'@'.$host, 'DTSTAMP:'.$stamp, 'SUMMARY:'.IcsWriter::escape($item['title']),
            'DTSTART;VALUE=DATE:'.str_replace('-', '', $item['date']), 'DTEND;VALUE=DATE:'.$end, 'CATEGORIES:Milestone', 'TRANSP:TRANSPARENT', 'END:VEVENT',
        ];
    }

    /**
     * The event up to (not including) END:VEVENT. All-day when there is no due time, otherwise a 30-minute event in the farm timezone.
     *
     * @param  list<string>  $notes
     * @return list<string>
     */
    private function event(string $uid, string $stamp, string $summary, string $date, ?string $time, string $tz, string $category, ?string $batch, array $notes, string $url): array
    {
        $description = array_merge($notes, ['Category: '.$category], $batch ? ['Batch: '.$batch] : [], ['Open in '.self::PRODUCT.': '.$url]);
        $lines = ['BEGIN:VEVENT', 'UID:'.$uid, 'DTSTAMP:'.$stamp, 'SUMMARY:'.IcsWriter::escape($summary)];
        if ($time === null) {
            $lines[] = 'DTSTART;VALUE=DATE:'.str_replace('-', '', $date);
            $lines[] = 'DTEND;VALUE=DATE:'.CarbonImmutable::parse($date, 'UTC')->addDay()->format('Ymd');
        } else {
            $start = CarbonImmutable::parse($date.' '.$time, $tz);
            $lines[] = 'DTSTART;TZID='.$tz.':'.$start->format('Ymd\THis');
            $lines[] = 'DTEND;TZID='.$tz.':'.$start->addMinutes(self::EVENT_MINUTES)->format('Ymd\THis');
        }
        array_push($lines, 'CATEGORIES:'.IcsWriter::escape($category), 'DESCRIPTION:'.IcsWriter::escape(implode("\n", $description)), 'URL:'.$url, 'STATUS:CONFIRMED', 'TRANSP:TRANSPARENT');
        if ($batch !== null) {
            $lines[] = 'X-BATCH:'.IcsWriter::escape($batch);
        }

        return $lines;
    }

    /**
     * One VALARM per reminder, relative to the event start. Uses the same fire times as the e-mail reminders (ReminderPlan).
     *
     * @return list<string>
     */
    private function alarms(string $title, string $tz, string $date, ?string $time, CarbonImmutable $dueAt, ?array $presets, ?array $offsets): array
    {
        $start = $time !== null ? CarbonImmutable::parse($date.' '.$time, $tz)->utc() : CarbonImmutable::parse($date, $tz)->startOfDay()->utc();
        $out = [];
        $seen = [];
        foreach (ReminderPlan::fireTimes($tz, $date, $time, $presets, $offsets, $dueAt) as $fire) {
            $minutes = intdiv($fire->getTimestamp() - $start->getTimestamp(), 60);
            if (isset($seen[$minutes])) {
                continue;
            }
            $seen[$minutes] = true;
            array_push($out, 'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:'.IcsWriter::escape($title), 'TRIGGER;RELATED=START:'.IcsWriter::duration($minutes), 'END:VALARM');
        }

        return $out;
    }

    /** A VTIMEZONE for the farm's zone covering the feed window (so clients that do not know the zone name still place events correctly). @return list<string> */
    private function timezone(string $tz, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $transitions = (new DateTimeZone($tz))->getTransitions($from->getTimestamp(), $to->getTimestamp());
        $fmt = fn (int $seconds) => sprintf('%s%02d%02d', $seconds < 0 ? '-' : '+', intdiv(abs($seconds), 3600), intdiv(abs($seconds) % 3600, 60));
        $lines = ['BEGIN:VTIMEZONE', 'TZID:'.$tz];
        $previous = null;
        foreach ($transitions as $i => $t) {
            $type = $t['isdst'] ? 'DAYLIGHT' : 'STANDARD';
            $startsAt = $i === 0 ? '19700101T000000' : gmdate('Ymd\THis', $t['ts'] + $previous['offset']);
            array_push($lines, 'BEGIN:'.$type, 'DTSTART:'.$startsAt, 'TZOFFSETFROM:'.$fmt($previous['offset'] ?? $t['offset']), 'TZOFFSETTO:'.$fmt($t['offset']), 'TZNAME:'.$t['abbr'], 'END:'.$type);
            $previous = $t;
        }
        $lines[] = 'END:VTIMEZONE';

        return $lines;
    }
}
