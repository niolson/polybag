# Any user can create, edit and delete carrier accounts

Status: needs-triage

Repo: `polybag`

Severity: high — the lowest role can change which account labels are billed to.
Verified: confirmed (tests below fail on `main` at `6d47232`).

## Problem

`CarrierAccountResource` has no policy (`app/Policies/` has none for `CarrierAccount`) and
no `canAccess()`. Filament then allows every action to every signed-in user. The other
resources without a policy (`Client`, `DataSource`, `LabelBatch`, `PickBatch`) each guard
themselves with `canAccess()` and a role check. This one was missed.

So a user with `Role::User`, a packer, can:

- list carrier accounts and open any one, seeing the account number, CRID, MID and EPS
  account (the billing identity; secrets are not shown);
- change the account number labels are billed to (`ups_account_number`, the FedEx account
  numbers, the USPS `credentials.*` fields);
- edit the account's **scopes**, which decide which account every client and location
  buys on;
- create new accounts, and delete accounts (`DeleteAction` on the edit page,
  `DeleteBulkAction` in the table);
- start FedEx registration (`HasFedexRegistration` on the edit page).

Completing an OAuth connection is the one thing they can't do:
`OAuthCallbackController` refuses anyone below Admin.

`AGENTS.md` treats this as operational-credential configuration, next to App Settings and
Connections, and both of those are Admin-only. Nothing in the app suggests a packer was
meant to reach it; `AuthorizationTest` simply has no case for it.

Area A's carrier-account fingerprint stops an Offer quoted before a billing edit from
being bought after it. It does nothing for Offers quoted after the edit.

## Evidence

```php
beforeEach(function (): void {
    $this->account = createUpsAccount();
    $this->actingAs(User::factory()->create(['role' => Role::User]));
});

it('keeps a User out of carrier accounts', function (): void {
    Livewire::test(ListCarrierAccounts::class)->assertForbidden();                                   // fails: 200
    Livewire::test(CreateCarrierAccount::class)->assertForbidden();                                  // fails: 200
    Livewire::test(EditCarrierAccount::class, ['record' => $this->account->id])->assertForbidden(); // fails: 200
});

it('does not let a User change the billing account', function (): void {
    Livewire::test(EditCarrierAccount::class, ['record' => $this->account->id])
        ->fillForm(['ups_account_number' => 'Z9Y8X7'])
        ->call('save');

    expect($this->account->fresh()->credentials['account_number'])->toBe('A1B2C3');           // fails: 'Z9Y8X7'
});
```

## What to build

- Add `canAccess()` to `CarrierAccountResource` requiring `Role::Admin`, matching
  `DataSourceResource`. Or add a `CarrierAccountPolicy` with Admin-only abilities, which also
  covers the table and page actions without relying on each one.
- Add the cases above to `tests/Feature/AuthorizationTest.php` for User and Manager, and a
  positive case for Admin.
- The other screens that write `carrier_account_scopes`, the setup wizard and the
  connection form (for Amazon Shipping), are already Admin-only; nothing else needs a
  gate.

## Comments
