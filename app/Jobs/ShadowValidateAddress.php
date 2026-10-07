<?php

namespace App\Jobs;

use App\Enums\AddressValidationOutcome;
use App\Enums\AddressValidator;
use App\Models\AddressValidationAnswer;
use App\Models\Shipment;
use App\Services\Validation\ShadowFedexAddressValidator;
use App\Services\Validation\ShipmentValidationPlan;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks FedEx about an address another validator settled, and logs the answer
 * as a shadow answer paired with the one that settled it
 * (`address-validation-routing/10`). Data for comparing validators on real
 * orders: it never changes the Shipment, and a failure is only logged.
 */
class ShadowValidateAddress implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public int $settledAnswerId,
    ) {
        $this->onQueue('low');
    }

    /**
     * Whether a Shipment just settled by `$source` gets a shadow check. Every
     * country counts, the excluded ones included, since those answers show
     * whether FedEx has improved there; only the FedEx agreement limits it.
     * Fake settles only where no real validator may run.
     */
    public static function shouldRun(Shipment $shipment, AddressValidator $source): bool
    {
        return config('services.fedex.shadow_address_validation')
            && ! in_array($source, [AddressValidator::Fedex, AddressValidator::Fake], true)
            && app(ShipmentValidationPlan::class)->fedexMayValidate($shipment);
    }

    public function handle(): void
    {
        $settled = AddressValidationAnswer::with('shipment')->find($this->settledAnswerId);
        $shipment = $settled?->shipment;

        // Eligibility is checked again: the switch may be off by now, or the
        // Shipment's method may no longer buy from FedEx.
        if ($shipment === null
            || ! $this->stillCurrent($settled, $shipment)
            || ! self::shouldRun($shipment, $settled->validator)) {
            return;
        }

        $validator = new ShadowFedexAddressValidator;
        $copy = clone $shipment;

        try {
            $result = $validator->validate($copy);
        } catch (Throwable $e) {
            Log::channel('fedex-validation')->warning('FedEx shadow validation failed', [
                'shipment_id' => $shipment->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if (! $result->outcome->answered()) {
            return;
        }

        $settledByFedex = $result->outcome === AddressValidationOutcome::Settled;

        try {
            $shadow = AddressValidationAnswer::create([
                'shipment_id' => $shipment->id,
                'validator' => AddressValidator::Fedex,
                'paid' => AddressValidator::Fedex->isPaid(),
                'outcome' => $result->outcome,
                'deliverability' => $settledByFedex ? $copy->deliverability : null,
                'reason' => $result->reason,
                'country' => $shipment->country,
                'trigger' => $settled->trigger,
                'shadow' => true,
                'shadows_answer_id' => $settled->id,
                ...($settledByFedex ? $validator->compare($shipment, $copy) : []),
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent run of the same job logged it first.
            return;
        }

        $this->carryAddressChange($settled, $shadow);
    }

    /**
     * An address edit during the FedEx request stamped the answers that
     * existed then, which this one didn't. It shadows the same address, so it
     * takes the same stamp. The locking read waits for an edit still in its
     * transaction; an edit after it finds this row and stamps it itself.
     */
    private function carryAddressChange(AddressValidationAnswer $settled, AddressValidationAnswer $shadow): void
    {
        DB::transaction(function () use ($settled, $shadow): void {
            $changedAt = AddressValidationAnswer::whereKey($settled->id)
                ->lockForUpdate()
                ->value('address_changed_at');

            if ($changedAt !== null) {
                $shadow->update(['address_changed_at' => $changedAt]);
            }
        });
    }

    /**
     * The answer still describes the Shipment's result: nothing has
     * re-validated or readdressed it since, and it hasn't been shadowed yet.
     */
    private function stillCurrent(AddressValidationAnswer $settled, Shipment $shipment): bool
    {
        $latestLiveAnswerId = (int) $shipment->validationAnswers()->live()->max('id');

        return $settled->address_changed_at === null
            && $shipment->validation_source === $settled->validator
            && $latestLiveAnswerId === $settled->id
            && ! AddressValidationAnswer::where('shadows_answer_id', $settled->id)->exists();
    }
}
