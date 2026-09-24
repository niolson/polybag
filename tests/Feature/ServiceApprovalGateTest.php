<?php

use App\DataTransferObjects\PostageSources\ApprovalRule;
use App\Enums\ApprovalEffect;
use App\Enums\AuditAction;
use App\Enums\SourceEnvironment;
use App\Models\AuditLog;
use App\Models\CarrierService;
use App\Models\Client;
use App\Models\ObservedService;
use App\Models\ServiceApproval;
use App\Models\User;
use App\Services\PostageSources\ObservedServiceMapper;
use App\Services\PostageSources\ServiceApprovalGate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->gate = app(ServiceApprovalGate::class);

    // Every grant names an author — the gate requires one, so the tests carry
    // one rather than papering over the invariant with a nullable default.
    $this->approver = User::factory()->create();
});

function approvalQuestion(ObservedService $observation, ?int $clientId, ?SourceEnvironment $environment = null): array
{
    return [
        $observation->source,
        $environment ?? $observation->environment,
        $observation->external_carrier_id,
        $observation->external_service_id,
        $clientId,
    ];
}

it('denies a service nobody has approved', function (): void {
    $observation = ObservedService::factory()->mapped()->create();
    $client = Client::factory()->create();

    expect($this->gate->approved(...approvalQuestion($observation, $client->id)))->toBeFalse();
});

it('approves a mapped service for one client', function (): void {
    $observation = ObservedService::factory()->mapped()->create();
    $client = Client::factory()->create();
    $approver = User::factory()->create();

    $approval = $this->gate->grant($observation, $client, $approver);

    expect($this->gate->approved(...approvalQuestion($observation, $client->id)))->toBeTrue()
        ->and($approval->approved_by_user_id)->toBe($approver->id)
        ->and($approval->approved_at)->not->toBeNull();
});

it('approves a service nobody has mapped', function (): void {
    // amazon-buy-shipping/18: what a service is called is not whether
    // automation may buy it. OnTrac has no Carrier row, and approving it must
    // not require authoring one.
    $observation = ObservedService::factory()->create();
    $client = Client::factory()->create();

    $this->gate->grant($observation, $client, $this->approver);

    expect($observation->isMapped())->toBeFalse()
        ->and($this->gate->approved(...approvalQuestion($observation, $client->id)))->toBeTrue();
});

it('keeps one client out of another client approval', function (): void {
    $observation = ObservedService::factory()->mapped()->create();
    $approved = Client::factory()->create();
    $other = Client::factory()->create();

    $this->gate->grant($observation, $approved, $this->approver);

    expect($this->gate->approved(...approvalQuestion($observation, $approved->id)))->toBeTrue()
        ->and($this->gate->approved(...approvalQuestion($observation, $other->id)))->toBeFalse();
});

it('does not let a sandbox approval authorize a production purchase', function (): void {
    // The sandbox run returned only AMZN_US / std-us-swa-mfn, while production
    // for the same channel returned OnTrac, UPS and USPS and no Amazon Shipping
    // at all. An approval earned against sandbox identifiers is evidence about
    // nothing that costs money.
    $carrierService = CarrierService::factory()->create();
    $client = Client::factory()->create();

    $sandbox = ObservedService::factory()->mapped($carrierService)->create([
        'environment' => SourceEnvironment::Sandbox,
    ]);

    $this->gate->grant($sandbox, $client, $this->approver);

    expect($this->gate->approved(...approvalQuestion($sandbox, $client->id)))->toBeTrue()
        ->and($this->gate->approved(...approvalQuestion($sandbox, $client->id, SourceEnvironment::Production)))->toBeFalse();
});

it('does not let a production approval authorize a sandbox purchase', function (): void {
    $observation = ObservedService::factory()->mapped()->create([
        'environment' => SourceEnvironment::Production,
    ]);
    $client = Client::factory()->create();

    $this->gate->grant($observation, $client, $this->approver);

    expect($this->gate->approved(...approvalQuestion($observation, $client->id, SourceEnvironment::Sandbox)))->toBeFalse();
});

