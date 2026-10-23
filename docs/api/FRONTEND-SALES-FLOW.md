# Sell flow — frontend contract

One form, one save. The farmer picks what was sold, enters quantity × price, names the buyer, says whether money was received, and taps **Save**. The API records the stock (or animals) leaving, the sale, the buyer and — when money was received — the invoice, the payment and the income, together and linked, in one transaction.

All paths are under `/api/v1`. Auth, CSRF and the optional `X-Farm-Id` header are as in [FRONTEND-INTEGRATION.md](FRONTEND-INTEGRATION.md). The examples below are real responses captured from the running code (Africa/Lagos farm, NGN); only long, unchanged id lists were left as returned. Reference: [PHASE-15-SALES-INVOICES.md](PHASE-15-SALES-INVOICES.md).

## 1. The form

### 1.1 "What did you sell?" — the list

Call `GET /sales/sellables` when the form opens (needs `sale.create`). It returns only what can be sold **right now**:

* `animals[]` — open livestock batches with at least 1 head. Use `production_cycle_id` in a `kind: "livestock"` line.
* `in_store[]` — produce and feed with stock on hand. If `output` is `"eggs"` or `"milk"` send `output` in the line (no item id, no location needed while the farm has one store); otherwise send `inventory_item_id` + one of the `locations[].storage_location_id` (and `lot_id` when the item is lot tracked). `available` is in the item's own unit; show it as "240 piece available".

Nothing to sell → both arrays are empty: show "You have nothing in store to sell yet" with links to record production or stock.

`GET /sales/sellables`

Response `200`:

```json
{
    "data": {
        "animals": [
            {
                "production_cycle_id": "01a16812-15e0-7136-a554-061ecabdb5f0",
                "reference": "BAT-2026-00001",
                "label": "Layer flock SNOI",
                "species": "Chicken",
                "head_count": 60,
                "unit": "head"
            }
        ],
        "in_store": [
            {
                "inventory_item_id": "01a16812-1005-71c4-9348-7a8cb84e5996",
                "output": "eggs",
                "label": "Eggs",
                "category": "produce",
                "available": {
                    "quantity": "240",
                    "unit": "piece"
                },
                "locations": [
                    {
                        "storage_location_id": "01a16812-0cec-734a-a871-300e202c5156",
                        "storage_location": "Store ZEJTB",
                        "lot_id": null,
                        "available": {
                            "quantity": "240",
                            "unit": "piece"
                        }
                    }
                ]
            },
            {
                "inventory_item_id": "01a16812-1215-720a-a580-9d9be82441da",
                "output": null,
                "label": "Tomatoes",
                "category": "produce",
                "available": {
                    "quantity": "80",
                    "unit": "kg"
                },
                "locations": [
                    {
                        "storage_location_id": "01a16812-0cec-734a-a871-300e202c5156",
                        "storage_location": "Store ZEJTB",
                        "lot_id": null,
                        "available": {
                            "quantity": "80",
                            "unit": "kg"
                        }
                    }
                ]
            }
        ]
    },
    "meta": [],
    "message": null
}
```


### 1.2 Quantity × price

Per line send **`quantity`** and **`unit_price`**; the API computes the line amount (rounded to 2 decimals, half up) and the total. Show the computed amount live in the form, but do not send it — if you do send `amount` too it must match exactly or the call is `422`.

* Stock line: `quantity` is in the item's stock unit (eggs → pieces, milk → litres, tomatoes → kg), `unit_price` is per that unit. (Advanced: crates etc. can still be sent as `components`, with `unit_price` per stock unit.)
* Livestock line: `quantity` is the number of animals, `unit_price` is per head.
* Other line (manure, services): `description` + `quantity` + `unit_price`, or just `description` + `amount`.

### 1.3 Buyer

Three ways, **exactly one** (more than one is `422` on `buyer`):

* `buyer: { name, phone }` — the quick way. Reuses the farm's contact with the same name (case and spacing ignored) and adds the customer role, or creates a customer contact. Works for every role that can sell (a worker does not need `contact.manage`). `phone` is optional.
* `contact_id` — a customer picked from `GET /contacts?role=customer` (managers/finance only: `contact.view`).
* `customer_name` — free text, no contact is created. Or send none for an anonymous sale.

### 1.4 Payment options

Show three choices: **Paid in full**, **Part payment**, **Not paid yet**.

* Paid in full → `payment: { amount: <total>, method }`.
* Part payment → `payment: { amount: <less than total>, method }`.
* Not paid yet → omit `payment`.

