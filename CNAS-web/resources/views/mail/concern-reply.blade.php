{{-- App\Mail\ConcernReplyMail (HTML part): the admin's reply, then the message it answers. Same frame as
     mail/email-code (tables and inline styles only, the logo embedded). Plain-text part: mail/concern-reply-text. --}}
@php
    $maroon = '#581825';
    $gold = '#C59B27';
    $ink = '#212529';
    $muted = '#6C757D';
    $line = '#E5E0DB';
    $paper = '#FAF8F5';
    $sans = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
    $logo = isset($message) ? $message->embed(public_path('icons/icon-192.png')) : null;
    $topic = \Illuminate\Support\Str::lower($concern->topic->shortLabel());
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>The administrator replied</title>
</head>
<body style="margin:0; padding:0; background:{{ $paper }}; -webkit-text-size-adjust:100%;">
    {{-- Inbox preview line. --}}
    <div style="display:none; max-height:0; overflow:hidden; mso-hide:all;">{{ \Illuminate\Support\Str::limit($reply->message, 110) }}&#8202;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;</div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:{{ $paper }};">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">

                    {{-- Brand --}}
                    <tr>
                        <td style="padding:0 4px 20px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    @if ($logo)
                                        <td style="padding-right:12px; vertical-align:middle;">
                                            <img src="{{ $logo }}" width="44" height="44" alt="" style="display:block; border:0; border-radius:12px;">
                                        </td>
                                    @endif
                                    <td style="vertical-align:middle; font-family:{{ $sans }};">
                                        <div style="font-size:18px; font-weight:700; letter-spacing:-0.3px; color:{{ $maroon }};">TCC NAV</div>
                                        <div style="font-size:12px; color:{{ $muted }};">Tagoloan Community College</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Card --}}
                    <tr>
                        <td style="background:#FFFFFF; border:1px solid {{ $line }}; border-top:4px solid {{ $maroon }}; border-radius:16px; padding:32px 28px; font-family:{{ $sans }};">
                            <h1 style="margin:0 0 6px; font-size:22px; line-height:28px; font-weight:700; letter-spacing:-0.4px; color:{{ $ink }};">The administrator replied</h1>
                            <p style="margin:0 0 22px; font-size:14px; line-height:21px; color:{{ $muted }};">About your {{ $topic }} message from {{ $sentOn }}.</p>

                            {{-- The reply --}}
                            <div style="font-size:15px; line-height:24px; color:{{ $ink }}; white-space:pre-line; word-wrap:break-word;">{{ $reply->message }}</div>

                            {{-- What it answers --}}
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:26px;">
                                <tr>
                                    <td style="border-left:3px solid {{ $gold }}; padding:2px 0 2px 14px;">
                                        <div style="font-size:12px; line-height:18px; font-weight:700; color:{{ $muted }};">You wrote</div>
                                        <div style="margin-top:4px; font-size:13px; line-height:20px; color:{{ $muted }}; white-space:pre-line; word-wrap:break-word;">{{ $concern->message }}</div>
                                    </td>
                                </tr>
                            </table>

                            @if ($helpUrl)
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:28px;">
                                    <tr>
                                        <td style="border-radius:10px; background:{{ $maroon }};">
                                            <a href="{{ $helpUrl }}" style="display:inline-block; padding:12px 20px; font-size:14px; font-weight:700; color:#FFFFFF; text-decoration:none; border-radius:10px;">Open Help &amp; contact</a>
                                        </td>
                                    </tr>
                                </table>
                            @endif
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="padding:20px 8px 0; font-family:{{ $sans }}; font-size:12px; line-height:19px; color:{{ $muted }};">
                            <p style="margin:0 0 8px;">You’re getting this because you sent a message to TCC NAV. To follow up, send a new message from {{ $helpUrl ? 'Help & contact in your portal' : 'the bottom of the TCC NAV website' }}.</p>
                            <p style="margin:0;">TCC NAV · Campus Navigation &amp; Alert System of Tagoloan Community College.</p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
