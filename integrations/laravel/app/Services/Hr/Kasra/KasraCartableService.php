<?php

namespace App\Services\Hr\Kasra;

use App\Models\CartableItem;
use App\Models\KasraConnection;
use App\Models\KasraRecord;
use App\Models\User;

/** Mirrors provider work; no local generic completion can approve a Kasra item. */
class KasraCartableService
{
    public function reconcile(KasraConnection $connection): void
    {
        if (! User::whereKey($connection->configured_by)->exists()) return;
        $liveItems = [];
        foreach (KasraRecord::where('connection_id', $connection->id)->where('generation', $connection->generation)->where('kind', 'request')->get() as $record) {
            $item = $record->cartable_item_id ? CartableItem::find($record->cartable_item_id) : null;
            if (! $item && ! in_array($record->source_status, ['pending', 'approved', 'rejected'], true)) continue;
            $payload = $record->payload;
            $status = match ($record->source_status) {
                'pending' => $connection->enabled ? 'pending' : 'cancelled',
                'approved' => 'completed', 'rejected' => 'rejected', default => 'cancelled',
            };
            $values = [
                'user_id' => $connection->configured_by, 'type' => 'kasra_request',
                'title' => __('بررسی درخواست کسرا: :type', ['type' => app(KasraParser::class)->requestLabel($payload)]),
                'description' => implode(' · ', array_filter([$record->person_name, $payload['title'] ?? '', $payload['requested_at'] ?? ''])),
                'status' => $status, 'metadata' => ['kasra_record_id' => $record->id, 'kasra_connection_id' => $connection->id,
                    'generation' => $connection->generation, 'fingerprint' => $record->fingerprint,
                    'source_status' => $record->source_status, 'link' => '/general/cartable'],
            ];
            if ($item) {
                $item->fill($values);
                if ($item->isDirty()) $item->save();
            } else {
                // Historical decisions belong in the cartable history without
                // producing a new pending-work notification for every old item.
                $item = $status === 'pending' ? CartableItem::create($values) : CartableItem::withoutEvents(fn () => CartableItem::create($values));
                $record->update(['cartable_item_id' => $item->id]);
            }
            $liveItems[] = $item->id;
        }
        // A replaced account/generation must not leave actionable ghosts.
        CartableItem::where('type', 'kasra_request')->where('metadata->kasra_connection_id', $connection->id)
            ->where('status', 'pending')->whereNotIn('id', $liveItems)->update(['status' => 'cancelled']);
    }
}