`method`: `cash`, `bank_transfer`, `pos`, `mobile_money`, `cheque`, `other`. `received_on` (YYYY-MM-DD) is optional and defaults to the sale day. `amount` must be > 0 and ≤ the total. Whenever `payment` is present the API also issues the invoice automatically — do not send an `invoice` block (workers cannot; it needs `invoice.create`).

### 1.5 Single save

`POST /sales` with a fresh `idempotency_key` (generate it once when the form opens and reuse it on every retry of the same save). Paid in full, quick buyer, quantity × price:

`POST /sales`

Request:

```json
{
    "recorded_at": "2026-10-14T09:30:00+01:00",
    "idempotency_key": "sell-2026-10-14-001",
    "buyer": {
        "name": "Mama Ngozi",
        "phone": "08031234567"
    },
    "items": [
        {
            "kind": "stock",
            "output": "eggs",
            "quantity": "60",
            "unit_price": "75.00"
        }
    ],
    "payment": {
        "amount": "4500.00",
        "method": "cash"
    }
}
```

Response `201`:

```json
{
    "data": {
        "id": "01a16812-1b24-703b-9e48-4bbfcc1ebbb0",
        "reference": "SAL-2026-00001",
        "status": "active",
        "contact_id": "01a16812-1a46-7068-8584-75bb8ff35188",
        "customer_name": "Mama Ngozi",
        "recorded_at": "2026-10-14T08:30:00.000000Z",
        "total_amount": "4500.00",
        "currency": "NGN",
        "finance_category": {
            "id": "01a16811-11e3-7371-bcf5-92ec75529f91",
            "code": "egg_sales",
            "name": "Egg sales"
        },
        "payment_status": "paid",
        "amount_paid": "4500.00",
        "outstanding": "0.00",
        "invoice": {
            "id": "01a16812-1cb0-716a-9d8b-75d7cff39434",
            "reference": "INV-2026-00001",
            "status": "issued"
        },
        "invoice_id": "01a16812-1cb0-716a-9d8b-75d7cff39434",
        "payments": [
            {
                "id": "01a16812-1d9c-7190-87aa-71a41e7db397",
                "reference": "PAY-2026-00001",
                "amount": "4500.00",
                "method": "cash",
                "received_on": "2026-10-14"
            }
        ],
        "notes": null,
        "corrects_sale_id": null,
        "cancelled_at": null,
        "cancel_reason": null,
        "items": [
            {
                "id": "01a16812-1bb8-7157-9b4d-c96d2e41de19",
                "line_no": 1,
                "kind": "stock",
                "description": "Eggs",
                "inventory_item_id": "01a16812-1005-71c4-9348-7a8cb84e5996",
                "storage_location_id": "01a16812-0cec-734a-a871-300e202c5156",
                "inventory_lot_id": null,
                "lot": null,
                "production_cycle_id": null,
                "head_count": null,
                "quantity": {
                    "quantity": "60",
                    "unit": "piece",
                    "normalized": {
                        "quantity": "60",
                        "unit": "piece"
                    }
                },
                "measurement": {
                    "total": {
                        "unit": "piece",
                        "quantity": "60"
                    },
                    "entered": [
                        {
                            "unit": "piece",
                            "quantity": "60"
                        }
                    ],
                    "snapshot": {
                        "total": {
                            "unit": "piece",
                            "quantity": "60"
                        },
                        "schema": 1,
                        "entered": [
                            {
                                "unit": {
                                    "code": "piece",
                                    "factor": "1",
                                    "family": "piece",
                                    "strategy": "linear",
                                    "dimension": "count",
                                    "integer_only": true
                                },
                                "quantity": "60"
                            }
                        ],
                        "packages": [],
                        "normalized": {
                            "unit": "piece",
                            "quantity": "60"
                        },
                        "result_unit": {
                            "code": "piece",
                            "factor": "1",
                            "family": "piece",
                            "strategy": "linear",
                            "dimension": "count",
                            "integer_only": true
                        }
                    },
                    "normalized": {
                        "unit": "piece",
                        "quantity": "60"
                    }
                },
                "amount": "4500.00",
                "inventory_movement_id": "01a16812-1c08-722e-8b75-a2da48c504ac",
                "inventory_reversal_movement_id": null,
                "operational_record_id": null,
                "reversal_record_id": null
            }
        ],
        "created_by": "01a16812-0a34-72cd-be51-7b39978e5aeb",
        "created_at": "2026-10-14T12:00:00.000000Z"
    },
    "meta": [],
    "message": "Sale recorded."
}
```


