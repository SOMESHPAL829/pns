<?php
declare(strict_types=1);

require __DIR__ . '/../includes/security.php';

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

expect(normalize_email(' User@Example.COM ') === 'user@example.com', 'email is trimmed and normalized');
expect(!password_is_valid('short'), 'short passwords are rejected');
expect(password_is_valid('correct horse battery staple'), 'long passphrases are accepted');

$firstToken = generate_reset_token();
$secondToken = generate_reset_token();
expect((bool) preg_match('/^[a-f0-9]{64}$/', $firstToken), 'reset token has 256 bits of hex entropy');
expect($firstToken !== $secondToken, 'reset tokens are unpredictable and unique');
expect(verify_csrf_token(csrf_token()), 'the session accepts its own CSRF token');
expect(!verify_csrf_token('not-the-session-token'), 'the session rejects a mismatched CSRF token');
expect(escape_html('<script>') === '&lt;script&gt;', 'HTML output is escaped');

echo "Security helper tests passed.\n";