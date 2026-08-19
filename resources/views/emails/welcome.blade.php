<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Welcome</title>
</head>
<body style="margin:0;padding:0;font-family:Inter,system-ui,Arial,sans-serif;background:#f1f5f9;color:#0f172a;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 16px;">
        <tr>
            <td align="center">
                <table width="100%" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0;">
                    <tr>
                        <td style="background:linear-gradient(135deg,#6366f1,#4f46e5);padding:32px 32px 24px;color:#ffffff;">
                            <div style="font-size:13px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;opacity:0.8;">SaaS Commerce</div>
                            <div style="font-size:24px;font-weight:700;margin-top:4px;">Welcome aboard, {{ $name }}.</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 32px;">
                            <p style="margin:0 0 12px;color:#334155;line-height:1.5;">
                                We're glad to have you. Your account is ready — the next step is setting up your first store.
                                It takes about a minute.
                            </p>

                            <p style="margin:24px 0;">
                                <a href="{{ $startUrl }}"
                                   style="display:inline-block;background:#6366f1;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;padding:12px 20px;border-radius:10px;">
                                    Set up your store
                                </a>
                            </p>

                            <div style="border-top:1px solid #e2e8f0;margin:24px 0;"></div>

                            <div style="font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;margin-bottom:8px;">
                                Get started in 3 quick steps
                            </div>
                            <ol style="margin:0 0 0 20px;padding:0;color:#334155;line-height:1.7;">
                                <li><strong>Add your first products</strong> — manually or sync from another platform.</li>
                                <li><strong>Connect your storefronts</strong> — WooCommerce, Shopify, or YouCan.</li>
                                <li><strong>Invite your team</strong> — set up cashier PINs and roles.</li>
                            </ol>

                            <div style="border-top:1px solid #e2e8f0;margin:24px 0;"></div>

                            <p style="margin:0;color:#64748b;font-size:12px;line-height:1.5;">
                                Questions? Reply to this email or write to
                                <a href="mailto:{{ $supportEmail }}" style="color:#6366f1;">{{ $supportEmail }}</a>.
                            </p>
                        </td>
                    </tr>
                </table>

                <div style="font-size:11px;color:#94a3b8;margin-top:16px;">
                    Sent by SaaS Commerce
                </div>
            </td>
        </tr>
    </table>
</body>
</html>
