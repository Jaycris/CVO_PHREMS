@php
    $removed = $change === \App\Notifications\OffsiteWorkScheduled::REMOVED;
    $changed = $change === \App\Notifications\OffsiteWorkScheduled::CHANGED;
    $logoPath = public_path('images/CreativeVision-LOGO-v2-01.png');
    $logoSrc = isset($message) && file_exists($logoPath)
        ? $message->embed($logoPath)
        : asset('images/CreativeVision-LOGO-v2-01.png');

    $headline = $removed
        ? 'Clock-in required'
        : ($changed ? 'Off-site work updated' : 'Off-site work confirmed');
    $intro = $removed
        ? 'Your previous off-site work arrangement has been removed.'
        : ($changed
            ? 'The details of your off-site work arrangement have changed.'
            : 'Your off-site work arrangement has been recorded.');
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $headline }}</title>
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
                        <td style="padding:34px 38px; background:#0f172a; border-bottom:4px solid {{ $removed ? '#dc2626' : '#157a52' }};">
                            <p style="margin:0 0 12px; color:{{ $removed ? '#fecaca' : '#a7f3d0' }}; font-size:12px; font-weight:700; letter-spacing:3px; text-transform:uppercase;">PHREMS Attendance</p>
                            <h1 style="margin:0; color:#ffffff; font-size:28px; line-height:1.25; font-weight:800;">{{ $headline }}</h1>
                            <p style="margin:12px 0 0; color:#cbd5e1; font-size:15px; line-height:1.7;">{{ $intro }}</p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:38px;">
                            <h2 style="margin:0 0 14px; color:#0f172a; font-size:22px; line-height:1.35; font-weight:800;">Hi {{ $employeeName }},</h2>

                            @if ($removed)
                                <p style="margin:0; color:#475569; font-size:16px; line-height:1.7;">
                                    You are no longer recorded as working away from the office for the assignment below.
                                </p>
                            @else
                                <p style="margin:0; color:#475569; font-size:16px; line-height:1.7;">
                                    {{ $changed ? 'Please review the updated details below.' : 'Here are the details of your approved off-site work.' }}
                                </p>
                            @endif

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:24px 0 0; border-radius:14px; background:#f8fafc; border:1px solid #e2e8f0;">
                                <tr>
                                    <td style="padding:18px 20px; border-bottom:1px solid #e2e8f0;">
                                        <p style="margin:0 0 5px; color:#64748b; font-size:11px; font-weight:700; letter-spacing:1.6px; text-transform:uppercase;">Assignment dates</p>
                                        <p style="margin:0; color:#0f172a; font-size:17px; line-height:1.5; font-weight:800;">{{ $assignment->rangeLabel() }}</p>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:18px 20px;">
                                        <p style="margin:0 0 5px; color:#64748b; font-size:11px; font-weight:700; letter-spacing:1.6px; text-transform:uppercase;">Reason</p>
                                        <p style="margin:0; color:#334155; font-size:15px; line-height:1.6; font-weight:600;">{{ $assignment->reason }}</p>
                                    </td>
                                </tr>
                            </table>

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:22px 0 0; border-radius:14px; background:{{ $removed ? '#fff7ed' : '#ecfdf5' }}; border:1px solid {{ $removed ? '#fed7aa' : '#a7f3d0' }};">
                                <tr>
                                    <td style="padding:18px 20px;">
                                        <p style="margin:0 0 5px; color:{{ $removed ? '#9a3412' : '#047857' }}; font-size:12px; font-weight:800; letter-spacing:1.2px; text-transform:uppercase;">
                                            {{ $removed ? 'Action required' : 'Attendance status' }}
                                        </p>
                                        <p style="margin:0; color:{{ $removed ? '#7c2d12' : '#065f46' }}; font-size:15px; line-height:1.65;">
                                            @if ($removed)
                                                <strong>Clock in and out as normal on these days.</strong> Without a time record, the affected days may be treated as absences.
                                            @else
                                                <strong>No clock-in or clock-out is required.</strong> These are paid working days and you will not be marked absent. Any rest day in the range remains a rest day.
                                            @endif
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <table role="presentation" cellspacing="0" cellpadding="0" style="margin:30px 0;">
                                <tr>
                                    <td style="border-radius:12px; background:#157a52;">
                                        <a href="{{ $url }}" style="display:inline-block; padding:14px 24px; color:#ffffff; font-size:15px; font-weight:700; text-decoration:none; border-radius:12px;">View My Attendance</a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0 0 24px; color:#475569; font-size:14px; line-height:1.7;">
                                If these details are incorrect, please contact HR before the listed dates.
                            </p>
                            <p style="margin:0; color:#475569; font-size:15px; line-height:1.7;">Regards,<br><strong style="color:#0f172a;">PHREMS</strong></p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:20px 38px; background:#f8fafc; border-top:1px solid #e2e8f0;">
                            <p style="margin:0; color:#64748b; font-size:12px; line-height:1.6;">If the button does not work, copy and paste this link into your browser:<br><a href="{{ $url }}" style="color:#157a52; word-break:break-all;">{{ $url }}</a></p>
                        </td>
                    </tr>
                </table>

                <p style="max-width:640px; margin:18px auto 0; color:#94a3b8; font-size:11px; line-height:1.6; text-align:center;">This is an automated attendance notice from PHREMS.</p>
            </td>
        </tr>
    </table>
</body>
</html>
