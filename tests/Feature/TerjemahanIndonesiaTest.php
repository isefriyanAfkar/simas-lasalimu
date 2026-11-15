<?php

namespace Tests\Feature;

use App\Domains\Foundation\Services\UserService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Laravel's own messages (validation, auth, pagination, passwords) and
 * attribute names come out in Indonesian when APP_LOCALE=id.
 */
class TerjemahanIndonesiaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('id');
    }

    public function test_required_message_and_attribute_name_are_indonesian(): void
    {
        $errors = Validator::make(['email' => ''], ['email' => ['required']])->errors();

        $this->assertSame('Email wajib diisi.', $errors->first('email'));
    }

    public function test_password_minimum_length_message_is_indonesian(): void
    {
        $errors = Validator::make(['password' => 'pendek'], ['password' => [Password::defaults()]])->errors();

        $this->assertSame('Kata sandi minimal berisi 8 karakter.', $errors->first('password'));
    }

    public function test_terminal_super_admin_messages_are_indonesian(): void
    {
        try {
            app(UserService::class)->createSuperAdminFromSystem([
                'name' => '',
                'username' => 'kepalatu',
                'password' => 'pendek',
                'password_confirmation' => 'pendek',
            ]);
            $this->fail('Expected validation errors.');
        } catch (ValidationException $exception) {
            $this->assertSame('Nama wajib diisi.', $exception->validator->errors()->first('name'));
            $this->assertSame('Kata sandi minimal berisi 8 karakter.', $exception->validator->errors()->first('password'));
        }
    }

    public function test_messages_use_the_word_email_like_the_form_labels(): void
    {
        $errors = Validator::make(['email' => 'bukan-email'], ['email' => ['email']])->errors();

        $this->assertSame('Email harus berupa alamat email yang valid.', $errors->first('email'));
        $this->assertSame('Kami sudah mengirim email yang berisi tautan untuk mereset kata sandi Anda!', __('passwords.sent'));
        $this->assertSame('Kami tidak dapat menemukan pengguna dengan alamat email tersebut.', __('passwords.user'));
    }

    public function test_auth_pagination_and_passwords_messages_are_indonesian(): void
    {
        $this->assertSame('Identitas tersebut tidak cocok dengan data kami.', __('auth.failed'));
        $this->assertSame('&laquo; Sebelumnya', __('pagination.previous'));
        $this->assertSame('Kata sandi Anda sudah direset!', __('passwords.reset'));
    }
}