it('scopes approval to one postage source', function (): void {
    $observation = ObservedService::factory()->mapped()->create(['source' => 'amazon']);
    $client = Client::factory()->create();

    $this->gate->grant($observation, $client, $this->approver);

    // The same carrier and service name, offered by something else, is a
    // different purchase on a different account.
    expect($this->gate->approved(
        'shopify',
        $observation->environment,
        $observation->external_carrier_id,
        $observation->external_service_id,
        $client->id,
    ))->toBeFalse();
});

it('denies when the caller cannot say whose money it is', function (): void {
    $observation = ObservedService::factory()->mapped()->create();
    $this->gate->grant($observation, Client::factory()->create(), $this->approver);

    expect($this->gate->approved(...approvalQuestion($observation, null)))->toBeFalse()
        ->and($this->gate->rulesFor($observation->source, $observation->environment, null)->rules)->toBeEmpty();
});

it('takes effect the moment approval is revoked, with nothing re-quoted', function (): void {
    $observation = ObservedService::factory()->mapped()->create();
    $client = Client::factory()->create();

    $this->gate->grant($observation, $client, $this->approver);
    expect($this->gate->approved(...approvalQuestion($observation, $client->id)))->toBeTrue();

    // No cache to expire between these two lines, which is the point: the
    // question is asked at selection time, so revoking stops the next
    // selection rather than the next hour's.
    expect($this->gate->revoke($observation, $client))->toBe(1)
        ->and($this->gate->approved(...approvalQuestion($observation, $client->id)))->toBeFalse();
});

it('grants again after a revoke without duplicating the row', function (): void {
    $observation = ObservedService::factory()->mapped()->create();
    $client = Client::factory()->create();

    $this->gate->grant($observation, $client, $this->approver);
    $this->gate->revoke($observation, $client);
    $this->gate->grant($observation, $client, $this->approver);

    expect(ServiceApproval::count())->toBe(1);
});

it('reads one client\'s rules for one source in a single question', function (): void {
    $client = Client::factory()->create();
    $other = Client::factory()->create();

    $ground = ObservedService::factory()->mapped()->create([
        'external_carrier_id' => 'USPS',
        'external_service_id' => 'USPS_GROUND_ADVANTAGE',
    ]);
    $express = ObservedService::factory()->mapped()->create([
        'external_carrier_id' => 'USPS',
        'external_service_id' => 'USPS_PRIORITY_MAIL_EXPRESS',
    ]);

    $this->gate->grant($ground, $client, $this->approver);
    $this->gate->grant($express, $other, $this->approver);

    DB::enableQueryLog();

    $rules = $this->gate->rulesFor('amazon', SourceEnvironment::Production, $client->id);

    expect(DB::getQueryLog())->toHaveCount(1)
        ->and($rules->permits('USPS', 'USPS_GROUND_ADVANTAGE'))->toBeTrue()
        ->and($rules->permits('USPS', 'USPS_PRIORITY_MAIL_EXPRESS'))->toBeFalse();

    DB::disableQueryLog();
});

it('sets one client\'s rules to exactly what was submitted', function (): void {
    $client = Client::factory()->create();
    $other = Client::factory()->create();

    $this->gate->grantRule('amazon', SourceEnvironment::Production, $client, ApprovalRule::service('UPS', 'UPS_PTP_GND'), $this->approver);
    $this->gate->grantRule('amazon', SourceEnvironment::Production, $client, ApprovalRule::service('USPS', 'USPS_GROUND_ADVANTAGE'), $this->approver);
    // Another client's rule, and this client's rule in another world, are not
    // this form's to withdraw.
    $this->gate->grantRule('amazon', SourceEnvironment::Production, $other, ApprovalRule::everything(), $this->approver);
    $this->gate->grantRule('amazon', SourceEnvironment::Sandbox, $client, ApprovalRule::everything(), $this->approver);

    $result = $this->gate->sync('amazon', SourceEnvironment::Production, $client, [
        ApprovalRule::service('UPS', 'UPS_PTP_GND'),
        ApprovalRule::everything(),
        ApprovalRule::carrier('ONTRAC', ApprovalEffect::Deny),
    ], $this->approver);

    $rules = $this->gate->rulesFor('amazon', SourceEnvironment::Production, $client->id);

    expect($result)->toBe(['granted' => 2, 'revoked' => 1])
        ->and($rules->rules->map->key()->sort()->values()->all())->toBe([
            'allow|*|*',
            'allow|UPS|UPS_PTP_GND',
            'deny|ONTRAC|*',
        ])
        ->and(ServiceApproval::count())->toBe(5);
});

