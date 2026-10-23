<?php

namespace Tests\Feature\Records;

use App\Enums\FarmRole;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\OperationalRecord;
use App\Models\OperationType;
use App\Models\Sale;
use App\Models\Species;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Feature\Team\TeamTestCase;

/** "Animals leaving a batch": one form (POST /livestock-exits) over sales, mortality and the new exit records, plus one list and one undo. */
class LivestockExitTest extends TeamTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
        $this->signInAs($this->owner);
    }

    // ------------------------------------------------------------------ helpers

    private function at(int $hoursAgo = 1): string
    {
        return now()->utc()->subHours($hoursAgo)->format('Y-m-d\TH:i:s\Z');
    }

    private function key(): string
    {
        return (string) Str::uuid();
    }

    private function cycle(int $population = 100, string $species = 'chicken'): string
    {
        $s = Species::where('code', $species)->firstOrFail();

        return $this->postJson('/api/v1/production-cycles', ['kind' => 'livestock', 'name' => ucfirst($species).' batch '.Str::random(4), 'operation_type_id' => $s->operation_type_id,
            'species_id' => $s->id, 'production_purpose' => 'meat', 'initial_population' => $population, 'start_date' => '2026-01-01'])->assertCreated()->json('data.id');
    }

    private function population(string $cycle): int
    {
        return $this->getJson('/api/v1/production-cycles/'.$cycle)->assertOk()->json('data.livestock.current_population');
    }

    private function exit(string $cycle, string $type, int $head, array $extra = [])
    {
        return $this->postJson('/api/v1/livestock-exits', array_replace(['production_cycle_id' => $cycle, 'type' => $type, 'head_count' => $head, 'recorded_at' => $this->at(2), 'idempotency_key' => $this->key()], $extra));
    }

    private function undo(string $record, array $extra = [])
    {
        return $this->postJson('/api/v1/livestock-exits/'.$record.'/reverse', array_replace(['reason' => 'Entered by mistake', 'recorded_at' => $this->at(1), 'idempotency_key' => $this->key()], $extra));
    }

    private function meatItem(): ?InventoryItem
    {
        return InventoryItem::where('system_key', 'like', 'output:meat:%')->first();
    }

    private function stock(string $item): string
    {
        return $this->getJson('/api/v1/inventory/items/'.$item)->assertOk()->json('data.stock.quantity');
    }

    // ------------------------------------------------------------------ each type

    public function test_sold_creates_the_sale_with_buyer_price_and_payment_in_one_request(): void
    {
        $cycle = $this->cycle(50);
        $body = ['unit_price' => '6500.00', 'buyer' => ['name' => 'Mama Ngozi', 'phone' => '08031234567'], 'payment' => ['amount' => '10000.00', 'method' => 'cash']];

        $res = $this->exit($cycle, 'sold', 4, $body)->assertCreated();

        $this->assertSame('sold', $res->json('data.type'));
        $this->assertSame(46, $res->json('data.head_count'));
        $this->assertFalse($res->json('data.close_batch_suggested'));
        $this->assertSame(46, $this->population($cycle));
        $this->assertSame('26000.00', $res->json('data.sale.total_amount'));
        $this->assertSame('partially_paid', $res->json('data.sale.payment_status'));
        $this->assertSame('Mama Ngozi', $res->json('data.sale.customer_name'));
        $this->assertSame('sale', $res->json('data.event.source.type'));
        $this->assertSame($res->json('data.sale.id'), $res->json('data.event.source.id'));
        $this->assertSame('26000.00', $res->json('data.event.details.amount'));
        $this->assertSame(1, Sale::count());
    }

    public function test_died_creates_the_ordinary_mortality_record_with_a_default_cause(): void
    {
        $cycle = $this->cycle(30);

        $res = $this->exit($cycle, 'died', 3)->assertCreated();
        $this->assertSame(27, $res->json('data.head_count'));
        $record = OperationalRecord::findOrFail($res->json('data.event.id'));
        $this->assertSame('mortality', $record->type);
        $this->assertSame(-3, $record->population_delta);
        $this->assertSame('Unknown', $record->details['cause']);

        $this->exit($cycle, 'died', 1, ['cause' => 'Newcastle disease'])->assertCreated();
        $this->assertEqualsCanonicalizing(['Unknown', 'Newcastle disease'], OperationalRecord::where('type', 'mortality')->get()->pluck('details.cause')->all());
        $this->assertSame(26, $this->population($cycle));
    }

    public function test_slaughtered_lowers_the_head_count_and_stocks_the_species_meat_item(): void
    {
        $cycle = $this->cycle(40);
        $res = $this->exit($cycle, 'slaughtered', 10, ['meat_weight_kg' => '14.5', 'live_weight_kg' => '20'])->assertCreated();

        $this->assertSame(30, $res->json('data.head_count'));
        $item = $this->meatItem();
        $this->assertSame('Chicken meat', $item->name);
        $this->assertSame('output:meat:chicken', $item->system_key);
        $this->assertSame($item->id, $res->json('data.meat.inventory_item_id'));
        $this->assertEquals('14.5', $this->stock($item->id));
        $this->assertSame('kg', $item->stockUnit->code);
        $this->assertSame('produce', $item->category->value);

        $record = OperationalRecord::findOrFail($res->json('data.event.id'));
        $this->assertSame('livestock_exit', $record->type);
        $this->assertSame('slaughtered', $record->details['exit_type']);
        $movement = InventoryMovement::where('operational_record_id', $record->id)->firstOrFail();
        $this->assertSame($item->id, $movement->inventory_item_id);

        // the second slaughter adds to the same item (one per species per farm); a goat gets its own
        $this->exit($cycle, 'slaughtered', 5, ['meat_weight_kg' => '7.5'])->assertCreated();
        $this->assertSame(1, InventoryItem::where('system_key', 'like', 'output:meat:chicken')->count());
        $this->assertEquals('22', $this->stock($item->id));
        $goats = $this->cycle(6, 'goat');
        $this->exit($goats, 'slaughtered', 1, ['meat_weight_kg' => '18'])->assertCreated();
        $this->assertSame(['Chicken meat', 'Goat meat'], InventoryItem::where('system_key', 'like', 'output:meat:%')->orderBy('name')->pluck('name')->all());
    }

    public function test_slaughtered_needs_a_meat_weight_and_the_other_fields_are_type_specific(): void
    {
        $cycle = $this->cycle(20);
        $this->exit($cycle, 'slaughtered', 2)->assertUnprocessable()->assertJsonValidationErrors('meat_weight_kg');
        $this->exit($cycle, 'slaughtered', 2, ['meat_weight_kg' => '0'])->assertUnprocessable()->assertJsonValidationErrors('meat_weight_kg');
        $this->exit($cycle, 'culled', 2, ['reason' => 'old', 'meat_weight_kg' => '3'])->assertUnprocessable()->assertJsonValidationErrors('meat_weight_kg');
        $this->exit($cycle, 'culled', 2)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->exit($cycle, 'culled', 2, ['reason' => 'bored'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->exit($cycle, 'moved', 2)->assertUnprocessable()->assertJsonValidationErrors('destination_cycle_id');
        $this->exit($cycle, 'sold', 2)->assertUnprocessable()->assertJsonValidationErrors('unit_price');
        $this->assertSame(20, $this->population($cycle));
        $this->assertNull($this->meatItem());
    }

    public function test_culled_and_given_away_only_lower_the_head_count(): void
    {
        $cycle = $this->cycle(25);
        $culled = $this->exit($cycle, 'culled', 4, ['reason' => 'poor_production', 'notes' => 'Low lay rate'])->assertCreated();
        $this->assertSame(21, $culled->json('data.head_count'));
        $this->assertSame('poor_production', $culled->json('data.event.details.reason'));

        $gift = $this->exit($cycle, 'given_away', 1, ['given_to' => 'Pastor Emeka'])->assertCreated();
        $this->assertSame(20, $gift->json('data.head_count'));
        $this->assertSame('Pastor Emeka', $gift->json('data.event.details.given_to'));
        $this->assertSame(20, $this->population($cycle));
        $this->assertNull($this->meatItem());
        $this->assertSame(0, Sale::count());
    }

    public function test_the_hint_to_close_the_batch_appears_when_the_head_count_reaches_zero(): void
    {
        $cycle = $this->cycle(3);
        $res = $this->exit($cycle, 'culled', 3, ['reason' => 'old'])->assertCreated();
        $this->assertSame(0, $res->json('data.head_count'));
        $this->assertTrue($res->json('data.close_batch_suggested'));
    }

    // ------------------------------------------------------------------ moves

    public function test_a_move_lowers_one_batch_and_raises_the_other_in_one_transaction(): void
    {
        $a = $this->cycle(30);
        $b = $this->cycle(10);

        $res = $this->exit($a, 'moved', 12, ['destination_cycle_id' => $b])->assertCreated();

        $this->assertSame(18, $res->json('data.head_count'));
        $this->assertSame(18, $this->population($a));
        $this->assertSame(22, $this->population($b));
        $this->assertSame(22, $res->json('data.destination.head_count'));
        $this->assertSame('production_cycle', $res->json('data.event.source.type'));
        $this->assertSame($b, $res->json('data.event.source.id'));

        $out = OperationalRecord::findOrFail($res->json('data.event.id'));
        $in = OperationalRecord::where('type', 'livestock_move_in')->firstOrFail();
        $this->assertSame(-12, $out->population_delta);
        $this->assertSame(12, $in->population_delta);
        $this->assertSame($out->id, $in->linked_record_id);
        $this->assertSame($b, $in->production_cycle_id);
    }

    public function test_a_move_needs_an_open_batch_of_the_same_species_that_is_not_the_same_batch(): void
    {
        $a = $this->cycle(30);
        $goats = $this->cycle(10, 'goat');
        $closed = $this->cycle(5);
        $this->postJson('/api/v1/production-cycles/'.$closed.'/close', ['end_date' => '2026-10-10', 'reason' => 'Done'])->assertOk();

        $this->exit($a, 'moved', 2, ['destination_cycle_id' => $goats])->assertUnprocessable()->assertJsonValidationErrors('destination_cycle_id');
        $this->exit($a, 'moved', 2, ['destination_cycle_id' => $a])->assertUnprocessable()->assertJsonValidationErrors('destination_cycle_id');
        $this->exit($a, 'moved', 2, ['destination_cycle_id' => $closed])->assertStatus(409)->assertJsonPath('code', 'cycle_closed');
        $this->exit($closed, 'culled', 1, ['reason' => 'old'])->assertStatus(409)->assertJsonPath('code', 'cycle_closed');
        $this->exit($a, 'moved', 2, ['destination_cycle_id' => '0197a1b2-0000-7000-8000-000000000000'])->assertNotFound();

        $this->assertSame(30, $this->population($a));
        $this->assertSame(10, $this->population($goats));
        $this->assertSame(0, OperationalRecord::where('type', 'livestock_move_in')->count());
    }

    // ------------------------------------------------------------------ headcount and idempotency

    public function test_more_animals_than_the_batch_holds_is_a_409_and_changes_nothing(): void
    {
        $a = $this->cycle(5);
        $b = $this->cycle(5);
        foreach (['culled' => ['reason' => 'old'], 'given_away' => [], 'died' => [], 'slaughtered' => ['meat_weight_kg' => '4'], 'moved' => ['destination_cycle_id' => $b]] as $type => $extra) {
            $this->exit($a, $type, 6, $extra)->assertStatus(409)->assertJsonPath('code', 'insufficient_population');
        }
        $this->exit($a, 'sold', 6, ['unit_price' => '1000.00'])->assertStatus(409);
        $this->assertSame(5, $this->population($a));
        $this->assertSame(5, $this->population($b));
        $this->assertNull($this->meatItem(), 'the failed slaughter left no stock item behind');
        $this->assertSame(0, Sale::count());
    }

    public function test_a_retry_with_the_same_key_never_duplicates_and_a_changed_body_is_refused(): void
    {
        $a = $this->cycle(40);
        $b = $this->cycle(10);
        $bodies = [
            'culled' => ['reason' => 'old'], 'given_away' => ['given_to' => 'Neighbour'], 'slaughtered' => ['meat_weight_kg' => '5'], 'died' => ['cause' => 'Heat'],
            'moved' => ['destination_cycle_id' => $b], 'sold' => ['unit_price' => '5000.00', 'payment' => ['amount' => '5000.00', 'method' => 'cash']],
        ];
        foreach ($bodies as $type => $extra) {
            $key = $this->key();
            $first = $this->exit($a, $type, 1, ['idempotency_key' => $key] + $extra)->assertCreated();
            $retry = $this->exit($a, $type, 1, ['idempotency_key' => $key] + $extra)->assertCreated();
            $this->assertSame($first->json('data.event.id'), $retry->json('data.event.id'), $type.' retry returns the original event');
            $this->exit($a, $type, 2, ['idempotency_key' => $key] + $extra)->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        }
        $this->assertSame(34, $this->population($a), 'six events of one head each');
        $this->assertSame(11, $this->population($b), 'the move arrived once');
        $this->assertSame(1, Sale::count());
        $this->assertSame(1, InventoryMovement::whereIn('operational_record_id', OperationalRecord::where('type', 'livestock_exit')->pluck('id'))->count());
        // the same key with another type is not a retry
        $key = $this->key();
        $this->exit($a, 'culled', 1, ['idempotency_key' => $key, 'reason' => 'old'])->assertCreated();
        $this->exit($a, 'sold', 1, ['idempotency_key' => $key, 'unit_price' => '1000.00'])->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->assertSame(1, Sale::count());
    }

    public function test_the_new_exit_types_cannot_be_created_through_post_records(): void
    {
        $cycle = $this->cycle(10);
        foreach (['livestock_exit', 'livestock_move_in'] as $type) {
            $this->postJson('/api/v1/records', ['production_cycle_id' => $cycle, 'type' => $type, 'recorded_at' => $this->at(2), 'idempotency_key' => $this->key(), 'details' => ['exit_type' => 'culled']])
                ->assertUnprocessable()->assertJsonValidationErrors('type');
        }
        $this->assertSame(10, $this->population($cycle));
    }

    // ------------------------------------------------------------------ list

    public function test_the_list_is_built_from_existing_records_and_filters_work(): void
    {
        $a = $this->cycle(100);
        $b = $this->cycle(20);
        $sold = $this->exit($a, 'sold', 5, ['unit_price' => '3000.00', 'recorded_at' => $this->at(30)])->assertCreated();
        $this->exit($a, 'culled', 2, ['reason' => 'sick_injured', 'recorded_at' => $this->at(20)])->assertCreated();
        $this->exit($a, 'slaughtered', 3, ['meat_weight_kg' => '6', 'recorded_at' => $this->at(10)])->assertCreated();
        $this->exit($a, 'given_away', 1, ['recorded_at' => $this->at(9)])->assertCreated();
        $moved = $this->exit($a, 'moved', 4, ['destination_cycle_id' => $b, 'recorded_at' => $this->at(8)])->assertCreated();
        // events entered through the existing endpoints appear too, with no copy
        $this->postJson('/api/v1/records', ['production_cycle_id' => $a, 'type' => 'mortality', 'recorded_at' => $this->at(7), 'idempotency_key' => $this->key(), 'details' => ['quantity' => 2, 'cause' => 'Predator']])->assertCreated();
        $this->postJson('/api/v1/sales', ['recorded_at' => $this->at(6), 'idempotency_key' => $this->key(), 'items' => [['kind' => 'livestock', 'production_cycle_id' => $a, 'head_count' => 6, 'amount' => '18000.00']]])->assertCreated();

        $all = $this->getJson('/api/v1/livestock-exits?production_cycle_id='.$a)->assertOk();
        $this->assertSame(['sold', 'died', 'moved', 'given_away', 'slaughtered', 'culled', 'sold'], array_column($all->json('data'), 'type'));
        $this->assertSame([6, 2, 4, 1, 3, 2, 5], array_column($all->json('data'), 'head_count'));
        $this->assertSame(7, $all->json('meta.total'));
        $this->assertSame(['Sold', 'Died', 'Moved to another batch', 'Given away', 'Slaughtered', 'Culled', 'Sold'], array_column($all->json('data'), 'label'));
        $this->assertSame(100 - 5 - 2 - 3 - 1 - 4 - 2 - 6, $this->population($a));

        // the destination batch has no exits of its own (the arrival is not an exit)
        $this->assertSame(0, $this->getJson('/api/v1/livestock-exits?production_cycle_id='.$b)->json('meta.total'));

        $byType = fn (string $type) => $this->getJson('/api/v1/livestock-exits?production_cycle_id='.$a.'&type='.$type)->assertOk();
        $this->assertSame(2, $byType('sold')->json('meta.total'));
        $this->assertSame(1, $byType('died')->json('meta.total'));
        $this->assertSame('6', $byType('slaughtered')->json('data.0.details.meat_weight_kg'));
        $this->assertSame('Chicken meat', $byType('slaughtered')->json('data.0.details.meat_item'));
        $this->assertSame('sick_injured', $byType('culled')->json('data.0.details.reason'));
        $this->assertSame($b, $byType('moved')->json('data.0.details.destination_cycle_id'));
        $this->assertSame($moved->json('data.event.id'), $byType('moved')->json('data.0.id'));
        $this->assertSame('cancel_sale', $byType('sold')->json('data.1.undo'));
        $this->assertSame($sold->json('data.sale.id'), $byType('sold')->json('data.1.source.id'));

        $day = CarbonImmutable::now()->subHours(20)->setTimezone('Africa/Lagos')->toDateString();
        $range = $this->getJson('/api/v1/livestock-exits?production_cycle_id='.$a.'&recorded_from='.$day.'&recorded_to='.$day)->assertOk();
        $this->assertContains('culled', array_column($range->json('data'), 'type'));
        $this->assertNotContains('died', array_column($range->json('data'), 'type'));
        $this->getJson('/api/v1/livestock-exits?type=eaten')->assertUnprocessable();
        $this->getJson('/api/v1/livestock-exits?per_page=2')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_reversed_events_cancelled_sales_and_reversal_rows_are_hidden(): void
    {
        $a = $this->cycle(50);
        $culled = $this->exit($a, 'culled', 4, ['reason' => 'old'])->assertCreated();
        $died = $this->exit($a, 'died', 2)->assertCreated();
        $sold = $this->exit($a, 'sold', 3, ['unit_price' => '1000.00'])->assertCreated();
        $this->assertSame(3, $this->getJson('/api/v1/livestock-exits?production_cycle_id='.$a)->json('meta.total'));

        $this->undo($culled->json('data.event.id'))->assertCreated();
        $this->postJson('/api/v1/sales/'.$sold->json('data.sale.id').'/cancel', ['reason' => 'Buyer backed out', 'recorded_at' => $this->at(1), 'idempotency_key' => $this->key()])->assertOk();
        $this->postJson('/api/v1/records/'.$died->json('data.event.id').'/reverse', ['reason' => 'Miscount', 'recorded_at' => $this->at(1), 'idempotency_key' => $this->key()])->assertCreated();

        $this->assertSame(0, $this->getJson('/api/v1/livestock-exits?production_cycle_id='.$a)->json('meta.total'));
        $this->assertSame(50, $this->population($a));
    }

    // ------------------------------------------------------------------ undo

    public function test_undoing_died_culled_and_given_away_returns_the_animals(): void
    {
        $a = $this->cycle(30);
        foreach ([['died', []], ['culled', ['reason' => 'old']], ['given_away', ['given_to' => 'Aunty']]] as [$type, $extra]) {
            $event = $this->exit($a, $type, 3, $extra)->assertCreated();
            $before = $this->population($a);
            $res = $this->undo($event->json('data.event.id'))->assertCreated();
            $this->assertSame($before + 3, $this->population($a));
            $this->assertSame($before + 3, $res->json('data.head_count'));
            $this->assertSame($event->json('data.event.id'), $res->json('data.reversed_record_id'));
            $this->assertSame(3, $res->json('data.reversal.population_delta'));
            $this->assertNull($res->json('data.linked_reversal'));
            $this->undo($event->json('data.event.id'))->assertStatus(409)->assertJsonPath('code', 'record_already_reversed');
        }
        $this->assertSame(30, $this->population($a));
    }

    public function test_undoing_a_move_reverses_both_sides_together(): void
    {
        $a = $this->cycle(30);
        $b = $this->cycle(10);
        $move = $this->exit($a, 'moved', 8, ['destination_cycle_id' => $b])->assertCreated();
        $id = $move->json('data.event.id');

        $key = $this->key();
        $res = $this->undo($id, ['idempotency_key' => $key])->assertCreated();
        $this->assertSame(30, $this->population($a));
        $this->assertSame(10, $this->population($b));
        $this->assertSame(8, $res->json('data.reversal.population_delta'));
        $this->assertSame(-8, $res->json('data.linked_reversal.population_delta'));
        $this->assertSame(1, OperationalRecord::where('type', 'reversal')->where('production_cycle_id', $b)->count());
        $this->undo($id, ['idempotency_key' => $key])->assertCreated(); // a retry replays
        $this->assertSame(2, OperationalRecord::where('type', 'reversal')->count());
        $this->assertSame(0, $this->getJson('/api/v1/livestock-exits?production_cycle_id='.$a)->json('meta.total'));
    }

    public function test_a_move_cannot_be_undone_once_the_receiving_batch_has_lost_the_animals_and_nothing_changes(): void
    {
        $a = $this->cycle(30);
        $b = $this->cycle(2);
        $move = $this->exit($a, 'moved', 8, ['destination_cycle_id' => $b])->assertCreated();
        $this->exit($b, 'culled', 9, ['reason' => 'old'])->assertCreated();

        $this->undo($move->json('data.event.id'))->assertStatus(409)->assertJsonPath('code', 'insufficient_population');
        $this->assertSame(22, $this->population($a));
        $this->assertSame(1, $this->population($b));
        $this->assertSame(0, OperationalRecord::where('type', 'reversal')->count());
    }

    public function test_a_moved_record_cannot_be_reversed_through_post_records(): void
    {
        $a = $this->cycle(30);
        $b = $this->cycle(10);
        $move = $this->exit($a, 'moved', 8, ['destination_cycle_id' => $b])->assertCreated();
        $in = OperationalRecord::where('type', 'livestock_move_in')->firstOrFail();

        foreach ([$move->json('data.event.id'), $in->id] as $record) {
            $this->postJson('/api/v1/records/'.$record.'/reverse', ['reason' => 'Oops', 'recorded_at' => $this->at(1), 'idempotency_key' => $this->key()])
                ->assertStatus(409)->assertJsonPath('code', 'reverse_via_livestock_exit');
        }
        $this->assertSame(22, $this->population($a));
        $this->assertSame(18, $this->population($b));
    }

    public function test_undoing_a_slaughter_takes_the_meat_back_out_unless_it_was_already_used(): void
    {
        $a = $this->cycle(30);
        $first = $this->exit($a, 'slaughtered', 4, ['meat_weight_kg' => '10'])->assertCreated();
        $item = $this->meatItem();

        $this->undo($first->json('data.event.id'))->assertCreated();
        $this->assertSame(30, $this->population($a));
        $this->assertEquals('0', $this->stock($item->id));

        // meat that has since been sold or used cannot be taken back: the whole undo is refused
        $second = $this->exit($a, 'slaughtered', 5, ['meat_weight_kg' => '12'])->assertCreated();
        $loc = $second->json('data.meat.storage_location_id');
        $this->postJson('/api/v1/inventory/stock-out', ['inventory_item_id' => $item->id, 'storage_location_id' => $loc, 'reason' => 'sold', 'components' => [['quantity' => '10', 'unit' => 'kg']],
            'recorded_at' => $this->at(1), 'idempotency_key' => $this->key()])->assertCreated();
        $this->undo($second->json('data.event.id'))->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
        $this->assertSame(25, $this->population($a));
        $this->assertEquals('2', $this->stock($item->id));
    }

    public function test_a_sold_exit_must_be_undone_by_cancelling_the_sale(): void
    {
        $a = $this->cycle(20);
        $sold = $this->exit($a, 'sold', 5, ['unit_price' => '2000.00'])->assertCreated();

        $this->undo($sold->json('data.event.id'))->assertStatus(409)->assertJsonPath('code', 'reverse_via_sale');
        $this->postJson('/api/v1/records/'.$sold->json('data.event.id').'/reverse', ['reason' => 'x', 'recorded_at' => $this->at(1), 'idempotency_key' => $this->key()])->assertStatus(409)->assertJsonPath('code', 'reverse_via_sale');
        $this->assertSame(15, $this->population($a));
        $this->postJson('/api/v1/sales/'.$sold->json('data.sale.id').'/cancel', ['reason' => 'Returned', 'recorded_at' => $this->at(1), 'idempotency_key' => $this->key()])->assertOk();
        $this->assertSame(20, $this->population($a));
    }

    // ------------------------------------------------------------------ permissions

    public function test_a_worker_can_record_every_type_but_cannot_undo(): void
    {
        $a = $this->cycle(60);
        $b = $this->cycle(5);
        $culled = $this->exit($a, 'culled', 1, ['reason' => 'old'])->assertCreated();

        $this->signInAs($this->member(FarmRole::FarmWorker, name: 'Field worker'));
        $this->exit($a, 'culled', 1, ['reason' => 'old'])->assertCreated();
        $this->exit($a, 'given_away', 1)->assertCreated();
        $this->exit($a, 'died', 1)->assertCreated();
        $this->exit($a, 'slaughtered', 1, ['meat_weight_kg' => '1.5'])->assertCreated();
        $this->exit($a, 'moved', 1, ['destination_cycle_id' => $b])->assertCreated();
        $this->exit($a, 'sold', 1, ['unit_price' => '1000.00', 'payment' => ['amount' => '1000.00', 'method' => 'cash']])->assertCreated();
        $this->getJson('/api/v1/livestock-exits?production_cycle_id='.$a)->assertOk();

        $this->undo($culled->json('data.event.id'))->assertForbidden();
    }

    public function test_a_vet_cannot_record_a_sale_or_a_slaughter_through_the_form(): void
    {
        $a = $this->cycle(20);
        $this->signInAs($this->member(FarmRole::Vet, name: 'The vet'));
        $this->exit($a, 'sold', 1, ['unit_price' => '1000.00'])->assertForbidden();
        $this->exit($a, 'slaughtered', 1, ['meat_weight_kg' => '1'])->assertForbidden();
        $this->assertSame(20, $this->population($a));
    }

    public function test_exits_stay_inside_the_farm(): void
    {
        $a = $this->cycle(10);
        [$otherOwner] = $this->otherFarm();
        $this->signInAs($otherOwner);
        $this->exit($a, 'culled', 1, ['reason' => 'old'])->assertNotFound();
        $this->getJson('/api/v1/livestock-exits?production_cycle_id='.$a)->assertNotFound();
    }

    // ------------------------------------------------------------------ population report

    public function test_the_population_report_has_a_bucket_for_each_new_exit_and_stays_reconciled(): void
    {
        $a = $this->cycle(100);
        $b = $this->cycle(10);
        $this->exit($a, 'slaughtered', 3, ['meat_weight_kg' => '6'])->assertCreated();
        $this->exit($a, 'culled', 2, ['reason' => 'old'])->assertCreated();
        $this->exit($a, 'given_away', 1)->assertCreated();
        $this->exit($a, 'moved', 4, ['destination_cycle_id' => $b])->assertCreated();
        $wrong = $this->exit($a, 'culled', 7, ['reason' => 'old'])->assertCreated();
        $this->undo($wrong->json('data.event.id'))->assertCreated();

        $rows = collect($this->getJson('/api/v1/reports/livestock_population')->assertOk()->json('data.rows'))->keyBy('name');
        $from = $rows->first(fn ($r) => str_contains($r['name'], 'Chicken batch') && $r['closing'] === 90);
        $this->assertNotNull($from);
        $this->assertSame(-3, $from['slaughtered']);
        $this->assertSame(-2, $from['culled'], 'a reversed cull nets to zero');
        $this->assertSame(-1, $from['given_away']);
        $this->assertSame(-4, $from['moved_out']);
        $this->assertSame(0, $from['moved_in']);
        $this->assertSame(0, $from['other']);
        $this->assertSame(90, $from['closing']);
        $to = $rows->first(fn ($r) => $r['closing'] === 14);
        $this->assertSame(4, $to['moved_in']);
        $this->assertSame(0, $to['other']);
        foreach ([$from, $to] as $row) {
            $this->assertSame($row['opening'] + $row['initial'] + $row['births'] + $row['deaths'] + $row['sold'] + $row['slaughtered'] + $row['culled'] + $row['given_away'] + $row['moved_out'] + $row['moved_in'] + $row['adjustments'] + $row['other'], $row['closing']);
        }
    }
}
