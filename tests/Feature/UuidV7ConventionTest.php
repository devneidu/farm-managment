<?php

namespace Tests\Feature;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UuidV7ConventionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('uuid_v7_probes');
        Schema::create('uuid_v7_probes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('uuid_v7_probes');

        parent::tearDown();
    }

    public function test_trait_assigns_version_7_uuids_that_are_time_ordered(): void
    {
        $first = UuidV7Probe::create(['name' => 'a']);
        usleep(2000);
        $second = UuidV7Probe::create(['name' => 'b']);

        foreach ([$first, $second] as $model) {
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $model->id
            );
        }

        $this->assertFalse($first->getIncrementing());
        $this->assertSame('string', $first->getKeyType());
        $this->assertSame(UuidV7Probe::find($first->id)->id, $first->id);
        $this->assertLessThan($second->id, $first->id);
    }

    public function test_automated_tests_run_against_the_isolated_test_database(): void
    {
        $this->assertSame('farm_management_test', DB::connection()->getDatabaseName());
    }
}

class UuidV7Probe extends Model
{
    use HasUuidV7;

    protected $table = 'uuid_v7_probes';

    public $timestamps = false;

    protected $fillable = ['name'];
}