it('leaves a rule that is already on file alone when the form is saved again', function (): void {
    $client = Client::factory()->create();
    $original = User::factory()->create(['name' => 'Dana Reyes']);

    $this->gate->grantRule('amazon', SourceEnvironment::Production, $client, ApprovalRule::everything(), $original);

    $result = $this->gate->sync('amazon', SourceEnvironment::Production, $client, [ApprovalRule::everything()], $this->approver);

    expect($result)->toBe(['granted' => 0, 'revoked' => 0])
        ->and(ServiceApproval::sole()->approved_by_name)->toBe('Dana Reyes');
});

it('leaves a service approved when it is unmapped', function (): void {
    // amazon-buy-shipping/18: the approval names the source's own identifiers,
    // which unmapping does not change. Withdrawing it would switch automation
    // off as a side effect of a naming fix.
    $observation = ObservedService::factory()->mapped()->create();
    $client = Client::factory()->create();

    $this->gate->grant($observation, $client, $this->approver);

    expect(app(ObservedServiceMapper::class)->unmap($observation))->toBe(1)
        ->and(ServiceApproval::count())->toBe(1)
        ->and($this->gate->approved(...approvalQuestion($observation, $client->id)))->toBeTrue();
});

it('leaves approvals alone in every world the unmapping reaches', function (): void {
    $carrierService = CarrierService::factory()->create();
    $client = Client::factory()->create();

    $production = ObservedService::factory()->mapped($carrierService)->create([
        'environment' => SourceEnvironment::Production,
        'external_carrier_id' => 'USPS',
        'external_service_id' => 'USPS_GROUND_ADVANTAGE',
    ]);
    $sandbox = ObservedService::factory()->mapped($carrierService)->create([
        'environment' => SourceEnvironment::Sandbox,
        'external_carrier_id' => 'USPS',
        'external_service_id' => 'USPS_GROUND_ADVANTAGE',
    ]);

    $this->gate->grant($production, $client, $this->approver);
    $this->gate->grant($sandbox, $client, $this->approver);

    app(ObservedServiceMapper::class)->unmap($production);

    expect(ServiceApproval::count())->toBe(2)
        ->and($sandbox->fresh()->isMapped())->toBeFalse();
});

it('keeps approval through a re-mapping, which changes the name and not the purchase', function (): void {
    $observation = ObservedService::factory()->mapped()->create();
    $client = Client::factory()->create();

    $this->gate->grant($observation, $client, $this->approver);

    app(ObservedServiceMapper::class)->map($observation, CarrierService::factory()->create());

    expect($this->gate->approved(...approvalQuestion($observation, $client->id)))->toBeTrue();
});

it('will not let a client be deleted out from under its approvals', function (): void {
    // A database-level cascade would withdraw permission to spend money without
    // loading a model, so `AuditableObserver` would never hear about it. Every
    // other client-scoped table restricts for its own reasons; this one
    // restricts so that withdrawal always goes through the audited path.
    $observation = ObservedService::factory()->mapped()->create();
    $client = Client::factory()->create();

    $this->gate->grant($observation, $client, $this->approver);

    expect(fn () => $client->delete())->toThrow(QueryException::class);

    $this->gate->revoke($observation, $client);

    $client->delete();

    expect(Client::whereKey($client->id)->exists())->toBeFalse();
});

