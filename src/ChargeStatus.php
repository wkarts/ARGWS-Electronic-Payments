<?php

declare(strict_types=1);

namespace Argws\ElectronicPayments;

enum ChargeStatus: string
{
    case Pending = 'pending';
    case Authorized = 'authorized';
    case Confirmed = 'confirmed';
    case Settled = 'settled';
    case ReceivedExternally = 'received_externally';
    case Overdue = 'overdue';
    case Cancelled = 'cancelled';
    case RefundPending = 'refund_pending';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case Disputed = 'disputed';
    case Unknown = 'unknown';
}
