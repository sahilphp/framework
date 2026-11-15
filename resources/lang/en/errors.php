<?php

declare(strict_types=1);

/**
 * User-facing error messages in English. Exception class names and other
 * technical identifiers are not translated.
 */
return [
    'not_found' => 'Not found',
    'unauthorized' => 'Unauthenticated',
    'forbidden' => 'You are not authorized to perform this action',
    'server_error' => 'An unexpected server error occurred.',
    'bad_request' => 'The request was malformed.',

    // Error page titles and details shown to users.
    'server_error_title' => 'Something went wrong on our end',
    'server_error_detail' => 'We hit an unexpected error while rendering this page. The details were logged; please try again in a moment.',
    'try_again' => 'Please try again. If the problem persists, contact the site administrator.',
    'back_home' => 'Back to home',
    'persistent_error' => 'If you keep seeing this page, contact the site administrator.',
    'sign_in' => 'Sign in',

    'bad_request_title' => 'The request could not be understood',
    'bad_request_detail' => 'The browser sent a request this server could not process. Check the address and try again.',

    'signin_required_title' => 'Please sign in to continue',
    'signin_required_detail' => 'This page is only available to signed-in users. Sign in and we will take you right back here.',

    'access_denied_title' => 'You do not have access to this page',
    'access_denied_detail' => 'Your account is not allowed to open this page. If you think that is a mistake, contact the site administrator.',

    'page_missing' => 'This page could not be found',
    'page_missing_detail' => 'The page you are looking for does not exist or has been moved. Check the address, or head back to the home page.',

    'method_title' => 'That method is not allowed here',
    'method_detail' => 'This address does not accept the request method that was used. Go back and try again with the form or link on the page.',

    'expired_title' => 'Your page has expired',
    'expired_detail' => 'Your session timed out or the security token is no longer valid. Refresh the page and submit the form again.',

    'too_many_title' => 'Too many requests',
    'too_many_detail' => 'You have made too many requests in a short time. Wait a moment, then try again.',

    'request_error_title' => 'This request could not be completed',
    'request_error_detail' => 'The server rejected the request (:reason). Check what you sent, then try again.',

    'unavailable_title' => 'The service is temporarily unavailable',
    'unavailable_detail' => 'We are performing maintenance or the service is restarting. Please try again shortly; your data is safe.',
];
