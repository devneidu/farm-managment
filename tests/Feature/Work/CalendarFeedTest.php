<?php

namespace Tests\Feature\Work;

use App\Enums\FarmRole;
use App\Models\CalendarFeed;
use App\Models\Schedule;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Team\TeamTestCase;

class CalendarFeedTest extends TeamTestCase
{
    use TaskTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->onPlan('farm-pro');
        $this->freezeClock();
        $this->signInAs($this->owner);
    }

    private function turnOn(array $extra = []): array
    {
        return $this->postJson('/api/v1/calendar-feed', $extra)->json('data');
    }

    private function path(array $feed): string
    {
        return parse_url($feed['https_url'], PHP_URL_PATH);
    }

    private function token(array $feed): string
    {
        return basename($this->path($feed), '.ics');
    }

    private function feed(array $feed)
    {
        return $this->get($this->path($feed));
    }

    /** The calendar text with folded lines joined back together. */
    private function body(array $feed): string
    {
        $res = $this->feed($feed)->assertOk();
        $res->assertHeader('Content-Type', 'text/calendar; charset=utf-8');

        return str_replace("\r\n ", '', $res->getContent());
    }

    /** @return list<string> the VEVENT blocks of an unfolded calendar */
    private function events(string $body): array
    {
        preg_match_all('/BEGIN:VEVENT\r\n.*?END:VEVENT\r\n/s', $body, $m);

        return $m[0];
    }

    private function event(string $body, string $needle): string
    {
        foreach ($this->events($body) as $e) {
            if (str_contains($e, $needle)) {
                return $e;
            }
        }
        $this->fail("No event contains: $needle");
    }

    // ------------------------------------------------------------------ link lifecycle

    public function test_the_link_can_be_turned_on_renewed_and_turned_off(): void
    {
        $this->getJson('/api/v1/calendar-feed')->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.https_url', null);

        $first = $this->postJson('/api/v1/calendar-feed')->assertCreated()->json('data');
        $this->assertTrue($first['enabled']);
        $this->assertStringStartsWith('https://', $first['https_url']);
        $this->assertStringStartsWith('webcal://', $first['webcal_url']);
        $this->assertStringEndsWith('.ics', $first['webcal_url']);
        $token = $this->token($first);
        $this->assertGreaterThanOrEqual(40, strlen($token));
        $this->assertSame($first['https_url'], $this->getJson('/api/v1/calendar-feed')->json('data.https_url'), 'Settings can show the link again');
        $row = CalendarFeed::first();
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertStringNotContainsString($token, json_encode($row->getAttributes()), 'the token is not stored in the clear');

        $same = $this->postJson('/api/v1/calendar-feed')->assertOk()->json('data');
        $this->assertSame($first['https_url'], $same['https_url'], 'turning on again keeps the link');

        $new = $this->postJson('/api/v1/calendar-feed', ['regenerate' => true])->assertCreated()->json('data');
        $this->assertNotSame($first['https_url'], $new['https_url']);
        $this->get($this->path($first))->assertNotFound();
        $this->feed($new)->assertOk();
        $this->assertSame(1, CalendarFeed::count());

        $this->deleteJson('/api/v1/calendar-feed')->assertOk()->assertJsonPath('data.enabled', false);
        $this->feed($new)->assertNotFound();
        $this->getJson('/api/v1/calendar-feed')->assertJsonPath('data.enabled', false);
        $this->deleteJson('/api/v1/calendar-feed')->assertOk();
        $turnedOnAgain = $this->postJson('/api/v1/calendar-feed')->assertCreated()->json('data');
        $this->assertNotSame($new['https_url'], $turnedOnAgain['https_url'], 'a turned-off link never comes back');
    }

    public function test_unknown_tokens_and_the_feed_url_need_no_session_but_are_rate_limited(): void
    {
        $this->get('/api/v1/calendar-feed/'.str_repeat('a', 48).'.ics')->assertNotFound();
        $this->get('/api/v1/calendar-feed/short.ics')->assertNotFound();
        for ($i = 0; $i < 30; $i++) {
            $this->get('/api/v1/calendar-feed/'.str_repeat('b', 48).'.ics');
        }
        $this->get('/api/v1/calendar-feed/'.str_repeat('c', 48).'.ics')->assertStatus(429);
    }

    // ------------------------------------------------------------------ content

    public function test_the_feed_is_valid_icalendar_with_escaping_folding_alarms_and_the_right_statuses(): void
    {
        $cycle = $this->cycle();
        $batch = $this->getJson('/api/v1/production-cycles/'.$cycle)->json('data.name');
        $long = 'Inspect the café drinkers, nipples; and \\ lines — '.str_repeat('éàü', 30);
        $timed = $this->task(['title' => 'Feed, water; check \\ now', 'due_time' => '14:00', 'reminders' => ['on_the_day', '1_day'], 'production_cycle_id' => $cycle, 'instructions' => "Line one\nLine two"])->assertCreated()->json('data');
        $allDay = $this->task(['title' => $long, 'reminders' => ['on_the_day'], 'due_date' => $this->day(1)])->assertCreated()->json('data');
        $legacy = $this->task(['title' => 'Legacy alarm', 'due_time' => '16:00', 'reminder_offsets' => [60]])->assertCreated()->json('data');
        $done = $this->task(['title' => 'Done job', 'reminders' => ['on_the_day']])->assertCreated()->json('data');
        $this->postJson('/api/v1/tasks/'.$done['id'].'/complete')->assertOk();
        $gone = $this->task(['title' => 'Cancelled job'])->assertCreated()->json('data');
        $this->postJson('/api/v1/tasks/'.$gone['id'].'/cancel', ['reason' => 'No longer needed'])->assertOk();
        $this->task(['title' => 'Too old', 'due_date' => $this->day(-45)])->assertCreated();
        $this->task(['title' => 'Recent', 'due_date' => $this->day(-20)])->assertCreated();
        $this->task(['title' => 'Too far', 'due_date' => $this->day(200)])->assertCreated();
        $repeat = $this->repeating(['pattern' => 'monthly'], ['title' => 'Month end', 'due_date' => '2026-10-31'])->assertCreated()->json('data');

        $feed = $this->turnOn();
        $res = $this->feed($feed)->assertOk();
        $raw = $res->getContent();
        $res->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:", $raw);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $raw);
        $this->assertSame(0, preg_match('/(?<!\r)\n/', $raw), 'every line ends in CRLF');
        foreach (explode("\r\n", $raw) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), 'no physical line is longer than 75 octets: '.$line);
            $this->assertTrue(mb_check_encoding($line, 'UTF-8'), 'folding never splits a character');
        }
        $body = str_replace("\r\n ", '', $raw);
        $this->assertSame(substr_count($body, 'BEGIN:VEVENT'), substr_count($body, 'END:VEVENT'));
        $this->assertSame(substr_count($body, 'BEGIN:VALARM'), substr_count($body, 'END:VALARM'));

        // Escaping, timed vs all-day, batch and link back.
        $e = $this->event($body, 'UID:task-'.$timed['id']);
        $this->assertStringContainsString("SUMMARY:Feed\\, water\\; check \\\\ now\r\n", $e);
        $this->assertStringContainsString("DTSTART;TZID=Africa/Lagos:20261014T140000\r\n", $e);
        $this->assertStringContainsString("DTEND;TZID=Africa/Lagos:20261014T143000\r\n", $e);
        $this->assertStringContainsString("CATEGORIES:Feeding / watering\r\n", $e);
        $this->assertStringContainsString("X-BATCH:$batch\r\n", $e);
        $this->assertStringContainsString('Batch: '.$batch.'\\n', $e);
        $this->assertStringContainsString('Line one\\nLine two', $e, 'newlines are escaped');
        $this->assertStringContainsString('/tasks/'.$timed['id'], $e);
        $this->assertStringContainsString("STATUS:CONFIRMED\r\n", $e);
        // 14:00 task: on the day 07:00 = 7 h before; 1 day before 07:00 = 31 h before.
        $this->assertStringContainsString("TRIGGER;RELATED=START:-PT7H\r\n", $e);
        $this->assertStringContainsString("TRIGGER;RELATED=START:-PT31H\r\n", $e);
        $this->assertSame(2, substr_count($e, 'BEGIN:VALARM'));

        $a = $this->event($body, 'UID:task-'.$allDay['id']);
        $this->assertStringContainsString("DTSTART;VALUE=DATE:20261015\r\n", $a);
        $this->assertStringContainsString("DTEND;VALUE=DATE:20261016\r\n", $a);
        $this->assertStringContainsString("TRIGGER;RELATED=START:PT7H\r\n", $a, 'all-day: 07:00 on the day is 7 h after midnight');
        $this->assertStringContainsString($long, str_replace(['\\\\', '\\,', '\\;'], ['\\', ',', ';'], $a), 'the long title survives folding intact');

        $this->assertStringContainsString("TRIGGER;RELATED=START:-PT1H\r\n", $this->event($body, 'UID:task-'.$legacy['id']), 'minute offsets become alarms too');

        $d = $this->event($body, 'UID:task-'.$done['id']);
        $this->assertStringContainsString("SUMMARY:\u{2713} Done job\r\n", $d, 'completed tasks are marked');
        $this->assertStringContainsString('Status: completed', $d);
        $this->assertStringNotContainsString('VALARM', $d, 'no alarm for finished work');

        $this->assertStringNotContainsString('Cancelled job', $body);
        $this->assertStringNotContainsString('Too old', $body);
        $this->assertStringNotContainsString('Too far', $body);
        $this->assertStringContainsString('SUMMARY:Recent', $body);

        // Coming repeats: the 30 Nov occurrence has no task yet but is in the feed, under the stable id it will keep once generated.
        $coming = $this->event($body, 'UID:occ-'.$repeat['schedule']['id'].'-2026-11-30@');
        $this->assertStringContainsString("DTSTART;VALUE=DATE:20261130\r\n", $coming);
        $this->assertStringContainsString('SUMMARY:Month end', $coming);
        // Stable UIDs: a second fetch gives identical event ids.
        preg_match_all('/UID:[^\r]+/', $body, $one);
        preg_match_all('/UID:[^\r]+/', $this->body($feed), $two);
        $this->assertSame($one[0], $two[0]);
        $this->assertSame(count($one[0]), count(array_unique($one[0])));
    }

    public function test_the_feed_uses_the_farm_timezone_and_describes_it(): void
    {
        DB::table('farms')->where('id', $this->farm->id)->update(['timezone' => 'America/New_York']);
        $this->task(['title' => 'Late vaccine', 'due_date' => '2026-10-20', 'due_time' => '14:00'])->assertCreated();
        $body = $this->body($this->turnOn());
        $this->assertStringContainsString('X-WR-TIMEZONE:America/New_York', $body);
        $this->assertStringContainsString("DTSTART;TZID=America/New_York:20261020T140000\r\n", $body);
        $this->assertMatchesRegularExpression('/BEGIN:VTIMEZONE\r\nTZID:America\/New_York\r\n.*BEGIN:DAYLIGHT.*TZOFFSETTO:-0400.*BEGIN:STANDARD.*TZOFFSETTO:-0500.*END:VTIMEZONE/s', $body);
    }

    public function test_milestones_are_optional_and_the_feed_only_shows_what_the_member_may_see(): void
    {
        $cycle = $this->cycle('chicken', ['start_date' => '2026-10-01']);
        $feed = $this->turnOn();
        $this->assertStringNotContainsString('UID:ms-', $this->body($feed));
        $with = $this->turnOn(['include_milestones' => true]);
        $this->assertSame($feed['https_url'], $with['https_url'], 'changing the option keeps the link');
        $this->assertStringContainsString('UID:ms-cycle_start-'.$cycle.'-2026-10-01@', $this->body($with));

        $worker = $this->member(FarmRole::FarmWorker, null, 'Wale Worker');
        $this->task(['title' => 'Owner only chore'])->assertCreated();
        $this->task(['title' => 'For Wale', 'assigned_user_id' => $worker->id])->assertCreated();
        $this->task(['title' => 'Everyone repeat'])->assertCreated();
        $this->repeating(['pattern' => 'monthly'], ['title' => 'Owner monthly', 'due_date' => '2026-10-31'])->assertCreated();

        $this->signInAs($worker);
        $mine = $this->turnOn();
        $this->assertNotSame($feed['https_url'], $mine['https_url'], 'each member has their own link');
        $body = $this->body($mine);
        $this->assertStringContainsString('SUMMARY:For Wale', $body);
        $this->assertStringNotContainsString('Owner only chore', $body);
        $this->assertStringNotContainsString('Owner monthly', $body, 'coming repeats follow the same visibility');
        $this->assertStringNotContainsString('UID:ms-', $body, 'milestones are off by default');
    }

    public function test_a_member_who_loses_access_gets_404_and_other_farms_never_leak(): void
    {
        $worker = $this->member(FarmRole::FarmWorker, null, 'Wale Worker');
        $this->signInAs($worker);
        $feed = $this->turnOn();
        $this->feed($feed)->assertOk();

        $this->removeMembership($worker);
        $this->feed($feed)->assertNotFound();

        [$otherOwner] = $this->otherFarm();
        $this->signInAs($otherOwner);
        $mine = $this->turnOn();
        $this->assertStringNotContainsString('Check drinkers', $this->body($mine));
        $this->assertNotSame($this->token($feed), $this->token($mine));
    }
}
