<?php

namespace App\Services\Auth\Google;

interface GoogleIdentityVerifier
{
    /**
     * Verify a Google ID token (credential) and return the trusted identity.
     *
     * @throws InvalidGoogleCredentialException when the credential is not valid for this application
     * @throws GoogleAuthUnavailableException when Google auth is unconfigured or Google's keys are unreachable
     */
    public function verify(string $credential): GoogleIdentity;
}
