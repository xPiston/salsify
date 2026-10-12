<?php

declare(strict_types=1);

namespace Piston\Salsify\Webhooks;

/**
 * Fetches the certificate a webhook names.
 *
 * A port rather than a method, for two reasons that are both about not making
 * an HTTP call: a verifier should be testable without a network, and a busy
 * endpoint should not fetch the same certificate for every webhook it
 * receives. See CachedCertificates.
 */
interface CertificateFetcher
{
    /**
     * The PEM-encoded certificate at this URL.
     *
     * The URL has already been checked against the expected host by the
     * verifier; an implementation that is given one directly should check it
     * again rather than trust its caller.
     *
     * @throws InvalidSignature if it cannot be fetched
     */
    public function fetch(string $url): string;
}
