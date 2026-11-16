<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Closure;
use Wonder\App\Media\LegacyMediaPlan;

/**
 * Il piano che porta i file di prima del catalogo nella libreria Media: è
 * quello del core ({@see LegacyMediaPlan}), con lo slug del catalogo
 * ({@see Slug::base()}).
 */
final class CatalogMediaPlan
{
    public const MISSING = LegacyMediaPlan::MISSING;
    public const EXTERNAL = LegacyMediaPlan::EXTERNAL;
    public const EXTENSION = LegacyMediaPlan::EXTENSION;
    public const NO_NAME = LegacyMediaPlan::NO_NAME;

    /** @see LegacyMediaPlan::plan() */
    public static function plan(array $sources, array $mediaSlugs = [], array $libraryFiles = [], ?Closure $identical = null): array
    {
        return LegacyMediaPlan::plan($sources, $mediaSlugs, $libraryFiles, $identical, static fn (string $text): string => trim(Slug::base($text), '-'));
    }

    public static function needed(array $plan): bool
    {
        return LegacyMediaPlan::needed($plan);
    }

    /** Riempie un modello con i valori della riga; con `$slug` ne fa uno slug. */
    public static function fill(string $template, array $row, bool $slug): string
    {
        $text = LegacyMediaPlan::fill($template, $row);

        return $slug ? trim(Slug::base($text), '-') : $text;
    }
}