Read from the response: `payment_status` (`paid` | `partially_paid` | `unpaid`; `uninvoiced` when no invoice exists), `invoice_id`, `amount_paid`, `outstanding`, `payments[]`, and per line `inventory_movement_id` / `operational_record_id`.

**Retry safety.** Sending the identical request again (same `idempotency_key`, same body) returns the same sale (`201`) — same `id`, same `invoice_id`, still one payment, no second stock-out:

```text
first  call: data.id = 01a16812-1b24-703b-9e48-4bbfcc1ebbb0
second call: data.id = 01a16812-1b24-703b-9e48-4bbfcc1ebbb0
```

Same key with a different body is `409 idempotency_conflict`. A failed save (any 4xx/5xx) records nothing — sale, stock, invoice and payment are all-or-nothing — and does not consume the key.

Part payment, an animals line and a stock line in one sale (the buyer already exists):

`POST /sales`

Request:

```json
{
    "recorded_at": "2026-10-14T10:15:00+01:00",
    "idempotency_key": "sell-2026-10-14-002",
    "buyer": {
        "name": "Mama Ngozi"
    },
    "items": [
        {
            "kind": "livestock",
            "production_cycle_id": "01a16812-15e0-7136-a554-061ecabdb5f0",
            "quantity": 4,
            "unit_price": "6500.00"
        },
        {
            "kind": "stock",
            "inventory_item_id": "01a16812-1215-720a-a580-9d9be82441da",
            "storage_location_id": "01a16812-0cec-734a-a871-300e202c5156",
            "quantity": "10",
            "unit_price": "900.00"
        }
    ],
    "payment": {
        "amount": "20000.00",
        "method": "bank_transfer",
        "received_on": "2026-10-14"
    }
}
```

Response `201`:

```json
{
    "data": {
        "id": "01a16812-2387-71c1-ae07-716923e2ace6",
        "reference": "SAL-2026-00002",
        "status": "active",
        "contact_id": "01a16812-1a46-7068-8584-75bb8ff35188",
        "customer_name": "Mama Ngozi",
        "recorded_at": "2026-10-14T09:15:00.000000Z",
        "total_amount": "35000.00",
        "currency": "NGN",
        "finance_category": {
            "id": "01a16811-1223-7200-bd42-72593870da6e",
            "code": "other_income",
            "name": "Other income"
        },
        "payment_status": "partially_paid",
        "amount_paid": "20000.00",
        "outstanding": "15000.00",
        "invoice": {
            "id": "01a16812-2569-7072-ae63-9a08136ea43e",
            "reference": "INV-2026-00002",
            "status": "issued"
        },
        "invoice_id": "01a16812-2569-7072-ae63-9a08136ea43e",
        "payments": [
            {
                "id": "01a16812-262a-726e-9f73-6258ee6ba90d",
                "reference": "PAY-2026-00002",
                "amount": "20000.00",
                "method": "bank_transfer",
                "received_on": "2026-10-14"
            }
        ],
        "notes": null,
        "corrects_sale_id": null,
        "cancelled_at": null,
        "cancel_reason": null,
        "items": [
            {
                "id": "01a16812-239a-710e-9833-e773d362a245",
                "line_no": 1,
                "kind": "livestock",
                "description": "Livestock - Layer flock SNOI",
                "inventory_item_id": null,
                "storage_location_id": null,
                "inventory_lot_id": null,
                "lot": null,
                "production_cycle_id": "01a16812-15e0-7136-a554-061ecabdb5f0",
                "head_count": 4,
                "quantity": null,
                "measurement": null,
                "amount": "26000.00",
                "inventory_movement_id": null,
                "inventory_reversal_movement_id": null,
                "operational_record_id": "01a16812-2452-7070-917b-c6210660d089",
                "reversal_record_id": null
            },
            {
                "id": "01a16812-240d-729d-9d20-d3063284a1ac",
                "line_no": 2,
                "kind": "stock",
                "description": "Tomatoes",
                "inventory_item_id": "01a16812-1215-720a-a580-9d9be82441da",
                "storage_location_id": "01a16812-0cec-734a-a871-300e202c5156",
                "inventory_lot_id": null,
                "lot": null,
                "production_cycle_id": null,
                "head_count": null,
                "quantity": {
                    "quantity": "10",
                    "unit": "kg",
                    "normalized": {
                        "quantity": "10000",
                        "unit": "g"
                    }
                },
                "measurement": {
                    "total": {
                        "unit": "kg",
                        "quantity": "10"
                    },
                    "entered": [
                        {
                            "unit": "kg",
                            "quantity": "10"
                        }
                    ],
                    "snapshot": {
                        "total": {
                            "unit": "kg",
                            "quantity": "10"
                        },
                        "schema": 1,
                        "entered": [
                            {
                                "unit": {
                                    "code": "kg",
                                    "factor": "1000",
                                    "family": "weight",
                                    "strategy": "linear",
                                    "dimension": "weight",
                                    "integer_only": false
                                },
                                "quantity": "10"
                            }
                        ],
                        "packages": [],
                        "normalized": {
                            "unit": "g",
                            "quantity": "10000"
                        },
                        "result_unit": {
                            "code": "kg",
                            "factor": "1000",
                            "family": "weight",
                            "strategy": "linear",
                            "dimension": "weight",
                            "integer_only": false
                        }
                    },
                    "normalized": {
                        "unit": "g",
                        "quantity": "10000"
                    }
                },
                "amount": "9000.00",
                "inventory_movement_id": "01a16812-24e8-73c1-8f0d-a9f41b0269de",
                "inventory_reversal_movement_id": null,
                "operational_record_id": null,
                "reversal_record_id": null
            }
        ],
        "created_by": "01a16812-0a34-72cd-be51-7b39978e5aeb",
        "created_at": "2026-10-14T12:00:00.000000Z"
    },
    "meta": [],
    "message": "Sale recorded."
}
```