it('records who authorized every approval it writes', function (): void {
    // A standing permission to spend somebody's money unattended, with no
    // answer to "on whose authority", is the row the two attribution columns
    // exist to prevent. The gate requires an approver, so there is no call that
    // produces one — and the database says so too: `approved_by_name` is NOT
    // NULL while the foreign key beside it is nullable.
    $observation = ObservedService::factory()->mapped()->create();

    $this->gate->sync($observation->source, $observation->environment, Client::factory()->create(), [ApprovalRule::everything()], $this->approver);

    expect(ServiceApproval::sole()->approved_by_name)->toBe($this->approver->name);
});

it('builds approvals whose two attribution columns agree', function (): void {
    // A fixture that named one author in the foreign key and another in the
    // snapshot would be a row the gate cannot write, and would make any test
    // reading provenance prove nothing.
    $approval = ServiceApproval::factory()->create();

    expect($approval->approved_by_name)->toBe($approval->approvedBy->name);

    $someone = User::factory()->create(['name' => 'Wren Okafor']);
    $named = ServiceApproval::factory()->approvedBy($someone)->create();

    expect($named->approved_by_user_id)->toBe($someone->id)
        ->and($named->approved_by_name)->toBe('Wren Okafor');

    $former = ServiceApproval::factory()->formerApprover()->create();

    expect($former->approved_by_user_id)->toBeNull()
        ->and($former->approved_by_name)->toBe('Dana Reyes');
});

it('still says who authorized a spend after their account is gone', function (): void {
    // The foreign key nulls on delete, which is why the name is snapshotted
    // beside it: an approval outlives the audit log's retention, and it should
    // outlive a departed administrator's account too.
    $observation = ObservedService::factory()->mapped()->create();
    $approver = User::factory()->create(['name' => 'Dana Reyes']);

    $approval = $this->gate->grant($observation, Client::factory()->create(), $approver);

    $approver->delete();

    expect($approval->fresh()->approved_by_user_id)->toBeNull()
        ->and($approval->fresh()->approved_by_name)->toBe('Dana Reyes');
});

it('records the withdrawal of an approval, not only the granting of one', function (): void {
    // Permission to spend money unattended is exactly the thing an audit log is
    // asked about afterwards, and a mass delete would have carried every grant
    // and no withdrawal: `->delete()` on a query never loads a model, so
    // `AuditableObserver` never hears about it.
    $observation = ObservedService::factory()->mapped()->create();
    $client = Client::factory()->create();

    $this->gate->grant($observation, $client, $this->approver);
    $this->gate->revoke($observation, $client);

    $entries = AuditLog::where('auditable_type', ServiceApproval::class)->pluck('action');

    expect($entries->all())->toBe([AuditAction::ModelCreated, AuditAction::ModelDeleted]);
});

it('withdraws nothing by unmapping, so there is nothing to record', function (): void {
    $observation = ObservedService::factory()->mapped()->create();

    $this->gate->grant($observation, Client::factory()->create(), $this->approver);
    app(ObservedServiceMapper::class)->unmap($observation);

    expect(AuditLog::where('auditable_type', ServiceApproval::class)
        ->where('action', AuditAction::ModelDeleted)
        ->count())->toBe(0);
});

it('records a withdrawal made by saving fewer rules', function (): void {
    $observation = ObservedService::factory()->mapped()->create();
    $client = Client::factory()->create();

    $this->gate->grant($observation, $client, $this->approver);
    $this->gate->sync($observation->source, $observation->environment, $client, [], $this->approver);

    expect(AuditLog::where('auditable_type', ServiceApproval::class)
        ->where('action', AuditAction::ModelDeleted)
        ->count())->toBe(1);
});

