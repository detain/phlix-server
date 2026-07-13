<?php

return [
    'enabled' => true,
    'timeout' => 5,

    /**
     * TCP connect timeout in seconds for outbound webhook deliveries (SV-4.4).
     *
     * Bounded separately from 'timeout': a target that never completes a TCP
     * handshake would otherwise hang for the full request timeout on every
     * delivery/retry attempt.
     *
     * @default 5
     */
    'connect_timeout' => 5,

    'max_retries' => 2,
    'parallel_dispatch' => true,

    /**
     * Path to the CA bundle used to verify webhook target certificates.
     *
     * Set to a private CA bundle path if your webhook endpoints use an
     * internal certificate authority. Defaults to the Debian system
     * bundle when omitted or empty.
     *
     * @default '/etc/ssl/certs/ca-certificates.crt'
     */
    'ca_bundle' => '/etc/ssl/certs/ca-certificates.crt',
];