Not paid yet (walk-in name, no payment block): `payment_status` is `uninvoiced` and `invoice_id` is `null`.

`POST /sales`

Request:

```json
{
    "recorded_at": "2026-10-14T11:00:00+01:00",
    "idempotency_key": "sell-2026-10-14-003",
    "customer_name": "Roadside buyer",
    "items": [
        {
            "kind": "stock",
            "output": "eggs",
            "quantity": "30",
            "unit_price": "75.00"
        }
    ]
}
```

Response `201`:

```json
{
    "data": {
        "id": "01a16812-2952-7035-a5b9-b9f90faa3e6b",
        "reference": "SAL-2026-00003",
        "status": "active",
        "contact_id": null,
        "customer_name": "Roadside buyer",
        "recorded_at": "2026-10-14T10:00:00.000000Z",
        "total_amount": "2250.00",
        "currency": "NGN",
        "finance_category": {
            "id": "01a16811-11e3-7371-bcf5-92ec75529f91",
            "code": "egg_sales",
            "name": "Egg sales"
        },
        "payment_status": "uninvoiced",
        "amount_paid": null,
        "outstanding": null,
        "invoice": null,
        "invoice_id": null,
        "payments": [],
        "notes": null,
        "corrects_sale_id": null,
        "cancelled_at": null,
        "cancel_reason": null,
        "items": [
            {
                "id": "01a16812-29c8-7064-8264-28e3a026bbb9",
                "line_no": 1,
                "kind": "stock",
                "description": "Eggs",
                "inventory_item_id": "01a16812-1005-71c4-9348-7a8cb84e5996",
                "storage_location_id": "01a16812-0cec-734a-a871-300e202c5156",
                "inventory_lot_id": null,
                "lot": null,
                "production_cycle_id": null,
                "head_count": null,
                "quantity": {
                    "quantity": "30",
                    "unit": "piece",
                    "normalized": {
                        "quantity": "30",
                        "unit": "piece"
                    }
                },
                "measurement": {
                    "total": {
                        "unit": "piece",
                        "quantity": "30"
                    },
                    "entered": [
                        {
                            "unit": "piece",
                            "quantity": "30"
                        }
                    ],
                    "snapshot": {
                        "total": {
                            "unit": "piece",
                            "quantity": "30"
                        },
                        "schema": 1,
                        "entered": [
                            {
                                "unit": {
                                    "code": "piece",
                                    "factor": "1",
                                    "family": "piece",
                                    "strategy": "linear",
                                    "dimension": "count",
                                    "integer_only": true
                                },
                                "quantity": "30"
                            }
                        ],
                        "packages": [],
                        "normalized": {
                            "unit": "piece",
                            "quantity": "30"
                        },
                        "result_unit": {
                            "code": "piece",
                            "factor": "1",
                            "family": "piece",
                            "strategy": "linear",
                            "dimension": "count",
                            "integer_only": true
                        }
                    },
                    "normalized": {
                        "unit": "piece",
                        "quantity": "30"
                    }
                },
                "amount": "2250.00",
                "inventory_movement_id": "01a16812-2a1a-72cd-8843-47e66ac30c89",
                "inventory_reversal_movement_id": null,
                "operational_record_id": null,
                "reversal_record_id": null
            }
        ],
        "created_by": "01a16812-0a34-72cd-be51-7b39978e5aeb",
        "created_at": "2026-10-14T12:00:00.000000Z"
    },
    "meta": [],
    "message": "Sale recorded."
}
```


