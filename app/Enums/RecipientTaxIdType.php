<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What kind of tax ID a recipient gave, for destinations that will not clear a
 * parcel without one (ADR-0008, PRD *Recipient tax ID types*).
 *
 * CPF, CNPJ and PCCC have formats that can be checked; a VAT number or any
 * other ID is taken as given.
 */
enum RecipientTaxIdType: string implements HasLabel
{
    /** Brazil: Cadastro de Pessoas Físicas, an individual's taxpayer number. */
    case Cpf = 'cpf';

    /** Brazil: Cadastro Nacional da Pessoa Jurídica, a company's taxpayer number. */
    case Cnpj = 'cnpj';

    /** South Korea: Personal Customs Clearance Code. */
    case Pccc = 'pccc';

    /** A recipient VAT number. */
    case Vat = 'vat';

    /** Any other tax or national ID a destination asks for. */
    case Other = 'other';

    /**
     * Longest ID stored, matching the `shipments.recipient_tax_id` column.
     */
    public const int MAX_LENGTH = 50;

    private const array CNPJ_FIRST_WEIGHTS = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    private const array CNPJ_SECOND_WEIGHTS = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    /**
     * The type an import or form value names, ignoring case and surrounding
     * space, or null when it names none.
     */
    public static function fromInput(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        return is_string($value) ? self::tryFrom(strtolower(trim($value))) : null;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Cpf => 'CPF (Brazil, individual)',
            self::Cnpj => 'CNPJ (Brazil, company)',
            self::Pccc => 'PCCC (South Korea)',
            self::Vat => 'VAT number',
            self::Other => 'Other',
        };
    }

    /**
     * The ID as stored and declared. CPF and CNPJ lose their punctuation
     * (`123.456.789-09`, `12.345.678/0001-95`), and a PCCC its spaces and
     * hyphens; any other ID is only trimmed.
     */
    public function normalize(string $id): string
    {
        return match ($this) {
            self::Cpf, self::Cnpj => strtoupper(preg_replace('/[\s.\/-]+/', '', $id) ?? $id),
            self::Pccc => strtoupper(preg_replace('/[\s-]+/', '', $id) ?? $id),
            self::Vat, self::Other => trim($id),
        };
    }

    public function isValid(?string $id): bool
    {
        if ($id === null) {
            return false;
        }

        $id = $this->normalize($id);

        return match ($this) {
            self::Cpf => self::isValidCpf($id),
            self::Cnpj => self::isValidCnpj($id),
            self::Pccc => preg_match('/^P\d{12}$/', $id) === 1,
            self::Vat, self::Other => $id !== '' && mb_strlen($id) <= self::MAX_LENGTH,
        };
    }

    /**
     * The format an ID must have, as an operator would read it.
     */
    public function format(): string
    {
        return match ($this) {
            self::Cpf => '11 digits with valid check digits',
            self::Cnpj => '14 characters with valid check digits',
            self::Pccc => 'P followed by 12 digits',
            self::Vat, self::Other => 'at most '.self::MAX_LENGTH.' characters',
        };
    }

    /**
     * Why an ID is not valid for this type, or null when it is.
     */
    public function error(?string $id): ?string
    {
        return $this->isValid($id) ? null : "A {$this->getLabel()} must be {$this->format()}.";
    }

    /**
     * Eleven digits whose last two are the mod-11 check digits of the nine
     * before them. A run of one repeated digit passes the arithmetic but is
     * not issued.
     */
    private static function isValidCpf(string $cpf): bool
    {
        if (preg_match('/^\d{11}$/', $cpf) !== 1 || preg_match('/^(\d)\1{10}$/', $cpf) === 1) {
            return false;
        }

        $digits = array_map(intval(...), str_split($cpf));

        foreach ([9, 10] as $length) {
            $sum = 0;

            for ($position = 0; $position < $length; $position++) {
                $sum += $digits[$position] * ($length + 1 - $position);
            }

            if ($digits[$length] !== self::mod11CheckDigit($sum)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Fourteen characters whose last two are the mod-11 check digits of the
     * twelve before them. Since July 2026 Brazil issues alphanumeric CNPJs, so
     * the first twelve may be letters, each valued at its ASCII code less 48,
     * which leaves a digit its own value.
     */
    private static function isValidCnpj(string $cnpj): bool
    {
        if (preg_match('/^[A-Z0-9]{12}\d{2}$/', $cnpj) !== 1 || preg_match('/^(.)\1{13}$/', $cnpj) === 1) {
            return false;
        }

        $values = array_map(fn (string $character): int => ord($character) - 48, str_split($cnpj));

        foreach ([self::CNPJ_FIRST_WEIGHTS, self::CNPJ_SECOND_WEIGHTS] as $weights) {
            $sum = 0;

            foreach ($weights as $position => $weight) {
                $sum += $values[$position] * $weight;
            }

            if ($values[count($weights)] !== self::mod11CheckDigit($sum)) {
                return false;
            }
        }

        return true;
    }

    private static function mod11CheckDigit(int $sum): int
    {
        $remainder = $sum % 11;

        return $remainder < 2 ? 0 : 11 - $remainder;
    }
}
