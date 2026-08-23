@php
    $displayName = $employee->fullName() ?: $employee->employee_id;
    $logoPath = public_path('images/CreativeVision-LOGO-v2-01.png');
    $logoSrc = isset($message) && file_exists($logoPath)
        ? $message->embed($logoPath)
        : asset('images/CreativeVision-LOGO-v2-01.png');
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Activate your PHREMS account</title>
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
                        <td style="padding:0;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#020617;">
                                <tr>
                                    <td style="padding:34px 38px; background:linear-gradient(135deg,#020617 0%,#0f172a 52%,#052e23 100%);">
                                        <p style="margin:0 0 12px; color:#a7f3d0; font-size:12px; font-weight:700; letter-spacing:3px; text-transform:uppercase;">PHREMS</p>
                                        <h1 style="margin:0; color:#ffffff; font-size:28px; line-height:1.25; font-weight:800;">Activate your account</h1>
                                        <p style="margin:12px 0 0; color:#cbd5e1; font-size:15px; line-height:1.7;">Your PHREMS login is ready to set up.</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:38px;">
                            <h2 style="margin:0 0 14px; color:#0f172a; font-size:22px; line-height:1.35; font-weight:800;">Hi {{ $displayName }},</h2>
                            <p style="margin:0; color:#475569; font-size:16px; line-height:1.7;">HR has created your PHREMS account. Set your password to activate your access to your employee profile, requests, and HR records.</p>

                            <table role="presentation" cellspacing="0" cellpadding="0" style="margin:30px 0;">
                                <tr>
                                    <td style="border-radius:12px; background:#157a52;">
                                        <a href="{{ $url }}" style="display:inline-block; padding:14px 24px; color:#ffffff; font-size:15px; font-weight:700; text-decoration:none; border-radius:12px;">Set My Password</a>
                                    </td>
                                </tr>
                            </table>

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 28px; border-radius:14px; background:#f8fafc; border:1px solid #e2e8f0;">
                                <tr>
                                    <td style="padding:18px 20px;">
                                        <p style="margin:0; color:#334155; font-size:14px; line-height:1.6;"><strong>Security note:</strong> This link is unique to you and will expire after 3 days.</p>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0; color:#475569; font-size:15px; line-height:1.7;">Thanks,<br><strong style="color:#0f172a;">{{ config('app.name') }}</strong></p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 38px; background:#f8fafc; border-top:1px solid #e2e8f0;">
                            <p style="margin:0; color:#64748b; font-size:12px; line-height:1.6;">If the button does not work, copy and paste this link into your browser:<br><a href="{{ $url }}" style="color:#157a52; word-break:break-all;">{{ $url }}</a></p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
