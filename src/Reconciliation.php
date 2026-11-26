<?php

declare(strict_types=1);

namespace Argws\ElectronicPayments;

/** Parecer sobre uma cobrança por parcela. A aplicação registra baixa e saldo na sua transação local. */
enum Reconciliation: string
{
    case Review = 'review';
    case Simulation = 'simulation';
    case Pending = 'pending';
    case PaymentConfirmed = 'payment_confirmed';
    case CreditAvailable = 'credit_available';
    case Reversal = 'reversal';
    case Cancelled = 'cancelled';

    public static function compare(Charge $remote, ChargeId $expected, string $reference, int $amountCents): self
    {
        if ($expected != $remote->id || $remote->reference !== $reference || trim($reference) === ''
            || $amountCents < 1 || $remote->amountCents !== $amountCents) {
            return self::Review;
        }
        if ($expected->account->environment === Environment::Sandbox) {
            return self::Simulation;
        }
        return match ($remote->status) {
            ChargeStatus::Authorized, ChargeStatus::Overdue, ChargeStatus::Pending => self::Pending,
            ChargeStatus::Confirmed => self::PaymentConfirmed,
            ChargeStatus::Settled => self::CreditAvailable,
            ChargeStatus::Refunded, ChargeStatus::PartiallyRefunded => self::Reversal,
            ChargeStatus::Cancelled => self::Cancelled,
            default => self::Review,
        };
    }
}
