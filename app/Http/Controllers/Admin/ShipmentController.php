<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Shipping\ConfirmOrderPickup;
use App\Actions\Shipping\DispatchShipment;
use App\Actions\Shipping\RecordShipmentTracking;
use App\Exceptions\IllegalOrderTransitionException;
use App\Exceptions\ShippingFailedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConfirmOrderPickupRequest;
use App\Http\Requests\Admin\DispatchShipmentRequest;
use App\Http\Requests\Admin\UpdateShipmentStatusRequest;
use App\Models\CourierService;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Shipping\ShippingManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Sending an order's goods out, from the branch that packed them
 * (P2-E2-T4, T6, T7).
 *
 * **No screen of its own.** Every one of these writes is made from the order's
 * detail page, because that is where somebody is standing when they decide to
 * send it: the waybill is typed beside the address it is going to, and the
 * parcel's timeline is read beside the order's own. A separate shipment screen
 * would be a second place to keep the same facts right.
 *
 * Four decisions, and each is a different one:
 *
 * - **`dispatch`** — the goods leave the branch. Authorised on the *order*
 *   (`OrderPolicy::dispatchShipment`), because an order whose delivery was
 *   arranged by hand has no `shipments` row to authorise against until the
 *   branch says which courier it used.
 * - **`updateStatus`** — the branch moves a parcel along by hand. The whole
 *   timeline of a manual courier, and authorised on the parcel.
 * - **`refreshTracking`** — ask the courier now instead of waiting for the
 *   ten-minute sweep (`SyncShipmentTracking`). Done in the foreground rather
 *   than queued, because the staff member pressing it is waiting for the
 *   answer and a provider's refusal is a sentence they can act on — which a
 *   queued job could only write to a log.
 * - **`confirmPickup`** — a Click & Collect order handed over at the counter,
 *   authorised on the order for the same reason as `dispatch`: there is no
 *   parcel at all.
 *
 * Because `SubstituteBindings` runs after `SetBranchContext` (ADR-027),
 * another branch's order or shipment id in the URL answers **404** rather than
 * 403 — the 404 is what keeps a branch from learning the row exists.
 */
class ShipmentController extends Controller
{
    /**
     * Hand an order's goods to a courier (P2-E2-T4).
     *
     * The refusals are reported on the field that caused them rather than in a
     * banner, so the message lands next to the control the staff member has to
     * change — and nothing is written: an order marked shipped with no waybill
     * is an order nobody can trace.
     */
    public function dispatch(
        DispatchShipmentRequest $request,
        Order $order,
        DispatchShipment $dispatchShipment,
    ): RedirectResponse {
        $this->authorize('dispatchShipment', $order);

        /** @var User $actor */
        $actor = $request->user();

        /** @var CourierService $service */
        $service = CourierService::query()->with('courier')->findOrFail($request->courierServiceId());

        try {
            $shipment = $dispatchShipment->handle(
                order: $order,
                service: $service,
                trackingNumber: $request->trackingNumber(),
                actor: $actor,
                notes: $request->notes(),
            );
        } catch (ShippingFailedException $refused) {
            return back()->withErrors(['courier_service_id' => $refused->getMessage()]);
        } catch (IllegalOrderTransitionException $moved) {
            // The order moved on between the screen being drawn and the button
            // being pressed — cancelled by central, or already dispatched at
            // another till. Nothing was written.
            return back()->withErrors(['courier_service_id' => $moved->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Pesanan {$order->order_number} dikirim via {$shipment->courier_name} "
                ."— {$shipment->service_name}, resi {$shipment->tracking_number}.",
        ]);

        return back();
    }

    /**
     * Record where a parcel has got to, in the branch's own words (P2-E2-T4).
     *
     * The only way a manual courier's parcel moves at all: there is nobody to
     * ask, so this *is* the timeline. `RecordShipmentTracking` decides whether
     * the status moves the order with it — a delivered parcel does, a failed
     * one deliberately does not.
     */
    public function updateStatus(
        UpdateShipmentStatusRequest $request,
        Shipment $shipment,
        RecordShipmentTracking $record,
    ): RedirectResponse {
        $this->authorize('manage', $shipment);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $updated = $record->manual(
                shipment: $shipment,
                status: $request->status(),
                description: $request->description(),
                location: $request->location(),
                actor: $actor,
            );
        } catch (ShippingFailedException $refused) {
            return back()->withErrors(['status' => $refused->getMessage()]);
        } catch (IllegalOrderTransitionException $moved) {
            return back()->withErrors(['status' => $moved->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Pengiriman {$updated->tracking_number} kini berstatus \"{$updated->status->label()}\".",
        ]);

        return back();
    }

    /**
     * Ask the courier where the parcel is, now (P2-E2-T6).
     *
     * The same two calls the sweep makes, run in the foreground because
     * somebody is waiting for the answer. A courier that cannot be reached
     * says so in words here, where the sweep could only log it.
     */
    public function refreshTracking(
        Request $request,
        Shipment $shipment,
        ShippingManager $manager,
        RecordShipmentTracking $record,
    ): RedirectResponse {
        $this->authorize('manage', $shipment);

        if (! $shipment->isTrackable()) {
            return back()->withErrors([
                'tracking' => 'Pengiriman ini tidak dapat dilacak otomatis: '
                    .'kurirnya dikelola manual, nomor resinya belum ada, atau paketnya sudah selesai. '
                    .'Perbarui statusnya dari kartu pengiriman.',
            ]);
        }

        try {
            $updated = $record->handle($shipment, $manager->forShipment($shipment)->track($shipment));
        } catch (ShippingFailedException $unavailable) {
            return back()->withErrors(['tracking' => $unavailable->getMessage()]);
        } catch (IllegalOrderTransitionException $moved) {
            return back()->withErrors(['tracking' => $moved->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $record->appendedEvents > 0
                ? "{$record->appendedEvents} pembaruan baru dari kurir. "
                    ."Status pengiriman: \"{$updated->status->label()}\"."
                : "Belum ada pembaruan baru dari kurir. Status pengiriman tetap \"{$updated->status->label()}\".",
        ]);

        return back();
    }

    /**
     * Hand a Click & Collect order over at the counter (P2-E2-T7).
     *
     * The code is compared by `ConfirmOrderPickup` inside the order's row
     * lock, not here: two tills must not both accept the same code for the
     * same order, and the comparison is the decision rather than the shape of
     * the input.
     */
    public function confirmPickup(
        ConfirmOrderPickupRequest $request,
        Order $order,
        ConfirmOrderPickup $confirmPickup,
    ): RedirectResponse {
        $this->authorize('confirmPickup', $order);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $confirmPickup->handle(
                order: $order,
                code: $request->pickupCode(),
                actor: $actor,
                override: $request->overridesMissingCode(),
                note: $request->note(),
            );
        } catch (ShippingFailedException $refused) {
            return back()->withErrors(['pickup_code' => $refused->getMessage()]);
        } catch (IllegalOrderTransitionException $moved) {
            return back()->withErrors(['pickup_code' => $moved->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Pesanan {$order->order_number} selesai — barang sudah diambil pelanggan.",
        ]);

        return back();
    }
}
