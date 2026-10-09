<?php

namespace App\Enums;

/** A seller's confirmation of a fixed-price purchase intent. Only `awaiting_buyer` is open; every other state is terminal. */
enum ConfirmationStatus: string
{
    case AwaitingBuyer = 'awaiting_buyer';
    /** The buyer confirmed: a deal exists. */
    case Converted = 'converted';
    case Withdrawn = 'withdrawn';
    case Lapsed = 'lapsed';
    /** The intent or listing changed after the seller confirmed, so the confirmed terms no longer hold. */
    case Voided = 'voided';
}
