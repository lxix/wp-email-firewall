<?php

/**
 * Plugin Name: Test env: Mailpit
 * Description: Delivers every email to the Mailpit container instead of the internet.
 */

add_action('phpmailer_init', static function ($phpmailer): void {
    $phpmailer->isSMTP();
    $phpmailer->Host = 'mailpit';
    $phpmailer->Port = 1025;
    $phpmailer->SMTPAuth = false;
    $phpmailer->SMTPAutoTLS = false;
});

// The default wordpress@localhost fails is_email() (no dot in the domain), so wp_mail() would refuse it.
add_filter('wp_mail_from', static fn (): string => 'wordpress@wp-email-firewall.test');
