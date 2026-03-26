<?php

// Superseded. The old /api/v1/auth/register/dealer endpoint no longer exists.
// Dealers now register via POST /api/v1/auth/register and choose account type
// during the activation wizard step.
// See tests/Feature/Auth/RegistrationTest.php and tests/Feature/Activation/ActivationFlowTest.php.

it('superseded by RegistrationTest and ActivationFlowTest', fn () => expect(true)->toBeTrue())->skip();
