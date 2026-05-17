<?php

declare(strict_types=1);

namespace Phlex\Plugins\Contract;

/**
 * Bridge interface preserving the legacy `Phlex\Plugins\Contract\LifecycleInterface`
 * FQCN for one release. The canonical contract now lives in
 * {@see \Phlex\Shared\Plugin\LifecycleInterface} (in `detain/phlex-shared`).
 *
 * `class_alias` does not work on interfaces, so we declare a sub-interface
 * with no extra method requirements — every legacy implementer still
 * satisfies `instanceof` checks in both directions thanks to inheritance.
 *
 * @deprecated since 0.11.0 — implement \Phlex\Shared\Plugin\LifecycleInterface
 *             directly. This bridge will be removed in 0.12.0.
 * @see \Phlex\Shared\Plugin\LifecycleInterface
 *
 * @package Phlex\Plugins\Contract
 * @since 0.10.0
 */
interface LifecycleInterface extends \Phlex\Shared\Plugin\LifecycleInterface
{
}
