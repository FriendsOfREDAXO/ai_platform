<?php

/**
 * Reads YCom's login page on stdin and prints what is needed to post it back:
 * the form action on the first line, then one `name=value` pair per line.
 *
 * Parsed generically instead of by field index: YForm names its inputs
 * `FORM[<form>][<n>]`, and those numbers depend on how the login form was built
 * in that installation. So the shape is what is read, not the numbering — one
 * text field is the login, one password field is the password, and every hidden
 * field (CSRF token, `returnTo`) rides along with the value YCom put there. The
 * "stay logged in" checkbox is dropped: sending it would be a decision this test
 * has no reason to make.
 *
 * **Attribute values are HTML-decoded, and that is the whole reason this is not
 * three lines of grep.** YCom renders `returnTo` into the markup as
 * `...authorize?response_type=code&amp;client_id=...`, which is correct HTML.
 * Posting it back verbatim sends a literal `&amp;`, so the parameter after it
 * arrives named `amp;client_id`, the authorize endpoint sees no `client_id` and
 * answers with an error page. A browser decodes before submitting; so does this.
 *
 * Credentials come from the environment, never from argv, so they stay out of
 * the process list.
 *
 * Usage: LOGIN=… PASSWORD=… php parse-login-form.php < page.html
 * Exit 1 means no form was found, which the caller reports as a failure.
 */

declare(strict_types=1);

$html = (string) stream_get_contents(STDIN);

if (!preg_match('#<form[^>]*>.*?</form>#si', $html, $matches)) {
    fwrite(STDERR, "no <form> found in the page\n");
    exit(1);
}
$form = $matches[0];

/** Turns an HTML attribute value into what a browser would actually submit. */
$decode = static fn (string $value): string => html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

$attribute = static function (string $tag, string $name) use ($decode): ?string {
    if (!preg_match('#\b' . $name . '="([^"]*)"#i', $tag, $found)) {
        return null;
    }
    return $decode($found[1]);
};

echo $attribute($form, 'action') ?? '', "\n";

preg_match_all('#<input[^>]*>#i', $form, $inputs);

foreach ($inputs[0] as $tag) {
    $name = $attribute($tag, 'name');
    if (null === $name) {
        continue;
    }

    $type = strtolower($attribute($tag, 'type') ?? 'text');
    $value = $attribute($tag, 'value') ?? '';

    if ('password' === $type) {
        $value = (string) getenv('PASSWORD');
    } elseif (in_array($type, ['text', 'email'], true)) {
        $value = (string) getenv('LOGIN');
    } elseif ('checkbox' === $type) {
        continue;
    }

    echo $name, '=', $value, "\n";
}
