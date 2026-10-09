<?php

namespace App\Services\Finance;

use App\Enums\Permission;
use App\Http\Requests\Contacts\StoreContactRequest;
use App\Http\Requests\Contacts\UpdateContactRequest;
use App\Models\Contact;
use App\Models\Farm;
use App\Models\LivestockBatchDetail;
use App\Models\Purchase;
use App\Models\Sale;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiHttpException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Contacts are the people and businesses a farm deals with. A role (supplier/customer) is a flag on ONE contact, so a
 * neighbour who both sells feed to the farm and buys from it is a single row. Contacts never hold money or stock; purchases
 * and finance transactions only reference them. Deactivating hides a contact from new documents but keeps history intact.
 */
class ContactService
{
    public function find(FarmContext $ctx, string $id): Contact
    {
        $ctx->authorize(Permission::ContactView);

        return Contact::ofFarm($ctx->farm)->findOrFail($id);
    }

    public function list(FarmContext $ctx, array $f): LengthAwarePaginator
    {
        $ctx->authorize(Permission::ContactView);
        $q = Contact::ofFarm($ctx->farm);
        if (! ($f['include_inactive'] ?? false)) {
            $q->where('is_active', true);
        }
        if (isset($f['role'])) {
            $q->where($f['role'] === 'supplier' ? 'is_supplier' : 'is_customer', true);
        }
        if (! empty($f['search'])) {
            $q->where('normalized_name', 'like', '%'.addcslashes(Contact::normalizeName($f['search']), '%_\\').'%');
        }

        return $q->orderBy('normalized_name')->orderBy('id')->paginate($f['per_page'] ?? 50);
    }

    public function create(FarmContext $ctx, array $input): Contact
    {
        $ctx->authorize(Permission::ContactManage);
        $data = Validator::make($input, (new StoreContactRequest)->rules())->validate();

        return DB::transaction(function () use ($ctx, $data) {
            Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
            $this->assertNameFree($ctx, $data['name']);
            $contact = new Contact([
                'farm_id' => $ctx->farm->id, 'name' => $this->clean($data['name']), 'kind' => $data['kind'] ?? 'person',
                'is_supplier' => in_array('supplier', $data['roles'], true), 'is_customer' => in_array('customer', $data['roles'], true),
                'phone' => $data['phone'] ?? null, 'email' => $data['email'] ?? null, 'address' => $data['address'] ?? null, 'notes' => $data['notes'] ?? null,
                'is_active' => true, 'created_by' => $ctx->membership->user_id,
            ]);
            $this->save($contact);

            return $contact->refresh();
        }, 3);
    }

    public function update(FarmContext $ctx, string $id, array $input): Contact
    {
        $ctx->authorize(Permission::ContactManage);
        $data = Validator::make($input, (new UpdateContactRequest)->rules())->validate();

        return DB::transaction(function () use ($ctx, $id, $data) {
            Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
            $contact = Contact::ofFarm($ctx->farm)->lockForUpdate()->findOrFail($id);
            if (isset($data['name']) && Contact::normalizeName($data['name']) !== $contact->normalized_name) {
                $this->assertNameFree($ctx, $data['name'], $contact->id);
            }
            if (isset($data['roles'])) {
                $supplier = in_array('supplier', $data['roles'], true);
                if ($contact->is_supplier && ! $supplier && (Purchase::where('farm_id', $ctx->farm->id)->where('contact_id', $contact->id)->exists()
                    || LivestockBatchDetail::where('supplier_contact_id', $contact->id)->exists())) {
                    throw new ApiHttpException(409, 'contact_in_use', 'This contact has purchases or livestock batches; it stays a supplier. Deactivate it instead.');
                }
                $customer = in_array('customer', $data['roles'], true);
                if ($contact->is_customer && ! $customer && Sale::where('farm_id', $ctx->farm->id)->where('contact_id', $contact->id)->exists()) {
                    throw new ApiHttpException(409, 'contact_in_use', 'This contact has sales; it stays a customer. Deactivate it instead.');
                }
                $contact->is_supplier = $supplier;
                $contact->is_customer = $customer;
            }
            foreach (['name' => fn ($v) => $this->clean($v), 'kind' => null, 'phone' => null, 'email' => null, 'address' => null, 'notes' => null, 'is_active' => null] as $field => $map) {
                if (array_key_exists($field, $data)) {
                    $contact->{$field} = $map && $data[$field] !== null ? $map($data[$field]) : $data[$field];
                }
            }
            $this->save($contact);

            return $contact->refresh();
        }, 3);
    }

    private function assertNameFree(FarmContext $ctx, string $name, ?string $ignore = null): void
    {
        $q = Contact::ofFarm($ctx->farm)->where('normalized_name', Contact::normalizeName($name));
        if ($ignore) {
            $q->where('id', '!=', $ignore);
        }
        if ($q->exists()) {
            throw new ApiHttpException(409, 'contact_exists', 'A contact with this name already exists on this farm; add the missing role to it instead.');
        }
    }

    private function save(Contact $contact): void
    {
        try {
            $contact->save();
        } catch (UniqueConstraintViolationException) {
            throw new ApiHttpException(409, 'contact_exists', 'A contact with this name already exists on this farm; add the missing role to it instead.');
        }
    }

    private function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value));
    }
}
