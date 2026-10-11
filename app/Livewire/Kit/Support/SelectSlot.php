<?php

declare(strict_types=1);

namespace App\Livewire\Kit\Support;

/**
 * What <x-kit.select> shows on its trigger before the page's scripts run.
 *
 * The dropdown's list and its live label are drawn in the browser from the
 * hidden native <select> the component keeps for its bindings. Until Alpine
 * starts, the trigger would be blank, and on the live host, which sends a page
 * in pieces, that blank is visible. So the server reads the same <option>
 * markup and picks the option the browser itself would show first: the LAST
 * one marked `selected`, else the first one that is not disabled. The
 * `:selected` options on the employee record's section select and on the
 * punch station's site list rely on exactly that.
 *
 * A `wire:model` value is applied by Livewire in the browser, so the server
 * cannot see it here. Those selects start on their first option, as a native
 * select does until Livewire starts. Presentation only.
 */
final class SelectSlot
{
    /**
     * The label of the option a browser would select, and whether that
     * option is Flux's placeholder (drawn muted, like an empty input).
     *
     * @return array{label: string, placeholder: bool}
     */
    public static function initial(string $html): array
    {
        preg_match_all('/<option\b([^>]*)>(.*?)<\/option>/si', $html, $matches, PREG_SET_ORDER);

        $chosen = null;
        $firstEnabled = null;

        foreach ($matches as $match) {
            if (self::has($match[1], 'selected')) {
                $chosen = $match;
            }

            if ($firstEnabled === null && ! self::has($match[1], 'disabled')) {
                $firstEnabled = $match;
            }
        }

        $chosen ??= $firstEnabled;

        if ($chosen === null) {
            return ['label' => '', 'placeholder' => true];
        }

        return [
            'label' => self::text($chosen[2]),
            'placeholder' => preg_match('/\bclass\s*=\s*"[^"]*\bplaceholder\b/i', $chosen[1]) === 1,
        ];
    }

    /** Whether an option's attribute string carries a boolean attribute. */
    private static function has(string $attributes, string $name): bool
    {
        // Attribute VALUES are skipped first, so `value="selected"` or a class
        // named `disabled-row` never reads as the boolean attribute itself.
        $names = preg_replace('/=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/', '', $attributes) ?? '';

        return preg_match('/(?:^|\s)'.$name.'(?=\s|\/|$)/i', $names) === 1;
    }

    /**
     * An option's text as the browser reports it (`HTMLOptionElement.text`):
     * tags dropped, entities decoded, ASCII whitespace stripped and
     * collapsed. A no-break space is not ASCII whitespace, so the indent the
     * department filter gives a unit survives.
     */
    private static function text(string $inner): string
    {
        $decoded = html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/[\t\n\f\r ]+/', ' ', $decoded) ?? '', "\t\n\f\r ");
    }
}
