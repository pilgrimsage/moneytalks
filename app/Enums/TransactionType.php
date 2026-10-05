<?php

namespace App\Enums;

enum TransactionType: string
{
    case Expense = 'expense';
    case Income = 'income';
    case Transfer = 'transfer';
    case OpeningBalance = 'opening_balance';
    case Reversal = 'reversal';
    case CcPayment = 'cc_payment';          // paying a credit card bill: NOT an expense
    case Lend = 'lend';                     // I gave someone money: NOT an expense
    case Borrow = 'borrow';                 // someone gave me money: NOT income
    case RepaymentIn = 'repayment_in';      // someone paid me back
    case RepaymentOut = 'repayment_out';    // I paid someone back
    case SplitExpense = 'split_expense';    // I paid, others owe me their share
    case EmiPayment = 'emi_payment';        // loan instalment: principal reduces the loan, interest is an expense
    // Later milestones: refund, adjustment.
}
