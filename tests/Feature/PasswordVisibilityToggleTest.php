<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The eye button inside password fields (show / hide what you typed).
 */
class PasswordVisibilityToggleTest extends TestCase
{
    public function test_the_sign_in_password_field_has_a_show_password_button_and_still_posts_as_password(): void
    {
        $this->withoutVite();

        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('aria-label="Show password"', $html);
        $this->assertMatchesRegularExpression('/<input type="password"[^>]*name="password"/', $html);
        $this->assertMatchesRegularExpression('/<button type="button"[^>]*aria-label="Show password"/', $html); // never submits the form
        $this->assertSame(1, substr_count($html, 'aria-label="Show password"'));
        $this->assertStringContainsString('autocomplete="current-password"', $html);
    }

    public function test_the_component_renders_an_eye_for_every_password_field_it_wraps(): void
    {
        $html = view('components.password-input', ['attributes' => new \Illuminate\View\ComponentAttributeBag(['name' => 'x', 'class' => 'text-sm'])])->render();

        $this->assertStringContainsString('pr-10', $html);
        $this->assertStringContainsString('text-sm', $html);
        $this->assertStringContainsString('pw-eye-off hidden', $html);
        $this->assertStringContainsString('-ms-reveal', $html);
    }
}