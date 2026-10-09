<?php

namespace Database\Seeders;

use App\Models\Breed;
use App\Models\ReferenceValue;
use App\Models\Species;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Exact product-owner options: farmer-facing types, not necessarily strict biological breeds. */
class LivestockBatchReferenceSeeder extends Seeder
{
    public const BREEDS = [
        'chicken' => ['Local/Indigenous', 'Fulani', 'Noiler', 'FUNAAB Alpha', 'Shika-Brown', 'ISA Brown', 'Kuroiler', 'Sasso', 'Cobb 500', 'Ross 308', 'Marshall', 'Arbor Acres', 'Rhode Island Red', 'White Leghorn'],
        'cattle' => ['White Fulani (Bunaji)', 'Red Bororo (Rahaji)', 'Sokoto Gudali', 'Adamawa Gudali', 'Wadara', 'Azawak', 'Muturu', 'N\'Dama', 'Kuri', 'Keteku', 'Holstein-Friesian', 'Jersey', 'Brahman'],
        'goat' => ['West African Dwarf', 'Red Sokoto (Maradi)', 'Sahel', 'Kano Brown', 'Boer', 'Saanen', 'Anglo-Nubian', 'Alpine'],
        'sheep' => ['Yankasa', 'Uda', 'Balami', 'West African Dwarf', 'Dorper', 'Suffolk', 'Merino'],
        'pig' => ['Nigerian Indigenous', 'Large White (Yorkshire)', 'Landrace', 'Duroc', 'Hampshire', 'Pietrain', 'Berkshire'],
        'fish' => ['African Catfish (Clarias gariepinus)', 'Heterobranchus Catfish', 'Heteroclarias Hybrid', 'Nile Tilapia', 'Red Tilapia', 'Galilee Tilapia', 'Redbelly Tilapia', 'Common Carp', 'African Bonytongue'],
        'turkey' => ['Local/Indigenous', 'Broad Breasted White', 'Broad Breasted Bronze', 'Standard Bronze', 'Bourbon Red', 'Narragansett', 'Royal Palm', 'Beltsville Small White'],
        'duck' => ['Local/Indigenous', 'Muscovy', 'Pekin', 'Khaki Campbell', 'Indian Runner', 'Rouen'],
        'guinea_fowl' => ['Indigenous Helmeted Guinea Fowl', 'Pearl', 'Lavender/Ash', 'Black', 'White'],
        'goose' => ['Domestic/Unspecified', 'African Goose', 'Chinese Goose', 'Embden', 'Toulouse'],
        'quail' => ['Japanese Quail', 'Bobwhite Quail'],
        'pigeon' => ['Local/Domestic', 'King', 'Carneau', 'Mondain', 'Homer'],
        'ostrich' => ['Common Ostrich', 'Red-necked/North African', 'Masai', 'Somali'],
        'rabbit' => ['New Zealand White', 'Californian', 'Chinchilla', 'Dutch', 'Flemish Giant', 'Rex', 'Angora', 'Local/Mixed'],
        'grasscutter' => ['Greater Cane Rat (Thryonomys swinderianus)'],
        'camel' => ['Dromedary (One-humped Camel)'],
        'water_buffalo' => ['River Buffalo', 'Swamp Buffalo', 'Murrah', 'Nili-Ravi', 'Mediterranean Buffalo'],
        'horse' => ['Local/Nigerian Horse', 'Dongola', 'Arabian', 'Thoroughbred'],
        'donkey' => ['Local/Indigenous Donkey', 'African Donkey Type'],
        'guinea_pig' => ['American', 'Abyssinian', 'Peruvian', 'Teddy'],
        'snail' => ['Archachatina marginata', 'Achatina achatina', 'Lissachatina fulica'],
        'honeybee' => ['Apis mellifera adansonii'],
    ];