it('withdraws and grants in one transaction', function (): void {
    // Apart, an exception that failed to write after the approvals around it
    // were committed would leave a service approved that was meant not to be.
    $client = Client::factory()->create();
    $this->gate->grantRule('amazon', SourceEnvironment::Production, $client, ApprovalRule::service('UPS', 'UPS_PTP_GND'), $this->approver);

    $outer = DB::transactionLevel();
    $duringDelete = null;

    ServiceApproval::deleted(function () use (&$duringDelete): void {
        $duringDelete ??= DB::transactionLevel();
    });

    $this->gate->sync('amazon', SourceEnvironment::Production, $client, [ApprovalRule::everything()], $this->approver);

    expect($duringDelete)->toBeGreaterThan($outer);
});

/*
|--------------------------------------------------------------------------
| amazon-buy-shipping/18 — carrier and whole-source approvals, and exceptions
|--------------------------------------------------------------------------
*/

/**
 * @return array{0: string, 1: SourceEnvironment, 2: string, 3: string, 4: int}
 */
function serviceQuestion(Client $client, string $carrier, string $service, SourceEnvironment $environment = SourceEnvironment::Production): array
{
    return ['amazon', $environment, $carrier, $service, $client->id];
}

function grantRuleFor(Client $client, ApprovalRule $rule, SourceEnvironment $environment = SourceEnvironment::Production): void
{
    app(ServiceApprovalGate::class)->grantRule('amazon', $environment, $client, $rule, User::factory()->create());
}

it('approves a service never seen before once everything is approved', function (): void {
    $client = Client::factory()->create();
    grantRuleFor($client, ApprovalRule::everything());

    expect(ObservedService::count())->toBe(0)
        ->and($this->gate->approved(...serviceQuestion($client, 'DHL_ECOMMERCE', 'DHL_PARCEL_GROUND')))->toBeTrue();
});

it('lets an exception for a carrier win over approving everything', function (): void {
    $client = Client::factory()->create();
    grantRuleFor($client, ApprovalRule::everything());
    grantRuleFor($client, ApprovalRule::carrier('ONTRAC', ApprovalEffect::Deny));

    expect($this->gate->approved(...serviceQuestion($client, 'ONTRAC', 'ONTRAC_MFN_GROUND')))->toBeFalse()
        ->and($this->gate->approved(...serviceQuestion($client, 'ONTRAC', 'ONTRAC_MFN_SUNRISE')))->toBeFalse()
        ->and($this->gate->approved(...serviceQuestion($client, 'UPS', 'UPS_PTP_GND')))->toBeTrue();
});

it('approves every service of one carrier and nothing of another', function (): void {
    $client = Client::factory()->create();
    grantRuleFor($client, ApprovalRule::carrier('ONTRAC'));

    expect($this->gate->approved(...serviceQuestion($client, 'ONTRAC', 'ONTRAC_MFN_GROUND')))->toBeTrue()
        ->and($this->gate->approved(...serviceQuestion($client, 'ONTRAC', 'ONTRAC_MFN_SUNRISE')))->toBeTrue()
        ->and($this->gate->approved(...serviceQuestion($client, 'UPS', 'UPS_PTP_GND')))->toBeFalse();
});

it('lets an exception for one service win over approving its carrier', function (): void {
    $client = Client::factory()->create();
    grantRuleFor($client, ApprovalRule::carrier('ONTRAC'));
    grantRuleFor($client, ApprovalRule::service('ONTRAC', 'ONTRAC_MFN_SUNRISE', ApprovalEffect::Deny));

    expect($this->gate->approved(...serviceQuestion($client, 'ONTRAC', 'ONTRAC_MFN_GROUND')))->toBeTrue()
        ->and($this->gate->approved(...serviceQuestion($client, 'ONTRAC', 'ONTRAC_MFN_SUNRISE')))->toBeFalse();
});

it('does not let a more specific approval win over an exception', function (): void {
    // Most-specific-wins was rejected: "no OnTrac, except OnTrac Ground" would
    // make a row's effect depend on which other rows exist.
    $client = Client::factory()->create();
    grantRuleFor($client, ApprovalRule::carrier('ONTRAC', ApprovalEffect::Deny));
    grantRuleFor($client, ApprovalRule::service('ONTRAC', 'ONTRAC_MFN_GROUND'));

    expect($this->gate->approved(...serviceQuestion($client, 'ONTRAC', 'ONTRAC_MFN_GROUND')))->toBeFalse();
});

