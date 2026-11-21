<?php

namespace Tests\Feature;

use App\Enums\EmailCodePurpose;
use App\Mail\EmailCodeMail;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * The code email as it is really sent (array mailer): HTML with the embedded logo, and a plain-text part.
 */
class EmailCodeMailTest extends TestCase
{
    private function send(EmailCodePurpose $purpose): Email
    {
        Mail::mailer('array')->to('juan@tcc.edu.ph')->sendNow(new EmailCodeMail('482915', $purpose));

        return Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
    }

    public function test_the_email_shows_the_code_and_what_it_is_for(): void
    {
        $email = $this->send(EmailCodePurpose::VerifyEmail);

        $this->assertSame('Your TCC NAV verification code', $email->getSubject());
        $this->assertStringContainsString('Verify your email', $email->getHtmlBody());
        $this->assertStringContainsString('482915', $email->getHtmlBody());
        $this->assertStringContainsString('Works for 10 minutes', $email->getHtmlBody());
        $this->assertStringContainsString('Your code: 482915', $email->getTextBody());
    }

    public function test_the_logo_is_embedded_so_it_shows_without_loading_the_site(): void
    {
        $email = $this->send(EmailCodePurpose::ChangeEmail);

        $this->assertStringContainsString('Confirm your new email', $email->getHtmlBody());
        $this->assertMatchesRegularExpression('/<img src="cid:[^"]+"/', $email->getHtmlBody());
        $this->assertCount(1, $email->getAttachments());
        $this->assertSame('image/png', $email->getAttachments()[0]->getMediaType().'/'.$email->getAttachments()[0]->getMediaSubtype());
    }
}