    /** species => [purpose codes, stage codes]. No biological defaults are inferred. */
    public const OPTIONS = [
        'chicken' => ['meat, eggs, dual_purpose, breeding, other', 'hatchling, chick, grower, adult, unknown'],
        'turkey' => ['meat, eggs, breeding, other', 'poult, grower, adult, unknown'],
        'guinea_fowl' => ['meat, eggs, dual_purpose, breeding, other', 'keet, grower, adult, unknown'],
        'duck' => ['meat, eggs, dual_purpose, breeding, other', 'duckling, grower, adult, unknown'],
        'goose' => ['meat, eggs, breeding, other', 'gosling, grower, adult, unknown'],
        'quail' => ['meat, eggs, dual_purpose, breeding, other', 'chick, grower, adult, unknown'],
        'pigeon' => ['meat, breeding, other', 'squab, juvenile, adult, unknown'],
        'ostrich' => ['meat, eggs, leather, feathers, breeding, other', 'chick, juvenile, adult, unknown'],
        'goat' => ['meat, dairy, fibre, hide, breeding, other', 'kid, weaner, grower, adult, unknown'],
        'sheep' => ['meat, dairy, wool, hide, breeding, other', 'lamb, weaner, grower, adult, unknown'],
        'cattle' => ['meat, dairy, dual_purpose, hide, breeding, draught, other', 'calf, weaner, grower, adult, unknown'],
        'camel' => ['meat, dairy, transport, breeding, other', 'calf, weaner, juvenile, adult, unknown'],
        'water_buffalo' => ['meat, dairy, draught, breeding, other', 'calf, weaner, grower, adult, unknown'],
        'pig' => ['meat, breeding, other', 'piglet, weaner, grower, finisher, adult, unknown'],
        'rabbit' => ['meat, breeding, fur, other', 'kit, weaner, grower, adult, unknown'],
        'grasscutter' => ['meat, breeding, other', 'pup, juvenile, adult, unknown'],
        'guinea_pig' => ['meat, breeding, other', 'pup, juvenile, adult, unknown'],
        'horse' => ['breeding, transport, recreation, work, other', 'foal, weanling, yearling, adult, unknown'],
        'donkey' => ['breeding, transport, work, other', 'foal, weanling, juvenile, adult, unknown'],
        'snail' => ['meat, breeding, other', 'hatchling, juvenile, adult, unknown'],
        'honeybee' => ['honey, beeswax, pollination, queen_rearing, colony_breeding, other', 'new_colony, developing_colony, established_colony, unknown'],
        'fish' => ['table_fish, fingerling_production, breeding, other', 'larva, fry, fingerling, juvenile, adult, unknown'],
    ];

    /**
     * Purpose labels that need a hint a farmer can understand. Codes never change; only the shown name does.
     * Species not listed keep the default readable code word (for example `queen_rearing` -> `Queen rearing`).
     */
    private const PURPOSE_LABELS = [
        'dual_purpose' => ['cattle' => 'Dual purpose (milk & meat)', '*' => 'Dual purpose (eggs & meat)'],
    ];

    public static function purposeLabel(string $speciesCode, string $code): string
    {
        return self::PURPOSE_LABELS[$code][$speciesCode] ?? self::PURPOSE_LABELS[$code]['*'] ?? self::defaultLabel($code);
    }

    private static function defaultLabel(string $code): string
    {
        return Str::ucfirst(str_replace('_', ' ', $code));
    }

    public function run(): void
    {
        DB::transaction(function () {
            foreach (self::BREEDS as $speciesCode => $names) {
                $species = Species::where('code', $speciesCode)->firstOrFail();
                if ($species->breed_field_label === null) {
                    $species->update(['breed_field_label' => in_array($speciesCode, ['fish', 'snail', 'honeybee', 'grasscutter', 'camel', 'quail', 'ostrich'], true) ? 'Species / Type' : 'Breed / Strain']);
                }
                foreach ($names as $name) {
                    $code = Str::slug($name, '_');
                    $row = Breed::system()->where('species_id', $species->id)
                        ->where(fn ($q) => $q->where('code', $code)->orWhere('normalized_name', Breed::normalizeName($name)))->first();
                    if (! $row) {
                        Breed::create(['species_id' => $species->id, 'farm_id' => null, 'code' => $code, 'name' => $name]);
                    } elseif ($row->code === null) {
                        $row->update(['code' => $code]);
                    }
                }
                foreach (['purpose', 'stage'] as $index => $kind) {
                    foreach (explode(', ', self::OPTIONS[$speciesCode][$index]) as $sort => $code) {
                        $label = $kind === 'purpose' ? self::purposeLabel($speciesCode, $code) : self::defaultLabel($code);
                        $row = ReferenceValue::firstOrCreate(['list' => ReferenceValue::livestockList($speciesCode, $kind), 'code' => $code], [
                            'name' => $label, 'sort_order' => $sort * 10,
                        ]);
                        // Upgrade a name still equal to the old auto-generated one; an admin-edited name is never overwritten.
                        if (! $row->wasRecentlyCreated && $row->name === self::defaultLabel($code) && $label !== $row->name) {
                            $row->update(['name' => $label]);
                        }
                    }
                }
            }
        });
    }
}
