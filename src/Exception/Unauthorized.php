<?php

declare(strict_types=1);

namespace Piston\Salsify\Exception;

/**
 * 401 or 403.
 *
 * Worth its own class because the three causes need three different answers
 * and the status alone does not tell them apart: a token that is wrong, a
 * token that is right for another organization, and an organization whose
 * licence does not include API access at all - which is a real state, since
 * Salsify sells API access as part of the licence rather than with the
 * product.
 */
final class Unauthorized extends ApiError {}
