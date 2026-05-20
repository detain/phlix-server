<?php

declare(strict_types=1);

namespace Phlix\Plugins\Contract;

/**
 * Bridge interface preserving the legacy `Phlix\Plugins\Contract\LifecycleInterface`
 * FQCN for one release. The canonical contract now lives in
 * {@see \Phlix\Shared\Plugin\LifecycleInterface} (in `detain/phlix-shared`).
 *
 * `class_alias` does not work on interfaces, so we declare a sub-interface
 * with no extra method requirements — every legacy implementer still
 * satisfies `instanceof` checks in both directions thanks to inheritance.
 *
 * @deprecated since 0.11.0 — implement \Phlix\Shared\Plugin\LifecycleInterface
 *             directly. This bridge will be removed in 0.12.0.
 * @see \Phlix\Shared\Plugin\LifecycleInterface
 *
 * @package Phlix\Plugins\Contract
 * @since 0.10.0
 */
interface LifecycleInterface extends \Phlix\Shared\Plugin\LifecycleInterface
{
}