## 2. Sale details page

`GET /sales/{id}` (needs `sale.view`, which workers have):

`GET /sales/01a16812-1b24-703b-9e48-4bbfcc1ebbb0`

Response `200`:

```json
{
    "data": {
        "id": "01a16812-1b24-703b-9e48-4bbfcc1ebbb0",
        "reference": "SAL-2026-00001",
        "status": "active",
        "contact_id": "01a16812-1a46-7068-8584-75bb8ff35188",
        "customer_name": "Mama Ngozi",
        "recorded_at": "2026-10-14T08:30:00.000000Z",
        "total_amount": "4500.00",
        "currency": "NGN",
        "finance_category": {
            "id": "01a16811-11e3-7371-bcf5-92ec75529f91",
            "code": "egg_sales",
            "name": "Egg sales"
        },
        "payment_status": "paid",
        "amount_paid": "4500.00",
        "outstanding": "0.00",
        "invoice": {
            "id": "01a16812-1cb0-716a-9d8b-75d7cff39434",
            "reference": "INV-2026-00001",
            "status": "issued"
        },
        "invoice_id": "01a16812-1cb0-716a-9d8b-75d7cff39434",
        "payments": [
            {
                "id": "01a16812-1d9c-7190-87aa-71a41e7db397",
                "reference": "PAY-2026-00001",
                "amount": "4500.00",
                "method": "cash",
                "received_on": "2026-10-14"
            }
        ],
        "notes": null,
        "corrects_sale_id": null,
        "cancelled_at": null,
        "cancel_reason": null,
        "items": [
            {
                "id": "01a16812-1bb8-7157-9b4d-c96d2e41de19",
                "line_no": 1,
                "kind": "stock",
                "description": "Eggs",
                "inventory_item_id": "01a16812-1005-71c4-9348-7a8cb84e5996",
                "storage_location_id": "01a16812-0cec-734a-a871-300e202c5156",
                "inventory_lot_id": null,
                "lot": null,
                "production_cycle_id": null,
                "head_count": null,
                "quantity": {
                    "quantity": "60",
                    "unit": "piece",
                    "normalized": {
                        "quantity": "60",
                        "unit": "piece"
                    }
                },
                "measurement": {
                    "total": {
                        "unit": "piece",
                        "quantity": "60"
                    },
                    "entered": [
                        {
                            "unit": "piece",
                            "quantity": "60"
                        }
                    ],
                    "snapshot": {
                        "total": {
                            "unit": "piece",
                            "quantity": "60"
                        },
                        "schema": 1,
                        "entered": [
                            {
                                "unit": {
                                    "code": "piece",
                                    "factor": "1",
                                    "family": "piece",
                                    "strategy": "linear",
                                    "dimension": "count",
                                    "integer_only": true
                                },
                                "quantity": "60"
                            }
                        ],
                        "packages": [],
                        "normalized": {
                            "unit": "piece",
                            "quantity": "60"
                        },
                        "result_unit": {
                            "code": "piece",
                            "factor": "1",
                            "family": "piece",
                            "strategy": "linear",
                            "dimension": "count",
                            "integer_only": true
                        }
                    },
                    "normalized": {
                        "unit": "piece",
                        "quantity": "60"
                    }
                },
                "amount": "4500.00",
                "inventory_movement_id": "01a16812-1c08-722e-8b75-a2da48c504ac",
                "inventory_reversal_movement_id": null,
                "operational_record_id": null,
                "reversal_record_id": null
            }
        ],
        "created_by": "01a16812-0a34-72cd-be51-7b39978e5aeb",
        "created_at": "2026-10-14T12:00:00.000000Z"
    },
    "meta": [],
    "message": null
}
```


Show: reference, date, buyer, lines (what, quantity, amount), total, paid, balance (`outstanding`), payment status badge, and the invoice reference when present.

* **Collect the balance**: `POST /invoices/{invoice_id}/payments` (needs `payment.create` — managers/finance). The amount cannot exceed `outstanding` (`409 payment_exceeds_balance`).

`POST /invoices/01a16812-2569-7072-ae63-9a08136ea43e/payments`

Request:

```json
{
    "amount": "10000.00",
    "method": "mobile_money",
    "received_on": "2026-10-14",
    "idempotency_key": "pay-balance-001"
}
```

Response `201`:

