<?php

namespace App\Enums;

/**
 * A code issued for one purpose can never be consumed for another.
 */
enum OtpPurpose: string
{
    case EmailVerification = 'email_verification';
    case PasswordReset = 'password_reset';
    case PasswordResetAuthorization = 'password_reset_authorization';
}
