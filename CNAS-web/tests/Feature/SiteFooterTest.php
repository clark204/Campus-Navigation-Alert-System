<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Site footer (#contact): CNAS contact details from config/cnas.php and the feedback form.
 * The portal and admin help footers show the same details.
 */
class SiteFooterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Distinct values prove the pages read the config rather than a hard-coded copy.
        config(['cnas.contact' => [
            'email' => 'help@test.example',
            'phone' => '+63 912-345-678',
            'facebook' => 'https://www.facebook.com/test-page',
        ]]);
    }

    private function assertContactDetailsFromConfig(TestResponse $response): void
    {
        $response
            ->assertSee('href="mailto:help@test.example"', false)
            ->assertSee('href="tel:+63912345678"', false)
            ->assertSee('+63 912-345-678')
            ->assertSee('href="https://www.facebook.com/test-page" target="_blank" rel="noopener noreferrer"', false)
            ->assertSee('Tagoloan, Misamis Oriental, Philippines')
            ->assertDontSee('clarklagumbay0@gmail.com')
            ->assertDontSee('cnas-admin@example.com')
            ->assertDontSee('Placeholder contact details');
    }

    public function test_the_footer_is_the_contact_section_with_details_from_config(): void
    {
        $response = $this->get(route('landing'))->assertOk();

        $response->assertSeeInOrder([
            '<footer id="contact"',
            'Contact the CNAS administrator',
            'Problems signing up, or someone already registered with your student ID? Get in touch.',
            'mailto:help@test.example',
            '</footer>',
        ], false);
        $this->assertContactDetailsFromConfig($response);
    }

    public function test_the_landing_page_has_no_separate_contact_section(): void
    {
        $html = $this->get(route('landing'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'id="contact"'));
        $this->assertSame(1, substr_count($html, 'Contact the CNAS administrator'));
        $this->assertStringNotContainsString('NEED HELP?', $html);
    }

    public function test_the_footer_shows_the_feedback_form(): void
    {
        $this->get(route('landing'))
            ->assertOk()
            ->assertSeeInOrder(['<footer id="contact"', 'Concerns, suggestions &amp; feedback', '<form id="feedback"', '</footer>'], false)
            ->assertSee('onsubmit="event.preventDefault()"', false)
            ->assertSee('placeholder="example@gmail.com" value=""', false)
            ->assertSee('<textarea id="feedback-message" name="message" rows="3" required', false)
            ->assertSee('placeholder="Tell us what\'s on your mind"', false)
            ->assertSeeInOrder(['name="email" type="email" required', 'name="message"', '<button type="submit"', 'Send', '</form>'], false)
            ->assertDontSee('QUICK LINKS')
            ->assertDontSee('EXTERNAL REFERENCES');
    }

    public function test_the_feedback_email_is_pre_filled_for_signed_in_users(): void
    {
        $this->actingAs(User::factory()->create(['email' => 'juan@tcc.edu.ph', 'role' => UserRole::Student]));

        $this->view('partials.site-footer')->assertSee('placeholder="example@gmail.com" value="juan@tcc.edu.ph"', false);
    }

    public function test_portal_and_admin_help_footers_show_the_same_contact_details(): void
    {
        $portal = $this->get(route('student.dashboard'))->assertOk()->assertSee('id="contact"', false);
        $this->assertContactDetailsFromConfig($portal);

        $admin = $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('id="contact"', false);
        $this->assertContactDetailsFromConfig($admin);
    }
}