```json
{
    "data": {
        "id": "01a16812-3730-7278-a483-a30542eabb1f",
        "reference": "PAY-2026-00003",
        "entry_type": "payment",
        "invoice_id": "01a16812-2569-7072-ae63-9a08136ea43e",
        "invoice_reference": "INV-2026-00002",
        "amount": "10000.00",
        "currency": "NGN",
        "method": "mobile_money",
        "payment_reference": null,
        "received_on": "2026-10-14",
        "recorded_at": "2026-10-14T12:00:00.000000Z",
        "notes": null,
        "finance_transaction_id": "01a16812-3752-70e6-99a5-0ab889d6d775",
        "reverses_payment_id": null,
        "reversed_by_payment_id": null,
        "is_reversed": false,
        "reason": null,
        "created_by": "01a16812-0a34-72cd-be51-7b39978e5aeb",
        "created_at": "2026-10-14T12:00:00.000000Z"
    },
    "meta": [],
    "message": "Payment recorded."
}
```


* **Generate the invoice** for a sale that has none (`invoice_id` is `null`): `POST /sales/{id}/invoice` (needs `invoice.create`; hide the button for roles without it). It is a snapshot of the buyer and lines at that moment.

`POST /sales/01a16812-2952-7035-a5b9-b9f90faa3e6b/invoice`

Request:

```json
{
    "idempotency_key": "inv-for-sale-3"
}
```

Response `201`:

```json
{
    "data": {
        "id": "01a16812-3883-7023-b3e5-5163acfa4cc4",
        "reference": "INV-2026-00003",
        "sale_id": "01a16812-2952-7035-a5b9-b9f90faa3e6b",
        "sale_reference": "SAL-2026-00003",
        "status": "issued",
        "payment_status": "unpaid",
        "issue_date": "2026-10-14",
        "due_date": null,
        "is_overdue": false,
        "total_amount": "2250.00",
        "amount_paid": "0.00",
        "outstanding": "2250.00",
        "currency": "NGN",
        "customer": {
            "contact_id": null,
            "name": "Roadside buyer",
            "phone": null,
            "email": null,
            "address": null
        },
        "seller_name": "Green Acres",
        "notes": null,
        "voided_at": null,
        "void_reason": null,
        "items": [
            {
                "id": "01a16812-389f-70cf-802c-cdace9e4d52c",
                "line_no": 1,
                "sale_item_id": "01a16812-29c8-7064-8264-28e3a026bbb9",
                "kind": "stock",
                "description": "Eggs",
                "quantity_label": "30 piece",
                "amount": "2250.00"
            }
        ],
        "payments": [],
        "created_by": "01a16812-0a34-72cd-be51-7b39978e5aeb",
        "created_at": "2026-10-14T12:00:00.000000Z"
    },
    "meta": [],
    "message": "Invoice issued."
}
```


* **Share / print**: `GET /invoices/{invoice_id}/pdf` (needs `invoice.view`) returns the PDF (`application/pdf`). Download it as a blob and hand it to the device share sheet or print dialog. Hide Share/Print for roles without `invoice.view` (workers).

## 3. Cancel

`POST /sales/{id}/cancel` needs `sale.cancel` (not workers). Body: `reason`, `recorded_at`, `idempotency_key`. It puts the stock or animals back, voids the invoice and keeps the sale as history (`status: "cancelled"`).

If money was received the plain cancel is refused:

`POST /sales/01a16812-1b24-703b-9e48-4bbfcc1ebbb0/cancel`

Request:

```json
{
    "reason": "Buyer returned the eggs",
    "recorded_at": "2026-10-14T12:00:00Z",
    "idempotency_key": "cancel-sale-1"
}
```

Response `409`:

```json
{
    "message": "Reverse the payments received on this sale before cancelling it.",
    "code": "sale_has_payments",
    "request_id": "01a16812-38e6-71db-994a-f7f1534245f0",
    "details": {
        "amount_paid": "4500.00"
    }
}
```


Offer **"Cancel and refund payments"** which sends the same body plus `reverse_payments: true` (also needs `payment.reverse`). Every live payment is reversed, the stock comes back, and the invoice is voided — in one transaction. `recorded_at` must not be earlier than the payments being reversed (use the current time).

`POST /sales/01a16812-1b24-703b-9e48-4bbfcc1ebbb0/cancel`

Request:

```json
{
    "reason": "Buyer returned the eggs",
    "recorded_at": "2026-10-14T12:00:00Z",
    "idempotency_key": "cancel-sale-1",
    "reverse_payments": true
}
```

Response `200`:

