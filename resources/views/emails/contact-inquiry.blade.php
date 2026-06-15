<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>New Contact Inquiry</title>
  <style>
    body { font-family: Arial, sans-serif; background: #f4f4f5; margin: 0; padding: 0; }
    .wrapper { max-width: 600px; margin: 40px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
    .header { background: #0f172a; background: linear-gradient(135deg, #0f172a 0%, #172554 60%, #020617 100%); padding: 28px 40px; text-align: left; }
    .header p { color: #93c5fd; margin: 0; font-size: 14px; font-weight: 700; letter-spacing: .4px; }
    .body { padding: 32px 40px; }
    .lead { font-size: 15px; color: #334155; margin: 0 0 22px; }
    .field { border-bottom: 1px solid #e2e8f0; padding: 12px 0; }
    .field:last-child { border-bottom: none; }
    .label { font-size: 11px; text-transform: uppercase; letter-spacing: .8px; color: #64748b; margin: 0 0 4px; font-weight: 700; }
    .value { font-size: 14px; color: #0f172a; margin: 0; }
    .message-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 16px 18px; margin-top: 6px; font-size: 14px; color: #334155; line-height: 1.6; white-space: pre-line; }
    .meta { margin-top: 22px; font-size: 12px; color: #94a3b8; }
    .footer { background: #f8fafc; border-top: 1px solid #e2e8f0; padding: 20px 40px; font-size: 12px; color: #94a3b8; text-align: center; }
    a { color: #1d4ed8; text-decoration: none; }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <img src="{{ asset('images/colonial-logo.png') }}" alt="Colonial Auction Services, Inc." width="170" style="display:inline-block; background:#fff; padding:6px 12px; border-radius:8px; margin-bottom:12px;">
      <p>New Contact Form Submission</p>
    </div>
    <div class="body">
      <p class="lead">
        A new inquiry has been submitted through the Colonial Auction Services website.
        You can reply directly to this email to respond to the sender.
      </p>

      <div class="field">
        <p class="label">Name</p>
        <p class="value">{{ $inquiry->name }}</p>
      </div>
      <div class="field">
        <p class="label">Email</p>
        <p class="value"><a href="mailto:{{ $inquiry->email }}">{{ $inquiry->email }}</a></p>
      </div>
      <div class="field">
        <p class="label">Phone</p>
        <p class="value">{{ $inquiry->phone ?: 'Not provided' }}</p>
      </div>
      <div class="field">
        <p class="label">Subject</p>
        <p class="value">{{ $inquiry->subject }}</p>
      </div>
      <div class="field">
        <p class="label">Message</p>
        <div class="message-box">{{ $inquiry->message }}</div>
      </div>

      <p class="meta">
        Submitted {{ $inquiry->created_at?->format('F j, Y \a\t g:i A T') }}
        @if($inquiry->ip_address)
          &middot; IP {{ $inquiry->ip_address }}
        @endif
      </p>
    </div>
    <div class="footer">
      &copy; {{ date('Y') }} Colonial Auction Services, Inc. — Automated contact form notification.
    </div>
  </div>
</body>
</html>
