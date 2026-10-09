<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f6f5f0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1f2a24;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f6f5f0;">
<tr>
<td align="center" style="padding:32px 16px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:520px;">
<tr>
<td style="padding:0 4px 16px;font-size:22px;font-weight:700;letter-spacing:-0.2px;color:#1e4d2b;">{{ $brand }}</td>
</tr>
<tr>
<td style="background-color:#ffffff;border-top:4px solid #1e4d2b;border-radius:6px;padding:32px 28px;font-size:16px;line-height:1.6;">
<p style="margin:0 0 16px;">Hello,</p>
@foreach ($lines as $line)
<p style="margin:0 0 16px;">{{ $line }}</p>
@endforeach
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0;">
<tr>
<td style="background-color:#eef3ee;border:1px solid #c9d8cc;border-radius:6px;padding:14px 24px;font-family:'SFMono-Regular',Menlo,Consolas,'Courier New',monospace;font-size:30px;font-weight:700;letter-spacing:8px;color:#1e4d2b;">{{ $code }}</td>
</tr>
</table>
<p style="margin:0 0 16px;">This code expires in {{ $ttlMinutes }} minutes.</p>
<p style="margin:0 0 24px;color:#56635b;font-size:14px;">{{ $ignore }}</p>
<p style="margin:0;">Regards,<br>{{ $brand }}</p>
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>