```json
{
    "data": {
        "id": "01a16812-1b24-703b-9e48-4bbfcc1ebbb0",
        "reference": "SAL-2026-00001",
        "status": "cancelled",
        "contact_id": "01a16812-1a46-7068-8584-75bb8ff35188",
        "customer_name": "Mama Ngozi",
        "recorded_at": "2026-10-14T08:30:00.000000Z",
        "total_amount": "4500.00",
        "currency": "NGN",
        "finance_category": {
            "id": "01a16811-11e3-7371-bcf5-92ec75529f91",
            "code": "egg_sales",
            "name": "Egg sales"
        },
        "payment_status": "uninvoiced",
        "amount_paid": null,
        "outstanding": null,
        "invoice": null,
        "invoice_id": null,
        "payments": [],
        "notes": null,
        "corrects_sale_id": null,
        "cancelled_at": "2026-10-14T12:00:00.000000Z",
        "cancel_reason": "Buyer returned the eggs",
        "items": [
            {
                "id": "01a16812-1bb8-7157-9b4d-c96d2e41de19",
                "line_no": 1,
                "kind": "stock",
                "description": "Eggs",
                "inventory_item_id": "01a16812-1005-71c4-9348-7a8cb84e5996",
                "storage_location_id": "01a16812-0cec-734a-a871-300e202c5156",
                "inventory_lot_id": null,
                "lot": null,
                "production_cycle_id": null,
                "head_count": null,
                "quantity": {
                    "quantity": "60",
                    "unit": "piece",
                    "normalized": {
                        "quantity": "60",
                        "unit": "piece"
                    }
                },
                "measurement": {
                    "total": {
                        "unit": "piece",
                        "quantity": "60"
                    },
                    "entered": [
                        {
                            "unit": "piece",
                            "quantity": "60"
                        }
                    ],
                    "snapshot": {
                        "total": {
                            "unit": "piece",
                            "quantity": "60"
                        },
                        "schema": 1,
                        "entered": [
                            {
                                "unit": {
                                    "code": "piece",
                                    "factor": "1",
                                    "family": "piece",
                                    "strategy": "linear",
                                    "dimension": "count",
                                    "integer_only": true
                                },
                                "quantity": "60"
                            }
                        ],
                        "packages": [],
                        "normalized": {
                            "unit": "piece",
                            "quantity": "60"
                        },
                        "result_unit": {
                            "code": "piece",
                            "factor": "1",
                            "family": "piece",
                            "strategy": "linear",
                            "dimension": "count",
                            "integer_only": true
                        }
                    },
                    "normalized": {
                        "unit": "piece",
                        "quantity": "60"
                    }
                },
                "amount": "4500.00",
                "inventory_movement_id": "01a16812-1c08-722e-8b75-a2da48c504ac",
                "inventory_reversal_movement_id": "01a16812-3c11-7346-90d9-2e999ee0dec8",
                "operational_record_id": null,
                "reversal_record_id": null
            }
        ],
        "created_by": "01a16812-0a34-72cd-be51-7b39978e5aeb",
        "created_at": "2026-10-14T12:00:00.000000Z"
    },
    "meta": [],
    "message": "Sale cancelled."
}
```


## 4. Errors

All errors use the standard envelope (`message`, `code`, `request_id`, and `errors` for `422`). Show `message` and highlight the field in `errors`.

| Case | Status | Example |
|---|---|---|
| Payment more than the total | `422` on `payment.amount` | see below |
| `amount` ≠ `quantity × unit_price` | `422` on `items.N.amount` | see below |
| More than one of `buyer` / `contact_id` / `customer_name` | `422` on `buyer` | see below |
| Not enough stock / animals | `409 insufficient_stock` / `insufficient_population` (`details.available`) | see below |
| Buyer exists but is inactive | `409 contact_inactive` | |
| Same idempotency key, different body | `409 idempotency_conflict` | |
| Cycle closed, expired lot, inactive item | `409 cycle_closed` / `lot_expired` / `item_inactive` | |
| Cancel with live payments, no flag | `409 sale_has_payments` | §3 |
| Missing permission | `403 forbidden` | §5 |

`POST /sales`

Request:

```json
{
    "recorded_at": "2026-10-14T11:30:00+01:00",
    "idempotency_key": "sell-2026-10-14-004",
    "buyer": {
        "name": "Mama Ngozi"
    },
    "items": [
        {
            "kind": "stock",
            "output": "eggs",
            "quantity": "10",
            "unit_price": "75.00"
        }
    ],
    "payment": {
        "amount": "900.00",
        "method": "cash"
    }
}
```

Response `422`:

