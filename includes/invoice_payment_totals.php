<?php
declare(strict_types=1);

function invoice_payment_totals_subquery(): string
{
    return '(SELECT invoice_id,SUM(amount) AS paid,SUM(amount) AS paid_amount,COUNT(*) AS payment_count FROM payments GROUP BY invoice_id)';
}
