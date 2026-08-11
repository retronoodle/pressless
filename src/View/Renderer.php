<?php

declare(strict_types=1);

namespace Stead\View;

/**
 * Renders a named template with simple view data.
 *
 * Phase 1 ships {@see SimpleRenderer}; the Twig implementation added with the
 * admin surface satisfies the same contract so controllers do not change.
 */
interface Renderer
{
    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string;
}