```json
{
    "message": "The payment cannot be more than the sale total (750.00).",
    "code": "validation_failed",
    "request_id": "01a16812-2ac3-71dd-a22e-d5dc699b9b18",
    "errors": {
        "payment.amount": [
            "The payment cannot be more than the sale total (750.00)."
        ]
    }
}
```


`POST /sales`

Request:

```json
{
    "recorded_at": "2026-10-14T11:30:00+01:00",
    "idempotency_key": "sell-2026-10-14-005",
    "items": [
        {
            "kind": "stock",
            "output": "eggs",
            "quantity": "10",
            "unit_price": "75.00",
            "amount": "700.00"
        }
    ]
}
```

Response `422`:

```json
{
    "message": "The amount (700.00) does not match quantity x unit_price (750.00).",
    "code": "validation_failed",
    "request_id": "01a16812-2d5e-724c-a6d0-c95de4e441ed",
    "errors": {
        "items.0.amount": [
            "The amount (700.00) does not match quantity x unit_price (750.00)."
        ]
    }
}
```


`POST /sales`

Request:

```json
{
    "recorded_at": "2026-10-14T11:30:00+01:00",
    "idempotency_key": "sell-2026-10-14-006",
    "buyer": {
        "name": "Mama Ngozi"
    },
    "customer_name": "Someone else",
    "items": [
        {
            "kind": "other",
            "description": "Manure",
            "amount": "500.00"
        }
    ]
}
```

Response `422`:

```json
{
    "message": "Name the buyer with exactly one of buyer, contact_id or customer_name.",
    "code": "validation_failed",
    "request_id": "01a16812-2f71-73f6-b145-f0916af00831",
    "errors": {
        "buyer": [
            "Name the buyer with exactly one of buyer, contact_id or customer_name."
        ]
    }
}
```


`POST /sales`

Request:

```json
{
    "recorded_at": "2026-10-14T11:30:00+01:00",
    "idempotency_key": "sell-2026-10-14-007",
    "items": [
        {
            "kind": "stock",
            "output": "eggs",
            "quantity": "5000",
            "unit_price": "75.00"
        }
    ],
    "payment": {
        "amount": "100.00",
        "method": "cash"
    }
}
```

Response `409`:

```json
{
    "message": "There is not enough stock in this location.",
    "code": "insufficient_stock",
    "request_id": "01a16812-308e-73fb-ae2b-c422140d61e2",
    "details": {
        "available": {
            "quantity": "150",
            "unit": "piece"
        },
        "requested": {
            "quantity": "5000",
            "unit": "piece"
        }
    }
}
```


## 5. Worker vs manager

| | Farm Worker | Manager / Finance / Owner |
|---|---|---|
| `GET /sales/sellables`, `POST /sales` (with `buyer`, `payment`, quantity × price) | yes (`sale.create`) | yes |
| `GET /sales`, `GET /sales/{id}` | yes (`sale.view`) | yes |
| Payment inside `POST /sales` | yes — no `payment.create` needed | yes |
| `invoice` block, `POST /sales/{id}/invoice`, invoice list/PDF | no (`403`) | yes |
| Collect a later payment (`POST /invoices/{id}/payments`), list payments | no | yes |
| Cancel, `reverse_payments`, reverse a payment, void an invoice | no | yes (`sale.cancel`, `payment.reverse`, `invoice.void`) |
| Contacts list / create | no (the `buyer` shortcut still works) | yes |
| Finance (income/expense, transactions) | no | yes |
| Vet | none of the above (`403`) | – |

A worker who taps a hidden-for-them action would get:

`POST /sales/01a16812-2387-71c1-ae07-716923e2ace6/cancel`

Request:

```json
{
    "reason": "Buyer returned the eggs",
    "recorded_at": "2026-10-14T12:00:00Z",
    "idempotency_key": "cancel-sale-1"
}
```

Response `403`:

```json
{
    "message": "You are not allowed to perform this action.",
    "code": "forbidden",
    "request_id": "01a16812-3d8c-7250-9f62-99b00cc6826a"
}
```


Use `GET /auth/me`/farm membership permissions to decide which buttons to render; the server enforces them regardless.

## 6. Relation to the older routes

* The existing three-step flow (`POST /sales`, `POST /sales/{id}/invoice`, `POST /invoices/{id}/payments`) is unchanged and still the way to invoice later or take further payments.
* `POST /inventory/stock-out` with reason `sold` still works for API clients but records only the stock movement — no buyer, invoice or money. The Eggs OUT / Milk OUT **Sold** choice and the Income form's "Sold something from your farm?" should open this Sell form instead.
