<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Invitation</title></head>
<body style="margin:0;padding:0;font-family:Inter,system-ui,Arial,sans-serif;background:#f1f5f9;color:#0f172a;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 16px;">
        <tr>
            <td align="center">
                <table width="100%" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0;">
                    <tr>
                        <td style="background:linear-gradient(135deg,#6366f1,#4f46e5);padding:32px;color:#ffffff;">
                            <div style="font-size:13px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;opacity:0.8;">SaaS Commerce</div>
                            <div style="font-size:22px;font-weight:700;margin-top:4px;">You're invited to join {{ $store->name }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 32px;color:#334155;line-height:1.55;">
                            <p style="margin:0 0 12px;">
                                <strong>{{ $invitedBy->name }}</strong> invited you to join <strong>{{ $store->name }}</strong> as a <strong>{{ $roleLabel }}</strong>.
                            </p>

                            <p style="margin:24px 0;">
                                <a href="{{ $acceptUrl }}"
                                   style="display:inline-block;background:#6366f1;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;padding:12px 20px;border-radius:10px;">
                                    Accept invitation
                                </a>
                            </p>

                            <p style="margin:0;color:#64748b;font-size:12px;">
                                This invitation expires {{ $expiresAt->diffForHumans() }} ({{ $expiresAt->format('M j, Y H:i') }}).
                                If you weren't expecting this, you can safely ignore this email.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
