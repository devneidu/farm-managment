<?php

namespace App\Enums;

/**
 * What a package conversion is about. The context is what makes "1 bag = ? kg" answerable: a bag of maize and a bag of
 * feed differ. Stored as a (type, id) pair - the id always identifies a real, validated entity, never display text - so
 * later phases can add their own types (for example `inventory_item`, id = the item's UUID) without a schema change.
 */
enum ConversionContextType: string
{
    case CropType = 'crop_type'; // id = crop_types.id (Phase 4 master data, e.g. maize); must be an active crop
    case Custom = 'custom';      // id = measurement_contexts.id, a farm-owned context (e.g. "Feed Grower Mash", "Eggs")
}
