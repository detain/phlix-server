<?php

return [
    'enabled' => true,
    'timeout' => 5,
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
