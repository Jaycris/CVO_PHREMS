@php
    /**
     * Unlike a payslip, this carries the figures.
     *
     * A payslip email says "there is one waiting for you" because the person
     * can log in and read it. Whoever this goes to has left: no login, no
     * company mailbox. Sending them a link they cannot open would be sending
     * them nothing.
     */
    $name = $employee->fullName() ?: $employee->employee_id;
    $money = fn ($amount) => '₱' . number_format((float) $amount, 2);

    $deductions = array_values(array_filter([
        (float) $finalPay->cash_advance_balance > 0
            ? ['Cash advance still owed', $money($finalPay->cash_advance_balance)] : null,
        (float) $finalPay->commission_advance_balance > 0
            ? ['Commission advance still owed', $money($finalPay->commission_advance_balance)] : null,
        (float) $finalPay->other_deduction > 0
            ? [$finalPay->other_deduction_label ?: 'Other deduction', $money($finalPay->other_deduction)] : null,
    ]));

    $logoPath = public_path('images/CreativeVision-email-logo.png');
    $logoSrc = isset($message) && file_exists($logoPath)
        ? $message->embed($logoPath)
        : asset('images/CreativeVision-email-logo.png');
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your final pay</title>
</head>
<body style="margin:0; padding:0; background:#f1f5f9; font-family:Arial, Helvetica, sans-serif; color:#0f172a;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f1f5f9; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px; margin:0 0 18px;">
                    <tr>
                        <td align="center">
                            <img src="{{ $logoSrc }}" alt="CreatiVision Outsourcing" width="160" style="display:block; max-width:160px; width:100%; height:auto;">
                        </td>
                    </tr>
                </table>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px; overflow:hidden; border-radius:18px; background:#ffffff; border:1px solid #dbe3ee;">
                    <tr>
                        <td style="padding:34px 38px; background:linear-gradient(135deg,#020617 0%,#0f172a 52%,#052e23 100%);">
                            <p style="margin:0 0 12px; color:#a7f3d0; font-size:12px; font-weight:700; letter-spacing:3px; text-transform:uppercase;">PHREMS</p>
                            <h1 style="margin:0; color:#ffffff; font-size:28px; line-height:1.25; font-weight:800;">Your final pay</h1>
                            <p style="margin:12px 0 0; color:#cbd5e1; font-size:15px; line-height:1.7;">
                                Last day {{ $finalPay->separation_date->format('F j, Y') }}
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:38px;">
                            <h2 style="margin:0 0 14px; color:#0f172a; font-size:22px; line-height:1.35; font-weight:800;">Hello {{ $name }},</h2>
                            <p style="margin:0; color:#475569; font-size:16px; line-height:1.7;">
                                Here is what you are owed after your last day. Your final salary is not in this statement — it was paid with your last payslip, for the days you worked in that cutoff.
                            </p>

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:26px 0 0; border-collapse:collapse;">
                                <tr>
                                    <td style="padding:12px 0; border-bottom:1px solid #e2e8f0; color:#475569; font-size:15px;">
                                        13th month pay for {{ $finalPay->for_year }}
                                        <span style="display:block; color:#94a3b8; font-size:13px;">{{ $money($finalPay->basic_earned) }} basic earned ÷ 12</span>
                                    </td>
                                    <td style="padding:12px 0; border-bottom:1px solid #e2e8f0; text-align:right; color:#0f172a; font-size:15px; font-weight:700;">
                                        {{ $money($finalPay->thirteenth_month) }}
                                    </td>
                                </tr>

                                @foreach ($deductions as [$label, $amount])
                                    <tr>
                                        <td style="padding:12px 0; border-bottom:1px solid #e2e8f0; color:#475569; font-size:15px;">Less: {{ $label }}</td>
                                        <td style="padding:12px 0; border-bottom:1px solid #e2e8f0; text-align:right; color:#9a3412; font-size:15px; font-weight:700;">- {{ $amount }}</td>
                                    </tr>
                                @endforeach

                                <tr>
                                    <td style="padding:16px 0; color:#0f172a; font-size:17px; font-weight:800;">Total</td>
                                    <td style="padding:16px 0; text-align:right; color:#157a52; font-size:20px; font-weight:800;">{{ $money($finalPay->net_amount) }}</td>
                                </tr>
                            </table>

                            @if ($finalPay->released_on)
                                <p style="margin:18px 0 0; color:#475569; font-size:15px; line-height:1.7;">
                                    Released on {{ $finalPay->released_on->format('F j, Y') }}.
                                </p>
                            @endif

                            <p style="margin:24px 0 0; color:#475569; font-size:15px; line-height:1.7;">
                                If any of this looks wrong, reply to this email or contact HR and we will go through it with you.
                            </p>

                            <p style="margin:24px 0 0; color:#475569; font-size:15px; line-height:1.7;">Thank you for your work with us,<br><strong style="color:#0f172a;">CreatiVision Outsourcing</strong></p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
