{{-- App\Mail\ConcernReplyMail (plain-text part). Unescaped ({!! !!}): plain text, not HTML, so an apostrophe stays an apostrophe. --}}
TCC NAV · Tagoloan Community College

The administrator replied
About your {{ \Illuminate\Support\Str::lower($concern->topic->shortLabel()) }} message from {{ $sentOn }}.

{!! $reply->message !!}

You wrote:
{!! $concern->message !!}
@if ($helpUrl)

Open Help & contact: {!! $helpUrl !!}
@endif

You're getting this because you sent a message to TCC NAV. To follow up, send a new message from {!! $helpUrl ? 'Help & contact in your portal' : 'the bottom of the TCC NAV website' !!}.
