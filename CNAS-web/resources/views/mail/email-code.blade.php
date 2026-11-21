{{-- App\Mail\EmailCodeMail (HTML part): the code, what it is for, and how long it works. Email clients ignore <style>
     blocks and modern CSS, so this is tables with inline styles only. The logo is embedded in the message (CID), so it
     shows without loading anything from the site. Plain-text part: mail/email-code-text. --}}
@php
    $maroon = '#581825';
    $gold = '#C59B27';
    $ink = '#212529';
    $muted = '#6C757D';
    $line = '#E5E0DB';
    $paper = '#FAF8F5';
    $sans = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
    $mono = "'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace";
    $logo = isset($message) ? $message->embed(public_path('icons/icon-192.png')) : null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $purpose->subject() }}</title>
</head>
<body style="margin:0; padding:0; background:{{ $paper }}; -webkit-text-size-adjust:100%;">
    {{-- Inbox preview line. --}}
    <div style="display:none; max-height:0; overflow:hidden; mso-hide:all;">Your TCC NAV code is {{ $code }}. It works for {{ $minutes }} minutes.&#8202;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;</div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:{{ $paper }};">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:520px;">

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
                            <h1 style="margin:0 0 10px; font-size:24px; line-height:30px; font-weight:700; letter-spacing:-0.4px; color:{{ $ink }};">{{ $purpose->heading() }}</h1>
                            <p style="margin:0 0 24px; font-size:15px; line-height:23px; color:{{ $muted }};">{{ $purpose->intro() }}</p>

                            {{-- The code: one block, so it copies as six digits with nothing in between. --}}
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td align="center" style="background:{{ $paper }}; border:1px solid {{ $line }}; border-radius:12px; padding:22px 12px 18px;">
                                        <div style="font-family:{{ $mono }}; font-size:38px; line-height:44px; font-weight:700; letter-spacing:12px; padding-left:12px; color:{{ $maroon }};">{{ $code }}</div>
                                        <div style="margin-top:10px; font-size:13px; line-height:18px; color:{{ $muted }};">
                                            <span style="color:{{ $gold }};">&#9679;</span>&nbsp; Works for {{ $minutes }} minutes
                                        </div>
                                    </td>
                                </tr>
                            </table>

                            {{-- Safety note --}}
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:24px;">
                                <tr>
                                    <td style="border-left:3px solid {{ $gold }}; padding:2px 0 2px 14px; font-size:13px; line-height:20px; color:{{ $ink }};">
                                        Don’t share this code with anyone. TCC NAV staff will never ask you for it.
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="padding:20px 8px 0; font-family:{{ $sans }}; font-size:12px; line-height:19px; color:{{ $muted }};">
                            <p style="margin:0 0 8px;">Didn’t ask for this code? You can ignore this email. Nothing changes until the code is entered.</p>
                            <p style="margin:0;">TCC NAV · Campus Navigation &amp; Alert System of Tagoloan Community College. This message was sent automatically.</p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