it('approves nothing with only exceptions on file', function (): void {
    $client = Client::factory()->create();
    grantRuleFor($client, ApprovalRule::carrier('ONTRAC', ApprovalEffect::Deny));

    expect($this->gate->approved(...serviceQuestion($client, 'UPS', 'UPS_PTP_GND')))->toBeFalse();
});

it('does not let a sandbox approval of everything approve anything in production', function (): void {
    $client = Client::factory()->create();
    grantRuleFor($client, ApprovalRule::everything(), SourceEnvironment::Sandbox);

    expect($this->gate->approved(...serviceQuestion($client, 'UPS', 'UPS_PTP_GND', SourceEnvironment::Sandbox)))->toBeTrue()
        ->and($this->gate->approved(...serviceQuestion($client, 'UPS', 'UPS_PTP_GND')))->toBeFalse();
});

it('does not let one client\'s approval of everything reach another client', function (): void {
    grantRuleFor(Client::factory()->create(), ApprovalRule::everything());

    expect($this->gate->approved(...serviceQuestion(Client::factory()->create(), 'UPS', 'UPS_PTP_GND')))->toBeFalse();
});

it('refuses one service of every carrier', function (): void {
    expect(fn (): ApprovalRule => ApprovalRule::service(ServiceApproval::WILDCARD, 'UPS_PTP_GND'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => ServiceApproval::factory()->create([
        'external_carrier_id' => ServiceApproval::WILDCARD,
        'external_service_id' => 'UPS_PTP_GND',
    ]))->toThrow(InvalidArgumentException::class);
});

it('will not store the same wildcard twice', function (): void {
    // The reason for a `*` sentinel rather than NULL: MySQL treats NULLs as
    // distinct in a unique index, so NULL wildcards could be duplicated.
    $approval = ServiceApproval::factory()->everything()->create();

    expect(fn () => ServiceApproval::factory()->everything()->create([
        'client_id' => $approval->client_id,
    ]))->toThrow(QueryException::class);
});

it('holds an approval and an exception of the same scope side by side', function (): void {
    $client = Client::factory()->create();
    grantRuleFor($client, ApprovalRule::carrier('ONTRAC'));
    grantRuleFor($client, ApprovalRule::carrier('ONTRAC', ApprovalEffect::Deny));

    expect(ServiceApproval::count())->toBe(2)
        ->and($this->gate->approved(...serviceQuestion($client, 'ONTRAC', 'ONTRAC_MFN_GROUND')))->toBeFalse();
});

it('reads a row written without an effect as an approval', function (): void {
    // Every row from before exceptions existed was an approval; the column's
    // default is what keeps them that way.
    $client = Client::factory()->create();

    DB::table('service_approvals')->insert([
        'source' => 'amazon',
        'environment' => 'production',
        'external_carrier_id' => 'UPS',
        'external_service_id' => 'UPS_PTP_GND',
        'client_id' => $client->id,
        'approved_by_name' => 'Dana Reyes',
        'approved_at' => now(),
    ]);

    expect(ServiceApproval::sole()->effect)->toBe(ApprovalEffect::Allow)
        ->and($this->gate->approved(...serviceQuestion($client, 'UPS', 'UPS_PTP_GND')))->toBeTrue();
});

it('withdraws only the single-service row when one service is revoked', function (): void {
    $client = Client::factory()->create();
    $observation = ObservedService::factory()->create();

    grantRuleFor($client, ApprovalRule::carrier($observation->external_carrier_id));
    $this->gate->grant($observation, $client, $this->approver);

    expect($this->gate->revoke($observation, $client))->toBe(1)
        ->and($this->gate->approved(...approvalQuestion($observation, $client->id)))->toBeTrue();
});
